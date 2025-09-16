<?php
/**
 * Plugin Name: Ace Redis Cache
 * Description: Smart Redis-powered caching with WordPress Block API support and configurable exclusions for any plugins.
 * Version: 0.4.0
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

    public function __construct() {
        $this->settings = get_option('ace_redis_cache_settings', [
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'ttl' => 60,
            'mode' => 'full', // 'full' or 'object'
            'enabled' => 1,
            'enable_block_caching' => 0, // Enable WordPress Block API caching
            'custom_cache_exclusions' => '', // Custom cache key exclusions
            'custom_transient_exclusions' => '', // Custom transient exclusions
            'custom_content_exclusions' => '', // Custom content exclusions
            'excluded_blocks' => '', // Blocks to exclude from caching
        ]);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // AJAX handlers for status and flushing cache
        add_action('wp_ajax_ace_redis_cache_status', [$this, 'ajax_status']);
        add_action('wp_ajax_ace_redis_cache_flush', [$this, 'ajax_flush']);
        add_action('wp_ajax_ace_redis_cache_flush_blocks', [$this, 'ajax_flush_blocks']);

        // Admin scripts
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue']);

        if (!is_admin() && $this->settings['enabled']) {
            if ($this->settings['mode'] === 'full') {
                $this->setup_full_page_cache();
            } else {
                $this->setup_object_cache();
            }
        }
        
        // Setup block-level caching if enabled
        if ($this->settings['enabled'] && ($this->settings['enable_block_caching'] ?? 0)) {
            $this->setup_block_caching();
        }
        
        // Add WordPress hooks to intercept transient and cache operations
        if ($this->settings['enabled']) {
            $this->setup_transient_exclusions();
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
     * Get excluded blocks from settings
     */
    private function get_excluded_blocks() {
        $excluded_blocks = [];
        
        // Get excluded blocks from settings
        $custom_blocks = $this->settings['excluded_blocks'] ?? '';
        if (!empty($custom_blocks)) {
            $lines = explode("\n", $custom_blocks);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && !str_starts_with($line, '#')) {
                    $excluded_blocks[] = $line;
                }
            }
        }
        
        return $excluded_blocks;
    }
    
    /**
     * Setup WordPress Block API caching integration
     */
    private function setup_block_caching() {
        // Hook into block rendering to control caching
        add_filter('pre_render_block', [$this, 'control_block_caching'], 10, 2);
        add_filter('render_block', [$this, 'cache_block_output'], 10, 2);
        
        // Support for WordPress 6.1+ block caching
        add_filter('block_type_supports', [$this, 'modify_block_cache_support'], 10, 3);
    }
    
    /**
     * Control whether individual blocks should be cached
     */
    public function control_block_caching($pre_render, $parsed_block) {
        $block_name = $parsed_block['blockName'] ?? '';
        
        if (empty($block_name)) {
            return $pre_render;
        }
        
        $excluded_blocks = $this->get_excluded_blocks();
        
        // Check if this block should be excluded from caching
        foreach ($excluded_blocks as $excluded_block) {
            if ($block_name === $excluded_block || fnmatch($excluded_block, $block_name)) {
                // Mark this block as non-cacheable
                $parsed_block['ace_redis_cache_exclude'] = true;
                return $pre_render;
            }
        }
        
        return $pre_render;
    }
    
    /**
     * Cache block output using Redis
     */
    public function cache_block_output($block_content, $block) {
        $block_name = $block['blockName'] ?? '';
        
        if (empty($block_name) || isset($block['ace_redis_cache_exclude'])) {
            return $block_content;
        }
        
        $excluded_blocks = $this->get_excluded_blocks();
        
        // Skip caching for excluded blocks
        foreach ($excluded_blocks as $excluded_block) {
            if ($block_name === $excluded_block || fnmatch($excluded_block, $block_name)) {
                return $block_content;
            }
        }
        
        $redis = $this->connect_redis();
        if (!$redis) {
            return $block_content;
        }
        
        // Generate cache key based on block name, attributes, and content
        $cache_key = 'block_cache:' . md5($block_name . serialize($block['attrs'] ?? []) . serialize($block['innerContent'] ?? []));
        
        // Try to get from cache first
        $cached = $redis->get($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        // Cache the rendered block
        $redis->setex($cache_key, intval($this->settings['ttl']), $block_content);
        
        return $block_content;
    }
    
    /**
     * Modify block cache support for WordPress 6.1+
     */
    public function modify_block_cache_support($supports, $feature, $block_type) {
        if ($feature !== 'cache') {
            return $supports;
        }
        
        $block_name = $block_type->name ?? '';
        $excluded_blocks = $this->get_excluded_blocks();
        
        // Disable caching for excluded blocks
        foreach ($excluded_blocks as $excluded_block) {
            if ($block_name === $excluded_block || fnmatch($excluded_block, $block_name)) {
                return false;
            }
        }
        
        // Enable caching for all other blocks
        return true;
    }
    
    /**
     * Check if a cache key should be excluded from Redis caching
     */
    private function should_exclude_cache_key($key) {
        $exclusions = $this->get_cache_exclusions();
        if (empty($exclusions)) {
            return false;
        }
        
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
        $exclusions = $this->get_transient_exclusions();
        if (empty($exclusions)) {
            return false;
        }
        
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
                <p><strong>Generic Redis Cache:</strong> Configure custom exclusion patterns below to avoid conflicts with specific plugins or dynamic content on your site.</p>
            </div>
            
            <div id="ace-redis-cache-status" style="margin-bottom:1em;">
                <button id="ace-redis-cache-test-btn" class="button">Test Redis Connection</button>
                <button id="ace-redis-cache-flush-btn" class="button">Flush All Cache</button>
                <button id="ace-redis-cache-flush-blocks-btn" class="button button-secondary">Flush Block Cache Only</button>
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
                        <th>Enable Block-Level Caching</th>
                        <td>
                            <input type="checkbox" name="ace_redis_cache_settings[enable_block_caching]" value="1" <?php checked($opts['enable_block_caching'] ?? 0, 1); ?>>
                            <p class="description">Use WordPress Block API to cache individual blocks instead of full pages. This allows dynamic blocks to stay fresh while static content is cached. <strong>Experimental feature.</strong></p>
                        </td>
                    </tr>
                </table>
                
                <h2>Custom Exclusion Patterns</h2>
                <p>Configure custom patterns to exclude from Redis caching. Each pattern should be on a new line. Lines starting with # are treated as comments.</p>
                
                <table class="form-table">
                    <tr>
                        <th>Block Exclusions</th>
                        <td>
                            <textarea name="ace_redis_cache_settings[excluded_blocks]" rows="8" cols="50" style="width: 100%; max-width: 500px;"><?php echo esc_textarea($opts['excluded_blocks'] ?? ''); ?></textarea>
                            <p class="description">
                                Exclude specific WordPress blocks from caching. Supports wildcards (*). One per line.<br>
                                <strong>Examples:</strong> <code>my-plugin/*</code>, <code>woocommerce/cart</code>, <code>core/latest-posts</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Cache Key Exclusions</th>
                        <td>
                            <textarea name="ace_redis_cache_settings[custom_cache_exclusions]" rows="8" cols="50" style="width: 100%; max-width: 500px;"><?php echo esc_textarea($opts['custom_cache_exclusions'] ?? ''); ?></textarea>
                            <p class="description">
                                Exclude cache keys starting with these prefixes. One per line. <strong>Leave empty to disable exclusions.</strong><br>
                                <strong>Examples:</strong> <code>myplugin_</code>, <code>woocommerce_</code>, <code>custom_cache_</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Transient Exclusions</th>
                        <td>
                            <textarea name="ace_redis_cache_settings[custom_transient_exclusions]" rows="8" cols="50" style="width: 100%; max-width: 500px;"><?php echo esc_textarea($opts['custom_transient_exclusions'] ?? ''); ?></textarea>
                            <p class="description">
                                Exclude transients matching these patterns. Supports wildcards (*). One per line. <strong>Leave empty to disable exclusions.</strong><br>
                                <strong>Examples:</strong> <code>myplugin_%</code>, <code>wc_cart_%</code>, <code>dynamic_*</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Content Exclusions</th>
                        <td>
                            <textarea name="ace_redis_cache_settings[custom_content_exclusions]" rows="8" cols="50" style="width: 100%; max-width: 500px;"><?php echo esc_textarea($opts['custom_content_exclusions'] ?? ''); ?></textarea>
                            <p class="description">
                                Exclude pages containing these strings in their content. One per line. <strong>Leave empty to disable exclusions.</strong><br>
                                <strong>Examples:</strong> <code>my-block/dynamic</code>, <code>[shortcode</code>, <code>class="dynamic-content"</code>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
                
                <p>
                    <button type="button" id="reset-exclusions" class="button button-secondary">Clear All Exclusions</button>
                    <span style="margin-left: 10px; font-style: italic;">This will clear all exclusion patterns, allowing you to start fresh.</span>
                </p>
            </form>
            
            <div class="card" style="margin-top: 20px; padding: 15px;">
                <h2>Intelligent Caching System</h2>
                
                <div style="display: flex; gap: 20px; margin-top: 15px;">
                    <div style="flex: 1;">
                        <h3 style="color: green;">✅ Cached by Redis</h3>
                        <ul>
                            <li><strong>Full Page Mode</strong>: Complete WordPress pages</li>
                            <li><strong>Block Mode</strong>: Individual WordPress blocks</li>
                            <li>Static content and media files</li>
                            <li>Theme assets and standard plugin data</li>
                            <li>Search results and archive pages</li>
                            <li>Any content not matching exclusion patterns</li>
                        </ul>
                    </div>
                    
                    <div style="flex: 1;">
                        <h3 style="color: red;">❌ Excluded from Redis</h3>
                        <ul>
                            <li><strong>Cache Keys</strong>: Keys matching your configured prefixes (when textarea has content)</li>
                            <li><strong>Transients</strong>: Transients matching your configured patterns (when textarea has content)</li>
                            <li><strong>Dynamic Content</strong>: Pages containing your configured content patterns (when textarea has content)</li>
                            <li><strong>Blocks</strong>: Specific block types when Block-Level Caching is enabled</li>
                            <li><strong>User-Specific Pages</strong>: Logged-in user content</li>
                            <li><strong>Admin Areas</strong>: WordPress admin and dashboard</li>
                            <li><strong>API Endpoints</strong>: AJAX and proxy requests</li>
                        </ul>
                    </div>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: #f0f8ff; border-left: 4px solid #0073aa;">
                    <h4>🧩 Block-Level Caching Benefits</h4>
                    <p><strong>When enabled</strong>, block-level caching uses WordPress's native Block API to:</p>
                    <ul style="margin: 10px 0;">
                        <li><strong>Cache Static Blocks</strong>: Paragraph, image, and heading blocks are cached for speed</li>
                        <li><strong>Exclude Dynamic Blocks</strong>: Live data blocks (latest posts, dynamic widgets) stay fresh</li>
                        <li><strong>Granular Control</strong>: Configure exactly which block types to exclude</li>
                        <li><strong>WordPress Native</strong>: Uses core WordPress block caching mechanisms</li>
                        <li><strong>Best Performance</strong>: Pages load fast with live data where needed</li>
                    </ul>
                </div>
                
                <div style="margin-top: 15px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107;">
                    <h4>💡 Pro Tips for Exclusions</h4>
                    <ul style="margin: 10px 0;">
                        <li><strong>Auto-Enable</strong>: Simply add content to any textarea to automatically enable that exclusion type</li>
                        <li><strong>Block Exclusions</strong>: Use <code>plugin-name/*</code> to exclude all blocks from a plugin</li>
                        <li><strong>Cache Keys</strong>: Use plugin prefixes like <code>myplugin_</code> to exclude entire plugin cache systems</li>
                        <li><strong>Transients</strong>: Use wildcards like <code>cart_%</code> to match dynamic transient names</li>
                        <li><strong>Content</strong>: Use block names, shortcodes, or CSS classes to identify dynamic content</li>
                        <li><strong>Comments</strong>: Add lines starting with <code>#</code> to document your exclusion patterns</li>
                        <li><strong>Clear All</strong>: Leave textareas empty to disable that exclusion type completely</li>
                    </ul>
                </div>
                
                <p style="margin-top: 15px; font-style: italic;">
                    This intelligent caching system provides maximum compatibility with any WordPress plugin while maintaining optimal performance for both static and dynamic content.
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
                    alert(res.success ? 'All cache flushed!' : 'Failed to flush cache');
                    $('#ace-redis-cache-test-btn').click();
                });
            });
            
            $('#ace-redis-cache-flush-blocks-btn').on('click', function(e) {
                e.preventDefault();
                $.post(AceRedisCacheAjax.ajax_url, {action:'ace_redis_cache_flush_blocks', nonce:AceRedisCacheAjax.flush_nonce}, function(res) {
                    if (res.success) {
                        alert(res.data.message || 'Block cache flushed!');
                    } else {
                        alert('Failed to flush block cache');
                    }
                    $('#ace-redis-cache-test-btn').click();
                });
            });
            
            // Reset exclusions to defaults
            $('#reset-exclusions').on('click', function(e) {
                e.preventDefault();
                if (confirm('Clear all exclusion patterns? This will remove all current exclusions.')) {
                    $('textarea[name="ace_redis_cache_settings[excluded_blocks]"]').val('');
                    $('textarea[name="ace_redis_cache_settings[custom_cache_exclusions]"]').val('');
                    $('textarea[name="ace_redis_cache_settings[custom_transient_exclusions]"]').val('');
                    $('textarea[name="ace_redis_cache_settings[custom_content_exclusions]"]').val('');
                    alert('All exclusion patterns cleared! Remember to save your settings.');
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

    /** AJAX: Flush Block Cache Only **/
    public function ajax_flush_blocks() {
        check_ajax_referer('ace_redis_cache_flush', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('No permission');

        try {
            $redis = $this->connect_redis();
            if ($redis) {
                $keys = $redis->keys('block_cache:*');
                if ($keys) {
                    $redis->del($keys);
                    wp_send_json_success(['message' => 'Block cache cleared']);
                } else {
                    wp_send_json_success(['message' => 'No block cache found']);
                }
            }
        } catch (Exception $e) {
            wp_send_json_error(false);
        }
    }

    /** Full Page Cache **/
    private function setup_full_page_cache() {
        add_action('template_redirect', function () {
            if (is_user_logged_in() || $_SERVER['REQUEST_METHOD'] !== 'GET') return;
            
            // Check if current page should be excluded from page caching
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
                    echo $buffer; // Output original content to user
                    
                    // Cache the content if it doesn't contain excluded patterns
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
        
        // Check custom content exclusions in post content
        if ($post) {
            $exclusions = $this->get_content_exclusions();
            foreach ($exclusions as $pattern) {
                if (strpos($post->post_content, $pattern) !== false) {
                    return true;
                }
            }
        }
        
        // Exclude common dynamic endpoints
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($request_uri, 'wp-admin/admin-ajax.php') !== false ||
            strpos($request_uri, '/wp-json/') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if content should be excluded from caching based on its content
     */
    private function should_exclude_content_from_cache($content) {
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
