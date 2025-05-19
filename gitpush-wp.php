<?php
/**
 * Plugin Name: GitPush WP
 * Plugin URI: https://xakoch.uz/
 * Description: Sync your WordPress theme files directly with GitHub repository.
 * Version: 1.1.1 
 * Author: Xakoch
 * Author URI: https://xakoch.uz/
 * Text Domain: gitpush-wp
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Определение констант плагина
define('GITPUSH_WP_VERSION', '1.1.1'); // Обновлена версия для отражения изменений
define('GITPUSH_WP_PATH', plugin_dir_path(__FILE__));
define('GITPUSH_WP_URL', plugin_dir_url(__FILE__));

// Автозагрузка классов
spl_autoload_register(function ($class_name) {
    if (strpos($class_name, 'GitPush_') !== 0) {
        return;
    }
    
    $class_file = str_replace('GitPush_', '', $class_name);
    $class_file = strtolower(str_replace('_', '-', $class_file));
    $class_path = GITPUSH_WP_PATH . 'includes/class-' . $class_file . '.php'; // Исправлен путь на 'includes'
    
    if (file_exists($class_path)) {
        require_once $class_path;
    } else {
        // Можно добавить логирование, если класс не найден, для отладки
        error_log("GitPush WP Autoloader: Class file not found at " . $class_path);
    }
});

// Функция активации плагина
function gitpush_wp_activate() {
    // Убедимся что папка includes существует (хотя она должна быть)
    if (!file_exists(GITPUSH_WP_PATH . 'includes')) {
        // Попытка создать директорию, если ее нет (маловероятно, но для полноты)
        // mkdir(GITPUSH_WP_PATH . 'includes', 0755, true); 
    }
    
    // Устанавливаем дефолтные настройки
    $current_settings = get_option('gitpush_wp_settings', []);
    $default_settings = [
        'github_token' => '',
        'github_username' => '',
        'github_repo' => '',
        'github_branch' => 'main',
        'last_sync' => '',
        // 'last_pull' => '' // Опция last_pull удалена
    ];
    
    // Обновляем только те настройки, которые не были установлены ранее, сохраняя существующие значения
    $new_settings = array_merge($default_settings, $current_settings);
    // Если 'last_pull' все еще существует в $new_settings после слияния, удаляем его
    if (isset($new_settings['last_pull'])) {
        unset($new_settings['last_pull']);
    }

    update_option('gitpush_wp_settings', $new_settings);
}
register_activation_hook(__FILE__, 'gitpush_wp_activate');

// Инициализация плагина
function gitpush_wp_init() {
    // Загружаем основные классы
    // Объекты будут созданы, когда они действительно понадобятся, например, в admin_menu или ajax handlers
    // Это немного оптимизирует загрузку, если классы не нужны на каждой странице
    if (is_admin()) { // Функционал плагина только для админ-панели
        $api = new GitPush_GitHub_API();
        $files_manager = new GitPush_Files_Manager(); // GitHub_API уже будет инстанцирован внутри него
        $admin_ui = new GitPush_Admin_UI($api, $files_manager);
        $ajax_handler = new GitPush_AJAX_Handler($api, $files_manager);
    }
}
add_action('plugins_loaded', 'gitpush_wp_init');

// Добавляем ресурсы плагина (JS/CSS)
function gitpush_wp_enqueue_scripts($hook) {
    // Проверяем, что мы находимся на одной из страниц нашего плагина
    if (strpos($hook, 'gitpush-wp') === false) {
        return;
    }
    
    // Подключаем CSS
    wp_enqueue_style('gitpush-wp-admin-css', GITPUSH_WP_URL . 'css/admin.css', [], GITPUSH_WP_VERSION);
    
    // Подключаем JavaScript
    wp_enqueue_script('gitpush-wp-admin-js', GITPUSH_WP_URL . 'js/admin.js', [], GITPUSH_WP_VERSION, true);
    
    // Добавляем highlight.js для подсветки синтаксиса в дифах
    wp_enqueue_style('highlight-js-css', 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github.min.css'); // Версия обновлена
    wp_enqueue_script('highlight-js', 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js', [], '11.9.0', true);
    
    // Передаем необходимые данные в JavaScript
    $settings = get_option('gitpush_wp_settings', []);
    
    wp_localize_script('gitpush-wp-admin-js', 'gitpush_wp_obj', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('gitpush_wp_nonce'),
        'theme_path' => get_template_directory(), // Путь к текущей активной теме
        'theme_name' => wp_get_theme()->get('Name'),
        'github_username' => $settings['github_username'] ?? '',
        'github_repo' => $settings['github_repo'] ?? '',
        'github_branch' => $settings['github_branch'] ?? 'main'
    ]);
}
add_action('admin_enqueue_scripts', 'gitpush_wp_enqueue_scripts');

// Добавляем ссылку "Settings" на страницу плагинов
function gitpush_wp_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=gitpush-wp-settings') . '">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'gitpush_wp_action_links');