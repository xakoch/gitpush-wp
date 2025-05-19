<?php
// Файл: includes/class-files-manager.php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class GitPush_Files_Manager {

    private $api;
    private $theme_path;
    private $github_files_tree_flat = null; 

    const GITHUB_TREE_CACHE_KEY = 'gitpush_wp_github_tree_cache_v3'; // Обновил ключ для сброса

    public function __construct(GitPush_GitHub_API $api) {
        $this->api = $api;
        // Убедимся, что theme_path всегда заканчивается слешем
        $this->theme_path = trailingslashit(get_stylesheet_directory()); 
    }

    public function clear_internal_caches() {
        $this->github_files_tree_flat = null;
        delete_transient(self::GITHUB_TREE_CACHE_KEY);
        error_log('GitPush WP: Internal caches cleared by Files_Manager.');
    }

    public function get_github_files_tree_flat($force_refresh = false) {
        if ($force_refresh) {
            $this->clear_internal_caches(); 
        }

        if ($this->github_files_tree_flat !== null && !$force_refresh) {
            return $this->github_files_tree_flat;
        }

        $cached_tree = get_transient(self::GITHUB_TREE_CACHE_KEY);
        if (!$force_refresh && $cached_tree !== false) {
            $this->github_files_tree_flat = $cached_tree;
            error_log('GitPush WP: Fetched GitHub tree from transient cache. Items: ' . count($cached_tree));
            return $cached_tree;
        }

        $branch_name = $this->api->get_branch();
        if (empty($branch_name)) { // Проверка на пустую ветку
            error_log('GitPush WP: Branch name is empty, cannot fetch repo tree.');
            return [];
        }
        // Передаем имя ветки и флаг рекурсии
        $tree_items = $this->api->get_repo_tree($branch_name, true); 

        if (is_null($tree_items)) { 
            error_log('GitPush WP: Failed to fetch GitHub tree from API (get_repo_tree returned null).');
            return []; 
        }
        
        $flat_tree = [];
        if (is_array($tree_items)) {
            foreach ($tree_items as $item) {
                if (isset($item['type'], $item['path'], $item['sha']) && $item['type'] === 'blob') { 
                    $flat_tree[$item['path']] = [
                        'path' => $item['path'],
                        'sha' => $item['sha'],
                        'type' => 'file' 
                    ];
                }
            }
        } else {
             error_log('GitPush WP: GitHub tree items from API is not an array. Response: ' . json_encode($tree_items));
        }

        set_transient(self::GITHUB_TREE_CACHE_KEY, $flat_tree, HOUR_IN_SECONDS);
        $this->github_files_tree_flat = $flat_tree;
        error_log('GitPush WP: Fetched GitHub tree from API and cached. Items: ' . count($flat_tree));
        return $flat_tree;
    }

    public function get_changed_files($force_refresh = false) {
        $github_files_flat = $this->get_github_files_tree_flat($force_refresh); 
        $local_files_list = $this->get_theme_files($this->theme_path, $this->theme_path); // base_dir = theme_path

        $changed_files_data = [];
        $processed_local_paths = [];

        foreach ($local_files_list as $local_file) {
            if (empty($local_file['path']) || (isset($local_file['type']) && $local_file['type'] === 'dir')) {
                continue;
            }
            $relative_path = $local_file['path'];
            $processed_local_paths[$relative_path] = true; 
            $full_local_path = $this->theme_path . $relative_path;

            if (!file_exists($full_local_path) || !is_readable($full_local_path)) {
                error_log("GitPush WP: Local file listed but not found/readable for comparison: " . $full_local_path);
                continue;
            }
            
            $local_content = @file_get_contents($full_local_path);
            if ($local_content === false) {
                error_log("GitPush WP: Failed to read local file content for SHA calculation: " . $full_local_path);
                continue;
            }
            $local_file_git_sha = $this->api->calculate_git_blob_sha($local_content);
            if ($local_file_git_sha === null) {
                 error_log("GitPush WP: Calculated local SHA is null for: " . $full_local_path);
                 continue; // Не можем сравнить, если SHA null
            }


            $file_data = [
                'path' => $relative_path,
                'status' => 'unknown', // Статус по умолчанию
                'type' => 'file' 
            ];

            $github_file_details = $github_files_flat[$relative_path] ?? null;

            if (!$github_file_details) {
                $file_data['status'] = 'new';
            } else {
                if (isset($github_file_details['type']) && $github_file_details['type'] === 'file') {
                    if (isset($github_file_details['sha']) && $github_file_details['sha'] !== $local_file_git_sha) {
                        $file_data['status'] = 'modified';
                        $file_data['github_sha'] = $github_file_details['sha']; 
                    } else if (!isset($github_file_details['sha'])) {
                        // На GitHub есть запись, но нет SHA? Странно, считаем измененным.
                        $file_data['status'] = 'modified';
                         error_log("GitPush WP: GitHub file item for {$relative_path} has no SHA. Treating as modified.");
                    }
                    else { // SHA совпадает
                        continue; 
                    }
                } else {
                    error_log("GitPush WP: Path on GitHub ('".$relative_path."') is not a file type ('".($github_file_details['type'] ?? 'unknown type')."'). Treating local as 'new'.");
                    $file_data['status'] = 'new'; 
                }
            }
            $changed_files_data[$relative_path] = $file_data;
        }

        foreach ($github_files_flat as $github_path => $github_item_details) {
            if (!isset($github_item_details['type']) || $github_item_details['type'] !== 'file') { 
                continue;
            }
            if ($this->is_ignored($github_path)) { 
                continue;
            }
            if (!isset($processed_local_paths[$github_path])) {
                $changed_files_data[$github_path] = [
                    'path' => $github_path,
                    'status' => 'deleted',
                    'github_sha' => $github_item_details['sha'] ?? null, 
                    'type' => 'file'
                ];
            }
        }
        return array_values($changed_files_data); 
    }
    
    public function get_theme_files($dir, $base_dir) {
        $files = array();
        // Гарантируем, что base_dir всегда заканчивается слешем для корректной замены
        $normalized_base_dir = trailingslashit($base_dir);

        if (!is_dir($dir) || !is_readable($dir)) {
            error_log("GitPush WP: Cannot scan theme directory, not a directory or not readable: " . $dir);
            return $files;
        }
        $items = @scandir($dir);

        if ($items === false) {
            error_log("GitPush WP: Failed to scan theme directory (scandir returned false): " . $dir);
            return $files;
        }

        foreach ($items as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            $path = trailingslashit($dir) . $item; 
            // Относительный путь формируется вычитанием $normalized_base_dir из $path
            // ltrim убирает возможный ведущий слеш, если $path и $normalized_base_dir были одинаковы
            $relative_path_item = ltrim(str_replace($normalized_base_dir, '', $path), '/\\'); 

            if (empty($relative_path_item) && is_dir($path)) { // Это сама базовая директория
                 $files = array_merge($files, $this->get_theme_files($path, $base_dir)); // Рекурсия без добавления самой базовой директории
                 continue;
            }
            
            // Игнорируем по полному относительному пути или по базовому имени
            if ($this->is_ignored($relative_path_item) || $this->is_ignored(basename($relative_path_item))) {
                continue;
            }
            
            if (is_dir($path)) {
                $files = array_merge($files, $this->get_theme_files($path, $base_dir));
            } else {
                 // Добавляем только если это файл и у него есть относительный путь
                 if (!empty($relative_path_item)) {
                    $files[] = [
                        'path' => $relative_path_item,
                        'type' => 'file' 
                        // 'extension' и другие детали не нужны для get_changed_files
                    ];
                }
            }
        }
        return $files;
    }

    private function is_ignored($path_segment) {
        // Проверяем пустой сегмент (может случиться при неаккуратном формировании пути)
        if (empty(trim($path_segment))) {
            return false; 
        }

        $ignored_exact_names = ['.git', '.svn', '.DS_Store', 'node_modules', 'vendor', '.vscode', '.idea', 'desktop.ini', 'thumbs.db'];  
        $ignored_paths_start_with = ['wp-content/', 'wp-admin/', 'wp-includes/', 'cache/', '.wp-env/', 'logs/']; 
        // $ignored_extensions = ['.log', '.tmp', '.bak', '.swp'];

        $basename = basename($path_segment);

        if (in_array($basename, $ignored_exact_names, true)) {
             // error_log("GitPush WP: Ignoring by exact name: " . $path_segment);
            return true;
        }
       
        foreach ($ignored_paths_start_with as $ignored_start) {
            if (strpos($path_segment, $ignored_start) === 0) {
                // error_log("GitPush WP: Ignoring by path start: " . $path_segment);
                return true;
            }
        }
        return false;
    }

    public function sync_files($commit_message, $selected_files_paths = []) {
        if (empty($commit_message)) {
            return ['error' => 'Commit message is required.'];
        }
        if (empty($selected_files_paths)) {
            return ['info' => 'No files selected to sync.', 'results_summary' => []]; 
        }

        // Получаем актуальные детали для выбранных файлов
        $all_changed_files_details = $this->get_changed_files(true); 
        $files_to_process_map = [];
        foreach ($all_changed_files_details as $file) {
            if (isset($file['path']) && in_array($file['path'], $selected_files_paths, true)) {
                $files_to_process_map[$file['path']] = $file;
            }
        }
        // Добавляем файлы, которые были выбраны, но могли не попасть в $all_changed_files_details (например, если они 'unchanged', но пользователь все равно выбрал)
        // Это сложнее, так как для них нужен будет SHA с GitHub для корректного push (если это обновление)
        // Пока упростим: работаем только с теми, что есть в $files_to_process_map из $all_changed_files_details
        // Если файл выбран, но не в $files_to_process_map, значит он либо unchanged, либо ошибка в get_changed_files

        $results_summary = [];

        foreach ($selected_files_paths as $relative_path) {
            if (!isset($files_to_process_map[$relative_path])) {
                // Файл был выбран, но не определен как 'new', 'modified', or 'deleted'.
                // Это может быть 'unchanged' файл. Попробуем его просто запушить (создать/обновить).
                // Для этого нужно получить его текущий SHA с GitHub.
                error_log("GitPush WP: File {$relative_path} selected but not in determined changes. Attempting direct push/update.");
                $file_info_temp = ['path' => $relative_path, 'status' => 'unknown']; // Временный статус
                $full_local_path_temp = $this->theme_path . $relative_path;

                if (file_exists($full_local_path_temp) && is_readable($full_local_path_temp)) {
                    $content_temp = @file_get_contents($full_local_path_temp);
                    if ($content_temp !== false) {
                        $gh_file_details_temp = $this->api->get_file_content($relative_path);
                        $existing_sha_temp = null;
                        if ($gh_file_details_temp && !isset($gh_file_details_temp['not_found']) && isset($gh_file_details_temp['sha'])) {
                            $existing_sha_temp = $gh_file_details_temp['sha'];
                        }
                        $final_commit_message_temp = $commit_message . " (force sync {$relative_path})";
                        $push_result = $this->api->push_file($relative_path, $content_temp, $final_commit_message_temp, $existing_sha_temp);
                        $file_info = $file_info_temp; // Для формирования ответа
                    } else {
                         $results_summary[] = ['path' => $relative_path, 'status_text' => 'Error: Could not read local file content.', 'type' => 'error'];
                         continue;
                    }
                } else {
                     $results_summary[] = ['path' => $relative_path, 'status_text' => 'Error: Local file not found or not readable.', 'type' => 'error'];
                    continue;
                }

            } else { // Файл найден в $files_to_process_map
                $file_info = $files_to_process_map[$relative_path];
                $full_local_path = $this->theme_path . $relative_path;
                $push_result = null;

                switch ($file_info['status']) {
                    case 'deleted':
                        if (empty($file_info['github_sha'])) {
                            $results_summary[] = ['path' => $relative_path, 'status_text' => 'Error: GitHub SHA missing for deleted file.', 'type' => 'error'];
                            error_log("GitPush WP: GitHub SHA missing for deleted file {$relative_path}.");
                            continue 2; 
                        }
                        $push_result = $this->api->delete_file($relative_path, $commit_message . " (delete {$relative_path})", $file_info['github_sha']);
                        break;
                    case 'new':
                    case 'modified':
                        if (!file_exists($full_local_path) || !is_readable($full_local_path)) {
                            $results_summary[] = ['path' => $relative_path, 'status_text' => 'Error: Local file not found or not readable.', 'type' => 'error'];
                            error_log("GitPush WP: Local file {$full_local_path} not found/readable for push (status: {$file_info['status']}).");
                            continue 2; 
                        }
                        $content = @file_get_contents($full_local_path);
                        if ($content === false) {
                            $results_summary[] = ['path' => $relative_path, 'status_text' => 'Error: Could not read local file content.', 'type' => 'error'];
                            error_log("GitPush WP: Failed to read content of {$full_local_path} for push.");
                            continue 2;
                        }
                        $final_commit_message = $commit_message . " ({$file_info['status']} {$relative_path})";
                        $github_sha_for_update = ($file_info['status'] === 'modified' && isset($file_info['github_sha'])) ? $file_info['github_sha'] : null;
                        $push_result = $this->api->push_file($relative_path, $content, $final_commit_message, $github_sha_for_update);
                        break;
                    default:
                        $results_summary[] = ['path' => $relative_path, 'status_text' => 'Error: Unknown file status for sync: ' . ($file_info['status'] ?? 'N/A'), 'type' => 'error'];
                        error_log("GitPush WP: Unknown file status '{$file_info['status']}' for file {$relative_path}");
                        continue 2;
                }
            }
            
            if (isset($push_result['success']) && $push_result['success']) {
                $status_text = $push_result['status_text'] ?? (($file_info['status'] === 'deleted') ? 'Deleted' : 'Synced');
                $results_summary[] = ['path' => $relative_path, 'status_text' => ucfirst($status_text), 'type' => 'success' ];
            } else {
                $error_msg = $push_result['error'] ?? 'Unknown API error during operation.';
                $results_summary[] = ['path' => $relative_path, 'status_text' => 'Error Pushing: ' . $error_msg, 'type' => 'error'];
                 error_log("GitPush WP: API operation failed for {$relative_path}. Push result: " . json_encode($push_result));
            }
        }
        
        $this->clear_internal_caches(); 
        return $results_summary; 
    }

    public function get_file_diff($relative_path) {
        $full_local_path = $this->theme_path . $relative_path;
        
        $github_content_data = $this->api->get_file_content($relative_path);

        $local_exists = file_exists($full_local_path) && is_readable($full_local_path);
        $local_content = $local_exists ? @file_get_contents($full_local_path) : null;
         if ($local_exists && $local_content === false) {
            error_log("GitPush WP (get_file_diff): Failed to read local file content {$full_local_path}");
            return ['error' => 'Failed to read local file content for diff.'];
        }

        $github_content = null;
        $github_sha = null;
        $file_on_github = false;

        if ($github_content_data && !isset($github_content_data['error']) && isset($github_content_data['content'])) {
            $github_content = base64_decode($github_content_data['content']);
            $github_sha = $github_content_data['sha'] ?? null;
            $file_on_github = true;
        } elseif (isset($github_content_data['not_found']) && $github_content_data['not_found']) {
            $file_on_github = false;
        } elseif (isset($github_content_data['error'])) {
             return ['status' => 'error_github', 'error_message' => 'GitHub API Error for diff: ' . $github_content_data['error']];
        }

        $status = 'unknown';
        if ($local_exists && $file_on_github) {
            $status = (rtrim((string)$local_content, "\n\r") === rtrim((string)$github_content, "\n\r")) ? 'unchanged' : 'modified';
        } elseif ($local_exists && !$file_on_github) {
            $status = 'new';
        } elseif (!$local_exists && $file_on_github) {
            $status = 'deleted'; 
        } elseif (!$local_exists && !$file_on_github) { // Это не должно происходить, если файл в списке измененных
            return ['status' => 'not_exists', 'error_message' => 'File does not exist locally or on GitHub.'];
        }
        
        $return_data = [
            'local_content' => $local_content ?? '', 
            'github_content' => $github_content ?? '', 
            'status' => $status, 
            'github_sha' => $github_sha,
            'diff_output' => '' // По умолчанию
        ];

        if ($status === 'modified' || $status === 'new' || $status === 'deleted') {
            if (!class_exists('WP_Text_Diff_Renderer_Table')) { 
                require_once(ABSPATH . WPINC . '/wp-diff.php');
            }
            
            $local_lines  = $local_exists ? explode("\n", (string)$local_content) : [];
            $github_lines = $file_on_github ? explode("\n", (string)$github_content) : [];

            $diff_params = ['ignore_ws' => false, 'ignore_blanks' => false, 'ignore_case' => false]; // Параметры по умолчанию для WP_Text_Diff
            $diff = new Text_Diff('auto', [$github_lines, $local_lines]); 
            
            $renderer = new WP_Text_Diff_Renderer_inline(['split_words' => true]); 
            $diff_output = $renderer->render($diff);
            
            if(empty($diff_output) && $status === 'modified') { 
                 $return_data['diff_output'] = "// Files differ, but visual inline diff is empty (possibly only whitespace changes or EOL).\n// Local content (" . strlen($local_content) . " bytes) vs GitHub content (" . strlen($github_content) . " bytes)";
            } else {
                $return_data['diff_output'] = $diff_output;
            }
        } elseif ($status === 'unchanged') {
             $return_data['diff_output'] = $local_content ?? '// File is unchanged.';
        }
        return $return_data;
    }
}