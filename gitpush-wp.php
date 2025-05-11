<?php
/**
 * Plugin Name: GitPush WP
 * Plugin URI: https://xakoch.uz/
 * Description: Sync your WordPress theme files directly with GitHub repository.
 * Version: 1.0.0
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
            'last_sync' => ''
        ]);
        
        // Register hooks
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // Register AJAX handlers
        add_action('wp_ajax_github_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_github_sync_theme', [$this, 'ajax_sync_theme']);
        add_action('wp_ajax_github_get_theme_files', [$this, 'ajax_get_theme_files']);
    }
    
    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_management_page(
            'GitPush WP',
            'GitPush WP',
            'manage_options',
            'gitpush-wp',
            [$this, 'render_admin_page']
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('gitpush_wp_group', 'gitpush_wp_settings');
        
        add_settings_section(
            'gitpush_wp_section',
            'GitHub Settings',
            [$this, 'settings_section_callback'],
            'gitpush-wp'
        );
        
        add_settings_field(
            'github_token',
            'GitHub Personal Access Token',
            [$this, 'github_token_render'],
            'gitpush-wp',
            'gitpush_wp_section'
        );
        
        add_settings_field(
            'github_username',
            'GitHub Username',
            [$this, 'github_username_render'],
            'gitpush-wp',
            'gitpush_wp_section'
        );
        
        add_settings_field(
            'github_repo',
            'GitHub Repository',
            [$this, 'github_repo_render'],
            'gitpush-wp',
            'gitpush_wp_section'
        );
        
        add_settings_field(
            'github_branch',
            'GitHub Branch',
            [$this, 'github_branch_render'],
            'gitpush-wp',
            'gitpush_wp_section'
        );
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts($hook) {
        if ('tools_page_gitpush-wp' !== $hook) {
            return;
        }
        
        wp_enqueue_style('gitpush-wp-css', plugin_dir_url(__FILE__) . 'assets/css/admin.css', [], '1.0.0');
        wp_enqueue_script('gitpush-wp-js', plugin_dir_url(__FILE__) . 'assets/js/admin.js', [], '1.0.0', true);
        
        wp_localize_script('gitpush-wp-js', 'gitpush_wp_obj', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gitpush_wp_nonce'),
            'theme_path' => get_template_directory(),
            'theme_name' => wp_get_theme()->get('Name')
        ]);
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
     * Render admin page
     */
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>GitPush WP</h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('gitpush_wp_group');
                do_settings_sections('gitpush-wp');
                submit_button('Save Settings');
                ?>
            </form>
            
            <hr>
            
            <h2>Theme Files</h2>
            
            <div class="gitpush-wp-actions">
                <button id="test-connection" class="button">Test GitHub Connection</button>
                <button id="sync-theme" class="button button-primary">Sync Theme to GitHub</button>
            </div>
            
            <div id="sync-status"></div>
            
            <div id="file-list" class="gitpush-wp-files">
                <h3>Theme Files</h3>
                <div class="loading">Loading theme files...</div>
                <ul class="files-list"></ul>
            </div>
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
                
                $files[] = [
                    'path' => $relative_path,
                    'type' => 'file',
                    'extension' => $extension
                ];
            }
        }
        
        return $files;
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
                $results[$file] = 'error';
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
                $results[$file] = 'success';
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