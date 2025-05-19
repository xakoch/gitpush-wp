<?php
// Файл: inludes/class-files-manager.php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class GitPush_Files_Manager {

    private $api;
    private $theme_path;
    private $github_files_tree_flat = null; 
    // private $github_file_cache = array(); // Этот кеш пока не используется активно, можно будет добавить позже если нужно кешировать контент отдельных файлов

    const GITHUB_TREE_CACHE_KEY = 'gitpush_wp_github_tree_cache';

    public function __construct(GitPush_GitHub_API $api) {
        $this->api = $api;
        $this->theme_path = trailingslashit(get_stylesheet_directory()); // Или get_template_directory() если это родительская тема
    }

    /**
     * Очищает внутренний кеш данных, полученных с GitHub.
     */
    public function clear_internal_caches() {
        $this->github_files_tree_flat = null;
        // $this->github_file_cache = []; // Если будет использоваться
        delete_transient(self::GITHUB_TREE_CACHE_KEY);
        error_log('GitPush WP: Internal caches cleared.');
    }

    /**
     * Получает плоский список файлов из репозитория GitHub с их SHA и типами.
     * Использует кеширование.
     *
     * @param bool $force_refresh Принудительно обновить данные с GitHub, игнорируя кеш.
     * @return array Плоский список файлов [относительный_путь => ['path' => ..., 'sha' => ..., 'type' => ...]] или пустой массив.
     */
    public function get_github_files_tree_flat($force_refresh = false) {
        if ($force_refresh) {
            $this->clear_internal_caches(); 
        }

        if ($this->github_files_tree_flat !== null && !$force_refresh) { // Добавил проверку !$force_refresh
            return $this->github_files_tree_flat;
        }

        $cached_tree = get_transient(self::GITHUB_TREE_CACHE_KEY);
        if (!$force_refresh && $cached_tree) {
            $this->github_files_tree_flat = $cached_tree;
            error_log('GitPush WP: Fetched GitHub tree from transient cache.');
            return $cached_tree;
        }

        // ИСПРАВЛЕННЫЙ ВЫЗОВ:
        // Убедитесь, что ваш метод $this->api->get_repo_tree() принимает ($branch_name, $recursive_flag)
        // и что $this->api->get_branch() возвращает имя текущей ветки.
        $branch_name = $this->api->get_branch(); // Получаем имя текущей ветки
        if (!$branch_name) {
            error_log('GitPush WP: Branch name not available from API for get_repo_tree.');
            return [];
        }
        $tree_items = $this->api->get_repo_tree($branch_name, true); // true для рекурсивного получения

        if (is_null($tree_items)) { 
            error_log('GitPush WP: Failed to fetch GitHub tree from API (null returned).');
            return []; 
        }
        
        $flat_tree = [];
        if (is_array($tree_items)) {
            foreach ($tree_items as $item) {
                if (isset($item['type']) && $item['type'] === 'blob' && isset($item['path'])) { 
                    $flat_tree[$item['path']] = [
                        'path' => $item['path'],
                        'sha' => $item['sha'],
                        'type' => 'file' 
                    ];
                }
            }
        }

        set_transient(self::GITHUB_TREE_CACHE_KEY, $flat_tree, HOUR_IN_SECONDS);
        $this->github_files_tree_flat = $flat_tree;
        error_log('GitPush WP: Fetched GitHub tree from API and cached. Items: ' . count($flat_tree));
        return $flat_tree;
    }

    /**
     * Получает список измененных, новых или удаленных файлов темы.
     *
     * @param bool $force_refresh Принудительно обновить данные с GitHub, игнорируя кеш.
     * @return array Массив измененных файлов.
     */
    public function get_changed_files($force_refresh = false) {
        // $force_refresh уже управляет очисткой кеша в get_github_files_tree_flat
        // $this->clear_internal_caches(); // Этот вызов здесь может быть избыточным, если get_github_files_tree_flat его делает.
                                      // Оставим его в get_github_files_tree_flat, чтобы он был центральной точкой очистки для дерева.

        $github_files_flat = $this->get_github_files_tree_flat($force_refresh); 
        
        // Передаем $this->theme_path как базовую директорию для корректного формирования относительных путей.
        $local_files_list = $this->get_theme_files($this->theme_path, $this->theme_path);

        $changed_files_data = [];
        $processed_local_paths = [];

        // 1. Итерация по локальным файлам для поиска новых и измененных
        foreach ($local_files_list as $local_file) {
            if (!isset($local_file['path']) || (isset($local_file['type']) && $local_file['type'] === 'dir')) {
                continue;
            }
            $relative_path = $local_file['path'];
            $processed_local_paths[$relative_path] = true; 
            $full_local_path = $this->theme_path . $relative_path;

            if (!file_exists($full_local_path) || !is_readable($full_local_path)) { // Добавил is_readable
                error_log("GitPush WP: Local file listed but not found or not readable: " . $full_local_path);
                continue;
            }
            
            $local_content = file_get_contents($full_local_path);
            if ($local_content === false) {
                error_log("GitPush WP: Failed to read local file content: " . $full_local_path);
                continue;
            }
            $local_file_git_sha = $this->api->calculate_git_blob_sha($local_content);

            $file_data = [
                'path' => $relative_path,
                // 'local_sha_for_debug' => $local_file_git_sha, // Можно раскомментировать для отладки
                'status' => 'unknown',
                'type' => 'file' 
            ];

            $github_file_details = isset($github_files_flat[$relative_path]) ? $github_files_flat[$relative_path] : null;

            if (!$github_file_details) {
                $file_data['status'] = 'new';
            } else {
                if (isset($github_file_details['type']) && $github_file_details['type'] === 'file') {
                    if ($github_file_details['sha'] !== $local_file_git_sha) {
                        $file_data['status'] = 'modified';
                        $file_data['github_sha'] = $github_file_details['sha']; 
                    } else {
                        continue; // Файл не изменен
                    }
                } else {
                    error_log("GitPush WP: Path on GitHub is not a file ('".($github_file_details['type'] ?? 'unknown type')."'), but local is: " . $relative_path);
                    $file_data['status'] = 'new'; // Считаем новым, если на GitHub по этому пути не файл (или тип неизвестен)
                }
            }
            $changed_files_data[$relative_path] = $file_data;
        }

        // 2. Итерация по файлам GitHub для поиска удаленных локально
        foreach ($github_files_flat as $github_path => $github_item_details) {
            if ($github_item_details['type'] !== 'file') { 
                continue;
            }
            if ($this->is_ignored($github_path)) { // Проверка на игнорирование для файлов с GitHub
                continue;
            }
            if (!isset($processed_local_paths[$github_path])) {
                // Файл есть на GitHub, но нет локально (и не в игноре) -> удален локально
                $changed_files_data[$github_path] = [
                    'path' => $github_path,
                    'status' => 'deleted',
                    'github_sha' => $github_item_details['sha'], 
                    'type' => 'file'
                ];
            }
        }
        return array_values($changed_files_data); 
    }

    /**
     * Рекурсивно получает список файлов и директорий в указанной директории темы.
     * Возвращает пути относительно $base_dir.
     */
    public function get_theme_files($dir, $base_dir) {
        $files = array();
        if (!is_dir($dir) || !is_readable($dir)) { // Добавил проверки
            error_log("GitPush WP: Cannot scan directory, not a directory or not readable: " . $dir);
            return $files;
        }
        $items = @scandir($dir);

        if ($items === false) {
            error_log("GitPush WP: Failed to scan directory (scandir returned false): " . $dir);
            return $files;
        }

        foreach ($items as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            $path = $dir . $item; 
            $relative_path_item = ltrim(str_replace(trailingslashit($base_dir), '', $path), '/\\'); // Убедимся что base_dir тоже с слешем

            if ($this->is_ignored($item) || $this->is_ignored($relative_path_item)) {
                // error_log("GitPush WP: Ignoring path during local scan: " . $relative_path_item); // Можно раскомментировать для детальной отладки
                continue;
            }
            
            $file_data = ['path' => $relative_path_item];

            if (is_dir($path)) {
                // Директории не добавляем, но рекурсивно обходим.
                // Если нужно будет создавать пустые директории на GitHub, логика усложнится.
                $files = array_merge($files, $this->get_theme_files(trailingslashit($path), $base_dir));
            } else {
                $file_data['type'] = 'file';
                // $file_data['extension'] = strtolower(pathinfo($item, PATHINFO_EXTENSION)); // Не используется дальше, можно убрать
                $files[] = $file_data;
            }
        }
        return $files;
    }

    /**
     * Проверяет, должен ли путь быть проигнорирован.
     */
    private function is_ignored($path_segment) {
        $ignored_exact_names = ['.git', '.svn', '.DS_Store', 'node_modules', 'vendor', '.vscode', '.idea', 'desktop.ini', 'thumbs.db'];  
        $ignored_paths_start_with = ['cache/', '.wp-env/']; // Пример: игнорировать директорию 'cache/' в корне темы
        // $ignored_extensions = ['.log', '.tmp', '.bak', '.swp']; // Пример

        $basename = basename($path_segment);

        if (in_array($basename, $ignored_exact_names, true)) {
            return true;
        }
        // if (in_array(strtolower(pathinfo($basename, PATHINFO_EXTENSION)), $ignored_extensions, true)) {
        //     return true;
        // }
        foreach ($ignored_paths_start_with as $ignored_start) {
            if (strpos($path_segment, $ignored_start) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Синхронизирует выбранные файлы с GitHub.
     */
    public function sync_files($commit_message, $selected_files_paths = []) {
        if (empty($commit_message)) {
            return ['error' => 'Commit message is required.'];
        }
        if (empty($selected_files_paths)) {
            // Это состояние должно обрабатываться в AJAX_Handler, но на всякий случай
            return ['info' => 'No files selected to sync.', 'results' => []]; 
        }

        // Получаем детали измененных файлов (статус, SHA для удаления/обновления)
        // Передаем false, чтобы использовать кеш, если JS уже вызвал refresh и список актуален.
        // Если есть сомнения, можно передать true, но это лишний API запрос за деревом.
        $all_changed_files_details = $this->get_changed_files(false); 
        $files_to_process_map = [];
        foreach ($all_changed_files_details as $file) {
            if (in_array($file['path'], $selected_files_paths, true)) {
                $files_to_process_map[$file['path']] = $file;
            }
        }

        $results = [];

        foreach ($selected_files_paths as $relative_path) {
            if (!isset($files_to_process_map[$relative_path])) {
                $results[] = ['path' => $relative_path, 'status' => 'Error: File details not found for sync. Refresh list and try again.'];
                error_log("GitPush WP: File {$relative_path} selected for sync but its details not found in current change set.");
                continue;
            }

            $file_info = $files_to_process_map[$relative_path];
            $full_local_path = $this->theme_path . $relative_path;
            $push_result = null;

            switch ($file_info['status']) {
                case 'deleted':
                    if (empty($file_info['github_sha'])) {
                        $results[] = ['path' => $relative_path, 'status' => 'Error: GitHub SHA missing for deleted file.'];
                        error_log("GitPush WP: GitHub SHA missing for deleted file {$relative_path}.");
                        continue 2; // PHP < 7.3, break 2; для PHP >= 7.3, здесь 'continue 2' прервет foreach и switch
                    }
                    $push_result = $this->api->delete_file($relative_path, $commit_message, $file_info['github_sha']);
                    break;
                case 'new':
                case 'modified':
                    if (!file_exists($full_local_path) || !is_readable($full_local_path)) {
                        $results[] = ['path' => $relative_path, 'status' => 'Error: Local file not found or not readable.'];
                        error_log("GitPush WP: Local file {$full_local_path} not found/readable for push (status: {$file_info['status']}).");
                        continue 2; 
                    }
                    $content = file_get_contents($full_local_path);
                    if ($content === false) {
                         $results[] = ['path' => $relative_path, 'status' => 'Error: Could not read local file content.'];
                         error_log("GitPush WP: Failed to read content of {$full_local_path} for push.");
                         continue 2;
                    }
                    $github_sha_for_update = ($file_info['status'] === 'modified' && isset($file_info['github_sha'])) ? $file_info['github_sha'] : null;
                    $push_result = $this->api->push_file($relative_path, $content, $commit_message, $github_sha_for_update);
                    break;
                default:
                    $results[] = ['path' => $relative_path, 'status' => 'Error: Unknown file status for sync: ' . $file_info['status']];
                    error_log("GitPush WP: Unknown file status '{$file_info['status']}' for file {$relative_path}");
                    continue 2;
            }
            
            if (isset($push_result['error'])) {
                $results[] = ['path' => $relative_path, 'status' => 'Error Pushing: ' . $push_result['error']];
            } elseif(isset($push_result['success']) && $push_result['success']) {
                $success_message = ($file_info['status'] === 'deleted') ? 'Deleted from GitHub' : (($file_info['status'] === 'new') ? 'Created on GitHub' : 'Updated on GitHub');
                $results[] = ['path' => $relative_path, 'status' => $success_message ];
            } else {
                // Неопределенный ответ от API push/delete
                $results[] = ['path' => $relative_path, 'status' => 'Error: Unknown API response during push/delete.'];
                 error_log("GitPush WP: Unknown API response for {$relative_path}. Push result: " . json_encode($push_result));
            }
        }
        
        $this->clear_internal_caches(); // Очищаем кеш после всех операций
        return $results; // Возвращаем массив результатов по каждому файлу
    }

     /**
     * Получение различий в файле (diff).
     * Этот метод должен быть реализован, если он нужен.
     * Он может получать контент файла с GitHub и сравнивать с локальным.
     */
    public function get_file_diff($relative_path) {
        $full_local_path = $this->theme_path . $relative_path;
        if (!file_exists($full_local_path) || !is_readable($full_local_path)) {
            return ['error' => 'Local file not found or not readable.'];
        }
        $local_content = file_get_contents($full_local_path);

        // Получаем контент с GitHub
        // Нужен метод в API классе типа get_file_content($path)
        $github_content_data = $this->api->get_file_content($relative_path); // Предполагаем, что этот метод возвращает ['content' => base64_encoded_content] или null/error

        if (!$github_content_data || isset($github_content_data['error']) || !isset($github_content_data['content'])) {
            // Если файла нет на GitHub (новый локальный файл), то diff это весь локальный контент
            if (isset($github_content_data['not_found']) && $github_content_data['not_found']) {
                 return ['diff' => $this->generate_diff_output(null, $local_content), 'status' => 'new'];
            }
            return ['error' => 'Could not get file content from GitHub to create diff. ' . ($github_content_data['error'] ?? '')];
        }

        $github_content = base64_decode($github_content_data['content']);
        
        // Базовое сравнение. Для полноценного diff нужна библиотека.
        if ($local_content === $github_content) {
            return ['diff' => 'Files are identical.', 'status' => 'identical'];
        } else {
            // Используем WP_Text_Diff_Renderer_Table или WP_Text_Diff_Renderer_inline
            if (!class_exists('WP_Text_Diff_Renderer_Table')) {
                require_once(ABSPATH . WPINC . '/wp-diff.php');
            }
            
            $local_lines = explode("\n", $local_content);
            $github_lines = explode("\n", $github_content);

            $diff = new Text_Diff('auto', [$github_lines, $local_lines]); // Порядок: old, new
            
            // $renderer = new WP_Text_Diff_Renderer_Table(); // Табличный вид
            $renderer = new WP_Text_Diff_Renderer_inline(); // Встроенный вид

            $diff_output = $renderer->render($diff);

            if(empty($diff_output)) { // Если render вернул пустоту, но файлы разные (например, только пробелы в конце)
                return ['diff' => "Files differ, but visual diff is empty.\n\nGitHub content:\n{$github_content}\n\nLocal content:\n{$local_content}", 'status' => 'modified'];
            }
            return ['diff' => $diff_output, 'status' => 'modified'];
        }
    }

    /**
     * Вспомогательная функция для генерации diff (очень упрощенная)
     */
    private function generate_diff_output($old_content, $new_content) {
        if ($old_content === null && $new_content !== null) { // Новый файл
            return "<pre class='diff-added'>".htmlspecialchars($new_content)."</pre>";
        }
        // Для других случаев нужна более сложная логика или библиотека
        return "Diff generation not fully implemented for this case.";
    }

}