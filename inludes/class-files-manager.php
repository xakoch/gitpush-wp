<?php
/**
 * Класс для работы с файлами
 */
class GitPush_Files_Manager {
    
    private $github_api;
    
    public function __construct() {
        $this->github_api = new GitPush_GitHub_API();
    }
    
    /**
     * Получить список файлов темы рекурсивно
     */
    public function get_theme_files($dir, $base_dir = '') {
        $files = [];
        $theme_dir = get_template_directory();
        
        if (empty($base_dir)) {
            $base_dir = $theme_dir;
        }
        
        // Получаем все файлы и директории
        $items = @scandir($dir); // Добавлено @ для подавления warning, если директория недоступна
        
        if ($items === false) {
             error_log("GitPush WP: Could not scan directory " . $dir);
             return $files; // Возвращаем пустой массив, если директория не читается
        }

        foreach ($items as $item) {
            // Пропускаем текущую и родительскую директории
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $path = $dir . '/' . $item;
            $relative_path = str_replace($base_dir . '/', '', $path);
            
            if (is_dir($path)) {
                // Пропускаем некоторые общие директории
                $skip_dirs = ['.git', 'node_modules', 'vendor', '.idea', '.vscode'];
                if (in_array($item, $skip_dirs)) {
                    continue;
                }
                
                $files[] = [
                    'path' => $relative_path,
                    'type' => 'dir'
                ];
                
                // Получаем файлы из поддиректории
                $sub_files = $this->get_theme_files($path, $base_dir);
                $files = array_merge($files, $sub_files);
            } else {
                // Пропускаем некоторые файлы
                $skip_files = ['.DS_Store', 'Thumbs.db', '.gitignore'];
                if (in_array($item, $skip_files)) {
                    continue;
                }
                
                $extension = pathinfo($path, PATHINFO_EXTENSION);
                // $hash = md5_file($path); // Хеш пока не используется активно, можно убрать для производительности если не нужен для сравнения
                
                $files[] = [
                    'path' => $relative_path,
                    'type' => 'file',
                    'extension' => $extension,
                    // 'hash' => $hash
                ];
            }
        }
        
        return $files;
    }
    
    /**
     * Сравнить локальные файлы с GitHub и найти изменения
     */
    public function get_changed_files() {
        $theme_dir = get_template_directory();
        $local_files_list = $this->get_theme_files($theme_dir);
        $changed_files = [];
        
        // Получаем список файлов с GitHub
        $github_files_tree = $this->github_api->get_files_list(); // Это список из дерева git, может быть неполным для контента
        
        // Создаем lookup для файлов GitHub по пути
        $github_files_map = [];
        foreach ($github_files_tree as $github_file_item) {
            if ($github_file_item['type'] === 'blob') { // 'blob' означает файл
                 $github_files_map[$github_file_item['path']] = $github_file_item;
            }
        }

        // Сравниваем локальные файлы с GitHub
        foreach ($local_files_list as $local_file) {
            if ($local_file['type'] === 'dir') {
                // Директории пока просто пропускаем, их статус не отслеживаем для коммита напрямую
                continue; 
            }
            
            $relative_path = $local_file['path'];
            $full_local_path = $theme_dir . '/' . $relative_path;

            if (isset($github_files_map[$relative_path])) {
                // Файл существует и локально, и на GitHub. Нужно сравнить содержимое.
                $github_file_data = $this->github_api->get_file_content($relative_path);
                
                if ($github_file_data && isset($github_file_data['content'])) {
                    $local_content = file_get_contents($full_local_path);
                    if (rtrim($local_content, "\n\r") !== rtrim($github_file_data['content'], "\n\r")) { // Сравнение с учетом возможных различий в концах строк
                        $local_file['status'] = 'modified';
                        $local_file['github_sha'] = $github_file_data['sha'];
                    } else {
                        $local_file['status'] = 'unchanged'; // Не изменился
                    }
                } else {
                    // Не смогли получить содержимое с GitHub, считаем измененным для безопасности
                    // Либо файл слишком большой и get_file_content вернул false
                    error_log("GitPush WP: Could not get content for GitHub file {$relative_path} to compare.");
                    $local_file['status'] = 'modified'; // Помечаем как измененный, чтобы пользователь мог его проверить
                    $local_file['github_sha'] = $github_files_map[$relative_path]['sha'] ?? null;
                }
                unset($github_files_map[$relative_path]); // Удаляем из карты, чтобы найти удаленные локально
            } else {
                // Файл существует локально, но нет на GitHub -> новый файл
                $local_file['status'] = 'new';
            }
            $changed_files[] = $local_file;
        }
        
        // Файлы, оставшиеся в $github_files_map, существуют на GitHub, но не локально -> удаленные
        foreach ($github_files_map as $path => $github_file_item_info) {
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $changed_files[] = [
                'path' => $path,
                'type' => 'file', // Это всегда файл (blob)
                'extension' => $extension,
                'status' => 'deleted', // Удален локально
                'github_sha' => $github_file_item_info['sha']
            ];
        }
        
        return $changed_files;
    }
    
    /**
     * Получить различия между локальной и GitHub версиями файла
     */
    public function get_file_diff($file_path) {
        $theme_dir = get_template_directory();
        $local_file_path = $theme_dir . '/' . $file_path;
        
        $github_file_data = $this->github_api->get_file_content($file_path);
        $github_content = ($github_file_data && isset($github_file_data['content'])) ? $github_file_data['content'] : null;
        $github_sha = ($github_file_data && isset($github_file_data['sha'])) ? $github_file_data['sha'] : null;

        if (!file_exists($local_file_path)) {
            // Файл удален локально
            return [
                'local_content' => '', // Локального контента нет
                'github_content' => $github_content ?? '', // Контент с GitHub, если есть
                'github_sha' => $github_sha,
                'status' => 'deleted' // Статус 'deleted' (или 'deleted_locally')
            ];
        }
        
        // Файл существует локально
        $local_content = file_get_contents($local_file_path);
        
        if ($github_content === null) {
            // Файл не существует на GitHub (или не удалось получить)
            return [
                'local_content' => $local_content,
                'github_content' => '',
                'github_sha' => null,
                'status' => 'new'
            ];
        }
        
        // Сравниваем контент
        $status = (rtrim($local_content, "\n\r") === rtrim($github_content, "\n\r")) ? 'unchanged' : 'modified';
        
        return [
            'local_content' => $local_content,
            'github_content' => $github_content,
            'github_sha' => $github_sha,
            'status' => $status
        ];
    }
    
    /**
     * Синхронизировать выбранные файлы с GitHub
     */
    public function sync_files($files, $commit_message) {
        $theme_dir = get_template_directory();
        $results = [];
        
        foreach ($files as $file_path) {
            $local_file_path = $theme_dir . '/' . $file_path;
            
            // Получаем актуальный SHA файла с GitHub перед операцией
            $github_file_info = $this->github_api->get_file_content($file_path);
            $github_sha = ($github_file_info && isset($github_file_info['sha'])) ? $github_file_info['sha'] : null;

            // Проверяем, существует ли файл локально
            if (!file_exists($local_file_path)) {
                // Файл был удален локально - удаляем его на GitHub
                if ($github_sha) { // Удаляем только если он действительно есть на GitHub
                    $result = $this->github_api->delete_file(
                        $file_path,
                        $commit_message . ' (deleted ' . basename($file_path) . ')',
                        $github_sha // Передаем SHA удаляемого файла
                    );
                    $results[$file_path] = $result['success'] ? 'deleted' : 'error_deleting';
                    if (!$result['success']) {
                        error_log("GitPush WP Error: Failed to delete {$file_path}. Message: " . ($result['message'] ?? 'Unknown error'));
                    }
                } else {
                    // Файла нет ни локально, ни на GitHub (или не удалось получить SHA)
                    $results[$file_path] = 'skipped_not_on_github'; 
                    error_log("GitPush WP Info: Skipped deleting {$file_path} as it was not found on GitHub or SHA is missing.");
                }
                continue;
            }
            
            // Файл существует локально - создаем или обновляем его на GitHub
            $local_content = file_get_contents($local_file_path);
            
            $result = $this->github_api->push_file(
                $file_path,
                $local_content,
                $commit_message,
                ($github_sha !== null), // file_exists on GitHub
                $github_sha // SHA для обновления, или null для нового файла
            );
            
            if ($result['success']) {
                $results[$file_path] = $result['status']; // 'created' или 'updated'
            } else {
                $results[$file_path] = 'error_pushing';
                error_log("GitPush WP Error: Failed to push {$file_path}. Message: " . ($result['message'] ?? 'Unknown error'));
            }
        }
        
        // Обновляем время последней синхронизации, если хотя бы одна операция успешна
        // Это лучше делать, если $results содержит успешные операции
        $successful_ops = false;
        foreach($results as $status) {
            if ($status === 'created' || $status === 'updated' || $status === 'deleted') {
                $successful_ops = true;
                break;
            }
        }

        if ($successful_ops) {
            $settings = $this->github_api->get_settings();
            $settings['last_sync'] = current_time('mysql');
            $this->github_api->update_settings($settings);
        }
        
        return $results;
    }
    
    /**
     * Pull всех файлов с GitHub -- ФУНКЦИЯ УДАЛЕНА
     */
    /*
    public function pull_files() {
        $theme_dir = get_template_directory();
        $github_files = $this->github_api->get_files_list(); // Это список из дерева git
        $updated_files = [];
        $errors = [];
        
        foreach ($github_files as $github_file_item) {
            if ($github_file_item['type'] !== 'blob') { // Только файлы
                continue;
            }

            $file_path = $github_file_item['path'];
            $local_path = $theme_dir . '/' . $file_path;
            
            // Получаем содержимое файла с GitHub
            $github_file_content_data = $this->github_api->get_file_content($file_path);
            
            if (!$github_file_content_data || !isset($github_file_content_data['content'])) {
                $errors[] = "Failed to get content for {$file_path} from GitHub.";
                error_log("GitPush WP Pull Error: Failed to get content for {$file_path}");
                continue;
            }
            
            // Создаем директорию, если ее нет
            $dir = dirname($local_path);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    $errors[] = "Failed to create directory {$dir} for file {$file_path}.";
                    error_log("GitPush WP Pull Error: Failed to create directory {$dir}");
                    continue;
                }
            }
            
            // Записываем файл
            $write_result = file_put_contents($local_path, $github_file_content_data['content']);
            
            if ($write_result === false) {
                $errors[] = "Failed to write file {$file_path} locally.";
                error_log("GitPush WP Pull Error: Failed to write file {$local_path}");
            } else {
                $updated_files[] = $file_path;
            }
        }
        
        // Обновляем время последнего pull
        $settings = $this->github_api->get_settings();
        $settings['last_pull'] = current_time('mysql');
        $this->github_api->update_settings($settings);
        
        return [
            'updated_files' => $updated_files,
            'errors' => $errors,
            'pull_time' => $settings['last_pull']
        ];
    }
    */
}