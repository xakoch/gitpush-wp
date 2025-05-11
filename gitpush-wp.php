<?php
/**
 * Plugin Name: GitPush WP
 * Plugin URI: https://xakoch.uz/
 * Description: Sync your WordPress theme files directly with GitHub repository.
 * Version: 1.1.0
 * Author: Xakoch
 * Author URI: https://xakoch.uz/
 * Text Domain: gitpush-wp
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class GitPush_WP {
    
    private $settings = [];
    private $file_cache = [];
    
    /**
     * Initialize the plugin
     */
    public function __construct() {
        // Load settings
        $this->settings = get_option('gitpush_wp_settings', [
            'github_token' => '',
            'github_username' => '',
            'github_repo' => '',
            'github_branch' => 'main',
            'last_sync' => '',
            'last_pull' => ''
        ]);
        
        // Register hooks
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // Register AJAX handlers
        add_action('wp_ajax_github_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_github_sync_theme', [$this, 'ajax_sync_theme']);
        add_action('wp_ajax_github_get_theme_files', [$this, 'ajax_get_theme_files']);
        add_action('wp_ajax_github_pull_from_github', [$this, 'ajax_pull_from_github']);
        add_action('wp_ajax_github_get_file_diff', [$this, 'ajax_get_file_diff']);
        add_action('wp_ajax_github_get_changed_files', [$this, 'ajax_get_changed_files']);
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        // Добавляем главное меню
        add_menu_page(
            'GitPush WP', 
            'GitPush WP', 
            'manage_options',
            'gitpush-wp',
            [$this, 'render_main_page'],
            'dashicons-cloud-upload',
            100
        );
        
        // Подменю для интерфейса синхронизации (будет первым пунктом)
        add_submenu_page(
            'gitpush-wp',
            'Sync Files', 
            'Sync Files',
            'manage_options',
            'gitpush-wp',
            [$this, 'render_main_page']
        );
        
        // Подменю для настроек
        add_submenu_page(
            'gitpush-wp',
            'GitPush Settings', 
            'Settings',
            'manage_options',
            'gitpush-wp-settings',
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('gitpush_wp_group', 'gitpush_wp_settings');
        
        add_settings_section(
            'gitpush_wp_section',
            'GitHub Connection Settings',
            [$this, 'settings_section_callback'],
            'gitpush-wp-settings'
        );
        
        add_settings_field(
            'github_token',
            'GitHub Personal Access Token',
            [$this, 'github_token_render'],
            'gitpush-wp-settings',
            'gitpush_wp_section'
        );
        
        add_settings_field(
            'github_username',
            'GitHub Username',
            [$this, 'github_username_render'],
            'gitpush-wp-settings',
            'gitpush_wp_section'
        );
        
        add_settings_field(
            'github_repo',
            'GitHub Repository',
            [$this, 'github_repo_render'],
            'gitpush-wp-settings',
            'gitpush_wp_section'
        );
        
        add_settings_field(
            'github_branch',
            'GitHub Branch',
            [$this, 'github_branch_render'],
            'gitpush-wp-settings',
            'gitpush_wp_section'
        );
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts($hook) {
        // Загружаем скрипты только на страницах нашего плагина
        if (strpos($hook, 'gitpush-wp') === false) {
            return;
        }
        
        wp_enqueue_style('gitpush-wp-css', plugin_dir_url(__FILE__) . 'css/admin.css', [], '1.1.0');
        wp_enqueue_script('gitpush-wp-js', plugin_dir_url(__FILE__) . 'js/admin.js', [], '1.1.0', true);
        
        // Добавляем highlight.js для подсветки синтаксиса в дифах (только на странице синхронизации)
        if ($hook === 'toplevel_page_gitpush-wp') {
            wp_enqueue_style('highlight-js-css', 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/styles/github.min.css');
            wp_enqueue_script('highlight-js', 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/highlight.min.js', [], '11.7.0', true);
        }
        
        wp_localize_script('gitpush-wp-js', 'gitpush_wp_obj', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gitpush_wp_nonce'),
            'theme_path' => get_template_directory(),
            'theme_name' => wp_get_theme()->get('Name'),
            'github_username' => $this->settings['github_username'],
            'github_repo' => $this->settings['github_repo'],
            'github_branch' => $this->settings['github_branch']
        ]);
    }
    
    /**
     * Показать информацию о репозитории
     */
    private function display_repo_info() {
        $repo_info = '';
        if (!empty($this->settings['github_username']) && !empty($this->settings['github_repo'])) {
            $repo_info = $this->settings['github_username'] . '/' . $this->settings['github_repo'] . ' (' . $this->settings['github_branch'] . ')';
        }
        ?>
        <div id="repo-info-banner" class="repo-info-banner">
            <div class="repo-info-icon">
                <span class="dashicons dashicons-github"></span>
            </div>
            <div class="repo-info-text">
                <?php if (!empty($repo_info)): ?>
                    Connected to: <strong><?php echo esc_html($repo_info); ?></strong>
                <?php else: ?>
                    <span class="not-connected">Not connected to any repository. Please configure settings.</span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Settings section callback
     */
    public function settings_section_callback() {
        echo '<p>Enter your GitHub credentials to sync your theme files.</p>';
    }
    
    /**
     * Render GitHub token field
     */
    public function github_token_render() {
        ?>
        <input type="password" name="gitpush_wp_settings[github_token]" value="<?php echo esc_attr($this->settings['github_token']); ?>" class="regular-text">
        <p class="description">Create a personal access token with 'repo' permissions at <a href="https://github.com/settings/tokens" target="_blank">GitHub Settings</a>.</p>
        <?php
    }
    
    /**
     * Render GitHub username field
     */
    public function github_username_render() {
        ?>
        <input type="text" name="gitpush_wp_settings[github_username]" value="<?php echo esc_attr($this->settings['github_username']); ?>" class="regular-text">
        <?php
    }
    
    /**
     * Render GitHub repo field
     */
    public function github_repo_render() {
        ?>
        <input type="text" name="gitpush_wp_settings[github_repo]" value="<?php echo esc_attr($this->settings['github_repo']); ?>" class="regular-text">
        <p class="description">Repository name without the username (e.g., my-theme)</p>
        <?php
    }
    
    /**
     * Render GitHub branch field
     */
    public function github_branch_render() {
        ?>
        <input type="text" name="gitpush_wp_settings[github_branch]" value="<?php echo esc_attr($this->settings['github_branch']); ?>" class="regular-text">
        <p class="description">Default: main</p>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>GitPush WP Settings</h1>
            
            <?php $this->display_repo_info(); ?>
            
            <div class="gitpush-settings-container">
                <form method="post" action="options.php">
                    <?php
                    settings_fields('gitpush_wp_group');
                    do_settings_sections('gitpush-wp-settings');
                    submit_button('Save Settings');
                    ?>
                </form>
                
                <div class="gitpush-wp-actions settings-actions">
                    <button id="test-connection" class="button button-primary">Test GitHub Connection</button>
                </div>
                
                <div id="sync-status"></div>
            </div>
            
            <?php if (empty($this->settings['github_username']) || empty($this->settings['github_repo'])): ?>
            <div class="gitpush-notice">
                <p>Please configure your GitHub settings to use GitPush WP.</p>
                <p>After saving settings, test your connection, then go to <a href="<?php echo admin_url('admin.php?page=gitpush-wp'); ?>">Sync Files</a> page to push/pull your theme files.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render main sync page
     */
    public function render_main_page() {
        // Проверяем наличие настроек
        $is_configured = !empty($this->settings['github_username']) && 
                         !empty($this->settings['github_repo']) && 
                         !empty($this->settings['github_token']);
        ?>
        <div class="wrap">
            <h1>GitPush WP</h1>
            
            <?php $this->display_repo_info(); ?>
            
            <?php if (!$is_configured): ?>
                <div class="gitpush-notice gitpush-warning">
                    <p>GitHub connection is not configured. Please go to <a href="<?php echo admin_url('admin.php?page=gitpush-wp-settings'); ?>">Settings</a> to setup your GitHub connection.</p>
                </div>
            <?php else: ?>
                <div class="gitpush-wp-actions">
                    <button id="pull-from-github" class="button button-secondary">Pull from GitHub</button>
                    <button id="sync-theme" class="button button-primary">Push to GitHub</button>
                </div>
                
                <div id="sync-status"></div>
                
                <div id="gitpush-container" class="gitpush-container">
                    <!-- Панель со списком файлов -->
                    <div class="gitpush-files-panel">
                        <div class="file-list-actions">
                            <button id="refresh-files" class="button">Refresh Files</button>
                            <button id="show-all-files" class="button">All Files</button>
                            <button id="show-changed-files" class="button button-primary">Changed Files</button>
                        </div>
                        
                        <div class="loading">Loading theme files...</div>
                        <ul class="files-list"></ul>
                    </div>
                    
                    <!-- Панель для отображения дифа -->
                    <div class="gitpush-diff-panel">
                        <div class="diff-header">
                            <h3>File Changes</h3>
                            <p class="diff-instructions">Select a file to view changes</p>
                        </div>
                        <div class="diff-content"></div>
                    </div>
                </div>
                
                <div class="gitpush-wp-commit">
                    <div class="commit-message-container">
                        <label for="commit-message">Commit Message:</label>
                        <input type="text" id="commit-message" class="regular-text" value="Update from WordPress Admin">
                    </div>
                    <button id="commit-selected" class="button button-primary">Commit Selected Files</button>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Get theme files recursively
     */
    private function get_theme_files($dir, $base_dir = '') {
        $files = [];
        $theme_dir = get_template_directory();
        
        if (empty($base_dir)) {
            $base_dir = $theme_dir;
        }
        
        // Get all files and directories in the current directory
        $items = scandir($dir);
        
        foreach ($items as $item) {
            // Skip current and parent directory
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $path = $dir . '/' . $item;
            $relative_path = str_replace($base_dir . '/', '', $path);
            
            if (is_dir($path)) {
                // Skip some common directories we don't want to sync
                $skip_dirs = ['.git', 'node_modules', 'vendor', '.idea', '.vscode'];
                if (in_array($item, $skip_dirs)) {
                    continue;
                }
                
                $files[] = [
                    'path' => $relative_path,
                    'type' => 'dir'
                ];
                
                // Get files from subdirectory
                $sub_files = $this->get_theme_files($path, $base_dir);
                $files = array_merge($files, $sub_files);
            } else {
                // Skip some files we don't want to sync
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
     * Get file content from GitHub
     */
    private function get_github_file_content($file_path) {
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
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            return false;
        }
        
        $file_data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($file_data['content']) && isset($file_data['encoding']) && $file_data['encoding'] === 'base64') {
            return base64_decode($file_data['content']);
        }
        
        return false;
    }
    
    /**
     * Get file diff between local and GitHub
     */
    private function get_file_diff($file_path) {
        $theme_dir = get_template_directory();
        $local_file_path = $theme_dir . '/' . $file_path;
        
        if (!file_exists($local_file_path)) {
            return [
                'local_content' => '',
                'github_content' => $this->get_github_file_content($file_path),
                'status' => 'deleted_locally'
            ];
        }
        
        $local_content = file_get_contents($local_file_path);
        $github_content = $this->get_github_file_content($file_path);
        
        if ($github_content === false) {
            return [
                'local_content' => $local_content,
                'github_content' => '',
                'status' => 'new'
            ];
        }
        
        $status = ($local_content === $github_content) ? 'unchanged' : 'modified';
        
        return [
            'local_content' => $local_content,
            'github_content' => $github_content,
            'status' => $status
        ];
    }
    
    /**
     * Compare local files with GitHub versions
     */
    private function get_changed_files() {
        $token = $this->settings['github_token'];
        $username = $this->settings['github_username'];
        $repo = $this->settings['github_repo'];
        $branch = $this->settings['github_branch'];
        
        $theme_files = $this->get_theme_files(get_template_directory());
        $changed_files = [];
        
        // Get GitHub file tree
        $github_tree_url = "https://api.github.com/repos/{$username}/{$repo}/git/trees/{$branch}?recursive=1";
        
        $response = wp_remote_get($github_tree_url, [
            'headers' => [
                'Authorization' => "token {$token}",
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            ]
        ]);
        
        if (is_wp_error($response)) {
            return $theme_files; // Return all files if we can't get GitHub tree
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            return $theme_files; // Return all files if API returns an error
        }
        
        $github_tree = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($github_tree['tree']) || !is_array($github_tree['tree'])) {
            return $theme_files; // Return all files if tree is invalid
        }
        
        // Create lookup for GitHub files
        $github_files = [];
        foreach ($github_tree['tree'] as $item) {
            if ($item['type'] === 'blob') {
                $github_files[$item['path']] = $item;
            }
        }
        
        // Compare local files with GitHub files
        foreach ($theme_files as $file) {
            if ($file['type'] === 'dir') {
                continue; // Skip directories
            }
            
            $status = 'new'; // Default status is new
            
            if (isset($github_files[$file['path']])) {
                // File exists on GitHub
                $github_sha = $github_files[$file['path']]['sha'];
                $local_content = file_get_contents(get_template_directory() . '/' . $file['path']);
                $local_blob = 'blob ' . strlen($local_content) . "\0" . $local_content;
                $local_sha = sha1($local_blob);
                
                if ($github_sha === $local_sha) {
                    $status = 'unchanged';
                } else {
                    $status = 'modified';
                }
                
                // Remove from GitHub files to track deleted files
                unset($github_files[$file['path']]);
            }
            
            $file['status'] = $status;
            $changed_files[] = $file;
        }
        
        // Add deleted files (files that exist on GitHub but not locally)
        foreach ($github_files as $path => $item) {
            if ($item['type'] === 'blob') {
                $extension = pathinfo($path, PATHINFO_EXTENSION);
                
                $changed_files[] = [
                    'path' => $path,
                    'type' => 'file',
                    'extension' => $extension,
                    'status' => 'deleted'
                ];
            }
        }
        
        return $changed_files;
    }
    
    /**
     * Ajax handler to get theme files
     */
    public function ajax_get_theme_files() {
        check_ajax_referer('gitpush_wp_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $theme_dir = get_template_directory();
        $files = $this->get_theme_files($theme_dir);
        
        wp_send_json_success([
            'files' => $files,
            'theme_dir' => $theme_dir
        ]);
    }
    
    /**
     * Ajax handler to get changed files
     */
    public function ajax_get_changed_files() {
        check_ajax_referer('gitpush_wp_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $changed_files = $this->get_changed_files();
        
        wp_send_json_success([
            'files' => $changed_files,
            'theme_dir' => get_template_directory()
        ]);
    }
    
    /**
     * Ajax handler to get file diff
     */
    public function ajax_get_file_diff() {
        check_ajax_referer('gitpush_wp_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';
        
        if (empty($file_path)) {
            wp_send_json_error('Missing file path');
        }
        
        $diff = $this->get_file_diff($file_path);
        
        wp_send_json_success($diff);
    }
    
    /**
     * Test GitHub connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('gitpush_wp_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $token = $this->settings['github_token'];
        $username = $this->settings['github_username'];
        $repo = $this->settings['github_repo'];
        
        if (empty($token) || empty($username) || empty($repo)) {
            wp_send_json_error('Missing GitHub settings. Please fill in all fields.');
        }
        
        $response = wp_remote_get("https://api.github.com/repos/{$username}/{$repo}", [
            'headers' => [
                'Authorization' => "token {$token}",
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            ]
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error('Connection error: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($response_code === 200) {
            wp_send_json_success('Connection successful! Repository: ' . $body['full_name']);
        } else {
            wp_send_json_error('Connection failed. Error: ' . $body['message']);
        }
    }
    
    /**
     * Pull from GitHub
     */
    public function ajax_pull_from_github() {
        check_ajax_referer('gitpush_wp_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $token = $this->settings['github_token'];
        $username = $this->settings['github_username'];
        $repo = $this->settings['github_repo'];
        $branch = $this->settings['github_branch'];
        
        if (empty($token) || empty($username) || empty($repo)) {
            wp_send_json_error('Missing GitHub settings. Please fill in all fields.');
        }
        
        // Get all files from GitHub
        $github_tree_url = "https://api.github.com/repos/{$username}/{$repo}/git/trees/{$branch}?recursive=1";
        
        $response = wp_remote_get($github_tree_url, [
            'headers' => [
                'Authorization' => "token {$token}",
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            ]
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error('Error getting GitHub files: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            wp_send_json_error('Error getting GitHub files: ' . (isset($body['message']) ? $body['message'] : 'Unknown error'));
        }
        
        $github_tree = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($github_tree['tree']) || !is_array($github_tree['tree'])) {
            wp_send_json_error('Invalid GitHub tree response');
        }
        
        $theme_dir = get_template_directory();
        $updated_files = [];
        $errors = [];
        
        // Download and update files
        foreach ($github_tree['tree'] as $item) {
            if ($item['type'] !== 'blob') {
                continue; // Skip directories
            }
            
            $path = $item['path'];
            $local_path = $theme_dir . '/' . $path;
            
            // Get file content from GitHub
            $github_content = $this->get_github_file_content($path);
            
            if ($github_content === false) {
                $errors[] = "Failed to get content for {$path}";
                continue;
            }
            
            // Create directory if it doesn't exist
            $dir = dirname($local_path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            // Write file
            $result = file_put_contents($local_path, $github_content);
            
            if ($result === false) {
                $errors[] = "Failed to write file {$path}";
            } else {
                $updated_files[] = $path;
            }
        }
        
        // Update last pull time
        $this->settings['last_pull'] = current_time('mysql');
        update_option('gitpush_wp_settings', $this->settings);
        
        wp_send_json_success([
            'message' => 'Pull completed',
            'updated_files' => $updated_files,
            'errors' => $errors,
            'pull_time' => $this->settings['last_pull']
        ]);
    }
    
    /**
     * Sync theme to GitHub
     */
    public function ajax_sync_theme() {
        check_ajax_referer('gitpush_wp_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $files = isset($_POST['files']) ? json_decode(stripslashes($_POST['files']), true) : [];
        $commit_message = isset($_POST['commit_message']) ? sanitize_text_field($_POST['commit_message']) : 'Update from WordPress';
        
        if (empty($files)) {
            wp_send_json_error('No files selected for sync');
        }
        
        $token = $this->settings['github_token'];
        $username = $this->settings['github_username'];
        $repo = $this->settings['github_repo'];
        $branch = $this->settings['github_branch'];
        
        if (empty($token) || empty($username) || empty($repo)) {
            wp_send_json_error('Missing GitHub settings. Please fill in all fields.');
        }
        
        $theme_dir = get_template_directory();
        $results = [];
        
        // Get the latest commit SHA
        $response = wp_remote_get("https://api.github.com/repos/{$username}/{$repo}/git/refs/heads/{$branch}", [
            'headers' => [
                'Authorization' => "token {$token}",
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            ]
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error('Error getting commit SHA: ' . $response->get_error_message());
        }
        
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($response_body['object']['sha'])) {
            wp_send_json_error('Failed to get the latest commit SHA');
        }
        
        $base_sha = $response_body['object']['sha'];
        
        foreach ($files as $file) {
            $file_path = $theme_dir . '/' . $file;
            
            if (!file_exists($file_path)) {
                // Попытка удалить файл с GitHub (если он помечен как удаленный)
                $github_file_url = "https://api.github.com/repos/{$username}/{$repo}/contents/{$file}?ref={$branch}";
                $file_response = wp_remote_get($github_file_url, [
                    'headers' => [
                        'Authorization' => "token {$token}",
                        'User-Agent' => 'WordPress/' . get_bloginfo('version')
                    ]
                ]);
                
                if (wp_remote_retrieve_response_code($file_response) === 200) {
                    $file_data = json_decode(wp_remote_retrieve_body($file_response), true);
                    
                    if (isset($file_data['sha'])) {
                        $delete_data = [
                            'message' => $commit_message . ' (deleted file)',
                            'sha' => $file_data['sha'],
                            'branch' => $branch
                        ];
                        
                        $delete_response = wp_remote_request("https://api.github.com/repos/{$username}/{$repo}/contents/{$file}", [
                            'method' => 'DELETE',
                            'headers' => [
                                'Authorization' => "token {$token}",
                                'User-Agent' => 'WordPress/' . get_bloginfo('version'),
                                'Content-Type' => 'application/json'
                            ],
                            'body' => json_encode($delete_data)
                        ]);
                        
                        if (wp_remote_retrieve_response_code($delete_response) === 200) {
                            $results[$file] = 'deleted';
                        } else {
                            $results[$file] = 'error_deleting';
                        }
                    }
                }
                
                continue;
            }
            
            $content = file_get_contents($file_path);
            $content_base64 = base64_encode($content);
            
            // Check if file exists on GitHub
            $github_file_url = "https://api.github.com/repos/{$username}/{$repo}/contents/{$file}?ref={$branch}";
            $file_response = wp_remote_get($github_file_url, [
                'headers' => [
                    'Authorization' => "token {$token}",
                    'User-Agent' => 'WordPress/' . get_bloginfo('version')
                ]
            ]);
            
            $file_exists = wp_remote_retrieve_response_code($file_response) === 200;
            $file_data = json_decode(wp_remote_retrieve_body($file_response), true);
            
            $update_data = [
                'message' => $commit_message,
                'content' => $content_base64,
                'branch' => $branch
            ];
            
            if ($file_exists && isset($file_data['sha'])) {
                $update_data['sha'] = $file_data['sha'];
            }
            
            $update_response = wp_remote_request("https://api.github.com/repos/{$username}/{$repo}/contents/{$file}", [
                'method' => 'PUT',
                'headers' => [
                    'Authorization' => "token {$token}",
                    'User-Agent' => 'WordPress/' . get_bloginfo('version'),
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode($update_data)
            ]);
            
            if (is_wp_error($update_response)) {
                $results[$file] = 'error';
                continue;
            }
            
            $update_code = wp_remote_retrieve_response_code($update_response);
            
            if ($update_code === 200 || $update_code === 201) {
                $results[$file] = $file_exists ? 'updated' : 'created';
            } else {
                $results[$file] = 'error';
            }
        }
        
        // Update last sync time
        $this->settings['last_sync'] = current_time('mysql');
        update_option('gitpush_wp_settings', $this->settings);
        
        wp_send_json_success([
            'message' => 'Sync completed',
            'results' => $results,
            'sync_time' => $this->settings['last_sync']
        ]);
    }
}

// Initialize the plugin
new GitPush_WP();