<?php
// Файл: includes/class-files-manager.php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class GitPush_Files_Manager {

    private $api;
    private $theme_path;
    private $github_files_tree_flat = null; 

    const GITHUB_TREE_CACHE_KEY = 'gitpush_wp_github_tree_cache_v2'; // Изменил ключ для сброса старого кеша

    public function __construct(GitPush_GitHub_API $api) {
        $this->api = $api;
        $this->theme_path = trailingslashit(get_stylesheet_directory());
    }

    public function clear_internal_caches() {
        $this->github_files_tree_flat = null;
        delete_transient(self::GITHUB_TREE_CACHE_KEY);
        error_log('GitPush WP: Internal caches cleared.');
    }

    public function get_github_files_tree_flat($force_refresh = false) {
        if ($force_refresh) {
            $this->clear_internal_caches(); 
        }

        if ($this->github_files_tree_flat !== null && !$force_refresh) {
            return $this->github_files_tree_flat;
        }

        $cached_tree = get_transient(self::GITHUB_TREE_CACHE_KEY);
        if (!$force_refresh && $cached_tree !== false) { // get_transient возвращает false, если нет или просрочено
            $this->github_files_tree_flat = $cached_tree;
            error_log('GitPush WP: Fetched GitHub tree from transient cache. Items: ' . count($cached_tree));
            return $cached_tree;
        }

        $branch_name = $this->api->get_branch();
        if (!$branch_name) {
            error_log('GitPush WP: Branch name not available from API for get_repo_tree.');
            return [];
        }
        $tree_items = $this->api->get_repo_tree($branch_name, true); 

        if (is_null($tree_items)) { 
            error_log('GitPush WP: Failed to fetch GitHub tree from API (null returned by API method).');
            // Возвращаем пустой массив, но не кешируем ошибку как пустой результат надолго, если это временная проблема API.
            // Можно не устанавливать транзиент в этом случае или кешировать на очень короткое время.
            return []; 
        }
        
        $flat_tree = [];
        if (is_array($tree_items)) {
            foreach ($tree_items as $item) {
                if (isset($item['type']) && $item['type'] === 'blob' && isset($item['path']) && isset($item['sha'])) { 
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

    public function get_changed_files($force_refresh = false) {
        $github_files_flat = $this->get_github_files_tree_flat($force_refresh); 
        $local_files_list = $this->get_theme_files($this->theme_path, $this->theme_path);

        $changed_files_data = [];
        $processed_local_paths = [];

        foreach ($local_files_list as $local_file) {
            if (!isset($local_file['path']) || (isset($local_file['type']) && $local_file['type'] === 'dir')) {
                continue;
            }
            $relative_path = $local_file['path'];
            $processed_local_paths[$relative_path] = true; 
            $full_local_path = $this->theme_path . $relative_path;

            if (!file_exists($full_local_path) || !is_readable($full_local_path)) {
                error_log("GitPush WP: Local file listed but not found or not readable: " . $full_local_path);
                continue;
            }
            
            $local_content = @file_get_contents($full_local_path); // Подавляем warning если файл исчезнет между scandir и file_get_contents
            if ($local_content === false) {
                error_log("GitPush WP: Failed to read local file content for hashing: " . $full_local_path);
                continue;
            }
            $local_file_git_sha = $this->api->calculate_git_blob_sha($local_content);

            $file_data = [
                'path' => $relative_path,
                'status' => 'unknown',
                'type' => 'file' 
            ];

            $github_file_details = $github_files_flat[$relative_path] ?? null;

            if (!$github_file_details) {
                $file_data['status'] = 'new';
            } else {
                if (isset($github_file_details['type']) && $github_file_details['type'] === 'file') {
                    if ($github_file_details['sha'] !== $local_file_git_sha) {
                        $file_data['status'] = 'modified';
                        $file_data['github_sha'] = $github_file_details['sha']; 
                    } else {
                        continue; 
                    }
                } else {
                    error_log("GitPush WP: Path on GitHub is not a file ('".($github_file_details['type'] ?? 'unknown type')."'), but local is: " . $relative_path);
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
                    'github_sha' => $github_item_details['sha'], 
                    'type' => 'file'
                ];
            }
        }
        return array_values($changed_files_data); 
    }

    public function get_theme_files($dir, $base_dir) {
        $files = array();
        if (!is_dir($dir) || !is_readable($dir)) {
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
            // Нормализуем $base_dir чтобы он всегда заканчивался слешем для корректного str_replace
            $normalized_base_dir = trailingslashit($base_dir);
            $relative_path_item = ltrim(str_replace($normalized_base_dir, '', $path), '/\\'); 

            if ($this->is_ignored($item) || (!empty($relative_path_item) && $this->is_ignored($relative_path_item)) ) {
                continue;
            }
            
            if (is_dir($path)) {
                $files = array_merge($files, $this->get_theme_files(trailingslashit($path), $base_dir));
            } else {
                 if (!empty($relative_path_item)) { // Добавляем только если есть относительный путь (не сам base_dir)
                    $files[] = [
                        'path' => $relative_path_item,
                        'type' => 'file'
                    ];
                }
            }
        }
        return $files;
    }

    private function is_ignored($path_segment) {
        $ignored_exact_names = ['.git', '.svn', '.DS_Store', 'node_modules', 'vendor', '.vscode', '.idea', 'desktop.ini', 'thumbs.db', 'wp-content', 'wp-admin', 'wp-includes'];  
        $ignored_paths_start_with = ['cache/', '.wp-env/', 'logs/']; 
        
        $basename = basename($path_segment);

        if (in_array($basename, $ignored_exact_names, true)) {
            return true;
        }
       
        foreach ($ignored_paths_start_with as $ignored_start) {
            if (strpos($path_segment, $ignored_start) === 0) {
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

        $all_changed_files_details = $this->get_changed_files(true); // Получаем свежайший список перед синхронизацией
        $files_to_process_map = [];
        foreach ($all_changed_files_details as $file) {
            if (isset($file['path']) && in_array($file['path'], $selected_files_paths, true)) {
                $files_to_process_map[$file['path']] = $file;
            }
        }

        $results_summary = [];

        foreach ($selected_files_paths as $relative_path) {
            if (!isset($files_to_process_map[$relative_path])) {
                $results_summary[] = ['path' => $relative_path, 'status' => 'Error: File details not found for sync. Refresh list and try again.'];
                error_log("GitPush WP: File {$relative_path} selected for sync but its details not found in current change set.");
                continue;
            }

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
            
            if (isset($push_result['success']) && $push_result['success']) {
                $status_text = $push_result['status'] ?? (($file_info['status'] === 'deleted') ? 'Deleted' : 'Synced');
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
        $local_content = $local_exists ? file_get_contents($full_local_path) : null;

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
             return ['local_content' => $local_content ?? '', 'github_content' => '', 'status' => 'error_github', 'error_message' => 'GitHub API Error: ' . $github_content_data['error']];
        }


        $status = 'unknown';
        if ($local_exists && $file_on_github) {
            $status = (rtrim($local_content, "\n\r") === rtrim($github_content, "\n\r")) ? 'unchanged' : 'modified';
        } elseif ($local_exists && !$file_on_github) {
            $status = 'new'; // Новый локальный файл
        } elseif (!$local_exists && $file_on_github) {
            $status = 'deleted'; // Удален локально
        } elseif (!$local_exists && !$file_on_github) {
            return ['local_content' => '', 'github_content' => '', 'status' => 'not_exists', 'error_message' => 'File does not exist locally or on GitHub.'];
        }
        
        // Для diff
        if ($status === 'modified' || $status === 'new' || $status === 'deleted') {
            if (!class_exists('WP_Text_Diff_Renderer_Table')) { // WP_Text_Diff_Renderer_inline может не существовать без этого
                require_once(ABSPATH . WPINC . '/wp-diff.php');
            }
            
            $local_lines  = $local_exists ? explode("\n", $local_content) : [];
            $github_lines = $file_on_github ? explode("\n", $github_content) : [];

            $diff = new Text_Diff('auto', [$github_lines, $local_lines]); 
            $renderer = new WP_Text_Diff_Renderer_inline(['split_words' => true]); // Используем inline и пробуем разбивку по словам
            $diff_output = $renderer->render($diff);
            
            return ['local_content' => $local_content ?? '', 'github_content' => $github_content ?? '', 'status' => $status, 'diff_output' => $diff_output, 'github_sha' => $github_sha];
        }

        return ['local_content' => $local_content ?? '', 'github_content' => $github_content ?? '', 'status' => $status, 'github_sha' => $github_sha];
    }
}