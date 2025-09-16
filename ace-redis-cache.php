<?php
/**
 * Plugin Name: Ace Redis Cache
 * Description: Smart Redis-powered full page and object caching for WordPress with configurable exclusions for external plugins.
 * Version: 0.3.0
 * Author: Ace Media
 */

if (!defined('ABSPATH')) exit;

// Polyfill for PHP < 8.0
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

class AceRedisCache {
    private $settings;
    private $cache_prefix = 'page_cache:'; // Used for counting and flushing cache keys
    
    // Excluded prefixes and patterns to avoid conflicts with other plugins
    private $excluded_cache_prefixes = [
        'betapi_',           // Bet API plugin cache keys
        'bet_api_',          // Bet API plugin cache keys
        'w3tc_',            // W3 Total Cache keys
        'litespeed_',       // LiteSpeed Cache keys
        'wp_rocket_',       // WP Rocket keys
        'ace_seo_',         // Ace SEO plugin keys
    ];
    
    private $excluded_transient_patterns = [
        'betapi_%',         // Bet API transients
        'racing_post_%',    // Racing Post API transients
        'bet_banner_%',     // Bet banner transients
        'naps_%',           // NAPs table transients
    ];

    public function __construct() {
        $this->settings = get_option('ace_redis_cache_settings', [
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'ttl' => 60,
            'mode' => 'full', // 'full' or 'object'
            'enabled' => 1,
            'exclude_transients' => 1, // New setting to exclude transients
            'exclude_external_plugins' => 1, // New setting to exclude external plugin data
            'custom_cache_exclusions' => "betapi_\nbet_api_\nw3tc_\nlitespeed_\nwp_rocket_\nace_seo_", // Custom cache key exclusions
            'custom_transient_exclusions' => "betapi_%\nracing_post_%\nbet_banner_%\nnaps_%\nwoocommerce_%\ncart_%", // Custom transient exclusions
            'custom_content_exclusions' => "bet-api/bet-banner\nbet-api/naps\n[bet-api\n[naps\ndata-bet-banner\nclass=\"naps-table\"", // Custom content exclusions
        ]);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // AJAX handlers for status and flushing cache
        add_action('wp_ajax_ace_redis_cache_status', [$this, 'ajax_status']);
        add_action('wp_ajax_ace_redis_cache_flush', [$this, 'ajax_flush']);

        // Admin scripts
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue']);

        if (!is_admin() && $this->settings['enabled']) {
            if ($this->settings['mode'] === 'full') {
                $this->setup_full_page_cache();
            } else {
                $this->setup_object_cache();
            }
        }
        
        // Add WordPress hooks to intercept transient and cache operations
        if ($this->settings['enabled'] && ($this->settings['exclude_transients'] ?? 1)) {
            $this->setup_transient_exclusions();
        }
        
        if ($this->settings['enabled'] && ($this->settings['exclude_external_plugins'] ?? 1)) {
            $this->setup_plugin_exclusions();
        }
    }
    
    /**
     * Get cache key exclusions from settings
     */
    private function get_cache_exclusions() {
        $exclusions = [];
        
        // Get custom exclusions from settings
        $custom_exclusions = $this->settings['custom_cache_exclusions'] ?? '';
        if (!empty($custom_exclusions)) {
            $lines = explode("\n", $custom_exclusions);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && !str_starts_with($line, '#')) {
                    $exclusions[] = $line;
                }
            }
        }
        
        // Add default fallbacks if no custom exclusions
        if (empty($exclusions)) {
            $exclusions = $this->excluded_cache_prefixes;
        }
        
        return $exclusions;
    }
    
    /**
     * Get transient exclusions from settings
     */
    private function get_transient_exclusions() {
        $exclusions = [];
        
        // Get custom exclusions from settings
        $custom_exclusions = $this->settings['custom_transient_exclusions'] ?? '';
        if (!empty($custom_exclusions)) {
            $lines = explode("\n", $custom_exclusions);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && !str_starts_with($line, '#')) {
                    $exclusions[] = $line;
                }
            }
        }
        
        // Add default fallbacks if no custom exclusions
        if (empty($exclusions)) {
            $exclusions = $this->excluded_transient_patterns;
        }
        
        return $exclusions;
    }
    
    /**
     * Get content exclusions from settings
     */
    private function get_content_exclusions() {
        $exclusions = [];
        
        // Get custom exclusions from settings
        $custom_exclusions = $this->settings['custom_content_exclusions'] ?? '';
        if (!empty($custom_exclusions)) {
            $lines = explode("\n", $custom_exclusions);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && !str_starts_with($line, '#')) {
                    $exclusions[] = $line;
                }
            }
        }
        
        return $exclusions;
    }
    
    /**
     * Check if a cache key should be excluded from Redis caching
     */
    private function should_exclude_cache_key($key) {
        if (!($this->settings['exclude_external_plugins'] ?? 1)) {
            return false;
        }
        
        $exclusions = $this->get_cache_exclusions();
        foreach ($exclusions as $prefix) {
            if (strpos($key, $prefix) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if a transient should be excluded from Redis caching
     */
    private function should_exclude_transient($transient) {
        if (!($this->settings['exclude_transients'] ?? 1)) {
            return false;
        }
        
        $exclusions = $this->get_transient_exclusions();
        foreach ($exclusions as $pattern) {
            if (fnmatch($pattern, $transient)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Setup exclusions for transient operations
     */
    private function setup_transient_exclusions() {
        // Intercept set_transient calls for excluded patterns
        add_filter('pre_set_transient', [$this, 'filter_set_transient'], 10, 3);
        add_filter('pre_get_transient', [$this, 'filter_get_transient'], 10, 2);
        add_filter('pre_delete_transient', [$this, 'filter_delete_transient'], 10, 2);
    }
    
    /**
     * Setup exclusions for external plugin cache operations
     */
    private function setup_plugin_exclusions() {
        // Intercept wp_cache operations for excluded keys
        add_filter('pre_wp_cache_get', [$this, 'filter_wp_cache_get'], 10, 3);
        add_filter('pre_wp_cache_set', [$this, 'filter_wp_cache_set'], 10, 5);
        add_filter('pre_wp_cache_delete', [$this, 'filter_wp_cache_delete'], 10, 3);
    }
    
    /**
     * Filter set_transient to exclude certain patterns
     */
    public function filter_set_transient($value, $transient, $expiration) {
        if ($this->should_exclude_transient($transient)) {
            // Let WordPress handle this transient normally (database)
            return false; // Continue with normal processing
        }
        return $value; // Let Redis handle it
    }
    
    /**
     * Filter get_transient to exclude certain patterns
     */
    public function filter_get_transient($value, $transient) {
        if ($this->should_exclude_transient($transient)) {
            // Let WordPress handle this transient normally (database)
            return false; // Continue with normal processing
        }
        return $value; // Let Redis handle it
    }
    
    /**
     * Filter delete_transient to exclude certain patterns
     */
    public function filter_delete_transient($value, $transient) {
        if ($this->should_exclude_transient($transient)) {
            // Let WordPress handle this transient normally (database)
            return false; // Continue with normal processing
        }
        return $value; // Let Redis handle it
    }
    
    /**
     * Filter wp_cache_get to exclude certain keys
     */
    public function filter_wp_cache_get($value, $key, $group) {
        if ($this->should_exclude_cache_key($key)) {
            // Let WordPress handle this cache key normally
            return false; // Continue with normal processing
        }
        return $value; // Let Redis handle it
    }
    
    /**
     * Filter wp_cache_set to exclude certain keys
     */
    public function filter_wp_cache_set($value, $key, $data, $group, $expire) {
        if ($this->should_exclude_cache_key($key)) {
            // Let WordPress handle this cache key normally
            return false; // Continue with normal processing
        }
        return $value; // Let Redis handle it
    }
    
    /**
     * Filter wp_cache_delete to exclude certain keys
     */
    public function filter_wp_cache_delete($value, $key, $group) {
        if ($this->should_exclude_cache_key($key)) {
            // Let WordPress handle this cache key normally
            return false; // Continue with normal processing
        }
        return $value; // Let Redis handle it
    }

    /** Admin Settings Page **/
    public function admin_menu() {
        add_options_page('Ace Redis Cache', 'Ace Redis Cache', 'manage_options', 'ace-redis-cache', [$this, 'settings_page']);
    }

    public function register_settings() {
        register_setting('ace_redis_cache_group', 'ace_redis_cache_settings');
    }

    public function admin_enqueue($hook) {
        if ($hook !== 'settings_page_ace-redis-cache') return;
        wp_enqueue_script('ace-redis-cache-admin', plugins_url('admin.js', __FILE__), ['jquery'], null, true);
        wp_localize_script('ace-redis-cache-admin', 'AceRedisCacheAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ace_redis_cache_status'),
            'flush_nonce' => wp_create_nonce('ace_redis_cache_flush')
        ]);
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Ace Redis Cache</h1>
            
            <div class="notice notice-info">
                <p><strong>Smart Exclusions Active:</strong> This cache is configured to avoid conflicts with the bet-api plugin and other external plugins. 
                Bet-api data and transients will be handled by their respective plugins, while basic WordPress content is cached in Redis for optimal performance.</p>
            </div>
            
            <div id="ace-redis-cache-status" style="margin-bottom:1em;">
                <button id="ace-redis-cache-test-btn" class="button">Test Redis Connection</button>
                <button id="ace-redis-cache-flush-btn" class="button">Flush Cache</button>
                <span id="ace-redis-cache-connection"></span>
                <br>
                <strong>Cache Size:</strong> <span id="ace-redis-cache-size">-</span>
            </div>
            <form method="post" action="options.php">
                <?php settings_fields('ace_redis_cache_group'); ?>
                <?php $opts = get_option('ace_redis_cache_settings', $this->settings); ?>
                <table class="form-table">
                    <tr><th>Enable Cache</th><td><input type="checkbox" name="ace_redis_cache_settings[enabled]" value="1" <?php checked($opts['enabled'], 1); ?>></td></tr>
                    <tr><th>Redis Host</th><td><input type="text" name="ace_redis_cache_settings[host]" value="<?php echo esc_attr($opts['host']); ?>"></td></tr>
                    <tr><th>Redis Port</th><td><input type="number" name="ace_redis_cache_settings[port]" value="<?php echo esc_attr($opts['port']); ?>"></td></tr>
                    <tr><th>Redis Password</th><td><input type="password" name="ace_redis_cache_settings[password]" value="<?php echo esc_attr($opts['password']); ?>"></td></tr>
                    <tr><th>Cache TTL (seconds)</th><td><input type="number" name="ace_redis_cache_settings[ttl]" value="<?php echo esc_attr($opts['ttl']); ?>"></td></tr>
                    <tr><th>Cache Mode</th>
                        <td>
                            <select name="ace_redis_cache_settings[mode]">
                                <option value="full" <?php selected($opts['mode'], 'full'); ?>>Full Page Cache</option>
                                <option value="object" <?php selected($opts['mode'], 'object'); ?>>Object Cache Only</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Exclude Transients</th>
                        <td>
                            <input type="checkbox" name="ace_redis_cache_settings[exclude_transients]" value="1" <?php checked($opts['exclude_transients'] ?? 1, 1); ?>>
                            <p class="description">Exclude WordPress transients from Redis caching to prevent conflicts with plugins that manage their own transients.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Exclude External Plugin Data</th>
                        <td>
                            <input type="checkbox" name="ace_redis_cache_settings[exclude_external_plugins]" value="1" <?php checked($opts['exclude_external_plugins'] ?? 1, 1); ?>>
                            <p class="description">Exclude cache data from bet-api, W3TC, and other external plugins to prevent conflicts. <strong>Recommended: Keep enabled.</strong></p>
                        </td>
                    </tr>
                </table>
                
                <h2>Custom Exclusion Patterns</h2>
                <p>Configure custom patterns to exclude from Redis caching. Each pattern should be on a new line. Lines starting with # are treated as comments.</p>
                
                <table class="form-table">
                    <tr>
                        <th>Cache Key Exclusions</th>
                        <td>
                            <textarea name="ace_redis_cache_settings[custom_cache_exclusions]" rows="8" cols="50" style="width: 100%; max-width: 500px;"><?php echo esc_textarea($opts['custom_cache_exclusions'] ?? ''); ?></textarea>
                            <p class="description">
                                Exclude cache keys starting with these prefixes. One per line.<br>
                                <strong>Examples:</strong> <code>betapi_</code>, <code>woocommerce_</code>, <code>my_plugin_</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Transient Exclusions</th>
                        <td>
                            <textarea name="ace_redis_cache_settings[custom_transient_exclusions]" rows="8" cols="50" style="width: 100%; max-width: 500px;"><?php echo esc_textarea($opts['custom_transient_exclusions'] ?? ''); ?></textarea>
                            <p class="description">
                                Exclude transients matching these patterns. Supports wildcards (*). One per line.<br>
                                <strong>Examples:</strong> <code>betapi_%</code>, <code>wc_cart_%</code>, <code>my_plugin_*</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Content Exclusions</th>
                        <td>
                            <textarea name="ace_redis_cache_settings[custom_content_exclusions]" rows="8" cols="50" style="width: 100%; max-width: 500px;"><?php echo esc_textarea($opts['custom_content_exclusions'] ?? ''); ?></textarea>
                            <p class="description">
                                Exclude pages containing these strings in their content. One per line.<br>
                                <strong>Examples:</strong> <code>bet-api/bet-banner</code>, <code>[shortcode</code>, <code>class="dynamic-content"</code>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
                
                <p>
                    <button type="button" id="reset-exclusions" class="button button-secondary">Reset to Default Exclusions</button>
                    <span style="margin-left: 10px; font-style: italic;">This will restore the default bet-api and plugin exclusion patterns.</span>
                </p>
            </form>
            
            <div class="card" style="margin-top: 20px; padding: 15px;">
                <h2>Dynamic Exclusion System</h2>
                
                <div style="display: flex; gap: 20px; margin-top: 15px;">
                    <div style="flex: 1;">
                        <h3 style="color: green;">✅ Cached by Redis</h3>
                        <ul>
                            <li>WordPress pages, posts, and custom post types</li>
                            <li>Static content and media files</li>
                            <li>Theme assets and standard plugin data</li>
                            <li>Search results and archive pages</li>
                            <li>Any content not matching exclusion patterns</li>
                        </ul>
                    </div>
                    
                    <div style="flex: 1;">
                        <h3 style="color: red;">❌ Excluded from Redis</h3>
                        <ul>
                            <li><strong>Custom Cache Keys</strong>: Keys matching your configured prefixes</li>
                            <li><strong>Custom Transients</strong>: Transients matching your configured patterns</li>
                            <li><strong>Dynamic Content</strong>: Pages containing your configured content patterns</li>
                            <li><strong>User-Specific Pages</strong>: Logged-in user content</li>
                            <li><strong>Admin Areas</strong>: WordPress admin and dashboard</li>
                            <li><strong>API Endpoints</strong>: AJAX and proxy requests</li>
                        </ul>
                    </div>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: #f0f8ff; border-left: 4px solid #0073aa;">
                    <h4>💡 Pro Tips for Custom Exclusions</h4>
                    <ul style="margin: 10px 0;">
                        <li><strong>Cache Keys</strong>: Use plugin prefixes like <code>myplugin_</code> to exclude entire plugin cache systems</li>
                        <li><strong>Transients</strong>: Use wildcards like <code>cart_%</code> to match dynamic transient names</li>
                        <li><strong>Content</strong>: Use block names, shortcodes, or CSS classes to identify dynamic content</li>
                        <li><strong>Comments</strong>: Add lines starting with <code>#</code> to document your exclusion patterns</li>
                    </ul>
                </div>
                
                <p style="margin-top: 15px; font-style: italic;">
                    This dynamic exclusion system makes Ace Redis Cache compatible with any WordPress plugin while maintaining optimal performance for cacheable content.
                </p>
            </div>
        </div>
        
        <script>
        jQuery(function($) {
            // Existing connection test and flush functionality
            $('#ace-redis-cache-test-btn').on('click', function(e) {
                e.preventDefault();
                $.post(AceRedisCacheAjax.ajax_url, {action:'ace_redis_cache_status', nonce:AceRedisCacheAjax.nonce}, function(res) {
                    if(res.success) {
                        $('#ace-redis-cache-connection').text(res.data.status);
                        $('#ace-redis-cache-size').text(res.data.size + ' keys (' + res.data.size_kb + ' KB)');
                    }
                });
            });
            $('#ace-redis-cache-flush-btn').on('click', function(e) {
                e.preventDefault();
                $.post(AceRedisCacheAjax.ajax_url, {action:'ace_redis_cache_flush', nonce:AceRedisCacheAjax.flush_nonce}, function(res) {
                    alert(res.success ? 'Cache flushed!' : 'Failed to flush cache');
                    $('#ace-redis-cache-test-btn').click();
                });
            });
            
            // Reset exclusions to defaults
            $('#reset-exclusions').on('click', function(e) {
                e.preventDefault();
                if (confirm('Reset exclusion patterns to defaults? This will overwrite your current custom patterns.')) {
                    $('textarea[name="ace_redis_cache_settings[custom_cache_exclusions]"]').val('betapi_\nbet_api_\nw3tc_\nlitespeed_\nwp_rocket_\nace_seo_');
                    $('textarea[name="ace_redis_cache_settings[custom_transient_exclusions]"]').val('betapi_%\nracing_post_%\nbet_banner_%\nnaps_%\nwoocommerce_%\ncart_%');
                    $('textarea[name="ace_redis_cache_settings[custom_content_exclusions]"]').val('bet-api/bet-banner\nbet-api/naps\n[bet-api\n[naps\ndata-bet-banner\nclass="naps-table"');
                    alert('Default exclusion patterns restored! Remember to save your settings.');
                }
            });
        });
        </script>
        <?php
    }

    /** AJAX: Connection status **/
    public function ajax_status() {
        check_ajax_referer('ace_redis_cache_status', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('No permission');

        $settings = get_option('ace_redis_cache_settings', $this->settings);
        try {
            $redis = $this->connect_redis();
            $status = $redis ? 'Connected' : 'Not connected';
            $keys = $redis ? $redis->keys($this->cache_prefix . '*') : [];
            $size = is_array($keys) ? count($keys) : 0;

            $totalBytes = 0;
            if ($redis && is_array($keys)) {
                foreach ($keys as $key) {
                    $len = $redis->strlen($key);
                    if ($len !== false) $totalBytes += $len;
                }
            }
            $size_kb = round($totalBytes / 1024, 2);

            wp_send_json_success([
                'status' => $status,
                'size'   => $size,
                'size_kb' => $size_kb,
            ]);
        } catch (Exception $e) {
            wp_send_json_success(['status'=>'Not connected','size'=>0,'size_kb'=>0]);
        }
    }

    /** AJAX: Flush Cache **/
    public function ajax_flush() {
        check_ajax_referer('ace_redis_cache_flush', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('No permission');

        try {
            $redis = $this->connect_redis();
            if ($redis) {
                $keys = $redis->keys($this->cache_prefix . '*');
                if ($keys) $redis->del($keys);
                wp_send_json_success(true);
            }
        } catch (Exception $e) {
            wp_send_json_error(false);
        }
    }

    /** Full Page Cache **/
    private function setup_full_page_cache() {
        add_action('template_redirect', function () {
            if (is_user_logged_in() || $_SERVER['REQUEST_METHOD'] !== 'GET') return;
            
            // Exclude pages with dynamic bet-api content
            if ($this->should_exclude_from_page_cache()) {
                return;
            }

            $redis = $this->connect_redis();
            if (!$redis) return;

            $cacheKey = $this->cache_prefix . md5($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);

            if ($cached = $redis->get($cacheKey)) {
                header('X-Cache: HIT');
                echo $cached;
                exit;
            }

            // Capture page content
            ob_start();
            header('X-Cache: MISS');

            add_action('shutdown', function () use ($redis, $cacheKey) {
                $buffer = ob_get_clean();
                if ($buffer !== false) {
                    echo $buffer;
                    
                    // Double-check we shouldn't cache this content before storing
                    if (!$this->should_exclude_content_from_cache($buffer)) {
                        $redis->setex($cacheKey, intval($this->settings['ttl']), $buffer);
                        $redis->ping();
                    }
                }
            }, 0);
        }, 0);
    }
    
    /**
     * Check if current page should be excluded from page caching
     */
    private function should_exclude_from_page_cache() {
        global $post;
        
        // Always exclude if external plugin exclusions are enabled
        if (!($this->settings['exclude_external_plugins'] ?? 1)) {
            return false;
        }
        
        // Check custom content exclusions in post content
        if ($post) {
            $exclusions = $this->get_content_exclusions();
            foreach ($exclusions as $pattern) {
                if (strpos($post->post_content, $pattern) !== false) {
                    return true;
                }
            }
        }
        
        // Exclude API proxy and ajax requests
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($request_uri, '/bet-api/') !== false ||
            strpos($request_uri, '/api/proxy.php') !== false ||
            strpos($request_uri, 'wp-admin/admin-ajax.php') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if content should be excluded from caching based on its content
     */
    private function should_exclude_content_from_cache($content) {
        if (!($this->settings['exclude_external_plugins'] ?? 1)) {
            return false;
        }
        
        // Check custom content exclusions from settings
        $exclusions = $this->get_content_exclusions();
        foreach ($exclusions as $pattern) {
            if (strpos($content, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /** Object Cache Placeholder **/
    private function setup_object_cache() {
        add_action('init', function () {
            header('X-Cache-Mode: Object');
        });
    }

    /** Redis Connection **/
    private function connect_redis() {
    try {
        $redis = new Redis();
        $host = $this->settings['host'];
        $port = intval($this->settings['port']);
        $timeout = 1.5;

        if (stripos($host, 'tls://') === 0) {
            $redis->setOption(Redis::OPT_READ_TIMEOUT, $timeout);
            // Only pass SSL context, no extra zero args
            $redis->connect($host, $port, $timeout, null, 0, [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ]
            ]);
        } else {
            $redis->connect($host, $port, $timeout);
        }

        if (!empty($this->settings['password'])) {
            $redis->auth($this->settings['password']);
        }

        if (!$redis->ping()) {
            return false;
        }

        return $redis;
    } catch (Exception $e) {
        error_log('Redis connect failed: ' . $e->getMessage());
        return false;
    }
}

}

new AceRedisCache();
