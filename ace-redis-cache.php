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
        
        // Clear cache when settings are updated
        add_action('update_option_ace_redis_cache_settings', [$this, 'clear_all_cache_on_settings_change'], 10, 2);

        // AJAX handlers for status and flushing cache
        add_action('wp_ajax_ace_redis_cache_status', [$this, 'ajax_status']);
        add_action('wp_ajax_ace_redis_cache_flush', [$this, 'ajax_flush']);
        add_action('wp_ajax_ace_redis_cache_flush_blocks', [$this, 'ajax_flush_blocks']);
        add_action('wp_ajax_ace_redis_cache_test_write', [$this, 'ajax_test_write']);

        // Admin scripts
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue']);

        if (!is_admin() && $this->settings['enabled']) {
            if ($this->settings['mode'] === 'full') {
                $this->setup_full_page_cache();
            } else {
                $this->setup_object_cache();
                
                // Setup block-level caching only in object cache mode if enabled
                if ($this->settings['enable_block_caching'] ?? 0) {
                    $this->setup_block_caching();
                }
            }
        }
        
        // Add WordPress hooks to intercept operations for exclusion logic only
        if ($this->settings['enabled']) {
            // Only setup exclusion filters if we're in full page mode
            // In object mode, Redis operations are handled directly in setup_object_cache()
            if ($this->settings['mode'] === 'full') {
                $this->setup_transient_exclusions();
                $this->setup_plugin_exclusions();
            }
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
        
        // Auto-exclude dynamic WordPress blocks when in object cache mode with block-level caching enabled
        if (($this->settings['mode'] === 'object') && ($this->settings['enable_block_caching'] ?? 0)) {
            $auto_excluded = [
                'core/latest-posts',      // Latest Posts - Dynamic content that changes frequently
                'core/latest-comments',   // Latest Comments - Dynamic content
                'core/archives',          // Archives - Dynamic navigation
                'core/categories',        // Categories - Dynamic navigation
                'core/tag-cloud',         // Tag Cloud - Dynamic navigation
                'core/calendar',          // Calendar - Time-based dynamic content
                'core/rss',              // RSS - External dynamic content
                'core/search',           // Search - User input dependent
                'core/query',            // Query Loop - CRITICAL: Don't cache this!
                'core/query-pagination', // Query Pagination - Dynamic based on query results
                'core/query-pagination-next',     // Next page link - Dynamic
                'core/query-pagination-numbers',  // Page numbers - Dynamic
                'core/query-pagination-previous', // Previous page link - Dynamic
                'core/query-title',      // Query Title - Dynamic based on query
                'core/query-no-results', // Query No Results - Dynamic based on query
                'core/comments',         // Comments - Dynamic user-generated content
                'core/comments-query-loop', // Comments Query Loop - Dynamic
                'core/comment-template',    // Comment Template - Dynamic
                'core/comment-author-name', // Comment Author Name - Dynamic
                'core/comment-content',     // Comment Content - Dynamic
                'core/comment-date',        // Comment Date - Dynamic
                'core/comment-edit-link',   // Comment Edit Link - User-dependent
                'core/comment-reply-link',  // Comment Reply Link - User-dependent
                'core/avatar',           // Avatar - Usually dynamic/user-dependent
                'core/loginout',         // Login/Logout link - User state dependent
                'woocommerce/*',         // All WooCommerce blocks - E-commerce is typically dynamic
                // Note: Removed core/post-* blocks to allow caching of individual post content
                // Note: Removed ace/popular-posts to allow custom caching control
                // Note: Removed '*' wildcard to enable selective block caching
            ];
            
            $excluded_blocks = array_merge($excluded_blocks, $auto_excluded);
        }
        
        return array_unique($excluded_blocks);
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
                // Debug: Log excluded blocks
                if (strpos($block_name, 'query') !== false || strpos($block_name, 'post') !== false) {
                    error_log("Ace Redis Cache: EXCLUDED block: " . $block_name . " (matched: " . $excluded_block . ")");
                }
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
        
        // Debug: Log block names for troubleshooting
        if (strpos($block_name, 'query') !== false || strpos($block_name, 'post') !== false) {
            error_log("Ace Redis Cache: Attempting to cache block: " . $block_name);
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
    
    /**
     * Clear all cache when settings are changed
     */
    public function clear_all_cache_on_settings_change($old_value, $new_value) {
        try {
            $redis = $this->connect_redis();
            if ($redis) {
                // Clear all cache types
                $page_keys = $redis->keys($this->cache_prefix . '*');
                $block_keys = $redis->keys('block_cache:*');
                $object_keys = $redis->keys('wp_cache_*');
                $transient_keys = $redis->keys('_transient_*');
                
                $all_keys = array_merge(
                    $page_keys ?: [], 
                    $block_keys ?: [], 
                    $object_keys ?: [], 
                    $transient_keys ?: []
                );
                
                if (!empty($all_keys)) {
                    $redis->del($all_keys);
                }
                
                // Log the cache clear
                error_log('Ace Redis Cache: All caches cleared due to settings change');
            }
        } catch (Exception $e) {
            error_log('Ace Redis Cache: Failed to clear cache on settings change: ' . $e->getMessage());
        }
    }

    public function admin_enqueue($hook) {
        if ($hook !== 'settings_page_ace-redis-cache') return;
        // External admin.js removed - all functionality now handled by inline JavaScript
        wp_localize_script('jquery', 'AceRedisCacheAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ace_redis_cache_status'),
            'flush_nonce' => wp_create_nonce('ace_redis_cache_flush')
        ]);
    }

    public function settings_page() {
        // Show notice if settings were just saved
        if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved and all caches cleared!</strong></p></div>';
        }
        ?>
        <div class="wrap">
            <h1>Ace Redis Cache</h1>
            
            <div class="notice notice-info">
                <p><strong>Generic Redis Cache:</strong> Configure custom exclusion patterns below to avoid conflicts with specific plugins or dynamic content on your site.</p>
            </div>
            
            <div id="ace-redis-cache-status" style="margin-bottom:1em;">
                <button id="ace-redis-cache-test-btn" class="button">Test Redis Connection</button>
                <button id="ace-redis-cache-test-write-btn" class="button button-secondary">Test Write/Read</button>
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
                            <select name="ace_redis_cache_settings[mode]" id="cache-mode-select">
                                <option value="full" <?php selected($opts['mode'], 'full'); ?>>Full Page Cache</option>
                                <option value="object" <?php selected($opts['mode'], 'object'); ?>>Object Cache Only</option>
                            </select>
                            <p class="description">
                                <strong>Full Page Cache:</strong> Caches entire rendered pages for maximum speed.<br>
                                <strong>Object Cache:</strong> Caches WordPress objects and database queries with optional block-level caching.
                            </p>
                        </td>
                    </tr>
                    <tr id="block-caching-row" style="display: <?php echo ($opts['mode'] === 'object') ? 'table-row' : 'none'; ?>;">
                        <th>Enable Block-Level Caching</th>
                        <td>
                            <input type="checkbox" name="ace_redis_cache_settings[enable_block_caching]" value="1" <?php checked($opts['enable_block_caching'] ?? 0, 1); ?>>
                            <p class="description">Use WordPress Block API to cache individual blocks instead of full pages. This allows dynamic blocks to stay fresh while static content is cached. <strong>Only available in Object Cache mode.</strong></p>
                            <p class="description"><strong>Auto-Exclusions:</strong> When enabled, automatically excludes dynamic WordPress blocks like <code>core/latest-posts</code>, <code>core/query</code>, <code>core/comments</code>, and all <code>woocommerce/*</code> blocks to prevent stale content in loops and dynamic widgets.</p>
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
                                <strong>Examples:</strong> <code>my-plugin/*</code>, <code>woocommerce/cart</code>, <code>core/latest-posts</code><br>
                                <strong>Note:</strong> When Block-Level Caching is enabled, common dynamic blocks are automatically excluded (Latest Posts, Query Loops, Comments, WooCommerce blocks, etc.)
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
                            <li><strong>Full Page Mode</strong>: Complete WordPress pages (HTML)</li>
                            <li><strong>Object Mode</strong>: Database queries, WordPress objects</li>
                            <li><strong>Block Mode</strong>: Individual WordPress blocks (when enabled)</li>
                            <li>Static content and media files</li>
                            <li>Theme assets and standard plugin data</li>
                            <li>Any content not matching exclusion patterns</li>
                        </ul>
                    </div>
                    
                    <div style="flex: 1;">
                        <h3 style="color: red;">❌ Excluded from Redis</h3>
                        <ul>
                            <li><strong>Cache Keys</strong>: Keys matching your configured prefixes</li>
                            <li><strong>Transients</strong>: Transients matching your configured patterns</li>
                            <li><strong>Dynamic Content</strong>: Pages containing your configured content patterns</li>
                            <li><strong>Dynamic Blocks</strong>: When block caching enabled (auto-excluded)</li>
                            <li><strong>User-Specific Pages</strong>: Logged-in user content</li>
                            <li><strong>Admin Areas</strong>: WordPress admin and dashboard</li>
                            <li><strong>API Endpoints</strong>: AJAX and REST API requests</li>
                        </ul>
                    </div>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: #f0f8ff; border-left: 4px solid #0073aa;">
                    <h4>🧩 Cache Mode Comparison</h4>
                    <div style="display: flex; gap: 20px; margin-top: 15px;">
                        <div style="flex: 1;">
                            <h5 style="color: #0073aa;">📄 Full Page Cache</h5>
                            <ul style="margin: 10px 0;">
                                <li><strong>Best Performance</strong>: Serves entire cached HTML pages</li>
                                <li><strong>Simple Setup</strong>: No configuration needed</li>
                                <li><strong>Use Case</strong>: Static websites, blogs, marketing pages</li>
                                <li><strong>Limitation</strong>: All content on page shares same cache</li>
                            </ul>
                        </div>
                        
                        <div style="flex: 1;">
                            <h5 style="color: #0073aa;">🧩 Object Cache + Block Caching</h5>
                            <ul style="margin: 10px 0;">
                                <li><strong>Granular Control</strong>: Cache individual blocks and database objects</li>
                                <li><strong>Dynamic Content</strong>: Latest posts, comments stay fresh</li>
                                <li><strong>Use Case</strong>: News sites, e-commerce, user-specific content</li>
                                <li><strong>Smart Exclusions</strong>: Auto-excludes 30+ dynamic WordPress blocks</li>
                            </ul>
                        </div>
                    </div>
                    
                    <p style="margin-top: 15px;"><strong>💡 Recommendation:</strong> Use <strong>Full Page Cache</strong> for static sites, <strong>Object Cache with Block Caching</strong> for dynamic content that needs fresh data.</p>
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
            // Show/hide block caching option based on cache mode
            function toggleBlockCachingOption() {
                const cacheMode = $('#cache-mode-select').val();
                const blockCachingRow = $('#block-caching-row');
                const blockCachingCheckbox = $('input[name="ace_redis_cache_settings[enable_block_caching]"]');
                
                if (cacheMode === 'object') {
                    blockCachingRow.show();
                } else {
                    blockCachingRow.hide();
                    blockCachingCheckbox.prop('checked', false); // Disable block caching in full page mode
                }
            }
            
            // Initialize on page load
            toggleBlockCachingOption();
            
            // Handle cache mode changes
            $('#cache-mode-select').on('change', toggleBlockCachingOption);
            
            // Existing connection test and flush functionality
            $('#ace-redis-cache-test-btn').on('click', function(e) {
                e.preventDefault();
                $(this).text('Checking...');
                $.post(AceRedisCacheAjax.ajax_url, {action:'ace_redis_cache_status', nonce:AceRedisCacheAjax.nonce}, function(res) {
                    $('#ace-redis-cache-test-btn').text('Test Redis Connection');
                    if(res.success) {
                        $('#ace-redis-cache-connection').text(res.data.status);
                        var sizeText = res.data.size + ' keys (' + res.data.size_kb + ' KB)';
                        if (res.data.debug_info) {
                            sizeText += ' - ' + res.data.debug_info;
                        }
                        $('#ace-redis-cache-size').text(sizeText);
                    } else {
                        $('#ace-redis-cache-connection').text('Connection failed');
                        $('#ace-redis-cache-size').text('0 keys (0 KB)');
                    }
                }).fail(function() {
                    $('#ace-redis-cache-test-btn').text('Test Redis Connection');
                    $('#ace-redis-cache-connection').text('AJAX failed - check console');
                    $('#ace-redis-cache-size').text('0 keys (0 KB)');
                });
            });
            
            $('#ace-redis-cache-test-write-btn').on('click', function(e) {
                e.preventDefault();
                $(this).text('Testing...');
                $.post(AceRedisCacheAjax.ajax_url, {action:'ace_redis_cache_test_write', nonce:AceRedisCacheAjax.nonce}, function(res) {
                    $('#ace-redis-cache-test-write-btn').text('Test Write/Read');
                    if(res.success) {
                        alert('Write: ' + res.data.write + ', Read: ' + res.data.read + '\nValue: ' + res.data.value);
                    } else {
                        alert('Test failed: ' + (res.data || 'Unknown error'));
                    }
                }).fail(function() {
                    $('#ace-redis-cache-test-write-btn').text('Test Write/Read');
                    alert('AJAX failed - check console');
                });
            });
            
            $('#ace-redis-cache-flush-btn').on('click', function(e) {
                e.preventDefault();
                $.post(AceRedisCacheAjax.ajax_url, {action:'ace_redis_cache_flush', nonce:AceRedisCacheAjax.flush_nonce}, function(res) {
                    alert(res.success ? 'All cache flushed!' : 'Failed to flush cache');
                    // Auto-refresh status after flush
                    if (res.success) {
                        $('#ace-redis-cache-size').text('0 keys (0 KB)');
                    }
                });
            });
            
            $('#ace-redis-cache-flush-blocks-btn').on('click', function(e) {
                e.preventDefault();
                $.post(AceRedisCacheAjax.ajax_url, {action:'ace_redis_cache_flush_blocks', nonce:AceRedisCacheAjax.flush_nonce}, function(res) {
                    if (res.success) {
                        alert(res.data.message || 'Block cache flushed!');
                        // Manually refresh the status without triggering duplicate requests
                        $(this).text('Refreshing...');
                        $.post(AceRedisCacheAjax.ajax_url, {action:'ace_redis_cache_status', nonce:AceRedisCacheAjax.nonce}, function(statusRes) {
                            if(statusRes.success) {
                                var sizeText = statusRes.data.size + ' keys (' + statusRes.data.size_kb + ' KB)';
                                if (statusRes.data.debug_info) {
                                    sizeText += ' - ' + statusRes.data.debug_info;
                                }
                                $('#ace-redis-cache-size').text(sizeText);
                            }
                        });
                    } else {
                        alert('Failed to flush block cache');
                    }
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
            if (!$redis) {
                wp_send_json_success(['status'=>'Not connected','size'=>0,'size_kb'=>0]);
                return;
            }

            $status = 'Connected';
            
            // Count different cache types
            $page_keys = $redis->keys($this->cache_prefix . '*') ?: [];
            $block_keys = $redis->keys('block_cache:*') ?: [];
            $object_keys = $redis->keys('wp_cache_*') ?: [];
            $transient_keys = $redis->keys('_transient_*') ?: [];
            
            $page_count = is_array($page_keys) ? count($page_keys) : 0;
            $block_count = is_array($block_keys) ? count($block_keys) : 0;
            $object_count = is_array($object_keys) ? count($object_keys) : 0;
            $transient_count = is_array($transient_keys) ? count($transient_keys) : 0;
            
            // Total cache entries managed by this plugin
            $total_cache_size = $page_count + $block_count + $object_count + $transient_count;
            
            // Count all keys for debugging
            $all_keys = $redis->keys('*') ?: [];
            $all_keys_count = is_array($all_keys) ? count($all_keys) : 0;

            // Calculate total bytes for all cache types
            $totalBytes = 0;
            $all_cache_keys = array_merge($page_keys, $block_keys, $object_keys, $transient_keys);
            
            foreach ($all_cache_keys as $key) {
                $len = $redis->strlen($key);
                if ($len !== false) $totalBytes += $len;
            }
            $size_kb = round($totalBytes / 1024, 2);

            // Build detailed cache breakdown
            $cache_breakdown = [];
            if ($page_count > 0) $cache_breakdown[] = "Pages: {$page_count}";
            if ($block_count > 0) $cache_breakdown[] = "Blocks: {$block_count}";
            if ($object_count > 0) $cache_breakdown[] = "Objects: {$object_count}";
            if ($transient_count > 0) $cache_breakdown[] = "Transients: {$transient_count}";
            
            $debug_info = implode(', ', $cache_breakdown) . " | Total Redis: {$all_keys_count}";

            wp_send_json_success([
                'status' => $status,
                'size'   => $total_cache_size,
                'size_kb' => $size_kb,
                'debug_info' => $debug_info,
            ]);
        } catch (Exception $e) {
            wp_send_json_success(['status'=>'Error: ' . $e->getMessage(),'size'=>0,'size_kb'=>0]);
        }
    }

    /** AJAX: Test Write **/
    public function ajax_test_write() {
        check_ajax_referer('ace_redis_cache_status', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('No permission');

        try {
            $redis = $this->connect_redis();
            if (!$redis) {
                wp_send_json_error('Not connected');
                return;
            }

            // Test write
            $test_key = 'test_write_' . time();
            $test_value = 'PHP Redis test at ' . date('Y-m-d H:i:s');
            
            $write_result = $redis->setex($test_key, 10, $test_value);
            $read_result = $redis->get($test_key);
            
            // Clean up
            $redis->del($test_key);
            
            wp_send_json_success([
                'write' => $write_result ? 'OK' : 'FAILED',
                'read' => $read_result === $test_value ? 'OK' : 'FAILED',
                'value' => $read_result
            ]);
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /** AJAX: Flush Cache **/
    public function ajax_flush() {
        check_ajax_referer('ace_redis_cache_flush', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('No permission');

        try {
            $redis = $this->connect_redis();
            if ($redis) {
                // Clear all cache types
                $page_keys = $redis->keys($this->cache_prefix . '*');
                $block_keys = $redis->keys('block_cache:*');
                $object_keys = $redis->keys('wp_cache_*');
                $transient_keys = $redis->keys('_transient_*');
                
                $all_keys = array_merge(
                    $page_keys ?: [], 
                    $block_keys ?: [], 
                    $object_keys ?: [], 
                    $transient_keys ?: []
                );
                
                if (!empty($all_keys)) {
                    $redis->del($all_keys);
                }
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

    /** Object Cache Implementation **/
    private function setup_object_cache() {
        add_action('init', function () {
            header('X-Cache-Mode: Object');
        });
        
        // Implement actual Redis-backed transient operations
        add_filter('pre_set_transient', [$this, 'redis_set_transient'], 10, 3);
        add_filter('pre_get_transient', [$this, 'redis_get_transient'], 10, 2);
        add_filter('pre_delete_transient', [$this, 'redis_delete_transient'], 10, 2);
        
        // Implement WordPress object cache operations
        add_filter('pre_wp_cache_set', [$this, 'redis_wp_cache_set'], 10, 5);
        add_filter('pre_wp_cache_get', [$this, 'redis_wp_cache_get'], 10, 3);
        add_filter('pre_wp_cache_delete', [$this, 'redis_wp_cache_delete'], 10, 3);
    }
    
    /**
     * Store transient in Redis
     */
    public function redis_set_transient($value, $transient, $expiration) {
        error_log("DEBUG: redis_set_transient called for: {$transient}");
        
        // Check if this transient should be excluded
        if ($this->should_exclude_transient($transient)) {
            error_log("DEBUG: Transient {$transient} excluded");
            return false; // Let WordPress handle it normally
        }
        
        $redis = $this->connect_redis();
        if (!$redis) {
            error_log("DEBUG: Redis connection failed for transient {$transient}");
            return false; // Fall back to database
        }
        
        $key = "_transient_{$transient}";
        $expiration = intval($expiration) ?: intval($this->settings['ttl']);
        
        try {
            $result = $redis->setex($key, $expiration, serialize($value));
            error_log("DEBUG: Stored transient {$key} in Redis, result: " . ($result ? 'SUCCESS' : 'FAILED'));
            return $value; // Return the value to indicate we handled it
        } catch (Exception $e) {
            error_log('Redis transient set failed: ' . $e->getMessage());
            return false; // Fall back to database
        }
    }
    
    /**
     * Get transient from Redis
     */
    public function redis_get_transient($value, $transient) {
        // Check if this transient should be excluded
        if ($this->should_exclude_transient($transient)) {
            return false; // Let WordPress handle it normally
        }
        
        $redis = $this->connect_redis();
        if (!$redis) {
            return false; // Fall back to database
        }
        
        $key = "_transient_{$transient}";
        
        try {
            $cached = $redis->get($key);
            if ($cached !== false) {
                return unserialize($cached);
            }
            return false; // Not found in cache
        } catch (Exception $e) {
            error_log('Redis transient get failed: ' . $e->getMessage());
            return false; // Fall back to database
        }
    }
    
    /**
     * Delete transient from Redis
     */
    public function redis_delete_transient($value, $transient) {
        // Check if this transient should be excluded
        if ($this->should_exclude_transient($transient)) {
            return false; // Let WordPress handle it normally
        }
        
        $redis = $this->connect_redis();
        if (!$redis) {
            return false; // Fall back to database
        }
        
        $key = "_transient_{$transient}";
        
        try {
            $redis->del($key);
            return true; // Indicate we handled it
        } catch (Exception $e) {
            error_log('Redis transient delete failed: ' . $e->getMessage());
            return false; // Fall back to database
        }
    }
    
    /**
     * Store object cache in Redis
     */
    public function redis_wp_cache_set($value, $key, $data, $group, $expire) {
        // Check if this cache key should be excluded
        if ($this->should_exclude_cache_key($key)) {
            return false; // Let WordPress handle it normally
        }
        
        $redis = $this->connect_redis();
        if (!$redis) {
            return false; // Fall back to memory cache
        }
        
        $cache_key = "wp_cache_{$group}:{$key}";
        $expire = intval($expire) ?: intval($this->settings['ttl']);
        
        try {
            $redis->setex($cache_key, $expire, serialize($data));
            return true; // Indicate we handled it
        } catch (Exception $e) {
            error_log('Redis wp_cache set failed: ' . $e->getMessage());
            return false; // Fall back to memory cache
        }
    }
    
    /**
     * Get object cache from Redis
     */
    public function redis_wp_cache_get($value, $key, $group) {
        // Check if this cache key should be excluded
        if ($this->should_exclude_cache_key($key)) {
            return false; // Let WordPress handle it normally
        }
        
        $redis = $this->connect_redis();
        if (!$redis) {
            return false; // Fall back to memory cache
        }
        
        $cache_key = "wp_cache_{$group}:{$key}";
        
        try {
            $cached = $redis->get($cache_key);
            if ($cached !== false) {
                return unserialize($cached);
            }
            return false; // Not found in cache
        } catch (Exception $e) {
            error_log('Redis wp_cache get failed: ' . $e->getMessage());
            return false; // Fall back to memory cache
        }
    }
    
    /**
     * Delete object cache from Redis
     */
    public function redis_wp_cache_delete($value, $key, $group) {
        // Check if this cache key should be excluded
        if ($this->should_exclude_cache_key($key)) {
            return false; // Let WordPress handle it normally
        }
        
        $redis = $this->connect_redis();
        if (!$redis) {
            return false; // Fall back to memory cache
        }
        
        $cache_key = "wp_cache_{$group}:{$key}";
        
        try {
            $redis->del($cache_key);
            return true; // Indicate we handled it
        } catch (Exception $e) {
            error_log('Redis wp_cache delete failed: ' . $e->getMessage());
            return false; // Fall back to memory cache
        }
    }

    /** Redis Connection **/
    private function connect_redis() {
    try {
        $redis = new Redis();
        $host = $this->settings['host'];
        $port = intval($this->settings['port']);
        $timeout = 5.0; // Increased timeout for slower connections

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

        // Test with INFO instead of PING for better Valkey compatibility
        try {
            $info = $redis->info();
            if (empty($info)) {
                return false;
            }
        } catch (Exception $e) {
            // Fallback to ping
            if (!$redis->ping()) {
                return false;
            }
        }

        return $redis;
    } catch (Exception $e) {
        error_log('Redis/Valkey connect failed: ' . $e->getMessage());
        return false;
    }
}

}

new AceRedisCache();
