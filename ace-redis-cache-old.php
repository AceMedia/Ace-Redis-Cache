<?php
/**
 * Plugin Name: Ace Redis Cache
 * Description: Smart Redis-powered caching with WordPress Block API support and configurable exclusions for any plugins.
 * Version: 0.5.0
 * Author: Ace Media
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ace-redis-cache
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ACE_REDIS_CACHE_VERSION', '0.5.0');
define('ACE_REDIS_CACHE_PLUGIN_FILE', __FILE__);
define('ACE_REDIS_CACHE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('ACE_REDIS_CACHE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Polyfill for PHP < 8.0
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

/**
 * Main plugin bootstrap class
 */
class AceRedisCacheBootstrap {
    
    private static $instance = null;
    private $plugin = null;
    
    /**
     * Get singleton instance
     *
     * @return AceRedisCacheBootstrap
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Initialize the plugin
     */
    private function init() {
        // Check requirements
        if (!$this->check_requirements()) {
            return;
        }
        
        // Load autoloader
        $this->load_autoloader();
        
        // Initialize plugin
        add_action('plugins_loaded', [$this, 'load_plugin']);
        
        // Plugin lifecycle hooks
        register_activation_hook(__FILE__, [$this, 'on_activation']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivation']);
        register_uninstall_hook(__FILE__, [__CLASS__, 'on_uninstall']);
    }
    
    /**
     * Check plugin requirements
     *
     * @return bool True if requirements are met
     */
    private function check_requirements() {
        $requirements_met = true;
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>Ace Redis Cache:</strong> This plugin requires PHP 7.4 or higher. ';
                echo 'You are running PHP ' . PHP_VERSION . '.';
                echo '</p></div>';
            });
            $requirements_met = false;
        }
        
        // Check WordPress version
        global $wp_version;
        if (version_compare($wp_version, '5.0', '<')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>Ace Redis Cache:</strong> This plugin requires WordPress 5.0 or higher. ';
                echo 'You are running WordPress ' . $GLOBALS['wp_version'] . '.';
                echo '</p></div>';
            });
            $requirements_met = false;
        }
        
        // Check if Redis extension is available
        if (!extension_loaded('redis')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning"><p>';
                echo '<strong>Ace Redis Cache:</strong> The PHP Redis extension is not installed. ';
                echo 'Please install php-redis to use this plugin.';
                echo '</p></div>';
            });
            // Don't prevent loading, but show warning
        }
        
        return $requirements_met;
    }
    
    /**
     * Load the autoloader
     */
    private function load_autoloader() {
        // Simple autoloader for our namespace
        spl_autoload_register(function($class) {
            // Check if this is our namespace
            if (strpos($class, 'AceMedia\\RedisCache\\') !== 0) {
                return;
            }
            
            // Remove namespace prefix
            $class = str_replace('AceMedia\\RedisCache\\', '', $class);
            
            // Convert class name to file name
            $class_file = 'class-' . strtolower(str_replace('_', '-', $class)) . '.php';
            
            // Try includes directory first
            $includes_file = ACE_REDIS_CACHE_PLUGIN_PATH . 'includes/' . $class_file;
            if (file_exists($includes_file)) {
                require_once $includes_file;
                return;
            }
            
            // Try admin directory
            $admin_file = ACE_REDIS_CACHE_PLUGIN_PATH . 'admin/' . $class_file;
            if (file_exists($admin_file)) {
                require_once $admin_file;
                return;
            }
        });
    }
    
    /**
     * Load and initialize the main plugin
     */
    public function load_plugin() {
        if (class_exists('AceMedia\\RedisCache\\AceRedisCache')) {
            $this->plugin = new \AceMedia\RedisCache\AceRedisCache();
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>Ace Redis Cache:</strong> Failed to load plugin classes. ';
                echo 'Please check file permissions and plugin integrity.';
                echo '</p></div>';
            });
        }
    }
    
    /**
     * Plugin activation
     */
    public function on_activation() {
        // Check requirements again on activation
        if (!$this->check_requirements()) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                'Ace Redis Cache activation failed: System requirements not met.',
                'Plugin Activation Error',
                ['back_link' => true]
            );
        }
        
        // Load plugin if not already loaded
        if (!$this->plugin) {
            $this->load_plugin();
        }
        
        // Call plugin activation if available
        if ($this->plugin && method_exists($this->plugin, 'on_activation')) {
            $this->plugin->on_activation();
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function on_deactivation() {
        // Call plugin deactivation if available
        if ($this->plugin && method_exists($this->plugin, 'on_deactivation')) {
            $this->plugin->on_deactivation();
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin uninstallation
     */
    public static function on_uninstall() {
        // Clean up options
        delete_option('ace_redis_cache_settings');
        delete_option('ace_redis_cache_dismissed_version');
        
        // Clean up transients
        delete_transient('ace_redis_circuit_breaker');
        delete_transient('ace_redis_recent_issues');
        
        // Note: We don't clear Redis cache data as it might be used by other applications
    }
    
    /**
     * Get plugin instance
     *
     * @return \AceMedia\RedisCache\AceRedisCache|null
     */
    public function get_plugin() {
        return $this->plugin;
    }
}

// Initialize the plugin
        $this->settings = get_option('ace_redis_cache_settings', [
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'ttl' => 3600, // Increased default TTL to 1 hour
            'mode' => 'full', // 'full' or 'object'
            'enabled' => 1,
            'enable_tls' => 1, // Enable TLS by default for AWS Valkey/ElastiCache
            'enable_block_caching' => 0, // Enable WordPress Block API caching
            'enable_minification' => 0, // Enable HTML/CSS/JS minification
            'custom_cache_exclusions' => '', // Custom cache key exclusions
            'custom_transient_exclusions' => '', // Custom transient exclusions
            'custom_content_exclusions' => '', // Custom content exclusions
            'excluded_blocks' => '', // Blocks to exclude from caching
        ]);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        
        // Version 0.4.1 update notice
        add_action('admin_notices', [$this, 'show_version_notice']);
        
        // Clear cache when settings are updated
        add_action('update_option_ace_redis_cache_settings', [$this, 'clear_all_cache_on_settings_change'], 10, 2);

        // AJAX handlers for status and flushing cache
        add_action('wp_ajax_ace_redis_cache_status', [$this, 'ajax_status']);
        add_action('wp_ajax_ace_redis_cache_flush', [$this, 'ajax_flush']);
        add_action('wp_ajax_ace_redis_cache_flush_blocks', [$this, 'ajax_flush_blocks']);
        add_action('wp_ajax_ace_redis_cache_test_write', [$this, 'ajax_test_write']);
        add_action('wp_ajax_ace_redis_cache_diagnostics', [$this, 'ajax_diagnostics']);

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
                'core/post-template',    // Post Template - Dynamic block safety net
                'core/query-loop',       // Query Loop - Additional safety net
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
     * Retry wrapper for Redis operations with automatic reconnection
     */
    private function rtry(callable $fn) {
        // Set maximum operation time to prevent 504 gateway timeout
        $max_operation_time = 15; // 15 seconds max to stay well below typical 30s gateway timeout
        $start_time = microtime(true);
        
        try {
            $redis = $this->get_redis_connection();
            if (!$redis) {
                throw new RedisException('No Redis connection available');
            }
            
            // Check if we're already near timeout before attempting operation
            $elapsed = microtime(true) - $start_time;
            if ($elapsed > ($max_operation_time * 0.8)) {
                $this->open_circuit_breaker();
                throw new RedisException('Operation timeout prevention');
            }
            
            // Execute the Redis operation with timeout monitoring
            $result = $this->execute_with_timeout($fn, $redis, $max_operation_time - $elapsed);
            return $result;
            
        } catch (RedisException $e) {
            // Check if we have time for a retry
            $elapsed = microtime(true) - $start_time;
            if ($elapsed > ($max_operation_time * 0.6)) {
                error_log('Ace Redis Cache: Skipping retry due to insufficient time');
                $this->open_circuit_breaker();
                throw new RedisException('Timeout prevention - no retry');
            }
            
            error_log('Ace Redis Cache: Attempting retry with reconnect');
            // Close existing connection and force reconnect
            $this->close_redis_connection();
            
            try {
                $redis = $this->get_redis_connection(true); // Force reconnect
                if (!$redis) {
                    error_log('Ace Redis Cache: Reconnection failed');
                    throw new RedisException('Reconnection failed');
                }
                
                // Add retry header for debugging
                if (!is_admin()) {
                    header('X-Redis-Retry: 1');
                }
                
                // Execute retry with remaining time
                $remaining_time = $max_operation_time - (microtime(true) - $start_time);
                $result = $this->execute_with_timeout($fn, $redis, $remaining_time);
                return $result;
            } catch (Exception $retry_e) {
                $total_time = microtime(true) - $start_time;
                error_log("Redis retry failed ({$total_time}s): " . $retry_e->getMessage());
                
                // Open circuit breaker if we're taking too long
                if ($total_time > ($max_operation_time * 0.5)) {
                    $this->open_circuit_breaker();
                }
                
                throw $retry_e;
            }
        }
    }
    
    /**
     * Execute Redis operation with timeout monitoring
     */
    private function execute_with_timeout(callable $fn, $redis, $max_time) {
        if ($max_time <= 0) {
            $this->record_redis_issue('timeout');
            throw new RedisException('Operation timeout - no time remaining');
        }
        
        $start = microtime(true);
        
        try {
            // Execute the operation
            $result = $fn($redis);
            
            // Check if operation took too long
            $duration = microtime(true) - $start;
            if ($duration > ($max_time * 0.8)) {
                $this->record_redis_issue('slow_operations');
                error_log("Redis operation slow ({$duration}s), monitoring for circuit breaker");
                
                // Don't open circuit breaker immediately for slow but successful operations
                // But log it for monitoring
                if (!is_admin()) {
                    header('X-Redis-Slow: ' . round($duration, 3));
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            $duration = microtime(true) - $start;
            
            // Record different types of issues
            if ($duration > 3) {
                $this->record_redis_issue('timeouts');
                error_log("Redis operation failed after {$duration}s, opening circuit breaker");
                $this->open_circuit_breaker();
            } else {
                $this->record_redis_issue('operation_failure');
            }
            
            throw $e;
        }
    }
    
    /**
     * Get or create Redis connection with connection pooling
     */
    private function get_redis_connection($force_reconnect = false) {
        error_log('Ace Redis Cache: get_redis_connection called - force_reconnect: ' . ($force_reconnect ? 'YES' : 'NO'));
        
        // Return existing connection if available and not forcing reconnect
        if (!$force_reconnect && $this->redis && $this->redis->isConnected()) {
            error_log('Ace Redis Cache: Returning existing connection');
            return $this->redis;
        }
        
        // Check circuit breaker on frontend
        if (!is_admin() && $this->is_circuit_breaker_open()) {
            error_log('Ace Redis Cache: Circuit breaker is open, refusing connection');
            return false;
        }
        
        error_log('Ace Redis Cache: Creating new Redis connection');
        $this->redis = $this->connect_redis($force_reconnect);
        
        if ($this->redis) {
            error_log('Ace Redis Cache: New connection created successfully');
        } else {
            error_log('Ace Redis Cache: Failed to create new connection');
        }
        
        return $this->redis;
    }
    
    /**
     * Close Redis connection
     */
    private function close_redis_connection() {
        if ($this->redis) {
            try {
                $this->redis->close();
            } catch (Exception $e) {
                // Ignore close errors
            }
            $this->redis = null;
        }
    }
    
    /**
     * Check if circuit breaker is open (for frontend failover)
     */
    private function is_circuit_breaker_open() {
        $breaker_time = get_transient($this->circuit_breaker_key);
        return $breaker_time && (time() - $breaker_time) < $this->circuit_breaker_window;
    }
    
    /**
     * Open circuit breaker (disable Redis for a period)
     */
    private function open_circuit_breaker() {
        if (!is_admin()) {
            set_transient($this->circuit_breaker_key, time(), $this->circuit_breaker_window);
        }
    }
    
    /**
     * Check if system is under load (high request rate)
     */
    private function is_under_load() {
        // Simple load detection based on concurrent requests
        // This could be enhanced with more sophisticated metrics
        
        $load_key = 'redis_load_tracker';
        $current_time = time();
        
        // Get recent request timestamps
        $requests = get_transient($load_key) ?: [];
        
        // Remove old requests (older than 60 seconds)
        $requests = array_filter($requests, function($time) use ($current_time) {
            return ($current_time - $time) < 60;
        });
        
        // Add current request
        $requests[] = $current_time;
        
        // Update the tracker
        set_transient($load_key, $requests, 120);
        
        // Consider high load if more than 50 requests in the last minute
        return count($requests) > 50;
    }
    
    /**
     * Check if there have been recent Redis connection issues
     */
    private function has_recent_redis_issues() {
        // Check multiple indicators of Redis issues
        $issue_indicators = [
            'redis_connection_failures',
            'redis_slow_operations', 
            'redis_timeouts',
            $this->circuit_breaker_key
        ];
        
        $recent_issues = 0;
        $check_period = 300; // Check last 5 minutes
        
        foreach ($issue_indicators as $indicator) {
            $issue_time = get_transient($indicator);
            if ($issue_time && (time() - $issue_time) < $check_period) {
                $recent_issues++;
            }
        }
        
        // If we have multiple recent issues, use aggressive timeouts
        return $recent_issues >= 2;
    }
    
    /**
     * Record a Redis issue for load balancing decisions
     */
    private function record_redis_issue($issue_type = 'connection_failure') {
        $issue_key = "redis_{$issue_type}";
        set_transient($issue_key, time(), 600); // Track for 10 minutes
    }
    
    /**
     * Get performance diagnostics for admin display
     */
    private function get_performance_diagnostics() {
        $diagnostics = [];
        
        // Check circuit breaker status
        if ($this->is_circuit_breaker_open()) {
            $diagnostics[] = "⚠️ Circuit Open";
        }
        
        // Check recent issues
        if ($this->has_recent_redis_issues()) {
            $diagnostics[] = "⚡ Fast-Fail Mode";
        }
        
        // Check load status
        if ($this->is_under_load()) {
            $diagnostics[] = "📈 High Load";
        }
        
        // Check recent issue types
        $issue_types = [
            'connection_failures' => 'Conn Fail',
            'slow_operations' => 'Slow Ops',
            'timeouts' => 'Timeouts'
        ];
        
        $recent_issues = [];
        foreach ($issue_types as $key => $label) {
            $issue_time = get_transient("redis_{$key}");
            if ($issue_time && (time() - $issue_time) < 300) {
                $recent_issues[] = $label;
            }
        }
        
        if (!empty($recent_issues)) {
            $diagnostics[] = "Issues: " . implode(', ', $recent_issues);
        }
        
        // Performance status
        if (empty($diagnostics)) {
            $diagnostics[] = "✅ Optimal";
        }
        
        return implode(' | ', $diagnostics);
    }
    
    /**
     * Scan Redis keys using SCAN instead of KEYS for better performance
     */
    private function scan_keys($pattern) {
        try {
            return $this->rtry(function($redis) use ($pattern) {
                $keys = [];
                $cursor = null;
                
                do {
                    $result = $redis->scan($cursor, $pattern, 1000);
                    if ($result !== false && !empty($result)) {
                        $keys = array_merge($keys, $result);
                    }
                } while ($cursor !== 0 && $cursor !== null);
                
                return $keys;
            });
        } catch (Exception $e) {
            error_log('Redis scan_keys failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Delete keys in chunks for better performance
     */
    private function del_keys_chunked($keys, $chunk_size = 1000) {
        if (empty($keys)) {
            return 0;
        }
        
        $total_deleted = 0;
        $chunks = array_chunk($keys, $chunk_size);
        
        foreach ($chunks as $chunk) {
            try {
                $deleted = $this->rtry(function($redis) use ($chunk) {
                    return $redis->del($chunk);
                });
                $total_deleted += $deleted;
            } catch (Exception $e) {
                error_log('Redis del_keys_chunked failed: ' . $e->getMessage());
                break; // Stop processing on error
            }
        }
        
        return $total_deleted;
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
        
        // Skip caching for query-based blocks that contain dynamic content
        if ($this->is_query_block($block_name)) {
            return $block_content;
        }
        
        try {
            // Generate cache key that includes block context and global state for better cache invalidation
            $cache_key = $this->generate_block_cache_key($block_name, $block, $block_content);
            
            // Try to get from cache first
            $cached = $this->rtry(function($redis) use ($cache_key) {
                return $redis->get($cache_key);
            });
            
            if ($cached !== false) {
                return $cached;
            }
            
            // Cache the rendered block (apply minification if enabled)
            $content_to_cache = $this->minify_content($block_content);
            
            $this->rtry(function($redis) use ($cache_key, $content_to_cache) {
                return $redis->setex($cache_key, intval($this->settings['ttl']), $content_to_cache);
            });
            
            return $block_content;
        } catch (Exception $e) {
            error_log('Block caching failed: ' . $e->getMessage());
            return $block_content;
        }
    }
    
    /**
     * Check if this is a query-based block that should not be cached
     */
    private function is_query_block($block_name) {
        $query_blocks = [
            'core/query',
            'core/post-template',
            'core/query-loop',
            'core/latest-posts',
            'core/latest-comments',
            'core/rss',
            'core/search',
            'core/tag-cloud',
            'core/categories',
            'core/archives'
        ];
        
        foreach ($query_blocks as $query_block) {
            if ($block_name === $query_block || strpos($block_name, $query_block) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Generate context-aware cache key for blocks
     */
    private function generate_block_cache_key($block_name, $block, $block_content) {
        // Include page context to prevent cross-page contamination
        global $post;
        $page_id = $post ? $post->ID : 0;
        
        // Include query variables that might affect block output
        $query_vars = [];
        if (is_single() || is_page()) {
            $query_vars['is_single'] = true;
            $query_vars['post_id'] = $page_id;
        } elseif (is_home()) {
            $query_vars['is_home'] = true;
            $query_vars['paged'] = get_query_var('paged', 1);
        } elseif (is_category()) {
            $query_vars['is_category'] = true;
            $query_vars['cat_id'] = get_query_var('cat');
        } elseif (is_tag()) {
            $query_vars['is_tag'] = true;
            $query_vars['tag_id'] = get_query_var('tag_id');
        } elseif (is_archive()) {
            $query_vars['is_archive'] = true;
            $query_vars['archive_type'] = get_post_type();
        }
        
        // Include user context for personalized content
        $user_context = [];
        if (is_user_logged_in()) {
            $user_context['logged_in'] = true;
            $user_context['user_id'] = get_current_user_id();
        }
        
        // Create cache key components
        $cache_components = [
            'block_name' => $block_name,
            'block_attrs' => $block['attrs'] ?? [],
            'query_vars' => $query_vars,
            'user_context' => $user_context,
            // Include a content hash for blocks that might have dynamic inner content
            'content_hash' => md5($block_content)
        ];
        
        // Don't include innerContent in the key as it causes circular reference issues
        return 'block_cache:' . md5(serialize($cache_components));
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

    public function show_version_notice() {
        $current_user = wp_get_current_user();
        $notice_key = 'ace_redis_cache_0_4_1_notice_dismissed_' . $current_user->ID;
        
        // Don't show if already dismissed
        if (get_user_meta($current_user->ID, $notice_key, true)) {
            return;
        }
        
        // Only show on admin pages
        if (!current_user_can('manage_options')) {
            return;
        }
        
        echo '<div class="notice notice-info is-dismissible" data-notice="' . esc_attr($notice_key) . '">';
        echo '<p><strong>Ace Redis Cache v0.4.1 Update:</strong> ';
        echo 'New TLS/SNI support for AWS ElastiCache, improved timeout handling, and enhanced block exclusions for Query Loops. ';
        echo '<a href="' . admin_url('options-general.php?page=ace-redis-cache') . '">Review Settings</a> | ';
        echo '<a href="#" onclick="jQuery(this).closest(\'.notice\').fadeOut(); jQuery.post(ajaxurl, {action: \'dismiss_ace_redis_notice\', notice: \'' . esc_js($notice_key) . '\', nonce: \'' . wp_create_nonce('dismiss_notice') . '\'});">Dismiss</a>';
        echo '</p></div>';
        
        // Add AJAX handler for dismissal
        add_action('wp_ajax_dismiss_ace_redis_notice', function() {
            if (!wp_verify_nonce($_POST['nonce'], 'dismiss_notice')) {
                wp_die('Invalid nonce');
            }
            
            $notice_key = sanitize_text_field($_POST['notice']);
            $current_user = wp_get_current_user();
            update_user_meta($current_user->ID, $notice_key, '1');
            wp_send_json_success();
        });
    }

    public function register_settings() {
        register_setting('ace_redis_cache_group', 'ace_redis_cache_settings');
    }
    
    /**
     * Clear all cache when settings are changed
     */
    public function clear_all_cache_on_settings_change($old_value, $new_value) {
        try {
            // Clear all cache types using scan instead of keys
            $page_keys = $this->scan_keys($this->cache_prefix . '*');
            $block_keys = $this->scan_keys('block_cache:*');
            $object_keys = $this->scan_keys('wp_cache_*');
            $transient_keys = $this->scan_keys('_transient_*');
            
            $all_keys = array_merge($page_keys, $block_keys, $object_keys, $transient_keys);
            
            if (!empty($all_keys)) {
                $deleted = $this->del_keys_chunked($all_keys);
                error_log("Ace Redis Cache: Cleared {$deleted} cache entries due to settings change");
            } else {
                error_log('Ace Redis Cache: No cache entries found to clear');
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
            echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved and all caches cleared! (Ace Redis Cache v0.4.1)</strong></p></div>';
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
                <button id="ace-redis-cache-diagnostics-btn" class="button button-secondary">System Diagnostics</button>
                <button id="ace-redis-cache-flush-btn" class="button">Flush All Cache</button>
                <button id="ace-redis-cache-flush-blocks-btn" class="button button-secondary">Flush Block Cache Only</button>
                <span id="ace-redis-cache-connection"></span>
                <br>
                <strong>Cache Size:</strong> <span id="ace-redis-cache-size">-</span>
                <div id="ace-redis-cache-diagnostics" style="margin-top: 10px; display: none; background: #f0f8ff; border: 1px solid #0073aa; padding: 15px; border-radius: 4px;">
                    <h4>System Diagnostics</h4>
                    <pre id="ace-redis-cache-diagnostics-content" style="white-space: pre-wrap; font-size: 12px;"></pre>
                </div>
            </div>
            <form method="post" action="options.php">
                <?php settings_fields('ace_redis_cache_group'); ?>
                <?php $opts = get_option('ace_redis_cache_settings', $this->settings); ?>
                <table class="form-table">
                    <tr><th>Enable Cache</th><td><input type="checkbox" name="ace_redis_cache_settings[enabled]" value="1" <?php checked($opts['enabled'], 1); ?>></td></tr>
                    <tr><th>Redis Host</th>
                        <td>
                            <input type="text" name="ace_redis_cache_settings[host]" value="<?php echo esc_attr($opts['host']); ?>" placeholder="localhost">
                            <p class="description">
                                <strong>Examples:</strong><br>
                                • Local Redis: <code>localhost</code> or <code>127.0.0.1</code><br>
                                • AWS ElastiCache: <code>clustercfg.my-cache.abc123.usw2.cache.amazonaws.com</code><br>
                                • Unix Socket: <code>/var/run/redis/redis-server.sock</code><br>
                                <strong>Note:</strong> For TLS connections, just enter the hostname — do not prefix the hostname with <code>tls://</code> — use the TLS checkbox below instead.
                            </p>
                        </td>
                    </tr>
                    <tr><th>Redis Port</th>
                        <td>
                            <input type="number" name="ace_redis_cache_settings[port]" value="<?php echo esc_attr($opts['port']); ?>" placeholder="6379">
                            <p class="description">Standard Redis & AWS ElastiCache (TLS or plain): <code>6379</code></p>
                        </td>
                    </tr>
                    <tr><th>Redis Password</th><td><input type="password" name="ace_redis_cache_settings[password]" value="<?php echo esc_attr($opts['password']); ?>" placeholder="Optional - leave blank for no auth"></td></tr>
                    <tr><th>Enable TLS/SSL</th>
                        <td>
                            <input type="checkbox" name="ace_redis_cache_settings[enable_tls]" value="1" <?php checked($opts['enable_tls'] ?? 0, 1); ?>>
                            <p class="description">
                                <strong>Enable TLS encryption for secure Redis connections.</strong><br>
                                • <strong>Required</strong> for AWS ElastiCache/Valkey with "encryption in transit"<br>
                                • Automatically enables SNI (Server Name Indication) for proper certificate validation<br>
                                • Do not prefix the hostname with <code>tls://</code> — use the TLS checkbox instead<br>
                                • For AWS: Use port 6379 (TLS is negotiated by the client). Do not use 6380.
                            </p>
                        </td>
                    </tr>
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
                    
                    <tr>
                        <th>Enable HTML/CSS/JS Minification</th>
                        <td>
                            <input type="checkbox" name="ace_redis_cache_settings[enable_minification]" value="1" <?php checked($opts['enable_minification'] ?? 0, 1); ?>>
                            <p class="description">Minify inline HTML, CSS, and JavaScript before caching to reduce file sizes and improve TTFB. <strong>Only affects inline code, not external files.</strong></p>
                            <p class="description"><strong>Features:</strong> Removes comments, unnecessary whitespace, and formatting while preserving functionality. Applied to cached content for optimal performance.</p>
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
                <h2>Connection Examples</h2>
                <div style="display: flex; gap: 20px; margin-top: 15px;">
                    <div style="flex: 1;">
                        <h4 style="color: #0073aa;">🔒 AWS ElastiCache/Valkey (TLS)</h4>
                        <ul>
                            <li><strong>Host:</strong> <code>master.development.bkuezy.euw2.cache.amazonaws.com</code></li>
                            <li><strong>Port:</strong> <code>6379</code></li>
                            <li><strong>Enable TLS/SSL:</strong> ✅ Checked</li>
                            <li><strong>Password:</strong> Leave empty (unless AUTH enabled)</li>
                            <li><strong>SNI:</strong> automatic</li>
                        </ul>
                    </div>
                    
                    <div style="flex: 1;">
                        <h4 style="color: #0073aa;">🌐 Standard Redis (TCP)</h4>
                        <ul>
                            <li><strong>Host:</strong> <code>127.0.0.1</code> or <code>localhost</code></li>
                            <li><strong>Port:</strong> <code>6379</code></li>
                            <li><strong>Enable TLS/SSL:</strong> ❌ Unchecked</li>
                            <li><strong>Password:</strong> Your Redis password (if set)</li>
                        </ul>
                    </div>
                </div>
                
                <div style="margin-top: 15px; padding: 15px; background: #e7f3ff; border-left: 4px solid #0073aa;">
                    <h4>🔧 Unix Socket Connection</h4>
                    <p>For local high-performance Redis connections, you can use Unix sockets:</p>
                    <ul>
                        <li><strong>Host:</strong> <code>/var/run/redis/redis.sock</code> or <code>unix:///var/run/redis/redis.sock</code></li>
                        <li><strong>Port:</strong> <code>0</code> (ignored for Unix sockets)</li>
                        <li><strong>Enable TLS/SSL:</strong> ❌ Not applicable for Unix sockets</li>
                    </ul>
                </div>
            </div>
            
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
            
            $('#ace-redis-cache-diagnostics-btn').on('click', function(e) {
                e.preventDefault();
                var $diagnosticsDiv = $('#ace-redis-cache-diagnostics');
                var $diagnosticsContent = $('#ace-redis-cache-diagnostics-content');
                
                if ($diagnosticsDiv.is(':visible')) {
                    $diagnosticsDiv.hide();
                    $(this).text('System Diagnostics');
                    return;
                }
                
                $(this).text('Loading...');
                $.post(AceRedisCacheAjax.ajax_url, {action:'ace_redis_cache_diagnostics', nonce:AceRedisCacheAjax.nonce}, function(res) {
                    $('#ace-redis-cache-diagnostics-btn').text('Hide Diagnostics');
                    if(res.success) {
                        $diagnosticsContent.text(res.data.diagnostics);
                        $diagnosticsDiv.show();
                    } else {
                        $diagnosticsContent.text('Failed to load diagnostics: ' + (res.data || 'Unknown error'));
                        $diagnosticsDiv.show();
                    }
                }).fail(function() {
                    $('#ace-redis-cache-diagnostics-btn').text('System Diagnostics');
                    alert('Diagnostics AJAX failed - check console');
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
        
        // Check PHP Redis extension first
        if (!extension_loaded('redis')) {
            wp_send_json_success([
                'status' => 'ERROR: PHP Redis extension not installed',
                'size' => 0,
                'size_kb' => 0,
                'debug_info' => 'Install php-redis extension'
            ]);
            return;
        }
        
        try {
            $start_time = microtime(true);
            
            if (!$this->get_redis_connection()) {
                $status = 'Not connected';
                if (!empty($settings['enable_tls'])) {
                    $status = 'Not connected (TLS required)';
                }
                wp_send_json_success(['status'=>$status,'size'=>0,'size_kb'=>0]);
                return;
            }

            // Send a single ping and measure RTT
            $ping_start = microtime(true);
            $this->rtry(function($redis) {
                return $redis->ping();
            });
            $rtt_ms = round((microtime(true) - $ping_start) * 1000, 1);

            // Calculate connection latency
            $connection_time = microtime(true) - $start_time;
            $latency_ms = round($connection_time * 1000, 1);
            
            // Base status with TLS info
            $status = 'Connected';
            if (!empty($settings['enable_tls'])) {
                $status .= ' (TLS)';
                if ($latency_ms > 50) {
                    $status .= ' - Check TLS overhead';
                }
            }
            
            // Add latency hint
            if ($latency_ms > 100) {
                $status .= ' - High latency (' . $latency_ms . 'ms)';
            } else if ($latency_ms > 50) {
                $status .= ' - Moderate latency (' . $latency_ms . 'ms)';
            } else if ($latency_ms < 10) {
                $status .= ' - Fast (' . $latency_ms . 'ms)';
            }
            
            // Count different cache types using scan instead of keys
            $page_keys = $this->scan_keys($this->cache_prefix . '*');
            $block_keys = $this->scan_keys('block_cache:*');
            $object_keys = $this->scan_keys('wp_cache_*');
            $transient_keys = $this->scan_keys('_transient_*');
            
            $page_count = count($page_keys);
            $block_count = count($block_keys);
            $object_count = count($object_keys);
            $transient_count = count($transient_keys);
            
            // Total cache entries managed by this plugin
            $total_cache_size = $page_count + $block_count + $object_count + $transient_count;
            
            // Count all keys for debugging
            $all_keys = $this->scan_keys('*');
            $all_keys_count = count($all_keys);

            // Calculate total bytes for all cache types
            $totalBytes = 0;
            $all_cache_keys = array_merge($page_keys, $block_keys, $object_keys, $transient_keys);
            
            foreach ($all_cache_keys as $key) {
                try {
                    $len = $this->rtry(function($redis) use ($key) {
                        return $redis->strlen($key);
                    });
                    if ($len !== false) $totalBytes += $len;
                } catch (Exception $e) {
                    // Skip failed strlen operations
                }
            }
            $size_kb = round($totalBytes / 1024, 2);

            // Build detailed cache breakdown
            $cache_breakdown = [];
            if ($page_count > 0) $cache_breakdown[] = "Pages: {$page_count}";
            if ($block_count > 0) $cache_breakdown[] = "Blocks: {$block_count}";
            if ($object_count > 0) $cache_breakdown[] = "Objects: {$object_count}";
            if ($transient_count > 0) $cache_breakdown[] = "Transients: {$transient_count}";
            
            // Add performance and timeout diagnostics
            $performance_info = $this->get_performance_diagnostics();
            
            $debug_info = implode(', ', $cache_breakdown) . " | Total Redis: {$all_keys_count} | RTT: {$rtt_ms}ms";
            if (!empty($performance_info)) {
                $debug_info .= " | " . $performance_info;
            }
            
            // Add TLS-specific debug info
            if (!empty($settings['enable_tls'])) {
                $debug_info .= " | TLS: Enabled";
                if ($latency_ms > 50) {
                    $debug_info .= " (High latency - consider TLS optimization)";
                }
            }

            wp_send_json_success([
                'status' => $status,
                'size'   => $total_cache_size,
                'size_kb' => $size_kb,
                'debug_info' => $debug_info,
                'latency_ms' => $latency_ms
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
            if (!$this->get_redis_connection()) {
                wp_send_json_error('Not connected');
                return;
            }

            // Test write
            $test_key = 'test_write_' . time();
            $test_value = 'PHP Redis test at ' . date('Y-m-d H:i:s');
            
            $write_result = $this->rtry(function($redis) use ($test_key, $test_value) {
                return $redis->setex($test_key, 10, $test_value);
            });
            
            $read_result = $this->rtry(function($redis) use ($test_key) {
                return $redis->get($test_key);
            });
            
            // Clean up
            $this->rtry(function($redis) use ($test_key) {
                return $redis->del($test_key);
            });
            
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
            // Clear all cache types using scan instead of keys
            $page_keys = $this->scan_keys($this->cache_prefix . '*');
            $block_keys = $this->scan_keys('block_cache:*');
            $object_keys = $this->scan_keys('wp_cache_*');
            $transient_keys = $this->scan_keys('_transient_*');
            
            $all_keys = array_merge($page_keys, $block_keys, $object_keys, $transient_keys);
            
            if (!empty($all_keys)) {
                $deleted = $this->del_keys_chunked($all_keys);
                wp_send_json_success(['deleted' => $deleted]);
            } else {
                wp_send_json_success(['deleted' => 0]);
            }
        } catch (Exception $e) {
            error_log('Ajax flush failed: ' . $e->getMessage());
            wp_send_json_error(false);
        }
    }

    /** AJAX: Flush Block Cache Only **/
    public function ajax_flush_blocks() {
        check_ajax_referer('ace_redis_cache_flush', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('No permission');

        try {
            $keys = $this->scan_keys('block_cache:*');
            if (!empty($keys)) {
                $deleted = $this->del_keys_chunked($keys);
                wp_send_json_success(['message' => "Block cache cleared ({$deleted} keys)"]);
            } else {
                wp_send_json_success(['message' => 'No block cache found']);
            }
        } catch (Exception $e) {
            error_log('Ajax flush blocks failed: ' . $e->getMessage());
            wp_send_json_error(false);
        }
    }

    /** AJAX: System Diagnostics **/
    public function ajax_diagnostics() {
        check_ajax_referer('ace_redis_cache_status', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('No permission');

        $diagnostics = [];
        
        // PHP Environment
        $diagnostics[] = "=== PHP Environment ===";
        $diagnostics[] = "PHP Version: " . PHP_VERSION;
        $diagnostics[] = "Redis Extension: " . (extension_loaded('redis') ? 'LOADED (v' . phpversion('redis') . ')' : 'NOT LOADED');
        
        if (extension_loaded('redis')) {
            $diagnostics[] = "Redis Class Available: " . (class_exists('Redis') ? 'YES' : 'NO');
        }
        
        // Current Settings
        $diagnostics[] = "\n=== Plugin Settings ===";
        $diagnostics[] = "Host: " . ($this->settings['host'] ?? 'not set');
        $diagnostics[] = "Port: " . ($this->settings['port'] ?? 'not set');
        $diagnostics[] = "Password: " . (empty($this->settings['password']) ? 'not set' : 'set');
        $diagnostics[] = "TLS Enabled: " . (($this->settings['enable_tls'] ?? 0) ? 'YES' : 'NO');
        $diagnostics[] = "TTL: " . ($this->settings['ttl'] ?? 'not set') . " seconds";
        $diagnostics[] = "Mode: " . ($this->settings['mode'] ?? 'not set');
        $diagnostics[] = "Plugin Enabled: " . (($this->settings['enabled'] ?? 0) ? 'YES' : 'NO');
        
        // Connection Status
        $diagnostics[] = "\n=== Connection Status ===";
        $diagnostics[] = "Current Connection: " . ($this->redis && $this->redis->isConnected() ? 'CONNECTED' : 'NOT CONNECTED');
        $diagnostics[] = "Circuit Breaker: " . ($this->is_circuit_breaker_open() ? 'OPEN (blocking connections)' : 'CLOSED');
        $diagnostics[] = "Under Load: " . ($this->is_under_load() ? 'YES' : 'NO');
        $diagnostics[] = "Recent Issues: " . ($this->has_recent_redis_issues() ? 'YES' : 'NO');
        
        // WordPress Environment
        $diagnostics[] = "\n=== WordPress Environment ===";
        $diagnostics[] = "WordPress Version: " . get_bloginfo('version');
        $diagnostics[] = "Is Admin: " . (is_admin() ? 'YES' : 'NO');
        $diagnostics[] = "Site URL: " . site_url();
        $diagnostics[] = "Current User Can Manage: " . (current_user_can('manage_options') ? 'YES' : 'NO');
        
        // Server Environment  
        $diagnostics[] = "\n=== Server Environment ===";
        $diagnostics[] = "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown');
        $diagnostics[] = "Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'unknown');
        $diagnostics[] = "HTTPS: " . (is_ssl() ? 'YES' : 'NO');
        
        // Network Tests
        $diagnostics[] = "\n=== Network Diagnostics ===";
        
        $host = $this->settings['host'] ?? 'localhost';
        $port = intval($this->settings['port'] ?? 6379);
        $enable_tls = !empty($this->settings['enable_tls']);
        
        if (!empty($host) && $port > 0) {
            if (!str_starts_with($host, '/') && !str_starts_with($host, 'unix://')) {
                // Connection test based on TLS setting
                $clean_host = str_starts_with($host, 'tls://') ? substr($host, 6) : $host;
                
                if ($enable_tls) {
                    // TLS connection test using stream_socket_client
                    $socket_timeout = 5;
                    $errno = 0;
                    $errstr = '';
                    
                    $socket = @stream_socket_client(
                        "ssl://{$clean_host}:{$port}", 
                        $errno, 
                        $errstr, 
                        $socket_timeout,
                        STREAM_CLIENT_CONNECT
                    );
                    
                    if ($socket) {
                        $diagnostics[] = "TLS Connection: SUCCESS (port {$port} is reachable via TLS)";
                        fclose($socket);
                    } else {
                        $diagnostics[] = "TLS Connection: FAILED (Error {$errno}: {$errstr})";
                        $diagnostics[] = "TLS handshake failed — check security group and that the client is in the same VPC/subnet, and ensure port 6379 is open from the EC2 SG to the ElastiCache SG.";
                    }
                } else {
                    // Standard TCP connection test
                    $socket_timeout = 5;
                    $errno = 0;
                    $errstr = '';
                    
                    $socket = @fsockopen($clean_host, $port, $errno, $errstr, $socket_timeout);
                    if ($socket) {
                        $diagnostics[] = "TCP Connection: SUCCESS (port {$port} is reachable)";
                        fclose($socket);
                    } else {
                        $diagnostics[] = "TCP Connection: FAILED (Error {$errno}: {$errstr})";
                        $diagnostics[] = "This usually means Redis is not running or port {$port} is blocked";
                    }
                }
            } else {
                $socket_path = str_starts_with($host, 'unix://') ? substr($host, 7) : $host;
                if (file_exists($socket_path)) {
                    $diagnostics[] = "Unix Socket: EXISTS at {$socket_path}";
                    if (is_readable($socket_path)) {
                        $diagnostics[] = "Unix Socket: READABLE";
                    } else {
                        $diagnostics[] = "Unix Socket: NOT READABLE (check permissions)";
                    }
                } else {
                    $diagnostics[] = "Unix Socket: NOT FOUND at {$socket_path}";
                }
            }
        }
        
        // Add TLS and SNI info
        $diagnostics[] = $enable_tls ? "TLS: YES" : "TLS: NO";
        if ($enable_tls) {
            $diagnostics[] = "SNI: set to {$host}";
        }
        
        wp_send_json_success(['diagnostics' => implode("\n", $diagnostics)]);
    }

    /** HTML/CSS/JS Minification **/
    
    /**
     * Minify HTML content by removing unnecessary whitespace, comments, and line breaks
     */
    private function minify_html($html) {
        if (empty($this->settings['enable_minification'])) {
            return $html;
        }

        // Preserve pre, code, textarea, and script content
        $preserve_tags = [];
        $preserve_patterns = [
            '/<(pre|code|textarea|script|style)[^>]*>.*?<\/\1>/is'
        ];
        
        foreach ($preserve_patterns as $i => $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[0] as $j => $match) {
                    $placeholder = "___PRESERVE_TAG_{$i}_{$j}___";
                    $preserve_tags[$placeholder] = $match;
                    $html = str_replace($match, $placeholder, $html);
                }
            }
        }

        // Remove HTML comments (but preserve conditional comments)
        $html = preg_replace('/<!--(?!\[if|<!\[endif).*?-->/s', '', $html);
        
        // Remove unnecessary whitespace between tags
        $html = preg_replace('/>\s+</', '><', $html);
        
        // Remove whitespace at the beginning/end of lines
        $html = preg_replace('/^\s+|\s+$/m', '', $html);
        
        // Collapse multiple whitespace characters into single space
        $html = preg_replace('/\s+/', ' ', $html);
        
        // Remove whitespace around specific elements
        $html = preg_replace('/\s*(<\/?(html|head|body|title|meta|link|script|style)[^>]*>)\s*/i', '$1', $html);
        
        // Restore preserved content
        foreach ($preserve_tags as $placeholder => $content) {
            $html = str_replace($placeholder, $content, $html);
        }

        return trim($html);
    }

    /**
     * Minify inline CSS by removing comments, unnecessary whitespace, and formatting
     */
    private function minify_inline_css($html) {
        if (empty($this->settings['enable_minification'])) {
            return $html;
        }

        return preg_replace_callback('/<style[^>]*>(.*?)<\/style>/is', function($matches) {
            $css = $matches[1];
            
            // Remove CSS comments
            $css = preg_replace('/\/\*.*?\*\//s', '', $css);
            
            // Remove unnecessary whitespace
            $css = preg_replace('/\s+/', ' ', $css);
            
            // Remove whitespace around specific characters
            $css = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $css);
            
            // Remove trailing semicolons before closing braces
            $css = preg_replace('/;+\s*}/', '}', $css);
            
            return '<style' . (isset($matches[0]) ? substr($matches[0], 6, strpos($matches[0], '>') - 6) : '') . '>' . trim($css) . '</style>';
        }, $html);
    }

    /**
     * Minify inline JavaScript by removing comments, unnecessary whitespace, and formatting
     */
    private function minify_inline_js($html) {
        if (empty($this->settings['enable_minification'])) {
            return $html;
        }

        return preg_replace_callback('/<script[^>]*(?!.*src=)[^>]*>(.*?)<\/script>/is', function($matches) {
            $js = $matches[1];
            
            // Skip if script has src attribute (external file)
            if (strpos($matches[0], 'src=') !== false) {
                return $matches[0];
            }
            
            // Remove single-line comments (// comments)
            $js = preg_replace('/\/\/.*?$/m', '', $js);
            
            // Remove multi-line comments (/* comments */)
            $js = preg_replace('/\/\*.*?\*\//s', '', $js);
            
            // Remove unnecessary whitespace (but preserve strings)
            $js = preg_replace('/\s+/', ' ', $js);
            
            // Remove whitespace around operators and punctuation
            $js = preg_replace('/\s*([{}();:,=+\-*\/])\s*/', '$1', $js);
            
            // Remove leading/trailing whitespace
            $js = trim($js);
            
            return '<script' . (isset($matches[0]) ? substr($matches[0], 7, strpos($matches[0], '>') - 7) : '') . '>' . $js . '</script>';
        }, $html);
    }

    /**
     * Apply all minification techniques to HTML content
     */
    private function minify_content($html) {
        if (empty($this->settings['enable_minification'])) {
            return $html;
        }

        // Apply minification in order: CSS first, then JS, then HTML
        $html = $this->minify_inline_css($html);
        $html = $this->minify_inline_js($html);
        $html = $this->minify_html($html);
        
        return $html;
    }

    /** Full Page Cache **/
    private function setup_full_page_cache() {
        add_action('template_redirect', function () {
            if (is_user_logged_in() || $_SERVER['REQUEST_METHOD'] !== 'GET') return;
            
            // Check if current page should be excluded from page caching
            if ($this->should_exclude_from_page_cache()) {
                return;
            }

            try {
                $cacheKey = $this->cache_prefix . md5($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);

                $cached = $this->rtry(function($redis) use ($cacheKey) {
                    return $redis->get($cacheKey);
                });

                if ($cached) {
                    header('X-Cache: HIT');
                    echo $cached;
                    exit;
                }

                // Capture page content
                ob_start();
                header('X-Cache: MISS');

                add_action('shutdown', function () use ($cacheKey) {
                    $buffer = ob_get_clean();
                    if ($buffer !== false) {
                        echo $buffer; // Output original content to user
                        
                        // Cache the content if it doesn't contain excluded patterns
                        if (!$this->should_exclude_content_from_cache($buffer)) {
                            try {
                                // Apply minification before caching
                                $minified_buffer = $this->minify_content($buffer);
                                
                                $this->rtry(function($redis) use ($cacheKey, $minified_buffer) {
                                    return $redis->setex($cacheKey, intval($this->settings['ttl']), $minified_buffer);
                                });
                            } catch (Exception $e) {
                                error_log('Page cache storage failed: ' . $e->getMessage());
                            }
                        }
                    }
                }, 0);
            } catch (Exception $e) {
                error_log('Full page cache failed: ' . $e->getMessage());
                // Let page load normally without caching
            }
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
        
        // Site transients
        add_filter('pre_set_site_transient', [$this, 'redis_set_site_transient'], 10, 3);
        add_filter('pre_get_site_transient', [$this, 'redis_get_site_transient'], 10, 2);
        add_filter('pre_delete_site_transient', [$this, 'redis_delete_site_transient'], 10, 2);
        
        // Override WordPress object cache functions with custom implementations
        $this->override_wp_cache_functions();
    }
    
    /**
     * Store transient in Redis
     */
    public function redis_set_transient($value, $transient, $expiration) {
        // Check if this transient should be excluded
        if ($this->should_exclude_transient($transient)) {
            return false; // Let WordPress handle it normally
        }
        
        try {
            $key = "_transient_{$transient}";
            $expiration = intval($expiration) ?: intval($this->settings['ttl']);
            
            $result = $this->rtry(function($redis) use ($key, $expiration, $value) {
                return $redis->setex($key, $expiration, serialize($value));
            });
            
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
        
        try {
            $key = "_transient_{$transient}";
            
            $cached = $this->rtry(function($redis) use ($key) {
                return $redis->get($key);
            });
            
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
        
        try {
            $key = "_transient_{$transient}";
            
            $this->rtry(function($redis) use ($key) {
                return $redis->del($key);
            });
            
            return true; // Indicate we handled it
        } catch (Exception $e) {
            error_log('Redis transient delete failed: ' . $e->getMessage());
            return false; // Fall back to database
        }
    }
    
    /**
     * Site transient functions
     */
    public function redis_set_site_transient($value, $transient, $expiration) {
        // Check if this transient should be excluded
        if ($this->should_exclude_transient($transient)) {
            return false; // Let WordPress handle it normally
        }
        
        try {
            $key = "_site_transient_{$transient}";
            $expiration = intval($expiration) ?: intval($this->settings['ttl']);
            
            $result = $this->rtry(function($redis) use ($key, $expiration, $value) {
                return $redis->setex($key, $expiration, serialize($value));
            });
            
            return $value; // Return the value to indicate we handled it
        } catch (Exception $e) {
            error_log('Redis site transient set failed: ' . $e->getMessage());
            return false; // Fall back to database
        }
    }
    
    public function redis_get_site_transient($value, $transient) {
        // Check if this transient should be excluded
        if ($this->should_exclude_transient($transient)) {
            return false; // Let WordPress handle it normally
        }
        
        try {
            $key = "_site_transient_{$transient}";
            
            $cached = $this->rtry(function($redis) use ($key) {
                return $redis->get($key);
            });
            
            if ($cached !== false) {
                return unserialize($cached);
            }
            return false; // Not found in cache
        } catch (Exception $e) {
            error_log('Redis site transient get failed: ' . $e->getMessage());
            return false; // Fall back to database
        }
    }
    
    public function redis_delete_site_transient($value, $transient) {
        // Check if this transient should be excluded
        if ($this->should_exclude_transient($transient)) {
            return false; // Let WordPress handle it normally
        }
        
        try {
            $key = "_site_transient_{$transient}";
            
            $this->rtry(function($redis) use ($key) {
                return $redis->del($key);
            });
            
            return true; // Indicate we handled it
        } catch (Exception $e) {
            error_log('Redis site transient delete failed: ' . $e->getMessage());
            return false; // Fall back to database
        }
    }
    
    /**
     * Override WordPress object cache functions
     */
    private function override_wp_cache_functions() {
        if (!function_exists('wp_cache_get_original')) {
            // Store original functions for fallback
            if (function_exists('wp_cache_get')) {
                function wp_cache_get_original($key, $group = 'default') {
                    global $wp_object_cache;
                    return $wp_object_cache->get($key, $group);
                }
            }
            
            if (function_exists('wp_cache_set')) {
                function wp_cache_set_original($key, $data, $group = 'default', $expire = 0) {
                    global $wp_object_cache;
                    return $wp_object_cache->set($key, $data, $group, $expire);
                }
            }
            
            if (function_exists('wp_cache_delete')) {
                function wp_cache_delete_original($key, $group = 'default') {
                    global $wp_object_cache;
                    return $wp_object_cache->delete($key, $group);
                }
            }
            
            if (function_exists('wp_cache_add')) {
                function wp_cache_add_original($key, $data, $group = 'default', $expire = 0) {
                    global $wp_object_cache;
                    return $wp_object_cache->add($key, $data, $group, $expire);
                }
            }
            
            if (function_exists('wp_cache_replace')) {
                function wp_cache_replace_original($key, $data, $group = 'default', $expire = 0) {
                    global $wp_object_cache;
                    return $wp_object_cache->replace($key, $data, $group, $expire);
                }
            }
            
            if (function_exists('wp_cache_incr')) {
                function wp_cache_incr_original($key, $offset = 1, $group = 'default') {
                    global $wp_object_cache;
                    return $wp_object_cache->incr($key, $offset, $group);
                }
            }
            
            if (function_exists('wp_cache_decr')) {
                function wp_cache_decr_original($key, $offset = 1, $group = 'default') {
                    global $wp_object_cache;
                    return $wp_object_cache->decr($key, $offset, $group);
                }
            }
            
            // Override with our Redis implementations
            add_filter('wp_cache_get', [$this, 'redis_wp_cache_get_override'], 10, 2);
            add_filter('wp_cache_set', [$this, 'redis_wp_cache_set_override'], 10, 4);
            add_filter('wp_cache_delete', [$this, 'redis_wp_cache_delete_override'], 10, 2);
            add_filter('wp_cache_add', [$this, 'redis_wp_cache_add_override'], 10, 4);
            add_filter('wp_cache_replace', [$this, 'redis_wp_cache_replace_override'], 10, 4);
            add_filter('wp_cache_incr', [$this, 'redis_wp_cache_incr_override'], 10, 3);
            add_filter('wp_cache_decr', [$this, 'redis_wp_cache_decr_override'], 10, 3);
        }
    }
    
    /**
     * Redis-backed wp_cache_get override
     */
    public function redis_wp_cache_get_override($key, $group = 'default') {
        // Check if this cache key should be excluded
        if ($this->should_exclude_cache_key($key)) {
            return wp_cache_get_original($key, $group);
        }
        
        try {
            $cache_key = "wp_cache_{$group}:{$key}";
            
            $cached = $this->rtry(function($redis) use ($cache_key) {
                return $redis->get($cache_key);
            });
            
            if ($cached !== false) {
                return unserialize($cached);
            }
            return false; // Not found in cache
        } catch (Exception $e) {
            error_log('Redis wp_cache get failed: ' . $e->getMessage());
            return wp_cache_get_original($key, $group);
        }
    }
    
    /**
     * Redis-backed wp_cache_set override
     */
    public function redis_wp_cache_set_override($key, $data, $group = 'default', $expire = 0) {
        // Check if this cache key should be excluded
        if ($this->should_exclude_cache_key($key)) {
            return wp_cache_set_original($key, $data, $group, $expire);
        }
        
        try {
            $cache_key = "wp_cache_{$group}:{$key}";
            $expire = intval($expire) ?: intval($this->settings['ttl']);
            
            $result = $this->rtry(function($redis) use ($cache_key, $expire, $data) {
                return $redis->setex($cache_key, $expire, serialize($data));
            });
            
            return $result;
        } catch (Exception $e) {
            error_log('Redis wp_cache set failed: ' . $e->getMessage());
            return wp_cache_set_original($key, $data, $group, $expire);
        }
    }
    
    /**
     * Redis-backed wp_cache_delete override
     */
    public function redis_wp_cache_delete_override($key, $group = 'default') {
        // Check if this cache key should be excluded
        if ($this->should_exclude_cache_key($key)) {
            return wp_cache_delete_original($key, $group);
        }
        
        try {
            $cache_key = "wp_cache_{$group}:{$key}";
            
            $result = $this->rtry(function($redis) use ($cache_key) {
                return $redis->del($cache_key);
            });
            
            return $result > 0;
        } catch (Exception $e) {
            error_log('Redis wp_cache delete failed: ' . $e->getMessage());
            return wp_cache_delete_original($key, $group);
        }
    }
    
    /**
     * Redis-backed wp_cache_add override
     */
    public function redis_wp_cache_add_override($key, $data, $group = 'default', $expire = 0) {
        // Check if this cache key should be excluded
        if ($this->should_exclude_cache_key($key)) {
            return wp_cache_add_original($key, $data, $group, $expire);
        }
        
        try {
            $cache_key = "wp_cache_{$group}:{$key}";
            
            // Check if key already exists
            $exists = $this->rtry(function($redis) use ($cache_key) {
                return $redis->exists($cache_key);
            });
            
            if ($exists) {
                return false;
            }
            
            $expire = intval($expire) ?: intval($this->settings['ttl']);
            
            $result = $this->rtry(function($redis) use ($cache_key, $expire, $data) {
                return $redis->setex($cache_key, $expire, serialize($data));
            });
            
            return $result;
        } catch (Exception $e) {
            error_log('Redis wp_cache add failed: ' . $e->getMessage());
            return wp_cache_add_original($key, $data, $group, $expire);
        }
    }
    
    /**
     * Redis-backed wp_cache_replace override
     */
    public function redis_wp_cache_replace_override($key, $data, $group = 'default', $expire = 0) {
        // Check if this cache key should be excluded
        if ($this->should_exclude_cache_key($key)) {
            return wp_cache_replace_original($key, $data, $group, $expire);
        }
        
        try {
            $cache_key = "wp_cache_{$group}:{$key}";
            
            // Check if key exists
            $exists = $this->rtry(function($redis) use ($cache_key) {
                return $redis->exists($cache_key);
            });
            
            if (!$exists) {
                return false;
            }
            
            $expire = intval($expire) ?: intval($this->settings['ttl']);
            
            $result = $this->rtry(function($redis) use ($cache_key, $expire, $data) {
                return $redis->setex($cache_key, $expire, serialize($data));
            });
            
            return $result;
        } catch (Exception $e) {
            error_log('Redis wp_cache replace failed: ' . $e->getMessage());
            return wp_cache_replace_original($key, $data, $group, $expire);
        }
    }
    
    /**
     * Redis-backed wp_cache_incr override
     */
    public function redis_wp_cache_incr_override($key, $offset = 1, $group = 'default') {
        // Check if this cache key should be excluded
        if ($this->should_exclude_cache_key($key)) {
            return wp_cache_incr_original($key, $offset, $group);
        }
        
        try {
            $cache_key = "wp_cache_{$group}:{$key}";
            
            return $this->rtry(function($redis) use ($cache_key, $offset) {
                return $redis->incrBy($cache_key, $offset);
            });
        } catch (Exception $e) {
            error_log('Redis wp_cache incr failed: ' . $e->getMessage());
            return wp_cache_incr_original($key, $offset, $group);
        }
    }
    
    /**
     * Redis-backed wp_cache_decr override
     */
    public function redis_wp_cache_decr_override($key, $offset = 1, $group = 'default') {
        // Check if this cache key should be excluded
        if ($this->should_exclude_cache_key($key)) {
            return wp_cache_decr_original($key, $offset, $group);
        }
        
        try {
            $cache_key = "wp_cache_{$group}:{$key}";
            
            return $this->rtry(function($redis) use ($cache_key, $offset) {
                return $redis->decrBy($cache_key, $offset);
            });
        } catch (Exception $e) {
            error_log('Redis wp_cache decr failed: ' . $e->getMessage());
            return wp_cache_decr_original($key, $offset, $group);
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
        
        try {
            $cache_key = "wp_cache_{$group}:{$key}";
            $expire = intval($expire) ?: intval($this->settings['ttl']);
            
            $this->rtry(function($redis) use ($cache_key, $expire, $data) {
                return $redis->setex($cache_key, $expire, serialize($data));
            });
            
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
        
        try {
            $cache_key = "wp_cache_{$group}:{$key}";
            
            $cached = $this->rtry(function($redis) use ($cache_key) {
                return $redis->get($cache_key);
            });
            
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
        
        try {
            $cache_key = "wp_cache_{$group}:{$key}";
            
            $this->rtry(function($redis) use ($cache_key) {
                return $redis->del($cache_key);
            });
            
            return true; // Indicate we handled it
        } catch (Exception $e) {
            error_log('Redis wp_cache delete failed: ' . $e->getMessage());
            return false; // Fall back to memory cache
        }
    }

    /** Redis Connection **/
    private function connect_redis($force_reconnect = false) {
        // Return existing connection if available and not forcing reconnect
        if (!$force_reconnect && $this->redis && $this->redis->isConnected()) {
            error_log('Ace Redis Cache: Using existing connection');
            return $this->redis;
        }
        
        // Check if Redis extension is loaded
        if (!extension_loaded('redis')) {
            error_log('Ace Redis Cache: CRITICAL - PHP Redis extension is not installed');
            return false;
        }
        
        error_log('Ace Redis Cache: PHP Redis extension version: ' . phpversion('redis'));
        
        $start_time = microtime(true);
        error_log('Ace Redis Cache: Starting connection attempt at ' . date('Y-m-d H:i:s'));
        
        try {
            $redis = new Redis();
            
            $host = $this->settings['host'] ?? 'localhost';
            $port = intval($this->settings['port'] ?? 6379);
            $password = $this->settings['password'] ?? '';
            $enable_tls = $this->settings['enable_tls'] ?? 0;
            
            error_log('Ace Redis Cache: Connection settings - Host: ' . $host . ', Port: ' . $port . ', TLS: ' . ($enable_tls ? 'YES' : 'NO') . ', Password: ' . (empty($password) ? 'NO' : 'YES'));
            
            // Set aggressive timeouts to prevent 504 errors
            // Use shorter timeouts if we're under load or circuit breaker was recently open
            $is_under_load = $this->is_under_load();
            $recent_issues = $this->has_recent_redis_issues();
            
            // Set explicit timeouts for faster fail semantics
            if ($is_under_load || $recent_issues) {
                // Very aggressive timeouts when under load
                $connect_timeout = is_admin() ? 1.5 : 1.0;
                $read_timeout = 3.0;
                error_log('Redis using fast-fail timeouts due to load/issues');
            } else {
                // Standard timeouts for normal conditions
                $connect_timeout = is_admin() ? 2.5 : 2.0;
                $read_timeout = 5.0;
            }
            
            // Check if it's a Unix socket path
            if (str_starts_with($host, '/') || str_starts_with($host, 'unix://')) {
                // Unix socket connection
                $socket_path = str_starts_with($host, 'unix://') ? substr($host, 7) : $host;
                error_log('Ace Redis Cache: Attempting Unix socket connection to: ' . $socket_path);
                $connect_result = $redis->pconnect($socket_path, 0, $connect_timeout, $this->redis_persistent_id);
                error_log('Ace Redis Cache: Unix socket pconnect result: ' . ($connect_result ? 'SUCCESS' : 'FAILED'));
            } else {
                // TCP connection with optional TLS
                if ($enable_tls || stripos($host, 'tls://') === 0) {
                    // Force TLS by using the scheme and pass SNI via the *ssl* context
                    $clean_host  = str_starts_with($host, 'tls://') ? substr($host, 6) : $host;
                    $scheme_host = 'tls://' . $clean_host;

                    $ssl_ctx = [
                        'ssl' => [
                            'peer_name' => $clean_host, // SNI
                            // leave verification ON and use system CAs
                            // (php.ini already points cafile to /etc/ssl/certs/ca-certificates.crt)
                        ]
                    ];

                    error_log('Ace Redis Cache: Attempting TLS connection to: ' . $clean_host . ':' . $port . ' with SNI (timeout: ' . $connect_timeout . 's)');

                    $connect_result = $redis->pconnect(
                        $scheme_host,
                        $port,
                        $connect_timeout,
                        $this->redis_persistent_id,
                        0,
                        0,
                        $ssl_ctx
                    );

                    error_log('Ace Redis Cache: TLS pconnect result: ' . ($connect_result ? 'SUCCESS' : 'FAILED'));
                } else {
                    // Standard TCP connection
                    error_log('Ace Redis Cache: Attempting standard TCP connection to: ' . $host . ':' . $port . ' (timeout: ' . $connect_timeout . 's)');
                    $connect_result = $redis->pconnect($host, $port, $connect_timeout, $this->redis_persistent_id);
                    error_log('Ace Redis Cache: Standard pconnect result: ' . ($connect_result ? 'SUCCESS' : 'FAILED'));
                }
            }
            
            if (!$connect_result) {
                error_log('Ace Redis Cache: Connection failed - pconnect returned false');
                return false;
            }

            // Set Redis options for better reliability and fast-fail behavior
            error_log('Ace Redis Cache: Setting Redis options - Read timeout: ' . $read_timeout . 's');
            $redis->setOption(Redis::OPT_READ_TIMEOUT, $read_timeout);
            $redis->setOption(Redis::OPT_TCP_KEEPALIVE, 1);
            $redis->setOption(Redis::OPT_MAX_RETRIES, 0); // No internal retries - we handle it in rtry()
            
            // Set TCP_NODELAY for faster response (reduce latency)
            if (defined('Redis::OPT_TCP_NODELAY')) {
                $redis->setOption(Redis::OPT_TCP_NODELAY, 1);
                error_log('Ace Redis Cache: TCP_NODELAY enabled');
            }
            
            error_log('Ace Redis Cache: Redis options set successfully');

            // Authenticate if password is provided
            if (!empty($password)) {
                error_log('Ace Redis Cache: Attempting authentication');
                if (!$redis->auth($password)) {
                    error_log('Ace Redis Cache: Authentication failed');
                    $this->record_redis_issue('connection_failures');
                    $redis->close();
                    return false;
                }
                error_log('Ace Redis Cache: Authentication successful');
            } else {
                error_log('Ace Redis Cache: No password provided, skipping auth');
            }

            // Test connection with ping/info compatibility
            error_log('Ace Redis Cache: Testing connection with ping/info');
            try {
                $redis->ping(); // Immediate ping test
                error_log('Ace Redis Cache: Ping successful');
            } catch (Exception $e) {
                error_log('Ace Redis Cache: Ping failed with exception: ' . $e->getMessage());
                // Fallback to INFO command exactly once for Valkey compatibility
                try {
                    error_log('Ace Redis Cache: Trying INFO command as fallback');
                    $info = $redis->info();
                    if (empty($info)) {
                        error_log('Ace Redis Cache: INFO command returned empty result');
                        $this->record_redis_issue('connection_failures');
                        $redis->close();
                        return false;
                    }
                    error_log('Ace Redis Cache: INFO command successful - connection verified');
                } catch (Exception $info_e) {
                    error_log('Ace Redis Cache: INFO command failed with exception: ' . $info_e->getMessage());
                    $this->record_redis_issue('connection_failures');
                    $redis->close();
                    return false;
                }
            }
            
            // Check if connection or ping/info exceeded timeout
            $connect_time = microtime(true) - $start_time;
            error_log('Ace Redis Cache: Total connection time: ' . round($connect_time, 3) . 's');
            if ($connect_time > $connect_timeout) {
                $this->record_redis_issue('timeouts');
                error_log("Ace Redis Cache: Connection/ping exceeded timeout ({$connect_time}s), opening circuit breaker");
                if (!is_admin()) {
                    $this->open_circuit_breaker();
                }
            } elseif ($connect_time > ($connect_timeout * 0.8)) {
                $this->record_redis_issue('slow_operations');
                error_log("Ace Redis Cache: Connection slow ({$connect_time}s), monitoring for issues");
            }
            
            error_log('Ace Redis Cache: Connection established successfully');
            return $redis;
            
        } catch (Exception $e) {
            $connect_time = microtime(true) - $start_time;
            
            // Record different types of connection failures
            if ($connect_time > $connect_timeout) {
                $this->record_redis_issue('timeouts');
                error_log('Ace Redis Cache: Connection timeout after ' . $connect_time . 's: ' . $e->getMessage());
            } else {
                $this->record_redis_issue('connection_failures');
                error_log('Ace Redis Cache: Connection failed after ' . $connect_time . 's: ' . $e->getMessage());
            }
            
            // Log detailed error information
            error_log('Ace Redis Cache: Exception class: ' . get_class($e));
            error_log('Ace Redis Cache: Exception code: ' . $e->getCode());
            error_log('Ace Redis Cache: Exception file: ' . $e->getFile() . ':' . $e->getLine());
            
            // Open circuit breaker on frontend for connection failures
            if (!is_admin()) {
                $this->open_circuit_breaker();
            }
            
            return false;
        }
    }

}

new AceRedisCache();
