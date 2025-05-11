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

// Определение констант плагина
define('GITPUSH_WP_VERSION', '1.1.0');
define('GITPUSH_WP_PATH', plugin_dir_path(__FILE__));
define('GITPUSH_WP_URL', plugin_dir_url(__FILE__));

// Автозагрузка классов
spl_autoload_register(function ($class_name) {
    if (strpos($class_name, 'GitPush_') !== 0) {
        return;
    }
    
    $class_file = str_replace('GitPush_', '', $class_name);
    $class_file = strtolower(str_replace('_', '-', $class_file));
    $class_path = GITPUSH_WP_PATH . 'includes/class-' . $class_file . '.php';
    
    if (file_exists($class_path)) {
        require_once $class_path;
    }
});

// Функция активации плагина
function gitpush_wp_activate() {
    // Создаем необходимые папки при активации
    if (!file_exists(GITPUSH_WP_PATH . 'includes')) {
        mkdir(GITPUSH_WP_PATH . 'includes', 0755);
    }
    
    // Устанавливаем дефолтные настройки
    $settings = get_option('gitpush_wp_settings', []);
    $default_settings = [
        'github_token' => '',
        'github_username' => '',
        'github_repo' => '',
        'github_branch' => 'main',
        'last_sync' => '',
        'last_pull' => ''
    ];
    
    update_option('gitpush_wp_settings', array_merge($default_settings, $settings));
}
register_activation_hook(__FILE__, 'gitpush_wp_activate');

// Инициализация плагина
function gitpush_wp_init() {
    // Загружаем основные классы
    $api = new GitPush_GitHub_API();
    $files_manager = new GitPush_Files_Manager();
    $admin_ui = new GitPush_Admin_UI($api, $files_manager);
    $ajax_handler = new GitPush_AJAX_Handler($api, $files_manager);
}
add_action('plugins_loaded', 'gitpush_wp_init');

// Добавляем ресурсы плагина (JS/CSS)
function gitpush_wp_enqueue_scripts($hook) {
    // Проверяем, что мы находимся на странице нашего плагина
    if (strpos($hook, 'gitpush-wp') === false) {
        return;
    }
    
    // Подключаем CSS
    wp_enqueue_style('gitpush-wp-css', GITPUSH_WP_URL . 'css/admin.css', [], GITPUSH_WP_VERSION);
    
    // Подключаем JavaScript
    wp_enqueue_script('gitpush-wp-js', GITPUSH_WP_URL . 'js/admin.js', [], GITPUSH_WP_VERSION, true);
    
    // Добавляем highlight.js для подсветки синтаксиса в дифах
    wp_enqueue_style('highlight-js-css', 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/styles/github.min.css');
    wp_enqueue_script('highlight-js', 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/highlight.min.js', [], '11.7.0', true);
    
    // Передаем необходимые данные в JavaScript
    $settings = get_option('gitpush_wp_settings', []);
    
    wp_localize_script('gitpush-wp-js', 'gitpush_wp_obj', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('gitpush_wp_nonce'),
        'theme_path' => get_template_directory(),
        'theme_name' => wp_get_theme()->get('Name'),
        'github_username' => isset($settings['github_username']) ? $settings['github_username'] : '',
        'github_repo' => isset($settings['github_repo']) ? $settings['github_repo'] : '',
        'github_branch' => isset($settings['github_branch']) ? $settings['github_branch'] : 'main'
    ]);
}
add_action('admin_enqueue_scripts', 'gitpush_wp_enqueue_scripts');