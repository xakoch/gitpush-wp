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
    private $settings; 

    public function __construct() {
        $this->settings = get_option('gitpush_wp_settings', [
            'github_token' => '', 'github_username' => '', 'github_repo' => '', 'github_branch' => 'main',
        ]);
        $this->token = $this->settings['github_token'] ?? '';
        $this->owner = $this->settings['github_username'] ?? '';
        $this->repo = $this->settings['github_repo'] ?? '';
        $this->branch = !empty($this->settings['github_branch']) ? $this->settings['github_branch'] : 'main';
        $this->user_agent = 'WordPress/' . get_bloginfo('version') . '; GitPush_WP/' . (defined('GITPUSH_WP_VERSION') ? GITPUSH_WP_VERSION : '1.0');
    }

    private function send_request($endpoint, $method = 'GET', $body_data = null) {
        if (empty($this->token) || empty($this->owner) || empty($this->repo)) {
            $error_msg = 'GitPush WP API Error: Missing GitHub settings. Configure plugin settings.';
            error_log($error_msg);
            return ['error' => $error_msg, 'is_config_error' => true];
        }
        $url = 'https://api.github.com' . $endpoint;
        $args = ['method'  => $method, 'headers' => ['Authorization' => 'Bearer ' . $this->token, 'User-Agent' => $this->user_agent, 'Accept' => 'application/vnd.github.v3+json', 'X-GitHub-Api-Version' => '2022-11-28',], 'timeout' => 45,];
        if ($body_data !== null) {
            $args['body'] = json_encode($body_data);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $json_error = json_last_error_msg();
                error_log("GitPush WP API Error: JSON encode error - " . $json_error);
                return ['error' => 'JSON encode error: ' . $json_error];
            }
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            $wp_error_msg = $response->get_error_message();
            error_log('GitPush WP API Error (wp_remote_request): ' . $wp_error_msg);
            return ['error' => 'WP_Error connecting to GitHub: ' . $wp_error_msg];
        }
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        if ($response_code >= 200 && $response_code < 300) return $response_data;
        
        $error_message = $response_data['message'] ?? 'Unknown API error.';
        if ($response_code === 404 && $method === 'GET' && strpos($endpoint, '/contents/') !== false) {
            return ['not_found' => true, 'message' => 'File not found on GitHub.', 'response_code' => $response_code];
        }
        error_log("GitPush WP API Error: Code {$response_code}, Message: {$error_message}, Endpoint: {$endpoint}, Body: " . $response_body);
        return ['error' => "GitHub API Error ({$response_code}): {$error_message}", 'details' => $response_data, 'response_code' => $response_code];
    }
    
    public function test_connection() {
        if (empty($this->owner) || empty($this->repo)) return ['success' => false, 'message' => 'Missing GitHub username or repository.'];
        $response = $this->send_request(sprintf("/repos/%s/%s", $this->owner, $this->repo));
        if (isset($response['is_config_error'])) return ['success' => false, 'message' => $response['error']];
        if (isset($response['error'])) return ['success' => false, 'message' => 'Test failed: ' . $response['error']];
        if (isset($response['full_name'])) return ['success' => true, 'message' => 'Connection OK! Repo: ' . $response['full_name']];
        return ['success' => false, 'message' => 'Test failed. Unexpected response.'];
    }
    
    public function get_file_content($file_path) {
        $normalized_path = ltrim($file_path, '/\\');
        $current_branch = $this->get_branch();
        $endpoint = sprintf("/repos/%s/%s/contents/%s?ref=%s", $this->owner, $this->repo, rawurlencode($normalized_path), rawurlencode($current_branch));
        $response = $this->send_request($endpoint);
        if (isset($response['not_found'])) return ['not_found' => true];
        if (isset($response['error'])) {error_log("GitPush WP Error (get_file_content for {$normalized_path}): " . $response['error']); return ['error' => $response['error']];}
        if (isset($response['content'], $response['encoding'], $response['sha']) && $response['encoding'] === 'base64') {
            return ['content' => $response['content'], 'sha' => $response['sha'], 'encoding' => 'base64'];
        }
        error_log("GitPush WP Error (get_file_content): Invalid response for {$normalized_path}.");
        return ['error' => 'Invalid format for file content.'];
    }

    public function get_repo_tree($tree_sha_or_branch = null, $recursive = false) {
        $target = empty($tree_sha_or_branch) ? $this->get_branch() : $tree_sha_or_branch;
        if (empty($target)) { error_log('GitPush WP API Error: Branch/SHA is empty for get_repo_tree.'); return null; }
        $endpoint = sprintf("/repos/%s/%s/git/trees/%s", $this->owner, $this->repo, rawurlencode($target));
        if ($recursive) $endpoint .= '?recursive=1';
        $response = $this->send_request($endpoint);
        if (isset($response['tree']) && is_array($response['tree'])) {
            if (isset($response['truncated']) && $response['truncated']) error_log('GitPush WP Warning: Tree truncated for: ' . $target);
            return $response['tree'];
        }
        $err_msg = isset($response['error']) ? $response['error'] : 'Unknown error or invalid response.';
        error_log("GitPush WP Error: Failed to get repo tree for '{$target}'. Error: {$err_msg}");
        return null;
    }
    
    public function get_file_commits($file_path) {
        $normalized_path = ltrim($file_path, '/\\');
        $endpoint = sprintf("/repos/%s/%s/commits?path=%s&sha=%s", $this->owner, $this->repo, rawurlencode($normalized_path), rawurlencode($this->get_branch()));
        $response = $this->send_request($endpoint);
        if (isset($response['error'])) return ['error' => $response['error']];
        if (is_array($response)) {
            return array_map(function($commit) {
                return [
                    'sha' => $commit['sha'] ?? null, 
                    'message' => $commit['commit']['message'] ?? null, 
                    'author' => $commit['commit']['author']['name'] ?? null, 
                    'date' => $commit['commit']['author']['date'] ?? null
                ];
            }, array_filter($response, function($c) { return isset($c['commit']); }));
        }
        return ['error' => 'Invalid format for commits.'];
    }
    
    public function push_file($file_path, $content, $commit_message, $existing_file_sha = null) {
        $normalized_path = ltrim($file_path, '/\\');
        $endpoint = sprintf("/repos/%s/%s/contents/%s", $this->owner, $this->repo, $normalized_path);
        $data = ['message' => $commit_message, 'content' => base64_encode($content), 'branch' => $this->get_branch()];
        if ($existing_file_sha) $data['sha'] = $existing_file_sha;
        $response = $this->send_request($endpoint, 'PUT', $data);
        if (isset($response['error'])) return ['success' => false, 'error' => $response['error'], 'details' => ($response['details'] ?? null)];
        if (isset($response['content']['sha'], $response['commit']['sha'])) {
            return ['success' => true, 'status_text' => $existing_file_sha ? 'updated' : 'created', 'data' => $response];
        }
        return ['success' => false, 'error' => 'Unknown error during push.', 'details' => $response];
    }
    
    public function delete_file($file_path, $commit_message, $sha) {
        $normalized_path = ltrim($file_path, '/\\');
        $endpoint = sprintf("/repos/%s/%s/contents/%s", $this->owner, $this->repo, $normalized_path);
        $data = ['message' => $commit_message, 'sha' => $sha, 'branch' => $this->get_branch()];
        $response = $this->send_request($endpoint, 'DELETE', $data);
        if (isset($response['error'])) return ['success' => false, 'error' => $response['error'], 'details' => ($response['details'] ?? null)];
        if (isset($response['commit']['sha'])) return ['success' => true, 'status_text' => 'deleted', 'data' => $response];
        return ['success' => false, 'error' => 'Unknown error during delete.', 'details' => $response];
    }

    public function get_branch() { return $this->branch; }

    public function calculate_git_blob_sha($content) {
        if ($content === null) { error_log("GitPush WP: calculate_git_blob_sha called with null."); return null; }
        return sha1("blob " . strlen($content) . "\0" . $content);
    }
    
    public function get_settings() {
        $this->settings = get_option('gitpush_wp_settings', []); // Обновляем из базы данных
        $this->token = $this->settings['github_token'] ?? '';
        $this->owner = $this->settings['github_username'] ?? '';
        $this->repo = $this->settings['github_repo'] ?? '';
        $this->branch = !empty($this->settings['github_branch']) ? $this->settings['github_branch'] : 'main';
        return $this->settings;
    }

    public function update_settings($new_settings) {
        $current = get_option('gitpush_wp_settings', []);
        $updated = array_merge($current, $new_settings);
        update_option('gitpush_wp_settings', $updated);
        $this->settings = $updated; // Обновляем свойство класса
        $this->token = $updated['github_token'] ?? '';
        $this->owner = $updated['github_username'] ?? '';
        $this->repo = $updated['github_repo'] ?? '';
        $this->branch = !empty($updated['github_branch']) ? $updated['github_branch'] : 'main';
    }
}