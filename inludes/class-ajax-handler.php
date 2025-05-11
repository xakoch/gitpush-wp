<?php
/**
 * Класс для обработки AJAX запросов
 */
class GitPush_AJAX_Handler {
    
    private $github_api;
    private $files_manager;
    
    public function __construct($github_api = null, $files_manager = null) {
        $this->github_api = $github_api ?: new GitPush_GitHub_API();
        $this->files_manager = $files_manager ?: new GitPush_Files_Manager();
        
        // Регистрируем обработчики AJAX
        add_action('wp_ajax_github_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_github_get_theme_files', [$this, 'ajax_get_theme_files']);
        add_action('wp_ajax_github_get_changed_files', [$this, 'ajax_get_changed_files']);
        add_action('wp_ajax_github_get_file_diff', [$this, 'ajax_get_file_diff']);
        add_action('wp_ajax_github_sync_theme', [$this, 'ajax_sync_theme']);
        add_action('wp_ajax_github_pull_from_github', [$this, 'ajax_pull_from_github']);
        add_action('wp_ajax_github_get_file_commits', [$this, 'ajax_get_file_commits']);
    }
    
    /**
     * Проверка доступа пользователя
     */
    private function check_permissions() {
        // Проверяем nonce
        check_ajax_referer('gitpush_wp_nonce', 'nonce');
        
        // Проверяем права доступа
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        return true;
    }
    
    /**
     * Тестирование соединения с GitHub
     */
    public function ajax_test_connection() {
        $this->check_permissions();
        
        $result = $this->github_api->test_connection();
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Получение списка всех файлов темы
     */
    public function ajax_get_theme_files() {
        $this->check_permissions();
        
        $theme_dir = get_template_directory();
        $files = $this->files_manager->get_theme_files($theme_dir);
        
        wp_send_json_success([
            'files' => $files,
            'theme_dir' => $theme_dir
        ]);
    }
    
    /**
     * Получение списка изменённых файлов
     */
    public function ajax_get_changed_files() {
        $this->check_permissions();
        
        $changed_files = $this->files_manager->get_changed_files();
        
        wp_send_json_success([
            'files' => $changed_files,
            'theme_dir' => get_template_directory()
        ]);
    }
    
    /**
     * Получение различий в файле
     */
    public function ajax_get_file_diff() {
        $this->check_permissions();
        
        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';
        
        if (empty($file_path)) {
            wp_send_json_error('Missing file path');
        }
        
        $diff = $this->files_manager->get_file_diff($file_path);
        
        wp_send_json_success($diff);
    }
    
    /**
     * Получение истории коммитов файла
     */
    public function ajax_get_file_commits() {
        $this->check_permissions();
        
        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';
        
        if (empty($file_path)) {
            wp_send_json_error('Missing file path');
        }
        
        $commits = $this->github_api->get_file_commits($file_path);
        
        wp_send_json_success([
            'commits' => $commits,
            'file_path' => $file_path
        ]);
    }
    
    /**
     * Синхронизация выбранных файлов с GitHub
     */
    public function ajax_sync_theme() {
        $this->check_permissions();
        
        // Получаем выбранные файлы и сообщение коммита
        $files = isset($_POST['files']) ? json_decode(stripslashes($_POST['files']), true) : [];
        $commit_message = isset($_POST['commit_message']) ? sanitize_text_field($_POST['commit_message']) : 'Update from WordPress';
        
        if (empty($files)) {
            wp_send_json_error('No files selected for sync');
        }
        
        // Выполняем синхронизацию
        $results = $this->files_manager->sync_files($files, $commit_message);
        
        // Получаем время последней синхронизации
        $settings = $this->github_api->get_settings();
        
        wp_send_json_success([
            'message' => 'Sync completed',
            'results' => $results,
            'sync_time' => $settings['last_sync']
        ]);
    }
    
    /**
     * Pull файлов с GitHub
     */
    public function ajax_pull_from_github() {
        $this->check_permissions();
        
        $pull_result = $this->files_manager->pull_files();
        
        wp_send_json_success([
            'message' => 'Pull completed',
            'updated_files' => $pull_result['updated_files'],
            'errors' => $pull_result['errors'],
            'pull_time' => $pull_result['pull_time']
        ]);
    }
}