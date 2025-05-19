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
        // ИСПРАВЛЕНО: Передаем $this->github_api в конструктор GitPush_Files_Manager
        $this->files_manager = $files_manager ?: new GitPush_Files_Manager($this->github_api); 
        
        // Регистрируем обработчики AJAX
        add_action('wp_ajax_github_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_github_get_theme_files', [$this, 'ajax_get_theme_files']);
        add_action('wp_ajax_github_get_changed_files', [$this, 'ajax_get_changed_files']); // Это новый обработчик
        add_action('wp_ajax_github_get_file_diff', [$this, 'ajax_get_file_diff']);
        add_action('wp_ajax_github_sync_theme', [$this, 'ajax_sync_theme']);         // Это новый обработчик
        add_action('wp_ajax_github_get_file_commits', [$this, 'ajax_get_file_commits']);
        // add_action('wp_ajax_github_pull_from_github', [$this, 'ajax_pull_from_github']); // Обработчик Pull удален
    }
    
    /**
     * Проверка доступа пользователя и nonce.
     * Nonce теперь 'gitpush_nonce'
     */
    private function check_permissions($nonce_action = 'gitpush_nonce') {
        // Проверяем nonce
        check_ajax_referer($nonce_action, 'nonce'); // Используем общий nonce
        
        // Проверяем права доступа
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']); // Улучшен ответ
        }
        return true;
    }
    
    /**
     * Тестирование соединения с GitHub
     */
    public function ajax_test_connection() {
        $this->check_permissions(); // Nonce 'gitpush_nonce' будет проверен здесь
        
        $result = $this->github_api->test_connection();
        
        if (isset($result['success']) && $result['success']) {
            wp_send_json_success(['message' => isset($result['message']) ? $result['message'] : 'Connection successful.']);
        } else {
            wp_send_json_error(['message' => isset($result['message']) ? $result['message'] : 'Connection failed.']);
        }
    }
    
    /**
     * Получение списка всех файлов темы (этот метод, возможно, больше не нужен, если get_changed_files покрывает все)
     * Оставлен для совместимости, если где-то используется.
     */
    public function ajax_get_theme_files() {
        $this->check_permissions();
        
        $theme_dir = get_template_directory();
        // Предполагается, что get_theme_files в Files_Manager существует и корректно работает.
        // Этот метод в Files_Manager должен быть тщательно проверен на предмет актуальности и необходимости.
        $files = $this->files_manager->get_theme_files($theme_dir, $theme_dir); 
        
        wp_send_json_success([
            'files' => $files,
            'theme_dir' => $theme_dir // Относительные пути уже должны быть в $files
        ]);
    }
    
    /**
     * Получение различий в файле
     */
    public function ajax_get_file_diff() {
        $this->check_permissions();
        
        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';
        
        if (empty($file_path)) {
            wp_send_json_error(['message' => 'Missing file path.']);
            return;
        }
        
        $diff = $this->files_manager->get_file_diff($file_path); // Убедитесь, что get_file_diff существует в Files_Manager
        
        if ($diff === null || isset($diff['error'])) {
             wp_send_json_error(['message' => isset($diff['error']) ? $diff['error'] : 'Could not get diff.', 'diff' => '']);
        } else {
             wp_send_json_success(['diff' => $diff]);
        }
    }
    
    /**
     * Получение истории коммитов файла
     */
    public function ajax_get_file_commits() {
        $this->check_permissions();
        
        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';
        
        if (empty($file_path)) {
            wp_send_json_error(['message' => 'Missing file path.']);
            return;
        }
        
        $commits = $this->github_api->get_file_commits($file_path);
        
        if (is_null($commits) || isset($commits['error'])) {
             wp_send_json_error([
                'message' => isset($commits['error']) ? $commits['error'] : 'Could not get commits.',
                'commits' => [],
                'file_path' => $file_path
            ]);
        } else {
            wp_send_json_success([
                'commits' => $commits,
                'file_path' => $file_path
            ]);
        }
    }

    // --------------------------------------------------------------------
    // НОВЫЕ ВЕРСИИ МЕТОДОВ (оставляем только их)
    // --------------------------------------------------------------------

    /**
     * Получение списка изменённых файлов (НОВАЯ ВЕРСИЯ)
     * С поддержкой принудительного обновления.
     */
    public function ajax_get_changed_files() {
        // Nonce и проверка прав уже внутри этого метода
        check_ajax_referer('gitpush_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
            return;
        }

        // Определяем, нужно ли принудительное обновление из POST-запроса
        $force_refresh = isset($_POST['force_refresh']) && filter_var($_POST['force_refresh'], FILTER_VALIDATE_BOOLEAN);
        
        $changed_files = $this->files_manager->get_changed_files($force_refresh);
        
        if (is_array($changed_files)) {
            wp_send_json_success(['changed_files' => $changed_files]);
        } else {
            // Этого не должно произойти, если get_changed_files всегда возвращает массив
            error_log('GitPush WP: ajax_get_changed_files received non-array from files_manager->get_changed_files');
            wp_send_json_error(['message' => 'Failed to retrieve changed files. Expected an array.']);
        }
    }

    /**
     * Синхронизация выбранных файлов с GitHub (НОВАЯ ВЕРСИЯ)
     */
    public function ajax_sync_theme() {
        // Nonce и проверка прав уже внутри этого метода
        check_ajax_referer('gitpush_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
            return;
        }

        $commit_message = isset($_POST['commit_message']) ? sanitize_textarea_field($_POST['commit_message']) : '';
        if (empty($commit_message)) {
            wp_send_json_error(['message' => 'Commit message is required.']);
            return;
        }

        // Файлы передаются как JSON-строка объектов {path: "...", status: "..."}
        $selected_files_json = isset($_POST['selected_files']) ? stripslashes($_POST['selected_files']) : '[]';
        $selected_files_input = json_decode($selected_files_json, true); // Это должен быть массив деталей файлов

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($selected_files_input)) {
            wp_send_json_error(['message' => 'Invalid selected files data. JSON decode error or not an array.']);
            return;
        }
        
        // Нам нужны только пути к файлам для передачи в sync_files, если он ожидает массив путей.
        // Однако, лучше передавать массив объектов с деталями, чтобы sync_files мог использовать статус.
        // Предположим, что JavaScript теперь отправляет массив строк (путей).
        // Если JS отправляет объекты {path: '...', status: '...'}, то sync_files должен быть адаптирован.
        // Метод sync_files в Files_Manager сейчас ожидает массив путей ($selected_files_paths).
        $paths_to_sync = [];
        foreach($selected_files_input as $file_detail) {
            if (isset($file_detail['path'])) {
                $paths_to_sync[] = $file_detail['path'];
            }
        }

        if (empty($paths_to_sync)) {
            wp_send_json_success([
                'message' => 'No files were selected or file data was incomplete.',
                'files' => [], 
                'changed_files' => $this->files_manager->get_changed_files(false), 
                'synced_at' => current_time('Y-m-d H:i:s')
            ]);
            return;
        }

        // Выполняем синхронизацию (sync_files ожидает $commit_message, $selected_files_paths)
        $results = $this->files_manager->sync_files($commit_message, $paths_to_sync);

        // Получаем АКТУАЛЬНЫЙ список измененных файлов ПОСЛЕ синхронизации
        // Передаем true, чтобы гарантированно сбросить кеш и получить свежие данные с GitHub.
        // sync_files уже должен вызывать clear_internal_caches(), так что здесь true избыточен, но безопасен.
        $changed_files_after_sync = $this->files_manager->get_changed_files(true); 

        $has_errors = false;
        if (isset($results['error'])) { // Общая ошибка от sync_files
            $has_errors = true;
        } elseif (is_array($results)) {
            foreach($results as $result_item) {
                if (isset($result_item['status']) && stripos($result_item['status'], 'error') !== false) {
                    $has_errors = true;
                    break;
                }
            }
        }

        if ($has_errors) {
            wp_send_json_error([
                'message' => isset($results['error']) ? $results['error'] : 'Sync process completed with errors.',
                'files' => is_array($results) ? $results : [$results], // Результаты по каждому файлу
                'changed_files' => $changed_files_after_sync,
                'synced_at' => current_time('Y-m-d H:i:s')
            ]);
        } else {
            wp_send_json_success([
                'message' => 'Theme synced successfully!',
                'files' => $results, // Результаты по каждому файлу (успешные)
                'changed_files' => $changed_files_after_sync, // Должен быть пустым или уменьшиться
                'synced_at' => current_time('Y-m-d H:i:s')
            ]);
        }
    }

    /* // Обработчик Pull удален
    public function ajax_pull_from_github() {
        // ...
    }
    */
}