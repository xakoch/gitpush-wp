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
        add_action('wp_ajax_github_get_theme_files', [$this, 'ajax_get_theme_files']); // Обработчик для "All Files"
        add_action('wp_ajax_github_get_changed_files', [$this, 'ajax_get_changed_files']);
        add_action('wp_ajax_github_get_file_diff', [$this, 'ajax_get_file_diff']);
        add_action('wp_ajax_github_sync_theme', [$this, 'ajax_sync_theme']);
        add_action('wp_ajax_github_get_file_commits', [$this, 'ajax_get_file_commits']);
    }
    
    private function check_permissions_and_nonce($nonce_action = 'gitpush_nonce') {
        check_ajax_referer($nonce_action, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
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
        $local_files = $this->files_manager->get_theme_files($theme_path, $theme_path); 
        
        $files_for_js = [];
        if (is_array($local_files)) {
            foreach ($local_files as $file) {
                if (isset($file['path'])) {
                    // Для "All Files" мы не знаем их статус относительно GitHub без сравнения.
                    // Присвоим 'unknown', чтобы JS мог их отобразить.
                    // JS renderFileList ожидает 'status' и 'type' (type уже есть из get_theme_files)
                    $file_entry = [
                        'path' => $file['path'],
                        'status' => 'unknown', // Или 'local' для ясности, но JS должен это понимать
                        'type' => $file['type'] ?? 'file' // get_theme_files должен возвращать type
                    ];
                    // Если get_theme_files не возвращает type, нужно добавить:
                    // if (!isset($file_entry['type'])) $file_entry['type'] = 'file'; 
                    $files_for_js[] = $file_entry;
                }
            }
        }
        // Отправляем в формате, который ожидает JS-функция fetchFiles -> renderFileList (т.е. в ключе 'changed_files')
        wp_send_json_success(['changed_files' => $files_for_js]); 
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
             wp_send_json_success(['data' => ['diff' => $diff_data]]); // Убедимся, что JS ожидает data.diff
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
        
        $selected_files_json = isset($_POST['files']) ? wp_unslash($_POST['files']) : '[]';
        $paths_to_sync = json_decode($selected_files_json, true); 

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($paths_to_sync)) {
            wp_send_json_error(['message' => 'Invalid selected files data. Expected a JSON array of file paths.']);
            return;
        }
        
        if (empty($paths_to_sync)) {
            wp_send_json_success([
                'message' => 'No files were selected to sync.',
                'results' => [], 
                'changed_files' => $this->files_manager->get_changed_files(false), 
                'sync_time' => current_time('Y-m-d H:i:s') 
            ]);
            return;
        }

        $results = $this->files_manager->sync_files($commit_message, $paths_to_sync);
        $changed_files_after_sync = $this->files_manager->get_changed_files(true); 

        $has_errors = false;
        $error_message_summary = 'Sync process completed with errors.';

        if (isset($results['error'])) { 
            $has_errors = true;
            $error_message_summary = $results['error'];
        } elseif (is_array($results)) { 
            foreach($results as $result_item) {
                if (isset($result_item['type']) && $result_item['type'] === 'error') {
                    $has_errors = true;
                    if (isset($result_item['status_text'])) { // Предполагаем, что status_text содержит сообщение об ошибке
                        $error_message_summary = $result_item['status_text']; // Можно взять первое сообщение об ошибке
                    }
                    break;
                }
            }
        }

        $response_data = [
            'results'       => $results, 
            'changed_files' => $changed_files_after_sync,
            'sync_time'     => current_time('Y-m-d H:i:s')
        ];

        if ($has_errors) {
            $response_data['message'] = $error_message_summary;
            wp_send_json_error($response_data);
        } else {
            $response_data['message'] = 'Theme synced successfully!';
            wp_send_json_success($response_data);
        }
    }
}