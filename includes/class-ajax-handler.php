<?php
/**
 * Класс для обработки AJAX запросов
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class GitPush_AJAX_Handler {
    
    private $github_api;
    private $files_manager;
    
    public function __construct($github_api = null, $files_manager = null) {
        $this->github_api = $github_api ?: new GitPush_GitHub_API();
        $this->files_manager = $files_manager ?: new GitPush_Files_Manager($this->github_api); 
        
        add_action('wp_ajax_github_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_github_get_theme_files', [$this, 'ajax_get_theme_files']); // Может быть устаревшим
        add_action('wp_ajax_github_get_changed_files', [$this, 'ajax_get_changed_files']);
        add_action('wp_ajax_github_get_file_diff', [$this, 'ajax_get_file_diff']);
        add_action('wp_ajax_github_sync_theme', [$this, 'ajax_sync_theme']);
        add_action('wp_ajax_github_get_file_commits', [$this, 'ajax_get_file_commits']);
    }
    
    private function check_permissions_and_nonce($nonce_action = 'gitpush_nonce') {
        check_ajax_referer($nonce_action, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
            // wp_die() здесь не нужен, так как wp_send_json_error уже завершает выполнение
        }
        return true;
    }
    
    public function ajax_test_connection() {
        $this->check_permissions_and_nonce(); 
        $result = $this->github_api->test_connection();
        
        if (isset($result['success']) && $result['success']) {
            wp_send_json_success(['message' => $result['message'] ?? 'Connection successful.']);
        } else {
            wp_send_json_error(['message' => $result['message'] ?? 'Connection failed.']);
        }
    }
    
    public function ajax_get_theme_files() {
        $this->check_permissions_and_nonce();
        $theme_path = trailingslashit(get_stylesheet_directory());
        // Убедитесь, что get_theme_files в Files_Manager возвращает ожидаемый формат (массив файлов с ключом 'path')
        $files = $this->files_manager->get_theme_files($theme_path, $theme_path); 
        wp_send_json_success(['files' => $files]); // theme_dir не нужен, если пути уже относительные
    }
    
    public function ajax_get_file_diff() {
        $this->check_permissions_and_nonce();
        $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';
        
        if (empty($file_path)) {
            wp_send_json_error(['message' => 'Missing file path.']);
            return;
        }
        
        $diff_data = $this->files_manager->get_file_diff($file_path); 
        
        if (isset($diff_data['error'])) {
             wp_send_json_error(['message' => $diff_data['error'], 'data' => $diff_data]);
        } else {
             wp_send_json_success(['data' => $diff_data]); // Возвращаем весь объект с diff, status и т.д.
        }
    }
    
    public function ajax_get_file_commits() {
        $this->check_permissions_and_nonce();
        $file_path = isset($_POST['file_path']) ? sanitize_text_field(wp_unslash($_POST['file_path'])) : '';
        
        if (empty($file_path)) {
            wp_send_json_error(['message' => 'Missing file path.']);
            return;
        }
        
        $commits = $this->github_api->get_file_commits($file_path);
        
        if (isset($commits['error'])) {
             wp_send_json_error(['message' => $commits['error'], 'commits' => [], 'file_path' => $file_path]);
        } else {
            wp_send_json_success(['commits' => $commits, 'file_path' => $file_path]);
        }
    }

    public function ajax_get_changed_files() {
        $this->check_permissions_and_nonce();
        $force_refresh = isset($_POST['force_refresh']) && filter_var($_POST['force_refresh'], FILTER_VALIDATE_BOOLEAN);
        $changed_files = $this->files_manager->get_changed_files($force_refresh);
        
        if (is_array($changed_files)) {
            // Отправляем массив файлов напрямую, JS будет ожидать его в response.data.changed_files
            wp_send_json_success(['changed_files' => $changed_files]); 
        } else {
            error_log('GitPush WP: ajax_get_changed_files received non-array from files_manager->get_changed_files');
            wp_send_json_error(['message' => 'Failed to retrieve changed files.']);
        }
    }

    public function ajax_sync_theme() {
        $this->check_permissions_and_nonce();

        $commit_message = isset($_POST['commit_message']) ? sanitize_textarea_field(wp_unslash($_POST['commit_message'])) : '';
        if (empty($commit_message)) {
            wp_send_json_error(['message' => 'Commit message is required.']);
            return;
        }
        
        // JavaScript отправляет JSON строку в $_POST['files']
        $selected_files_json = isset($_POST['files']) ? wp_unslash($_POST['files']) : '[]';
        $paths_to_sync = json_decode($selected_files_json, true); 

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($paths_to_sync)) {
            wp_send_json_error(['message' => 'Invalid selected files data. Expected a JSON array of file paths.']);
            return;
        }
        
        if (empty($paths_to_sync)) {
            wp_send_json_success([
                'message' => 'No files were selected to sync.',
                'results' => [], // Был 'files', меняем на 'results' для единообразия с JS
                'changed_files' => $this->files_manager->get_changed_files(false), 
                'sync_time' => current_time('Y-m-d H:i:s') // Был 'synced_at'
            ]);
            return;
        }

        $results = $this->files_manager->sync_files($commit_message, $paths_to_sync);
        $changed_files_after_sync = $this->files_manager->get_changed_files(true); 

        $has_errors = false;
        if (isset($results['error'])) { 
            $has_errors = true;
        } elseif (is_array($results)) { // $results теперь $results_summary из Files_Manager
            foreach($results as $result_item) {
                if (isset($result_item['type']) && $result_item['type'] === 'error') {
                    $has_errors = true;
                    break;
                }
            }
        }

        $response_data = [
            'results'       => $results, // $results теперь $results_summary
            'changed_files' => $changed_files_after_sync,
            'sync_time'     => current_time('Y-m-d H:i:s')
        ];

        if ($has_errors) {
            $response_data['message'] = isset($results['error']) ? $results['error'] : 'Sync process completed with errors.';
            wp_send_json_error($response_data);
        } else {
            $response_data['message'] = 'Theme synced successfully!';
            wp_send_json_success($response_data);
        }
    }
}