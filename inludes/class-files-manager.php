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
        $items = scandir($dir);
        
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
                $hash = md5_file($path);
                
                $files[] = [
                    'path' => $relative_path,
                    'type' => 'file',
                    'extension' => $extension,
                    'hash' => $hash
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
        $local_files = $this->get_theme_files($theme_dir);
        $changed_files = [];
        
        // Получаем список файлов с GitHub
        $github_files_list = $this->github_api->get_files_list();
        
        // Создаем поиск по Github файлам
        $github_files = [];
        foreach ($github_files_list as $github_file) {
            $github_files[$github_file['path']] = $github_file;
        }
        
        // Сравниваем локальные файлы с GitHub
        foreach ($local_files as $file) {
            if ($file['type'] === 'dir') {
                continue; // Пропускаем директории
            }
            
            // Проверяем статус файла
            if (isset($github_files[$file['path']])) {
                // Файл существует на GitHub
                $github_file_content = $this->github_api->get_file_content($file['path']);
                
                if ($github_file_content) {
                    $local_content = file_get_contents($theme_dir . '/' . $file['path']);
                    
                    if ($local_content !== $github_file_content['content']) {
                        $file['status'] = 'modified';
                        $file['github_sha'] = $github_file_content['sha'];
                    } else {
                        $file['status'] = 'unchanged';
                    }
                } else {
                    // Не смогли получить содержимое - помечаем как измененный
                    $file['status'] = 'modified';
                }
                
                // Удаляем из списка GitHub файлов, чтобы потом найти удаленные
                unset($github_files[$file['path']]);
            } else {
                // Новый файл
                $file['status'] = 'new';
            }
            
            $changed_files[] = $file;
        }
        
        // Добавляем файлы, которые существуют на GitHub, но не локально (удаленные)
        foreach ($github_files as $path => $github_file) {
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            
            $changed_files[] = [
                'path' => $path,
                'type' => 'file',
                'extension' => $extension,
                'status' => 'deleted',
                'github_sha' => $github_file['sha']
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
        
        if (!file_exists($local_file_path)) {
            // Файл удален локально
            $github_file = $this->github_api->get_file_content($file_path);
            
            if ($github_file) {
                return [
                    'local_content' => '',
                    'github_content' => $github_file['content'],
                    'github_sha' => $github_file['sha'],
                    'status' => 'deleted_locally'
                ];
            } else {
                return [
                    'local_content' => '',
                    'github_content' => '',
                    'status' => 'error'
                ];
            }
        }
        
        // Файл существует локально
        $local_content = file_get_contents($local_file_path);
        $github_file = $this->github_api->get_file_content($file_path);
        
        if (!$github_file) {
            // Файл не существует на GitHub
            return [
                'local_content' => $local_content,
                'github_content' => '',
                'status' => 'new'
            ];
        }
        
        $github_content = $github_file['content'];
        $status = ($local_content === $github_content) ? 'unchanged' : 'modified';
        
        return [
            'local_content' => $local_content,
            'github_content' => $github_content,
            'github_sha' => $github_file['sha'],
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
            
            // Проверяем, существует ли файл локально
            if (!file_exists($local_file_path)) {
                // Файл был удален локально - удаляем его на GitHub
                $github_file = $this->github_api->get_file_content($file_path);
                
                if ($github_file && isset($github_file['sha'])) {
                    $result = $this->github_api->delete_file(
                        $file_path,
                        $commit_message . ' (deleted file)',
                        $github_file['sha']
                    );
                    
                    $results[$file_path] = $result['success'] ? 'deleted' : 'error_deleting';
                    
                    if (!$result['success']) {
                        error_log("GitPush Error: Failed to delete {$file_path}. " . (isset($result['message']) ? $result['message'] : ''));
                    }
                } else {
                    $results[$file_path] = 'error_no_github_file';
                }
                
                continue;
            }
            
            // Файл существует локально - проверяем, существует ли он на GitHub
            $local_content = file_get_contents($local_file_path);
            $github_file = $this->github_api->get_file_content($file_path);
            
            $file_exists = ($github_file !== false);
            $file_sha = $file_exists ? $github_file['sha'] : null;
            
            // Отправляем файл на GitHub
            $result = $this->github_api->push_file(
                $file_path,
                $local_content,
                $commit_message,
                $file_exists,
                $file_sha
            );
            
            if ($result['success']) {
                $results[$file_path] = $result['status'];
            } else {
                $results[$file_path] = 'error';
                error_log("GitPush Error: Failed to push {$file_path}. " . (isset($result['message']) ? $result['message'] : ''));
            }
        }
        
        // Обновляем время последней синхронизации
        $settings = $this->github_api->get_settings();
        $settings['last_sync'] = current_time('mysql');
        $this->github_api->update_settings($settings);
        
        return $results;
    }
    
    /**
     * Pull всех файлов с GitHub
     */
    public function pull_files() {
        $theme_dir = get_template_directory();
        $github_files = $this->github_api->get_files_list();
        $updated_files = [];
        $errors = [];
        
        foreach ($github_files as $github_file) {
            $file_path = $github_file['path'];
            $local_path = $theme_dir . '/' . $file_path;
            
            // Получаем содержимое файла с GitHub
            $github_file_content = $this->github_api->get_file_content($file_path);
            
            if (!$github_file_content || !isset($github_file_content['content'])) {
                $errors[] = "Failed to get content for {$file_path}";
                continue;
            }
            
            // Создаем директорию, если ее нет
            $dir = dirname($local_path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            // Записываем файл
            $result = file_put_contents($local_path, $github_file_content['content']);
            
            if ($result === false) {
                $errors[] = "Failed to write file {$file_path}";
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
}