<?php
/**
 * Класс для работы с GitHub API
 */
class GitPush_GitHub_API {
    
    private $settings;
    
    public function __construct() {
        $this->settings = get_option('gitpush_wp_settings', [
            'github_token' => '',
            'github_username' => '',
            'github_repo' => '',
            'github_branch' => 'main',
            'last_sync' => '',
            'last_pull' => ''
        ]);
    }
    
    /**
     * Проверка соединения с GitHub
     */
    public function test_connection() {
        $token = $this->settings['github_token'];
        $username = $this->settings['github_username'];
        $repo = $this->settings['github_repo'];
        
        if (empty($token) || empty($username) || empty($repo)) {
            return [
                'success' => false,
                'message' => 'Missing GitHub settings. Please fill in all fields.'
            ];
        }
        
        $response = wp_remote_get("https://api.github.com/repos/{$username}/{$repo}", [
            'headers' => [
                'Authorization' => "token {$token}",
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            ]
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'Connection error: ' . $response->get_error_message()
            ];
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($response_code === 200) {
            return [
                'success' => true,
                'message' => 'Connection successful! Repository: ' . $body['full_name']
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Connection failed. Error: ' . (isset($body['message']) ? $body['message'] : 'Unknown error')
            ];
        }
    }
    
    /**
     * Получить содержимое файла с GitHub
     */
    public function get_file_content($file_path) {
        $token = $this->settings['github_token'];
        $username = $this->settings['github_username'];
        $repo = $this->settings['github_repo'];
        $branch = $this->settings['github_branch'];
        
        $github_file_url = "https://api.github.com/repos/{$username}/{$repo}/contents/{$file_path}?ref={$branch}";
        
        $response = wp_remote_get($github_file_url, [
            'headers' => [
                'Authorization' => "token {$token}",
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            ]
        ]);
        
        if (is_wp_error($response)) {
            error_log('GitHub API Error: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            error_log("GitHub API Error ({$response_code}): {$body}");
            return false;
        }
        
        $file_data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($file_data['content']) && isset($file_data['encoding']) && $file_data['encoding'] === 'base64') {
            return [
                'content' => base64_decode($file_data['content']),
                'sha' => $file_data['sha']
            ];
        }
        
        return false;
    }
    
    /**
     * Получить список всех файлов в репозитории
     */
    public function get_files_list() {
        $token = $this->settings['github_token'];
        $username = $this->settings['github_username'];
        $repo = $this->settings['github_repo'];
        $branch = $this->settings['github_branch'];
        
        $github_tree_url = "https://api.github.com/repos/{$username}/{$repo}/git/trees/{$branch}?recursive=1";
        
        $response = wp_remote_get($github_tree_url, [
            'headers' => [
                'Authorization' => "token {$token}",
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            ]
        ]);
        
        if (is_wp_error($response)) {
            error_log('GitHub API Error: ' . $response->get_error_message());
            return [];
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            error_log("GitHub API Error ({$response_code}): {$body}");
            return [];
        }
        
        $github_tree = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($github_tree['tree']) || !is_array($github_tree['tree'])) {
            return [];
        }
        
        $files = [];
        foreach ($github_tree['tree'] as $item) {
            if ($item['type'] === 'blob') {
                $files[] = [
                    'path' => $item['path'],
                    'sha' => $item['sha'],
                    'size' => isset($item['size']) ? $item['size'] : 0
                ];
            }
        }
        
        return $files;
    }
    
    /**
     * Получить историю коммитов для файла
     */
    public function get_file_commits($file_path) {
        $token = $this->settings['github_token'];
        $username = $this->settings['github_username'];
        $repo = $this->settings['github_repo'];
        $branch = $this->settings['github_branch'];
        
        $commits_url = "https://api.github.com/repos/{$username}/{$repo}/commits";
        $commits_url = add_query_arg([
            'path' => $file_path,
            'sha' => $branch
        ], $commits_url);
        
        $response = wp_remote_get($commits_url, [
            'headers' => [
                'Authorization' => "token {$token}",
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            ]
        ]);
        
        if (is_wp_error($response)) {
            error_log('GitHub API Error: ' . $response->get_error_message());
            return [];
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            error_log("GitHub API Error ({$response_code}): {$body}");
            return [];
        }
        
        $commits = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!is_array($commits)) {
            return [];
        }
        
        $result = [];
        foreach ($commits as $commit) {
            $result[] = [
                'sha' => $commit['sha'],
                'message' => $commit['commit']['message'],
                'author' => $commit['commit']['author']['name'],
                'date' => $commit['commit']['author']['date']
            ];
        }
        
        return $result;
    }
    
    /**
     * Отправить файл на GitHub
     */
    public function push_file($file_path, $content, $commit_message, $file_exists = false, $file_sha = null) {
        $token = $this->settings['github_token'];
        $username = $this->settings['github_username'];
        $repo = $this->settings['github_repo'];
        $branch = $this->settings['github_branch'];
        
        // Для дебаггинга
        error_log("GitPush Debug: Push file {$file_path}. Exists: " . ($file_exists ? 'Yes' : 'No'));
        
        // Кодируем содержимое файла в base64
        $content_base64 = base64_encode($content);
        
        // Подготавливаем данные для запроса
        $update_data = [
            'message' => $commit_message,
            'content' => $content_base64,
            'branch' => $branch
        ];
        
        // Если файл существует, добавляем его SHA
        if ($file_exists && $file_sha) {
            $update_data['sha'] = $file_sha;
            error_log("GitPush Debug: Using SHA: {$file_sha}");
        }
        
        // Отправляем запрос на GitHub API
        $github_file_url = "https://api.github.com/repos/{$username}/{$repo}/contents/{$file_path}";
        
        error_log("GitPush Debug: URL: {$github_file_url}");
        error_log("GitPush Debug: Data: " . json_encode($update_data));
        
        $update_response = wp_remote_request($github_file_url, [
            'method' => 'PUT',
            'headers' => [
                'Authorization' => "token {$token}",
                'User-Agent' => 'WordPress/' . get_bloginfo('version'),
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($update_data)
        ]);
        
        // Обрабатываем ответ
        if (is_wp_error($update_response)) {
            $error = $update_response->get_error_message();
            error_log("GitPush Error: {$error}");
            return [
                'success' => false,
                'message' => $error
            ];
        }
        
        $response_code = wp_remote_retrieve_response_code($update_response);
        $response_body = wp_remote_retrieve_body($update_response);
        
        error_log("GitPush Debug: Response code: {$response_code}");
        error_log("GitPush Debug: Response body: {$response_body}");
        
        if ($response_code === 200 || $response_code === 201) {
            return [
                'success' => true,
                'status' => $file_exists ? 'updated' : 'created',
                'data' => json_decode($response_body, true)
            ];
        } else {
            return [
                'success' => false,
                'status' => 'error',
                'message' => "API Error ({$response_code}): {$response_body}"
            ];
        }
    }
    
    /**
     * Удалить файл с GitHub
     */
    public function delete_file($file_path, $commit_message, $file_sha) {
        $token = $this->settings['github_token'];
        $username = $this->settings['github_username'];
        $repo = $this->settings['github_repo'];
        $branch = $this->settings['github_branch'];
        
        // Для дебаггинга
        error_log("GitPush Debug: Delete file {$file_path}. SHA: {$file_sha}");
        
        $delete_data = [
            'message' => $commit_message,
            'sha' => $file_sha,
            'branch' => $branch
        ];
        
        $github_file_url = "https://api.github.com/repos/{$username}/{$repo}/contents/{$file_path}";
        
        $delete_response = wp_remote_request($github_file_url, [
            'method' => 'DELETE',
            'headers' => [
                'Authorization' => "token {$token}",
                'User-Agent' => 'WordPress/' . get_bloginfo('version'),
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($delete_data)
        ]);
        
        if (is_wp_error($delete_response)) {
            $error = $delete_response->get_error_message();
            error_log("GitPush Error: {$error}");
            return [
                'success' => false,
                'message' => $error
            ];
        }
        
        $response_code = wp_remote_retrieve_response_code($delete_response);
        $response_body = wp_remote_retrieve_body($delete_response);
        
        error_log("GitPush Debug: Response code: {$response_code}");
        error_log("GitPush Debug: Response body: {$response_body}");
        
        if ($response_code === 200) {
            return [
                'success' => true,
                'status' => 'deleted',
                'data' => json_decode($response_body, true)
            ];
        } else {
            return [
                'success' => false,
                'status' => 'error_deleting',
                'message' => "API Error ({$response_code}): {$response_body}"
            ];
        }
    }
    
    /**
     * Обновить настройки
     */
    public function update_settings($new_settings) {
        $this->settings = array_merge($this->settings, $new_settings);
        update_option('gitpush_wp_settings', $this->settings);
    }
    
    /**
     * Получить настройки
     */
    public function get_settings() {
        return $this->settings;
    }
}