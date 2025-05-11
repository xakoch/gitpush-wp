<?php
/**
 * Класс для работы с интерфейсом администратора
 */
class GitPush_Admin_UI {
    
    private $github_api;
    private $files_manager;
    
    public function __construct($github_api = null, $files_manager = null) {
        $this->github_api = $github_api ?: new GitPush_GitHub_API();
        $this->files_manager = $files_manager ?: new GitPush_Files_Manager();
        
        // Добавляем пункты меню
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Регистрируем настройки
        add_action('admin_init', [$this, 'register_settings']);
    }
    
    /**
     * Добавление пунктов меню
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
     * Регистрация настроек
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
     * Callback для секции настроек
     */
    public function settings_section_callback() {
        echo '<p>Enter your GitHub credentials to sync your theme files.</p>';
    }
    
    /**
     * Отображение поля с токеном GitHub
     */
    public function github_token_render() {
        $settings = $this->github_api->get_settings();
        ?>
        <input type="password" name="gitpush_wp_settings[github_token]" value="<?php echo esc_attr($settings['github_token']); ?>" class="regular-text">
        <p class="description">Create a personal access token with 'repo' permissions at <a href="https://github.com/settings/tokens" target="_blank">GitHub Settings</a>.</p>
        <?php
    }
    
    /**
     * Отображение поля с именем пользователя GitHub
     */
    public function github_username_render() {
        $settings = $this->github_api->get_settings();
        ?>
        <input type="text" name="gitpush_wp_settings[github_username]" value="<?php echo esc_attr($settings['github_username']); ?>" class="regular-text">
        <?php
    }
    
    /**
     * Отображение поля с репозиторием GitHub
     */
    public function github_repo_render() {
        $settings = $this->github_api->get_settings();
        ?>
        <input type="text" name="gitpush_wp_settings[github_repo]" value="<?php echo esc_attr($settings['github_repo']); ?>" class="regular-text">
        <p class="description">Repository name without the username (e.g., my-theme)</p>
        <?php
    }
    
    /**
     * Отображение поля с веткой GitHub
     */
    public function github_branch_render() {
        $settings = $this->github_api->get_settings();
        ?>
        <input type="text" name="gitpush_wp_settings[github_branch]" value="<?php echo esc_attr($settings['github_branch']); ?>" class="regular-text">
        <p class="description">Default: main</p>
        <?php
    }
    
    /**
     * Отображение информации о репозитории
     */
    private function display_repo_info() {
        $settings = $this->github_api->get_settings();
        $repo_info = '';
        if (!empty($settings['github_username']) && !empty($settings['github_repo'])) {
            $repo_info = $settings['github_username'] . '/' . $settings['github_repo'] . ' (' . $settings['github_branch'] . ')';
        }
        ?>
        <div id="repo-info-banner" class="repo-info-banner">
            <div class="repo-info-icon">
                <span class="dashicons dashicons-github"></span>
            </div>
            <div class="repo-info-text">
                <?php if (!empty($repo_info)): ?>
                    Connected to: <strong><?php echo esc_html($repo_info); ?></strong>
                    <?php if (!empty($settings['github_username']) && !empty($settings['github_repo'])): ?>
                        <a href="https://github.com/<?php echo esc_attr($settings['github_username'] . '/' . $settings['github_repo']); ?>" 
                           target="_blank" class="repo-link">Open on GitHub</a>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="not-connected">Not connected to any repository. Please configure settings.</span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Отображение страницы настроек
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
            
            <?php if (empty($this->github_api->get_settings()['github_username']) || empty($this->github_api->get_settings()['github_repo'])): ?>
            <div class="gitpush-notice">
                <p>Please configure your GitHub settings to use GitPush WP.</p>
                <p>After saving settings, test your connection, then go to <a href="<?php echo admin_url('admin.php?page=gitpush-wp'); ?>">Sync Files</a> page to push/pull your theme files.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Отображение основной страницы синхронизации
     */
    public function render_main_page() {
        // Проверяем наличие настроек
        $settings = $this->github_api->get_settings();
        $is_configured = !empty($settings['github_username']) && 
                         !empty($settings['github_repo']) && 
                         !empty($settings['github_token']);
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
                        
                        <!-- Секция с историей коммитов -->
                        <div class="commit-history-section">
                            <h4>Commit History</h4>
                            <div class="commit-history-content"></div>
                        </div>
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
}