<?php
/**
 * Класс для работы с GitHub API
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class GitPush_GitHub_API {
    
    private $token;
    private $owner;
    private $repo;
    private $branch;
    private $user_agent;

    public function __construct() {
        $settings = get_option('gitpush_wp_settings', [
            'github_token' => '',
            'github_username' => '',
            'github_repo' => '',
            'github_branch' => 'main', // Ветка по умолчанию
        ]);

        $this->token = $settings['github_token'] ?? '';
        $this->owner = $settings['github_username'] ?? '';
        $this->repo = $settings['github_repo'] ?? '';
        $this->branch = !empty($settings['github_branch']) ? $settings['github_branch'] : 'main';
        $this->user_agent = 'WordPress/' . get_bloginfo('version') . '; GitPush_WP/' . (defined('GITPUSH_WP_VERSION') ? GITPUSH_WP_VERSION : '1.0');
    }

    private function send_request($endpoint, $method = 'GET', $body_data = null) {
        if (empty($this->token) || empty($this->owner) || empty($this->repo)) {
            $error_msg = 'GitPush WP API Error: Missing GitHub settings (token, owner, or repo). Configure plugin settings.';
            error_log($error_msg);
            return ['error' => $error_msg, 'is_config_error' => true];
        }

        $url = 'https://api.github.com' . $endpoint;
        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token, // 'Bearer' предпочтительнее для PAT
                'User-Agent'    => $this->user_agent,
                'Accept'        => 'application/vnd.github.v3+json',
                'X-GitHub-Api-Version' => '2022-11-28', // Рекомендуемый заголовок
            ],
            'timeout' => 45, // Увеличен таймаут
        ];

        if ($body_data !== null) {
            $args['body'] = json_encode($body_data);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $json_error = json_last_error_msg();
                error_log("GitPush WP API Error: JSON encode error for body_data - " . $json_error);
                return ['error' => 'JSON encode error: ' . $json_error];
            }
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
        }
        
        // error_log("GitPush WP API Request: {$method} {$url}" . ($body_data ? " Body: " . json_encode($body_data) : ""));

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $wp_error_msg = $response->get_error_message();
            error_log('GitPush WP API Error (wp_remote_request): ' . $wp_error_msg);
            return ['error' => 'WP_Error connecting to GitHub: ' . $wp_error_msg];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        // error_log("GitPush WP API Response: Code {$response_code}, Body: " . $response_body);

        if ($response_code >= 200 && $response_code < 300) {
            return $response_data; // Успешный ответ (может быть null, если тело пустое, например, для 204 No Content)
        } else {
            $error_message = isset($response_data['message']) ? $response_data['message'] : 'Unknown API error.';
            // 404 для GET /contents/path - это ожидаемо, если файл не найден
            if ($response_code === 404 && $method === 'GET' && strpos($endpoint, '/contents/') !== false) {
                return ['not_found' => true, 'message' => 'File not found on GitHub.', 'response_code' => $response_code];
            }
            error_log("GitPush WP API Error: Code {$response_code}, Message: {$error_message}, Endpoint: {$endpoint}, Response Body: " . $response_body);
            return ['error' => "GitHub API Error ({$response_code}): {$error_message}", 'details' => $response_data, 'response_code' => $response_code];
        }
    }
    
    public function test_connection() {
        $response = $this->send_request(sprintf("/repos/%s/%s", $this->owner, $this->repo));
        if (isset($response['error'])) {
            return ['success' => false, 'message' => $response['error']];
        }
        if (isset($response['full_name'])) {
            return ['success' => true, 'message' => 'Connection successful! Repository: ' . $response['full_name']];
        }
        return ['success' => false, 'message' => 'Connection test failed. Unexpected response from GitHub.'];
    }
    
    public function get_file_content($file_path) {
        // Нормализуем путь, удаляя возможные ведущие слеши
        $file_path_normalized = ltrim($file_path, '/');
        $endpoint = sprintf("/repos/%s/%s/contents/%s?ref=%s", $this->owner, $this->repo, $file_path_normalized, $this->branch);
        $response = $this->send_request($endpoint);

        if (isset($response['not_found']) && $response['not_found']) {
            return ['not_found' => true]; // Файл не найден, это нормальный исход для этого метода
        }
        if (isset($response['error'])) {
            error_log("GitPush WP Error: Failed to get file content for {$file_path_normalized}. Error: " . $response['error']);
            return ['error' => $response['error']];
        }

        if (isset($response['content']) && isset($response['encoding']) && $response['encoding'] === 'base64' && isset($response['sha'])) {
            return [
                'content' => $response['content'], // Оставляем base64
                'sha' => $response['sha'],
                'encoding' => 'base64'
            ];
        }
        error_log("GitPush WP Error: Invalid response format for file content: {$file_path_normalized}. Response: " . json_encode($response));
        return ['error' => 'Invalid response format for file content.'];
    }

    public function get_repo_tree($tree_sha_or_branch = null, $recursive = false) {
        if (empty($tree_sha_or_branch)) {
            $tree_sha_or_branch = $this->get_branch(); // Используем актуальную ветку из настроек
        }
        if (empty($tree_sha_or_branch)) { // Если ветка все еще не определена
             error_log('GitPush WP API Error: Branch name is empty for get_repo_tree.');
             return null;
        }

        $endpoint = sprintf("/repos/%s/%s/git/trees/%s", $this->owner, $this->repo, rawurlencode($tree_sha_or_branch));
        if ($recursive) {
            $endpoint .= '?recursive=1';
        }
        
        $response = $this->send_request($endpoint);

        if (isset($response['tree']) && is_array($response['tree'])) {
            if (isset($response['truncated']) && $response['truncated'] === true) {
                error_log('GitPush WP Warning: GitHub API tree response was truncated for: ' . $tree_sha_or_branch);
            }
            return $response['tree'];
        } elseif (isset($response['error'])) {
             error_log('GitPush WP Error: Failed to get repo tree. GitHub API error: ' . $response['error'] . ' for tree ' . $tree_sha_or_branch);
        } else {
            error_log('GitPush WP Error: Failed to get repo tree for ' . $tree_sha_or_branch . '. Unknown error or invalid response.');
        }
        return null;
    }
    
    public function get_file_commits($file_path) {
        $file_path_normalized = ltrim($file_path, '/');
        $endpoint = sprintf("/repos/%s/%s/commits?path=%s&sha=%s", $this->owner, $this->repo, rawurlencode($file_path_normalized), rawurlencode($this->get_branch()));
        $response = $this->send_request($endpoint);

        if (isset($response['error'])) {
            return ['error' => $response['error']];
        }
        if (is_array($response)) {
            $commits_data = [];
            foreach ($response as $commit_item) {
                if (isset($commit_item['sha'], $commit_item['commit']['message'], $commit_item['commit']['author']['name'], $commit_item['commit']['author']['date'])) {
                    $commits_data[] = [
                        'sha' => $commit_item['sha'],
                        'message' => $commit_item['commit']['message'],
                        'author' => $commit_item['commit']['author']['name'],
                        'date' => $commit_item['commit']['author']['date']
                    ];
                }
            }
            return $commits_data;
        }
        return ['error' => 'Invalid response format for commits.'];
    }
    
    public function push_file($file_path, $content, $commit_message, $existing_file_sha = null) {
        $file_path_normalized = ltrim($file_path, '/');
        $endpoint = sprintf("/repos/%s/%s/contents/%s", $this->owner, $this->repo, $file_path_normalized);
        $data = [
            'message' => $commit_message,
            'content' => base64_encode($content),
            'branch'  => $this->get_branch(),
        ];
        if ($existing_file_sha) {
            $data['sha'] = $existing_file_sha;
        }
        
        $response = $this->send_request($endpoint, 'PUT', $data);

        if (isset($response['error'])) {
            return ['success' => false, 'error' => $response['error'], 'details' => ($response['details'] ?? null)];
        }
        
        if (isset($response['content']['sha']) && isset($response['commit']['sha'])) {
            return ['success' => true, 'status' => $existing_file_sha ? 'updated' : 'created', 'data' => $response];
        }
        error_log("GitPush WP: Unknown response during push for {$file_path_normalized}. Response: " . json_encode($response));
        return ['success' => false, 'error' => 'Unknown error during push operation. Check API response in logs.', 'details' => $response];
    }
    
    public function delete_file($file_path, $commit_message, $sha) {
        $file_path_normalized = ltrim($file_path, '/');
        $endpoint = sprintf("/repos/%s/%s/contents/%s", $this->owner, $this->repo, $file_path_normalized);
        $data = [
            'message' => $commit_message,
            'sha'     => $sha,
            'branch'  => $this->get_branch(),
        ];
        $response = $this->send_request($endpoint, 'DELETE', $data);

        if (isset($response['error'])) {
            return ['success' => false, 'error' => $response['error'], 'details' => ($response['details'] ?? null)];
        }
        
        if (isset($response['commit']['sha'])) {
            return ['success' => true, 'status' => 'deleted', 'data' => $response];
        }
        error_log("GitPush WP: Unknown response during delete for {$file_path_normalized}. Response: " . json_encode($response));
        return ['success' => false, 'error' => 'Unknown error during delete operation. Check API response in logs.', 'details' => $response];
    }

    public function get_branch() {
        // Обновляем настройки, чтобы получить актуальную ветку, если она была изменена
        $settings = get_option('gitpush_wp_settings', []);
        return $settings['github_branch'] ?? 'main';
    }

    public function calculate_git_blob_sha($content) {
        if ($content === null) return null; // Не можем рассчитать SHA для null
        return sha1("blob " . strlen($content) . "\0" . $content);
    }

    /**
     * Возвращает текущие настройки плагина.
     * Обновляет внутренние свойства класса из опций WordPress, чтобы гарантировать актуальность.
     * @return array
     */
    public function get_settings() {
        // Получаем самые свежие настройки из базы данных WordPress
        $this->settings = get_option('gitpush_wp_settings', [
            'github_token' => '',
            'github_username' => '',
            'github_repo' => '',
            'github_branch' => 'main',
        ]);
        
        // Также обновляем свойства экземпляра для согласованности, если они используются напрямую
        $this->token = $this->settings['github_token'] ?? '';
        $this->owner = $this->settings['github_username'] ?? '';
        $this->repo = $this->settings['github_repo'] ?? '';
        $this->branch = !empty($this->settings['github_branch']) ? $this->settings['github_branch'] : 'main';
        
        return $this->settings;
    }
    
    // Этот метод больше не нужен, т.к. свойства класса обновляются в конструкторе
    // и при вызове get_branch() для актуальности.
    // public function get_settings() { ... }

    public function update_settings($new_settings) {
        $current_settings = get_option('gitpush_wp_settings', []);
        $updated_settings = array_merge($current_settings, $new_settings);
        update_option('gitpush_wp_settings', $updated_settings);

        // Обновляем свойства класса немедленно
        $this->token = $updated_settings['github_token'] ?? '';
        $this->owner = $updated_settings['github_username'] ?? '';
        $this->repo = $updated_settings['github_repo'] ?? '';
        $this->branch = !empty($updated_settings['github_branch']) ? $updated_settings['github_branch'] : 'main';
    }
}