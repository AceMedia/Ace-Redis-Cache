<?php
/**
 * Main Plugin Class
 * 
 * Core plugin orchestrator that initializes all components and manages
 * WordPress hooks and plugin lifecycle.
 *
 * @package AceMedia\RedisCache
 * @since 0.5.0
 */

namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) exit;

class AceRedisCache {
    
    private $settings;
    private $redis_connection;
    private $cache_manager;
    private $block_caching;
    private $minification;
    private $admin_interface;
    private $admin_ajax;
    private $api_handler;
    private $diagnostics;
    
    private $plugin_url;
    private $plugin_path;
    private $plugin_version = '0.5.0';
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_path = dirname(__DIR__);
        $this->plugin_url = plugin_dir_url(dirname(__FILE__));
        
        // Load settings
        $this->load_settings();
        
        // Initialize components
        $this->init_components();
        
        // Setup hooks
        $this->setup_hooks();
    }
    
    /**
     * Load plugin settings
     */
    private function load_settings() {
        $this->settings = get_option('ace_redis_cache_settings', [
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'ttl' => 3600,
            'mode' => 'full',
            'enabled' => 1,
            'enable_tls' => 0, // Changed default to 0 (disabled)
            'enable_block_caching' => 0,
            'enable_transient_cache' => 1,
            'enable_minification' => 0,
            'custom_cache_exclusions' => '',
            'custom_transient_exclusions' => '',
            'custom_content_exclusions' => '',
            'excluded_blocks' => '',
        ]);
    }
    
    /**
     * Initialize all plugin components
     */
    private function init_components() {
        // Skip Redis initialization during plugin activation to prevent timeouts
        if (defined('WP_ADMIN') && (
            (isset($_GET['action']) && $_GET['action'] === 'activate') ||
            (isset($_POST['action']) && $_POST['action'] === 'activate-selected') ||
            (function_exists('is_plugin_activation') && is_plugin_activation())
        )) {
            return;
        }
        
        // Check if cache is enabled before initializing Redis components
        if (empty($this->settings['enabled'])) {
            // Still initialize API handler for admin interface (without Redis components)
            $this->api_handler = new \AceMedia\RedisCache\API_Handler(null, null, $this->settings);
            
            // Admin interface (only for admin)
            if (is_admin()) {
                $this->admin_interface = new AdminInterface(
                    null, // No cache manager when disabled
                    $this->settings,
                    $this->plugin_url,
                    $this->plugin_version
                );
            }
            return;
        }
        
        // Add timeout protection for Redis connection
        set_time_limit(10); // Limit initialization to 10 seconds
        
        try {
            // Redis connection management
            $this->redis_connection = new RedisConnection($this->settings);
            
            // Cache management
            $this->cache_manager = new CacheManager($this->redis_connection, $this->settings);
            
            // Block caching (only if enabled)
            $this->block_caching = new BlockCaching($this->cache_manager, $this->settings);
            
            // Minification
            $this->minification = new Minification($this->settings);
            
        } catch (Exception $e) {
            // Continue without Redis - plugin will show admin warnings
        }
        
        // Reset time limit
        set_time_limit(0);
        
        // REST API handlers (always available for API endpoints, even if Redis fails)
        $this->api_handler = new \AceMedia\RedisCache\API_Handler($this->cache_manager, $this->redis_connection, $this->settings);
        
        // Admin interface (only for admin)
        if (is_admin() && $this->cache_manager) {
            $this->admin_interface = new AdminInterface(
                $this->cache_manager,
                $this->settings,
                $this->plugin_url,
                $this->plugin_version
            );
            
            // Diagnostics
            if ($this->redis_connection) {
                $this->diagnostics = new \AceMedia\RedisCache\Diagnostics(
                    $this->redis_connection,
                    $this->cache_manager,
                    $this->settings
                );
                
                // AJAX handlers
                $this->admin_ajax = new \AceMedia\RedisCache\AdminAjax(
                    $this->redis_connection,
                    $this->cache_manager,
                    $this->diagnostics,
                    $this->settings
                );
            }
        }
    }
    
    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        // Plugin lifecycle hooks
        register_activation_hook($this->plugin_path . '/ace-redis-cache.php', [$this, 'on_activation']);
        register_deactivation_hook($this->plugin_path . '/ace-redis-cache.php', [$this, 'on_deactivation']);
        
        // Settings update hook
        add_action('update_option_ace_redis_cache_settings', [$this, 'on_settings_updated'], 10, 2);
        
        // Setup component hooks (only if components are initialized)
        if (is_admin()) {
            if ($this->admin_interface) {
                $this->admin_interface->setup_hooks();
            }
            if ($this->admin_ajax) {
                $this->admin_ajax->setup_hooks();
            }
        }
        
        // Frontend caching hooks (only if enabled, not admin, and cache manager exists)
        if (!is_admin() && $this->settings['enabled'] && $this->cache_manager) {
            $this->setup_caching_hooks();
        }
    }
    
    /**
     * Setup caching hooks based on mode
     */
    private function setup_caching_hooks() {
        if ($this->settings['mode'] === 'full') {
            $this->setup_full_page_cache();
        } else {
            $this->setup_object_cache();
            
            // Setup block caching only in object mode if enabled
            if (($this->settings['enable_block_caching'] ?? 0) && $this->block_caching) {
                $this->block_caching->setup_hooks();
            }
        }
        
        // Setup minification if enabled
        if (($this->settings['enable_minification'] ?? 0) && $this->minification) {
            $this->minification->setup_hooks();
        }
        
        // Setup exclusion filters for transients and cache operations (object mode only)
        $this->setup_exclusion_filters();
    }
    
    /**
     * Setup full page caching
     */
    private function setup_full_page_cache() {
        if ($this->should_cache_request()) {
            add_action('template_redirect', [$this, 'start_full_page_cache'], 1);
        }
    }
    
    /**
     * Setup object caching
     */
    private function setup_object_cache() {
        // Transients (optional toggle, guests only)
        if (($this->settings['enable_transient_cache'] ?? 1) && !is_user_logged_in()) {
            add_filter('pre_transient_*', [$this, 'get_transient'], 10, 2);
            add_filter('pre_set_transient_*', [$this, 'set_transient'], 10, 3);
            add_filter('pre_delete_transient_*', [$this, 'delete_transient'], 10, 2);
            add_filter('pre_set_transient', [$this, 'filter_set_transient'], 10, 3);
            add_filter('pre_get_transient', [$this, 'filter_get_transient'], 10, 2);
            add_filter('pre_delete_transient', [$this, 'filter_delete_transient'], 10, 2);
        }

        // Query caching removed (too many edge-cases with Query Loop and dynamics)
    }
    
    /**
     * Setup exclusion filters
     */
    private function setup_exclusion_filters() {
        // Only in object cache mode, guest-only, and when transient cache toggle is on
        if (
            ($this->settings['mode'] ?? 'full') === 'object'
            && ($this->settings['enable_transient_cache'] ?? 1)
            && !is_user_logged_in()
        ) {
            add_filter('pre_set_transient', [$this, 'filter_set_transient'], 10, 3);
            add_filter('pre_get_transient', [$this, 'filter_get_transient'], 10, 2);
            add_filter('pre_delete_transient', [$this, 'filter_delete_transient'], 10, 2);
        }
    }
    
    /**
     * Check if current request should be cached
     *
     * @return bool True if request should be cached
     */
    private function should_cache_request() {
        // Don't cache admin pages
        if (is_admin()) {
            return false;
        }
        
        // Don't cache AJAX requests
        if (wp_doing_ajax()) {
            return false;
        }
        
        // Don't cache REST API requests
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }
        
        // Don't cache if user is logged in (optional - can be configured)
        if (is_user_logged_in()) {
            return false;
        }
        
        // Don't cache POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return false;
        }
        
        return true;
    }
    
    /**
     * Start full page cache output buffering
     */
    public function start_full_page_cache() {
        $cache_key = $this->generate_page_cache_key();
        
        // Try to get cached version (with minification handling)
        $cached_content = $this->cache_manager->get_with_minification($cache_key);
        if ($cached_content !== null) {
            echo $cached_content;
            exit;
        }
        
        // Start output buffering
        ob_start(function($content) use ($cache_key) {
            // Cache the content with intelligent minification handling
            if (!empty($content)) {
                $this->cache_manager->set_with_minification($cache_key, $content, $this->minification);
            }
            return $content;
        });
    }
    
    /**
     * Generate cache key for current page
     *
     * @return string Cache key
     */
    private function generate_page_cache_key() {
        $key_parts = [
            'page_cache',
            $_SERVER['REQUEST_URI'] ?? '/',
            is_ssl() ? 'https' : 'http',
            is_mobile() ? 'mobile' : 'desktop'
        ];
        
        return implode(':', $key_parts);
    }
    
    /**
     * Handle transient get operations
     */
    public function get_transient($value, $transient) {
        if ($this->cache_manager->should_exclude_transient($transient)) {
            return $value; // Let WordPress handle it
        }
        
        $cache_key = 'transient:' . $transient;
        return $this->cache_manager->get($cache_key);
    }
    
    /**
     * Handle transient set operations
     */
    public function set_transient($value, $transient, $expiration) {
        if ($this->cache_manager->should_exclude_transient($transient)) {
            return $value; // Let WordPress handle it
        }
        
        $cache_key = 'transient:' . $transient;
        $this->cache_manager->set($cache_key, $value, $expiration);
        
        return $value;
    }
    
    /**
     * Handle transient delete operations
     */
    public function delete_transient($value, $transient) {
        if ($this->cache_manager->should_exclude_transient($transient)) {
            return $value; // Let WordPress handle it
        }
        
        $cache_key = 'transient:' . $transient;
        $this->cache_manager->delete($cache_key);
        
        return $value;
    }
    
    /**
     * Filter set transient operations
     */
    public function filter_set_transient($value, $transient, $expiration) {
        if ($this->cache_manager->should_exclude_transient($transient)) {
            return null; // Continue with WordPress default
        }
        
        return $this->set_transient($value, $transient, $expiration);
    }
    
    /**
     * Filter get transient operations
     */
    public function filter_get_transient($value, $transient) {
        if ($this->cache_manager->should_exclude_transient($transient)) {
            return null; // Continue with WordPress default
        }
        
        return $this->get_transient($value, $transient);
    }
    
    /**
     * Filter delete transient operations
     */
    public function filter_delete_transient($value, $transient) {
        if ($this->cache_manager->should_exclude_transient($transient)) {
            return null; // Continue with WordPress default
        }
        return $this->delete_transient($value, $transient);
    }

    /**
     * Decide if a WP_Query should be cached (guests only), with safe exclusions
     */
    
    /**
     * Handle plugin activation
     */
    public function on_activation() {
        // Set default settings if they don't exist
        if (!get_option('ace_redis_cache_settings')) {
            update_option('ace_redis_cache_settings', $this->settings);
        }
        
        // Skip Redis connection test during activation if components weren't initialized
        // (This prevents timeout issues during activation)
        if ($this->redis_connection) {
            $status = $this->redis_connection->get_status();
            if (!$status['connected']) {
                wp_die(
                    'Ace Redis Cache activation failed: Unable to connect to Redis server. Please check your Redis configuration.',
                    'Plugin Activation Error',
                    ['back_link' => true]
                );
            }
        } else {
            // Redis connection wasn't initialized (likely due to activation safety check)
            // Plugin will initialize properly on next request
        }
    }
    
    /**
     * Handle plugin deactivation
     */
    public function on_deactivation() {
        try {
            // Clear all cache on deactivation (only if cache manager exists)
            if ($this->cache_manager) {
                $this->cache_manager->clear_all_cache();
            }
            
            // Close Redis connection (only if connection exists)
            if ($this->redis_connection) {
                $this->redis_connection->close_connection();
            }
        } catch (Exception $e) {
            // Log any errors during deactivation but don't fail
        }
    }
    
    /**
     * Handle settings updates
     */
    public function on_settings_updated($old_value, $new_value) {
        // Reload settings
        $this->settings = $new_value;
        
        // Reinitialize components with new settings
        $this->init_components();
        
        // Clear cache when settings change
        $this->cache_manager->clear_all_cache();
    }
    
    /**
     * Get plugin version
     *
     * @return string Plugin version
     */
    public function get_version() {
        return $this->plugin_version;
    }
    
    /**
     * Get plugin URL
     *
     * @return string Plugin URL
     */
    public function get_plugin_url() {
        return $this->plugin_url;
    }
    
    /**
     * Get plugin path
     *
     * @return string Plugin path
     */
    public function get_plugin_path() {
        return $this->plugin_path;
    }
    
    /**
     * Get settings
     *
     * @return array Plugin settings
     */
    public function get_settings() {
        return $this->settings;
    }
    
    /**
     * Get cache manager instance
     *
     * @return CacheManager Cache manager instance
     */
    public function get_cache_manager() {
        return $this->cache_manager;
    }
    
    /**
     * Get Redis connection instance
     *
     * @return RedisConnection Redis connection instance
     */
    public function get_redis_connection() {
        return $this->redis_connection;
    }
}
