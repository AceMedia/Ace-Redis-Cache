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

        // In case the actual hook differs slightly (network admin, etc.), we allow any hook
        // containing our slug. The original strict equality may have prevented loading if the
        // suffix changed (e.g. multisite or translation context).
        if (strpos($hook, 'ace-redis-cache') === false) {
            return; // Not our page at all.
        }
        
        // Check if compiled assets exist, otherwise use inline styles
    $css_file = $this->plugin_url . 'assets/dist/admin-styles.min.css';
    $js_file = $this->plugin_url . 'assets/dist/admin.min.js';
        
        $css_path = str_replace($this->plugin_url, dirname(__DIR__) . '/', $css_file);
        $js_path  = str_replace($this->plugin_url, dirname(__DIR__) . '/', $js_file);

        // (Former debug logging removed after stabilization.)

        if (file_exists($css_path)) {
            wp_enqueue_style(
                'ace-redis-cache-admin',
                $css_file,
                [],
                $this->plugin_version
            );
            
            // Add inline CSS with admin color scheme variables
            wp_add_inline_style('ace-redis-cache-admin', $this->get_admin_color_scheme_css());
        }
        
        if (file_exists($js_path)) {
            wp_enqueue_script(
                'ace-redis-cache-admin',
                $js_file,
                ['jquery'],
                $this->plugin_version,
                true
            );
            
            // Localize script for AJAX - use our script handle and correct variable name
            $user_auto = get_user_meta(get_current_user_id(), 'ace_rc_auto_save_enabled', true);
            wp_localize_script('ace-redis-cache-admin', 'ace_redis_admin', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'rest_url' => rest_url(),
                'nonce' => wp_create_nonce('ace_redis_admin_nonce'),
                'rest_nonce' => wp_create_nonce('wp_rest'),
                'user_auto_save' => ($user_auto === '' ? null : (int) (bool) $user_auto)
            ]);
        } else {
            // Fallback to inline JavaScript if compiled version doesn't exist
            wp_enqueue_script('jquery');
            
            // Localize script for fallback too
            $user_auto = get_user_meta(get_current_user_id(), 'ace_rc_auto_save_enabled', true);
            wp_localize_script('jquery', 'ace_redis_admin', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'rest_url' => rest_url(),
                'nonce' => wp_create_nonce('ace_redis_admin_nonce'),
                'rest_nonce' => wp_create_nonce('wp_rest'),
                'user_auto_save' => ($user_auto === '' ? null : (int) (bool) $user_auto)
            ]);
            
            add_action('admin_footer', [$this, 'inline_admin_scripts']);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Ace-Redis-Cache] Using inline fallback (compiled JS not found).');
            }
        }
    }
    
    /**
     * Add inline admin scripts as fallback
     */
    public function inline_admin_scripts() {
        ?>
        <script>
        jQuery(function($) {
            // Show/hide rows based on dual toggles (fallback when compiled JS not present)
            function toggleBasedOnToggles() {
                const objectOn = $('#enable_object_cache').is(':checked');
                const $blockCachingRow = $('#block-caching-row');
                const $blockCachingCheckbox = $('input[name="ace_redis_cache_settings[enable_block_caching]"]');
                const $transientRow = $('#transient-cache-row');
                $blockCachingRow.toggle(!!objectOn);
                $transientRow.toggle(!!objectOn);
                if (!objectOn) { $blockCachingCheckbox.prop('checked', false); }
            }

            toggleBasedOnToggles();
            $(document).on('change', '#enable_object_cache', toggleBasedOnToggles);

            // Compression sub-options visibility (fallback)
            function toggleCompressionSubOptions() {
                const enabled = $('#enable_compression').is(':checked');
                const $field = $('#enable_compression').closest('.setting-field');
                if ($field.length) {
                    $field.find('.compression-methods').toggle(!!enabled);
                    $field.find('.compression-methods').next('p.description').toggle(!!enabled);
                }
            }
            toggleCompressionSubOptions();
            $(document).on('change', '#enable_compression', toggleCompressionSubOptions);

            // Basic TTL validation fallback
            function validateTTLField($field) {
                const v = parseInt($field.val(), 10);
                if (!v || v < 60) { $field.addClass('error'); } else { $field.removeClass('error'); }
            }
            $('#ttl_page, #ttl_object').on('blur', function(){ validateTTLField($(this)); });
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
                            <button type="button" id="ace-redis-cache-test-btn" class="button" <?php echo empty($this->settings['enabled']) ? 'disabled' : ''; ?>>Test Redis Connection</button>
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
                            <div id="ace-redis-inline-cache-actions" style="<?php echo empty($this->settings['enabled']) ? 'display:none;' : ''; ?>">
                                <button type="button" id="ace-redis-cache-flush-btn" class="button button-secondary">Clear All Cache</button>
                                <button type="button" id="ace-redis-cache-flush-blocks-btn" class="button button-secondary">Clear Block Cache</button>
                            </div>
                        </td>
                    </tr>
                </table>
                <script>
                (function(){
                    var enableInputs = document.querySelectorAll('input[name="ace_redis_cache_settings[enabled]"]');
                    var actions = document.getElementById('ace-redis-inline-cache-actions');
                    if (enableInputs.length && actions) {
                        var update = function(){
                            var checked = false;
                            enableInputs.forEach(function(el){ if (el.checked) checked = true; });
                            actions.style.display = checked ? '' : 'none';
                            var testBtn = document.getElementById('ace-redis-cache-test-btn');
                            if (testBtn) testBtn.disabled = !checked;
                        };
                        enableInputs.forEach(function(el){ el.addEventListener('change', update); });
                        update();
                    }
                })();
                </script>
                
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
        if (defined('WP_DEBUG') && WP_DEBUG && isset($input['enable_transient_cache'])) {
            error_log('Ace-Redis-Cache: admin sanitize incoming enable_transient_cache=' . (int)!empty($input['enable_transient_cache']));
        }
        
        $sanitized['enabled'] = !empty($input['enabled']) ? 1 : 0;
    $sanitized['mode'] = in_array($input['mode'] ?? 'full', ['full', 'object']) ? ($input['mode'] ?? 'full') : 'full';
        $sanitized['host'] = sanitize_text_field($input['host']);
        $sanitized['port'] = intval($input['port']);
        $sanitized['password'] = sanitize_text_field($input['password']);
    $sanitized['ttl'] = max(60, intval($input['ttl']));
    // New dual-cache toggles and TTLs
    $sanitized['enable_page_cache'] = !empty($input['enable_page_cache']) ? 1 : 0;
    $sanitized['enable_object_cache'] = !empty($input['enable_object_cache']) ? 1 : 0;
    $sanitized['ttl_page'] = max(60, intval($input['ttl_page'] ?? ($sanitized['ttl'] ?? 3600)));
    $sanitized['ttl_object'] = max(60, intval($input['ttl_object'] ?? ($sanitized['ttl'] ?? 3600)));
        $sanitized['enable_tls'] = !empty($input['enable_tls']) ? 1 : 0;
        $sanitized['enable_block_caching'] = !empty($input['enable_block_caching']) ? 1 : 0;
        $sanitized['enable_transient_cache'] = !empty($input['enable_transient_cache']) ? 1 : 0; 
        // Keep drop-in enabled whenever transient caching is enabled so transients persist
        $sanitized['enable_object_cache_dropin'] = !empty($sanitized['enable_transient_cache']) ? 1 : 0;
        // If object cache was enabled but transient flag missing from POST (unchecked or JS omission), preserve previous stored state
        if ($sanitized['enable_object_cache'] && !isset($input['enable_transient_cache'])) {
            $prev = get_option('ace_redis_cache_settings', []);
            if (isset($prev['enable_transient_cache'])) {
                $sanitized['enable_transient_cache'] = (int)!empty($prev['enable_transient_cache']);
                if (!empty($sanitized['enable_transient_cache'])) {
                    $sanitized['enable_object_cache_dropin'] = 1;
                }
            }
        }
        $sanitized['enable_minification'] = !empty($input['enable_minification']) ? 1 : 0;
        
        // Sanitize exclusion patterns
        $sanitized['custom_cache_exclusions'] = sanitize_textarea_field($input['custom_cache_exclusions'] ?? '');
        $sanitized['custom_transient_exclusions'] = sanitize_textarea_field($input['custom_transient_exclusions'] ?? '');
        $sanitized['custom_content_exclusions'] = sanitize_textarea_field($input['custom_content_exclusions'] ?? '');
        $sanitized['excluded_blocks'] = sanitize_textarea_field($input['excluded_blocks'] ?? '');
    $sanitized['exclude_basic_blocks'] = !empty($input['exclude_basic_blocks']) ? 1 : 0;
    // Unified dynamic runtime mode: treat excluded blocks as dynamic
    $sanitized['dynamic_excluded_blocks'] = !empty($input['dynamic_excluded_blocks']) ? 1 : 0;
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Ace-Redis-Cache: admin sanitize outgoing enable_transient_cache=' . ($sanitized['enable_transient_cache'] ?? 'NA') . ' dropin=' . ($sanitized['enable_object_cache_dropin'] ?? 'NA'));
        }
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
    
    /**
     * Get admin color scheme CSS variables
     *
     * @return string CSS variables based on user's admin color scheme
     */
    private function get_admin_color_scheme_css() {
        global $_wp_admin_css_colors;
        
        // Get current user's admin color scheme
        $admin_color_scheme = get_user_option('admin_color');
        if (!$admin_color_scheme || !isset($_wp_admin_css_colors[$admin_color_scheme])) {
            $admin_color_scheme = 'fresh'; // Default WordPress color scheme
        }
        
        // Get the actual colors from WordPress for the current scheme
        $scheme_data = $_wp_admin_css_colors[$admin_color_scheme] ?? $_wp_admin_css_colors['fresh'];
        $colors = $scheme_data->colors ?? [
            '#1d2327', // Base background
            '#2c3338', // Highlight background  
            '#2271b1', // Notification background
            '#72aee6'  // Highlight color
        ];
        
        // Map WordPress color positions to our variables
        // WordPress admin colors array: [base, highlight, notification, primary]
        $base_color = $colors[0] ?? '#1d2327';      // Base color (usually black/dark)
        $highlight_color = $colors[1] ?? '#FFFFFF'; // Highlight color (usually white/light)  
        $notification_color = $colors[2] ?? '#2271b1'; // Notification color (main theme color)
        $accent_color = $colors[3] ?? '#72aee6';    // Accent color (secondary theme color)
        
        // Generate colors based on the actual theme colors from the array
        $scheme_colors = [
            'base' => $base_color,           // Dynamic base color from theme
            'highlight' => $highlight_color, // Dynamic highlight color from theme 
            'primary' => $notification_color, // Dynamic primary color from theme
            'accent' => $accent_color,       // Dynamic accent color from theme
            'primary-hover' => $this->adjust_color_brightness($notification_color, -0.15),
            'sidebar' => $base_color,        // Use dynamic base color for sidebar/tabs
            'sidebar-light' => $this->adjust_color_brightness($base_color, 0.2),
            'success' => '#00a32a', // Keep consistent success color
            'error' => '#d63638',   // Keep consistent error color  
            'warning' => '#dba617', // Keep consistent warning color
            'info' => $highlight_color,
            'background' => ($admin_color_scheme === 'midnight') ? '#1e1e1e' : '#f0f0f1',
            'surface' => ($admin_color_scheme === 'midnight') ? '#2c2c2c' : '#ffffff',
            'text' => $base_color,           // Use dynamic base color for text
            'text-light' => $this->adjust_color_brightness($base_color, 0.3), // Lighter base color for secondary text
            'border' => $notification_color  // Use dynamic primary color for borders
        ];
        
        // Generate CSS variables
        $css = ":root {\n";
        $css .= "    /* Debug: Admin color scheme is '{$admin_color_scheme}' */\n";
        $css .= "    /* Debug: Colors are [" . implode(', ', $colors) . "] */\n";
        foreach ($scheme_colors as $name => $color) {
            $css .= "    --wp-admin-" . str_replace('_', '-', $name) . ": {$color};\n";
            
            // Add adjusted color variants for SCSS compatibility
            if (in_array($name, ['primary', 'sidebar', 'base', 'accent', 'success', 'error', 'border'])) {
                // Lighter variants
                $lighter = $this->adjust_color_brightness($color, 0.1);
                $css .= "    --wp-admin-" . str_replace('_', '-', $name) . "-light: {$lighter};\n";
                
                // Darker variants  
                $darker = $this->adjust_color_brightness($color, -0.1);
                $css .= "    --wp-admin-" . str_replace('_', '-', $name) . "-dark: {$darker};\n";
                
                // Extra dark variants
                $extra_dark = $this->adjust_color_brightness($color, -0.2);
                $css .= "    --wp-admin-" . str_replace('_', '-', $name) . "-extra-dark: {$extra_dark};\n";
            }
        }
        $css .= "}\n";
        
        return $css;
    }
    
    /**
     * Adjust color brightness
     */
    private function adjust_color_brightness($hex, $percent) {
        // Remove # if present
        $hex = ltrim($hex, '#');
        
        // Convert to RGB
        if (strlen($hex) === 3) {
            $hex = str_repeat(substr($hex,0,1), 2) . str_repeat(substr($hex,1,1), 2) . str_repeat(substr($hex,2,1), 2);
        }
        
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        // Adjust brightness
        $r = max(0, min(255, $r + ($r * $percent)));
        $g = max(0, min(255, $g + ($g * $percent)));
        $b = max(0, min(255, $b + ($b * $percent)));
        
        // Convert back to hex
        return sprintf('#%02x%02x%02x', round($r), round($g), round($b));
    }


    /**
     * Render simple cache health page
     */
    public function render_health_page() {
        if (!current_user_can('manage_options')) { wp_die('Insufficient permissions'); }
        $health = [ 'connected' => null, 'status' => 'unknown' ];
        $stats = [];
        $slow_ops = get_transient('ace_rc_slow_op_count');
        $slow_ops = $slow_ops !== false ? (int)$slow_ops : 0;
    $runtime_bypass = (isset($wp_object_cache) && method_exists($wp_object_cache,'is_bypassed') && $wp_object_cache->is_bypassed());
    $bypass = (defined('ACE_OC_BYPASS') && ACE_OC_BYPASS) || $runtime_bypass;
        $prof = defined('ACE_OC_PROF') && ACE_OC_PROF;
        // Attempt to derive connection info from global object cache
        global $wp_object_cache; $using_dropin = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        $dropin_connected = false;
        if ($using_dropin && isset($wp_object_cache) && is_object($wp_object_cache)) {
            if (method_exists($wp_object_cache, 'is_connected')) {
                $dropin_connected = (bool)$wp_object_cache->is_connected();
            } elseif (property_exists($wp_object_cache, 'redis') && isset($wp_object_cache->redis)) {
                // Heuristic: attempt lightweight info() call; if it succeeds we treat as connected
                try { $wp_object_cache->redis->ping(); $dropin_connected = true; } catch (\Throwable $t) { $dropin_connected = false; }
            }
        }
        $transport = 'unknown';
    if ($dropin_connected && isset($wp_object_cache->redis)) {
            try { $info = $wp_object_cache->redis->info(); $transport = !empty($info['redis_mode']) ? $info['redis_mode'] : 'tcp'; } catch (\Throwable $t) {}
        }
        // Basic environment
        $autoload_size = $this->estimate_autoload_size();
        ?>
        <div class="wrap">
            <h1>Ace Redis Cache Health</h1>
            <table class="widefat fixed striped" style="max-width:900px;">
                <tbody>
                    <tr><th>Object Cache Drop-In</th><td><?php echo $using_dropin ? 'Present' : 'Not Detected'; ?></td></tr>
                    <tr><th>Drop-In Connected</th><td><?php echo $dropin_connected ? '<span style="color:green">YES</span>' : '<span style="color:#cc0000">NO</span>'; ?></td></tr>
                    <tr><th>Transport</th><td><?php echo esc_html($transport); ?></td></tr>
                    <tr><th>Bypass Flag</th><td><?php echo $bypass ? '<span style="color:#cc0000">ACTIVE</span>' : 'off'; ?><?php if($runtime_bypass && !(defined('ACE_OC_BYPASS') && ACE_OC_BYPASS)) { echo ' <span style="color:#666;">(auto fail-open)</span>'; } ?></td></tr>
                    <?php if(isset($wp_object_cache) && method_exists($wp_object_cache,'connection_details')) { $cd = $wp_object_cache->connection_details(); if(!$cd['connected'] && !empty($cd['error'])) { ?>
                    <tr><th>Connection Error</th><td><code style="font-size:11px;"><?php echo esc_html($cd['error']); ?></code></td></tr>
                    <?php } elseif($cd['connected']) { ?>
                    <tr><th>Connection Via</th><td><?php echo esc_html($cd['via']); ?></td></tr>
                    <?php } } ?>
                    <tr><th>Profiling</th><td><?php echo $prof ? 'enabled' : 'disabled'; ?></td></tr>
                    <tr><th>Slow Ops (>=100ms)</th><td><?php echo (int)$slow_ops; ?></td></tr>
                    <tr><th>Autoload Option Size (approx)</th><td><?php echo esc_html(size_format($autoload_size)); ?></td></tr>
                </tbody>
            </table>
            <p class="description">Slow op counter increments when ACE_OC_PROF is enabled and an op exceeds threshold; stored transiently for visibility.</p>
        </div>
        <?php
    }

    private function estimate_autoload_size() {
        global $wpdb; $row = $wpdb->get_row("SELECT SUM(LENGTH(option_value)) AS sz FROM {$wpdb->options} WHERE autoload='yes'");
        return $row && isset($row->sz) ? (int)$row->sz : 0;
    }
}
