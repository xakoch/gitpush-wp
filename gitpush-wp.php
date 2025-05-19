<?php
/**
 * Plugin Name: GitPush WP
 * Plugin URI: https://xakoch.uz/
 * Description: Sync your WordPress theme files directly with GitHub repository.
 * Version: 1.2.5
 * Author: Xakoch
 * Author URI: https://xakoch.uz/
 * Text Domain: gitpush-wp
 * Requires PHP: 7.2
 * Requires at least: 5.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

define('GITPUSH_WP_VERSION', '1.2.6'); 
define('GITPUSH_WP_PATH', plugin_dir_path(__FILE__));
define('GITPUSH_WP_URL', plugin_dir_url(__FILE__));

spl_autoload_register(function ($class_name) {
    if (strpos($class_name, 'GitPush_') !== 0) {
        return;
    }
    
    $base_name = str_replace('GitPush_', '', $class_name);
    $file_name_part = '';

    switch ($base_name) {
        case 'GitHub_API':
            $file_name_part = 'github-api';
            break;
        case 'Files_Manager':
            $file_name_part = 'files-manager';
            break;
        case 'Admin_UI':
            $file_name_part = 'admin-ui';
            break;
        case 'AJAX_Handler':
            $file_name_part = 'ajax-handler';
            break;
        default:
            $temp_name = str_replace('_', '-', $base_name);
            $temp_name = preg_replace('/(?<=[a-z0-9])([A-Z])/', '-$1', $temp_name);
            $file_name_part = strtolower($temp_name);
            break;
    }
    
    $class_path = GITPUSH_WP_PATH . 'includes/class-' . $file_name_part . '.php'; 
    
    if (file_exists($class_path)) {
        require_once $class_path;
    } else {
        error_log(
            sprintf(
                'GitPush WP Autoloader: Class file not found for class "%s". Attempted path: "%s". (Base name: "%s", Processed name part: "%s")',
                $class_name,
                $class_path,
                $base_name,
                $file_name_part
            )
        );
    }
});

function gitpush_wp_activate() {
    $current_settings = get_option('gitpush_wp_settings', []);
    $default_settings = [
        'github_token' => '',
        'github_username' => '',
        'github_repo' => '',
        'github_branch' => 'main',
        'last_sync' => '',
    ];
    $new_settings = array_merge($default_settings, $current_settings);
    if (isset($new_settings['last_pull'])) {
        unset($new_settings['last_pull']);
    }
    update_option('gitpush_wp_settings', $new_settings);
}
register_activation_hook(__FILE__, 'gitpush_wp_activate');

function gitpush_wp_init() {
    if (is_admin()) { 
        if (class_exists('GitPush_GitHub_API') && 
            class_exists('GitPush_Files_Manager') && 
            class_exists('GitPush_Admin_UI') && 
            class_exists('GitPush_AJAX_Handler')) {
            
            $api = new GitPush_GitHub_API();
            $files_manager = new GitPush_Files_Manager($api); 
            new GitPush_Admin_UI($api, $files_manager); 
            new GitPush_AJAX_Handler($api, $files_manager); 
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>GitPush WP plugin critical error: One or more required classes could not be loaded. Please check PHP error logs and ensure all plugin files (e.g., <code>includes/class-github-api.php</code>) are correctly uploaded and have correct names that match the autoloader logic.</p></div>';
            });
            error_log('GitPush WP Error: Plugin classes not found during init. Autoloader might have failed or files are missing.');
        }
    }
}
add_action('plugins_loaded', 'gitpush_wp_init', 20); 

function gitpush_wp_enqueue_scripts($hook_suffix) {
    $plugin_pages = [
        'toplevel_page_gitpush-wp', 
        'gitpush-wp_page_gitpush-wp-settings' 
    ];

    if (!in_array($hook_suffix, $plugin_pages, true)) {
        return;
    }
    
    wp_enqueue_style('gitpush-wp-admin-css', GITPUSH_WP_URL . 'css/admin.css', [], GITPUSH_WP_VERSION);
    wp_enqueue_script('gitpush-wp-admin-js', GITPUSH_WP_URL . 'js/admin.js', ['jquery'], GITPUSH_WP_VERSION, true);
    
    wp_enqueue_style('highlight-js-css', 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css', [], '11.9.0');
    wp_enqueue_script('highlight-js', 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js', [], '11.9.0', true);
    
    $settings = get_option('gitpush_wp_settings', []);
    wp_localize_script('gitpush-wp-admin-js', 'gitpush_wp_obj', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('gitpush_nonce'), 
        'github_username' => $settings['github_username'] ?? '',
        'github_repo' => $settings['github_repo'] ?? '',
        'github_branch' => $settings['github_branch'] ?? 'main'
    ]);
}
add_action('admin_enqueue_scripts', 'gitpush_wp_enqueue_scripts');

function gitpush_wp_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=gitpush-wp-settings') . '">' . __('Settings', 'gitpush-wp') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'gitpush_wp_action_links');