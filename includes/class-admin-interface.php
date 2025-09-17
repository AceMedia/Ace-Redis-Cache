<?php
/**
 * Admin Interface Class
 * 
 * Handles the WordPress admin interface, settings page,
 * and admin-related functionality.
 *
 * @package AceMedia\RedisCache
 * @since 0.5.0
 */

namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) exit;

class AdminInterface {
    
    private $cache_manager;
    private $settings;
    private $plugin_url;
    private $plugin_version;
    
    /**
     * Constructor
     *
     * @param CacheManager $cache_manager Cache manager instance
     * @param array $settings Plugin settings
     * @param string $plugin_url Plugin URL
     * @param string $plugin_version Plugin version
     */
    public function __construct($cache_manager, $settings, $plugin_url, $plugin_version) {
        $this->cache_manager = $cache_manager;
        $this->settings = $settings;
        $this->plugin_url = $plugin_url;
        $this->plugin_version = $plugin_version;
    }
    
    /**
     * Setup admin hooks
     */
    public function setup_hooks() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_notices', [$this, 'show_version_notice']);
        
        // Note: Removed update_option hook - now using AJAX save
    }
    
    /**
     * Add admin menu
     */
    public function admin_menu() {
        add_options_page(
            'Ace Redis Cache Settings',
            'Ace Redis Cache',
            'manage_options',
            'ace-redis-cache',
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting(
            'ace_redis_cache_settings',
            'ace_redis_cache_settings',
            [
                'sanitize_callback' => [$this, 'sanitize_settings']
            ]
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_ace-redis-cache') {
            return;
        }
        
        // Check if compiled assets exist, otherwise use inline styles
        $css_file = $this->plugin_url . 'assets/dist/admin-styles.min.css';
        $js_file = $this->plugin_url . 'assets/dist/admin.min.js';
        $savebar_js_file = $this->plugin_url . 'assets/src/js/components/SaveBar.js';
        
        if (file_exists(str_replace($this->plugin_url, dirname(__DIR__) . '/', $css_file))) {
            wp_enqueue_style(
                'ace-redis-cache-admin',
                $css_file,
                [],
                $this->plugin_version
            );
        }
        
        // Enqueue SaveBar component first (so it's available to main script)
        if (file_exists(str_replace($this->plugin_url, dirname(__DIR__) . '/', $savebar_js_file))) {
            wp_enqueue_script(
                'ace-redis-cache-savebar',
                $savebar_js_file,
                ['jquery'],
                $this->plugin_version,
                true
            );
        }
        
        if (file_exists(str_replace($this->plugin_url, dirname(__DIR__) . '/', $js_file))) {
            wp_enqueue_script(
                'ace-redis-cache-admin',
                $js_file,
                ['jquery', 'ace-redis-cache-savebar'], // Depend on SaveBar component
                $this->plugin_version,
                true
            );
            
            // Localize script for AJAX - use our script handle and correct variable name
            wp_localize_script('ace-redis-cache-admin', 'ace_redis_admin', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'rest_url' => rest_url(),
                'nonce' => wp_create_nonce('ace_redis_admin_nonce'),
                'rest_nonce' => wp_create_nonce('wp_rest')
            ]);
        } else {
            // Fallback to inline JavaScript if compiled version doesn't exist
            wp_enqueue_script('jquery');
            
            // Enqueue SaveBar component in fallback mode too
            if (file_exists(str_replace($this->plugin_url, dirname(__DIR__) . '/', $savebar_js_file))) {
                wp_enqueue_script(
                    'ace-redis-cache-savebar-fallback',
                    $savebar_js_file,
                    ['jquery'],
                    $this->plugin_version,
                    true
                );
            }
            
            // Localize script for fallback too
            wp_localize_script('jquery', 'ace_redis_admin', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'rest_url' => rest_url(),
                'nonce' => wp_create_nonce('ace_redis_admin_nonce'),
                'rest_nonce' => wp_create_nonce('wp_rest')
            ]);
            
            add_action('admin_footer', [$this, 'inline_admin_scripts']);
        }
    }
    
    /**
     * Add inline admin scripts as fallback
     */
    public function inline_admin_scripts() {
        ?>
        <script>
        jQuery(function($) {
            // Show/hide block caching option based on cache mode
            function toggleBlockCachingOption() {
                const cacheMode = $('#cache-mode-select').val();
                const blockCachingRow = $('#block-caching-row');
                const blockCachingCheckbox = $('input[name="ace_redis_cache_settings[enable_block_caching]"]');
                
                if (cacheMode === 'object') {
                    blockCachingRow.show();
                } else {
                    blockCachingRow.hide();
                    blockCachingCheckbox.prop('checked', false);
                }
            }
            
            // Initialize on page load
            toggleBlockCachingOption();
            
            // Handle cache mode changes
            $('#cache-mode-select').on('change', toggleBlockCachingOption);
        });
        </script>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        $template_file = dirname(__DIR__) . '/admin/views/settings-page.php';
        
        if (file_exists($template_file)) {
            // Pass variables to template
            $settings = $this->settings;
            $cache_manager = $this->cache_manager;
            include $template_file;
        } else {
            // Fallback inline template if separate file doesn't exist
            $this->render_inline_settings_page();
        }
    }
    
    /**
     * Render inline settings page as fallback
     */
    private function render_inline_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        ?>
        <div class="wrap">
            <h1>Ace Redis Cache Settings</h1>
            
            <?php settings_errors(); ?>
            
            <div class="notice notice-info">
                <p><strong>Plugin Refactored!</strong> This is the new modular version of Ace Redis Cache v0.5.0 with improved architecture and modern build tools.</p>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('ace_redis_cache_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Cache</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ace_redis_cache_settings[enabled]" value="1" <?php checked($this->settings['enabled']); ?> />
                                Enable Redis caching
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Cache Mode</th>
                        <td>
                            <select name="ace_redis_cache_settings[mode]" id="cache-mode-select">
                                <option value="full" <?php selected($this->settings['mode'], 'full'); ?>>Full Page Cache</option>
                                <option value="object" <?php selected($this->settings['mode'], 'object'); ?>>Object Cache Only</option>
                            </select>
                            <p class="description">Full page cache provides maximum performance but may conflict with dynamic content.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Redis Host</th>
                        <td>
                            <input type="text" name="ace_redis_cache_settings[host]" value="<?php echo esc_attr($this->settings['host']); ?>" class="regular-text" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Redis Port</th>
                        <td>
                            <input type="number" name="ace_redis_cache_settings[port]" value="<?php echo esc_attr($this->settings['port']); ?>" min="1" max="65535" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Password</th>
                        <td>
                            <input type="password" name="ace_redis_cache_settings[password]" value="<?php echo esc_attr($this->settings['password']); ?>" class="regular-text" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Cache TTL (seconds)</th>
                        <td>
                            <input type="number" name="ace_redis_cache_settings[ttl]" value="<?php echo esc_attr($this->settings['ttl']); ?>" min="60" max="604800" />
                            <p class="description">Default: 3600 seconds (1 hour)</p>
                        </td>
                    </tr>
                    
                    <tr id="block-caching-row" style="display: none;">
                        <th scope="row">Block Caching</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ace_redis_cache_settings[enable_block_caching]" value="1" <?php checked($this->settings['enable_block_caching'] ?? 0); ?> />
                                Enable WordPress Block API caching
                            </label>
                            <p class="description">Cache individual Gutenberg blocks for improved performance (Object Cache mode only)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Minification</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ace_redis_cache_settings[enable_minification]" value="1" <?php checked($this->settings['enable_minification'] ?? 0); ?> />
                                Enable HTML/CSS/JS minification
                            </label>
                        </td>
                    </tr>
                </table>
                
                <h2>Connection Test</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Redis Status</th>
                        <td>
                            <button type="button" id="ace-redis-cache-test-btn" class="button">Test Redis Connection</button>
                            <p><strong>Status:</strong> <span id="ace-redis-cache-connection">Unknown</span></p>
                            <p><strong>Cache Size:</strong> <span id="ace-redis-cache-size">Unknown</span></p>
                        </td>
                    </tr>
                </table>
                
                <h2>Cache Management</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Clear Cache</th>
                        <td>
                            <button type="button" id="ace-redis-cache-flush-btn" class="button button-secondary">Clear All Cache</button>
                            <button type="button" id="ace-redis-cache-flush-blocks-btn" class="button button-secondary">Clear Block Cache</button>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        
        <style>
        .ace-redis-settings .notice {
            margin: 20px 0;
            padding: 15px;
            border-left: 4px solid #0073aa;
            background: #f7f7f7;
        }
        
        .ace-redis-settings .button-group {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .ace-redis-settings .status-success {
            color: #46b450;
            font-weight: bold;
        }
        
        .ace-redis-settings .status-error {
            color: #dc3232;
            font-weight: bold;
        }
        </style>
        <?php
    }
    
    /**
     * Show version notice for updates
     */
    public function show_version_notice() {
        $screen = get_current_screen();
        if ($screen->id !== 'settings_page_ace-redis-cache') {
            return;
        }

        // Check if this version notice has been dismissed
        $dismissed_version = get_option('ace_redis_cache_dismissed_version', '');
        if ($dismissed_version === $this->plugin_version) {
            return;
        }

        // Only show the notice once per version, and only if it's a significant update (0.5.0)
        $shown_version = get_option('ace_redis_cache_shown_version', '');
        if ($shown_version === $this->plugin_version) {
            return;
        }

        // Mark this version as shown
        update_option('ace_redis_cache_shown_version', $this->plugin_version);

        ?>
        <div class="notice notice-info is-dismissible ace-redis-update-notice" data-notice="ace-redis-version">
            <p>
                <strong>🚀 Plugin Refactored!</strong> Welcome to Ace Redis Cache v<?php echo $this->plugin_version; ?> with modern modular architecture, improved performance monitoring, and professional development workflow.
            </p>
            <p><strong>New in this version:</strong> Modular codebase, asset compilation, better error handling, and enhanced diagnostics.</p>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.ace-redis-update-notice .notice-dismiss').on('click', function() {
                $.post(ajaxurl, {
                    action: 'ace_redis_dismiss_notice',
                    version: '<?php echo $this->plugin_version; ?>',
                    nonce: '<?php echo wp_create_nonce("ace_redis_dismiss"); ?>'
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Sanitize settings input
     *
     * @param array $input Raw input data
     * @return array Sanitized settings
     */
    public function sanitize_settings($input) {
        $sanitized = [];
        
        $sanitized['enabled'] = !empty($input['enabled']) ? 1 : 0;
        $sanitized['mode'] = in_array($input['mode'] ?? 'full', ['full', 'object']) ? ($input['mode'] ?? 'full') : 'full';
        $sanitized['host'] = sanitize_text_field($input['host']);
        $sanitized['port'] = intval($input['port']);
        $sanitized['password'] = sanitize_text_field($input['password']);
        $sanitized['ttl'] = max(60, intval($input['ttl']));
        $sanitized['enable_tls'] = !empty($input['enable_tls']) ? 1 : 0;
        $sanitized['enable_block_caching'] = !empty($input['enable_block_caching']) ? 1 : 0;
        $sanitized['enable_minification'] = !empty($input['enable_minification']) ? 1 : 0;
        
        // Sanitize exclusion patterns
        $sanitized['custom_cache_exclusions'] = sanitize_textarea_field($input['custom_cache_exclusions'] ?? '');
        $sanitized['custom_transient_exclusions'] = sanitize_textarea_field($input['custom_transient_exclusions'] ?? '');
        $sanitized['custom_content_exclusions'] = sanitize_textarea_field($input['custom_content_exclusions'] ?? '');
        $sanitized['excluded_blocks'] = sanitize_textarea_field($input['excluded_blocks'] ?? '');
        
        return $sanitized;
    }
    
    /**
     * Get admin page URL
     *
     * @return string Admin page URL
     */
    public function get_admin_url() {
        return admin_url('options-general.php?page=ace-redis-cache');
    }
}
