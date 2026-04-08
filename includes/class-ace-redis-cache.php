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
    private $minification;
    private $admin_interface;
    private $admin_ajax;
    private $api_handler;
    private $diagnostics;
    
    private $plugin_url;
    private $plugin_path; // define to avoid dynamic property deprecation
    private $plugin_version = '0.5.0';
    // Debug tracking for transient flag mutation during save
    private $debug_pre_update_transient_early = null;
    
    /**
     * Collected block data for placeholder substitution on page cache storage.
     * @var array
     */
    private $placeholder_blocks = [];
    /**
     * Feature flag for experimental dynamic block placeholders inside page cache.
     * Disabled for now due to incomplete context on early cache hits causing missing content.
     * @var bool
     */
    private $enable_dynamic_block_placeholders = false;
    // Dynamic runtime blocks derived from excluded blocks (if enabled)
    private $dynamic_placeholders_enabled = false; // evaluated per request
    private $dynamic_placeholder_patterns = []; // patterns from excluded_blocks
    private $dynamic_placeholder_regexes = []; // precompiled regexes for fast block-name matching
    private $dynamic_placeholder_hashes = [];
    private $dynamic_placeholder_limit = 50; // allow more since patterns may match multiple block types
    private $dynamic_placeholder_stats = [ 'count' => 0, 'skipped_over_limit' => false, 'render_miss' => 0 ];
    private $dynamic_render_every_hit = true; // unified mode always re-renders
    // Microcache (short-lived) for dynamic block HTML to avoid re-render bursts
    private $dynamic_microcache_enabled = false;
    private $dynamic_microcache_ttl = 10; // seconds
    private $dynamic_microcache_hits = 0;

    // Precise stored_at meta for page cache Age header (side key)
    private $page_cache_meta_prefix = 'page_cache_meta:'; // stores JSON {stored_at:int}

    // OPcache optimization toggle
    private $opcache_runtime_enabled = false;
    // Request-local memoized site version for cache key generation.
    private $site_cache_version = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_path = dirname(__DIR__);
        // Use the proper root plugin URL. The previous implementation used
        // plugin_dir_url(dirname(__FILE__)) which yielded a URL ending in
        // '/includes/' (because __FILE__ is this class file). That caused
        // admin asset URLs to become '<plugin>/includes/assets/dist/...'
        // which do not exist, so the main admin JS/CSS never loaded.
        // This manifested as only core WP scripts (jquery-core, etc.)
        // appearing in the Network panel and the settings page JS (tabs,
        // save bar, clear cache button) being inert.
        //
        // By switching to the constant defined in the plugin bootstrap we
        // ensure the URL always targets the plugin root.
        if (defined('ACE_REDIS_CACHE_PLUGIN_URL')) {
            $this->plugin_url = ACE_REDIS_CACHE_PLUGIN_URL;
        } else {
            // Fallback: compute from the main plugin file path.
            $this->plugin_url = plugin_dir_url(dirname(__DIR__) . '/ace-redis-cache.php');
        }
        
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
        SettingsStore::maybe_migrate_legacy_settings_to_network();

        $loaded = SettingsStore::get_settings([
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            // Back-compat defaults
            'ttl' => 3600,
            'mode' => 'full',
            'enabled' => 1,
            'enable_tls' => 0, // Changed default to 0 (disabled)
            'enable_transient_cache' => 0,
            'enable_admin_optimization' => 1,  // Skip expensive operations in admin
            'enable_lightweight_cleanup' => 1, // Use lightweight cleanup in admin contexts
            'enable_minification' => 0,
            'enable_compression' => 0,
            'compression_method' => 'brotli', // brotli | gzip
            // Compression level defaults (stored but not exposed in UI by default)
            // These act as documentation and optional overrides; runtime uses filters first.
            'brotli_level_object' => 5,
            'brotli_level_page'   => 9,
            'gzip_level_object'   => 6,
            'gzip_level_page'     => 6,
            // Minimum payload size to consider compression (bytes)
            'min_compress_size'   => 512,
            'custom_cache_exclusions' => '',
            'custom_transient_exclusions' => '',
            'custom_content_exclusions' => '',
            'exclude_sitemaps' => 0,
            // New dual-cache settings
            'enable_page_cache' => 1,
            'enable_object_cache' => 0,
            'ttl_page' => 3600,
            'ttl_object' => 3600,
            'enable_browser_cache_headers' => 0,
            'browser_cache_max_age' => 3600,
            'send_cache_meta_headers' => 0,
            'enable_dynamic_microcache' => 0,
            'dynamic_microcache_ttl' => 10,
            'enable_opcache_helpers' => 0,
            'enable_static_asset_cache' => 0,
            'static_asset_cache_ttl' => 604800,
            'enable_asset_proxy_cache' => 0,
            'asset_proxy_cache_ttl' => 604800,
            'manage_static_cache_via_htaccess' => 0,
            'prefer_existing_static_cache_headers' => 1,
            // WooCommerce performance module
            'wc_skip_cart_cookies' => 1,
            'wc_disable_persistent_cart' => 1,
            'wc_variation_threshold' => 15,
            'wc_action_scheduler_time_limit' => 15,
            'wc_action_scheduler_batch_size' => 10,
            'wc_skip_children_on_archives' => 1,
            'wc_skip_composite_sync_on_archives' => 1,
            'wc_cache_url_exclusions' => 1,
            'wc_gla_disable_notification_pill' => 1,
            'wc_disable_blocks_animation_translate' => 1,
        ]);
        // If settings were stored as a JSON string (e.g., via wp-cli), decode safely
        if (is_string($loaded)) {
            $decoded = json_decode($loaded, true);
            if (is_array($decoded)) {
                $loaded = $decoded;
            }
        }
        // Ensure we have an array; if not, fallback to defaults
        if (!is_array($loaded)) {
            $loaded = [];
        }
        // Merge with defaults to ensure all keys exist
        $defaults = [
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            // Back-compat defaults
            'ttl' => 3600,
            'mode' => 'full',
            'enabled' => 1,
            'enable_tls' => 0,
            'enable_transient_cache' => 0,
            'enable_admin_optimization' => 1,  // Skip expensive operations in admin
            'enable_lightweight_cleanup' => 1, // Use lightweight cleanup in admin contexts
            'enable_minification' => 0,
            'enable_compression' => 0,
            'compression_method' => 'brotli',
            'brotli_level_object' => 5,
            'brotli_level_page'   => 9,
            'gzip_level_object'   => 6,
            'gzip_level_page'     => 6,
            'min_compress_size'   => 512,
            'custom_cache_exclusions' => '',
            'custom_transient_exclusions' => '',
            'custom_content_exclusions' => '',
            'exclude_sitemaps' => 0,
            'excluded_blocks' => '',
            'exclude_basic_blocks' => 0,
            'include_rendered_block_hash' => 0,
            // New dual-cache settings
            'enable_page_cache' => 1,
            'enable_object_cache' => 0,
            'ttl_page' => 3600,
            'ttl_object' => 3600,
            'dynamic_excluded_blocks' => 1,
            'enable_browser_cache_headers' => 0,
            'browser_cache_max_age' => 3600,
            'send_cache_meta_headers' => 0,
            'enable_dynamic_microcache' => 0,
            'dynamic_microcache_ttl' => 10,
            'enable_opcache_helpers' => 0,
            'enable_static_asset_cache' => 0,
            'static_asset_cache_ttl' => 604800,
            'enable_asset_proxy_cache' => 0,
            'asset_proxy_cache_ttl' => 604800,
            'manage_static_cache_via_htaccess' => 0,
            'prefer_existing_static_cache_headers' => 1,
            // WooCommerce performance module
            'wc_skip_cart_cookies' => 1,
            'wc_disable_persistent_cart' => 1,
            'wc_variation_threshold' => 15,
            'wc_action_scheduler_time_limit' => 15,
            'wc_action_scheduler_batch_size' => 10,
            'wc_skip_children_on_archives' => 1,
            'wc_skip_composite_sync_on_archives' => 1,
            'wc_cache_url_exclusions' => 1,
            'wc_gla_disable_notification_pill' => 1,
            'wc_disable_blocks_animation_translate' => 1,
        ];
        $this->settings = array_merge($defaults, $loaded);

        // Normalize microcache settings
        $this->dynamic_microcache_enabled = !empty($this->settings['enable_dynamic_microcache']);
        $ttl = (int)($this->settings['dynamic_microcache_ttl'] ?? 10);
        $this->dynamic_microcache_ttl = max(1, min(3600, $ttl));
        $this->opcache_runtime_enabled = !empty($this->settings['enable_opcache_helpers']);

        // Back-compat migration: map old 'mode' and 'ttl' to new toggles/ttls if not set explicitly
        if (!isset($loaded['enable_page_cache']) && !isset($loaded['enable_object_cache'])) {
            $mode = $this->settings['mode'] ?? 'full';
            $this->settings['enable_page_cache'] = ($mode === 'full') ? 1 : 0;
            $this->settings['enable_object_cache'] = ($mode === 'object') ? 1 : 0;
        }
        if (!isset($loaded['ttl_page']) && isset($this->settings['ttl'])) {
            $this->settings['ttl_page'] = (int) $this->settings['ttl'];
        }
        if (!isset($loaded['ttl_object']) && isset($this->settings['ttl'])) {
            $this->settings['ttl_object'] = (int) $this->settings['ttl'];
        }
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

        // Register transient filters for all users when enabled and cache manager exists
        $this->register_transient_filters();

        // Load WooCommerce performance module only when WooCommerce is active.
        add_action('plugins_loaded', function () {
            if (class_exists('WooCommerce')) {
                new \AceMedia\RedisCache\WooCommercePerformance($this->settings);
            }
        }, 20);
    }
    
    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        // Settings update hooks (single-site + network mode).
        add_action('update_option_ace_redis_cache_settings', [$this, 'on_settings_updated'], 10, 2);
        add_action('update_site_option_ace_redis_cache_settings', [$this, 'on_site_settings_updated'], 10, 4);

        // Pre-update filter (fires before DB write) to trace and preserve transient flag if it mysteriously drops.
        add_filter('pre_update_option_ace_redis_cache_settings', [$this, 'pre_update_settings_trace'], 10, 2);
        add_filter('pre_update_option_ace_redis_cache_settings', [$this, 'pre_update_settings_trace_late'], 9999, 2);
        add_filter('pre_update_site_option_ace_redis_cache_settings', [$this, 'pre_update_site_settings_trace'], 10, 3);

        // Trace reads to detect external mutation (low priority to run after other filters).
        add_filter('option_ace_redis_cache_settings', [$this, 'trace_option_read'], 9999, 1);
        add_filter('site_option_ace_redis_cache_settings', [$this, 'trace_option_read'], 9999, 1);

        add_action('post_updated', [$this, 'on_post_updated'], 10, 3);
        
        // Prime critical options after permalink or rewrite changes
        add_action('update_option_permalink_structure', [$this, 'prime_critical_options_after_permalink_change']);
        add_action('flush_rewrite_rules', [$this, 'prime_critical_options_after_rewrite_flush']);
        
        // Guard against canonical redirects to draft URLs
        add_filter('redirect_canonical', [$this, 'guard_canonical_redirect'], 10, 2);
        
        // Setup component hooks (only if components are initialized)
        if (is_admin()) {
            if ($this->admin_interface) {
                $this->admin_interface->setup_hooks();
            }
            if ($this->admin_ajax) {
                $this->admin_ajax->setup_hooks();
            }
        }
        // Deferred invalidation runner
        add_action('ace_rc_run_deferred_invalidation', [$this, 'run_deferred_invalidation']);
        add_action('init', [$this, 'maybe_serve_asset_proxy_request'], 0);
        // Static asset headers (apply for all requests including admin media loads)
        if (!empty($this->settings['enable_static_asset_cache'])) {
            add_filter('wp_headers', [$this, 'set_static_cache_headers']);
            add_filter('wp_get_attachment_url', [$this, 'version_attachment_url'], 20, 2);
            add_filter('wp_get_attachment_image_src', [$this, 'version_attachment_image_src'], 20, 4);
            add_filter('wp_get_attachment_image_attributes', [$this, 'version_attachment_image_attributes'], 20, 3);
            add_filter('post_thumbnail_url', [$this, 'version_post_thumbnail_url'], 20, 3);
            add_filter('wp_calculate_image_srcset', [$this, 'version_image_srcset_sources'], 20, 5);
            add_filter('get_avatar_url', [$this, 'version_avatar_url'], 20, 3);
            add_filter('style_loader_src', [$this, 'version_enqueued_asset_url'], 20, 2);
            add_filter('script_loader_src', [$this, 'version_enqueued_asset_url'], 20, 2);
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
        $request_dev_mode = $this->is_request_cache_dev_mode();

        // Full page cache (if enabled)
        if (!$request_dev_mode && !empty($this->settings['enable_page_cache'])) {
            $this->setup_full_page_cache();
        }

        // Object-level caching (transients) if enabled
        if (!$request_dev_mode && !empty($this->settings['enable_object_cache'])) {
            $this->setup_object_cache();
        }

        // Setup minification if enabled (the Minification class will avoid double-processing when page cache is active)
        if (!empty($this->settings['enable_minification']) && $this->minification) {
            $this->minification->setup_hooks();
        }

        // Setup exclusion filters for transients and cache operations
        if (!$request_dev_mode) {
            $this->setup_exclusion_filters();
        }

        // Initialize dynamic placeholders runtime (after settings loaded) only if page cache enabled
        if (!$request_dev_mode && !empty($this->settings['enable_page_cache'])) {
            $this->init_dynamic_placeholder_runtime();
            // Register per-post page cache invalidation hooks
            add_action('save_post', [$this, 'maybe_invalidate_post_page_cache'], 50, 3);
            add_action('deleted_post', [$this, 'maybe_invalidate_post_page_cache_deleted'], 50, 1);
            add_action('transition_post_status', [$this, 'maybe_invalidate_post_page_cache_status'], 50, 3);
            
            // Force complete Redis flush for post updates to ensure block caching compatibility
            // This ensures all caches (page, block, object) are cleared when posts change
            add_filter('ace_rc_broad_page_cache_purge_on_post_update', '__return_true');
            
            // Priming cron handler
            add_action('ace_rc_prime_post_cache', [$this, 'prime_post_cache'], 10, 1);
        }
    }
    
    /**
     * Setup full page caching
     */
    private function setup_full_page_cache() {
        if ($this->should_cache_request()) {
            // Start page cache later (template_include) to ensure all enqueue and style aggregation has occurred.
            add_filter('template_include', function($template){
                $this->start_full_page_cache();
                return $template;
            }, 99);
            
            // Post-save hooks for priming coherent option state & setting no-cache warm window.
            add_action('save_post', [$this, 'post_save_prime_schedule'], 10, 3);
        }
    }
    
    /**
     * Setup object caching
     */
    private function setup_object_cache() {
        // Register transient filters (when enabled) for all users
        $this->register_transient_filters();

        // Query caching removed (too many edge-cases with Query Loop and dynamics)
    }
    
    /**
     * Setup exclusion filters
     */
    private function setup_exclusion_filters() {
        // Transient exclusion logic is handled inside the filter callbacks via
        // CacheManager::should_exclude_transient(). Ensure filters are registered.
        $this->register_transient_filters();

        // Optional canonical redirect debug – only active when WP_DEBUG true and filter returns true.
        if (defined('WP_DEBUG') && WP_DEBUG && apply_filters('ace_rc_enable_canonical_debug', false)) {
            add_filter('redirect_canonical', function($redirect_url, $requested_url){
                // Log only when redirecting to a bare ?p= form (numeric) to catch stale permalink situations.
                if ($redirect_url && preg_match('#[?&]p=\d+#', $redirect_url) && strpos($requested_url, '?p=') === false) {
                    error_log('AceRedisCache CanonicalDebug: requested=' . $requested_url . ' redirect=' . $redirect_url);
                }
                return $redirect_url;
            }, 999, 2);
        }
    }

    /**
     * Register transient filters (site + single) when enabled.
     * Applies to all users and modes. Guarded to avoid duplicate registration.
     */
    private function register_transient_filters() {
        // Require cache manager and setting enabled
        if (!$this->cache_manager || empty($this->settings['enable_transient_cache'])) {
            return;
        }

        if ($this->is_request_cache_dev_mode()) {
            return;
        }

        // Only intercept transients for guest frontend traffic
        // Avoid affecting wp-admin, logged-in sessions, AJAX, and REST requests
        if (\is_admin() || \is_user_logged_in() || \wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        // If a persistent object cache drop-in is present, WP core will handle
        // transients through wp_cache_* for all users. We still register filters
        // to enable transient invalidation tracking when posts are updated.
        $external_object_cache = wp_using_ext_object_cache();
        if ($external_object_cache) {
            // For external object cache, we only hook into wp_cache_set/delete for tracking
            add_action('wp_cache_set', [$this, 'track_transient_set_for_invalidation'], 10, 5);
            add_action('wp_cache_delete', [$this, 'track_transient_delete_for_invalidation'], 10, 2);
            return;
        }

        // Generic transient hooks
        if (!has_filter('pre_set_transient', [$this, 'filter_set_transient'])) {
            add_filter('pre_set_transient', [$this, 'filter_set_transient'], 10, 3);
        }
        if (!has_filter('pre_transient', [$this, 'filter_get_transient'])) {
            add_filter('pre_transient', [$this, 'filter_get_transient'], 10, 2);
        }
        if (!has_filter('pre_delete_transient', [$this, 'filter_delete_transient'])) {
            add_filter('pre_delete_transient', [$this, 'filter_delete_transient'], 10, 2);
        }

        // Site transient hooks (single + multisite friendly)
        if (!has_filter('pre_set_site_transient', [$this, 'filter_set_site_transient'])) {
            add_filter('pre_set_site_transient', [$this, 'filter_set_site_transient'], 10, 3);
        }
        if (!has_filter('pre_site_transient', [$this, 'filter_get_site_transient'])) {
            add_filter('pre_site_transient', [$this, 'filter_get_site_transient'], 10, 2);
        }
        if (!has_filter('pre_delete_site_transient', [$this, 'filter_delete_site_transient'])) {
            add_filter('pre_delete_site_transient', [$this, 'filter_delete_site_transient'], 10, 2);
        }
    }
    
    /**
     * Check if current request should be cached
     *
     * @return bool True if request should be cached
     */
    private function should_cache_request() {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);

        // Never cache core auth/admin/system endpoints.
        if ($request_uri !== '' && preg_match('#/(wp-login\.php|wp-admin(?:/|$)|xmlrpc\.php|wp-cron\.php)#i', $request_uri)) {
            return false;
        }

        // Never cache WooCommerce cart/session sensitive requests.
        if ($this->is_woocommerce_uncacheable_request($request_uri, $path)) {
            return false;
        }

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

        if (!empty($this->settings['exclude_sitemaps']) && $this->is_sitemap_request()) {
            return false;
        }

        // Allow operators to provide additional URL/query exclusions (e.g. WooCommerce endpoints).
        $exclusions = apply_filters('ace_redis_cache_excluded_urls', []);
        if (is_array($exclusions) && !empty($exclusions)) {
            foreach ($exclusions as $pattern) {
                $pattern = trim((string) $pattern);
                if ($pattern === '') {
                    continue;
                }
                if (stripos($request_uri, $pattern) !== false) {
                    return false;
                }
            }
        }
        
        return true;
    }

    /**
     * Runtime request mode for logged-in/admin/session traffic.
     * In this mode the plugin should not add page/object/transient cache behavior.
     */
    private function is_request_cache_dev_mode() {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);

        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return true;
        }

        if (is_user_logged_in()) {
            return true;
        }

        return $this->is_woocommerce_uncacheable_request($request_uri, $path);
    }

    /**
     * Detect WooCommerce request patterns that must never be page-cached.
     */
    private function is_woocommerce_uncacheable_request($request_uri, $path) {
        $session_cookies = [
            'woocommerce_cart_hash',
            'woocommerce_items_in_cart',
            'wp_woocommerce_session_',
        ];

        foreach ($_COOKIE as $cookie_name => $cookie_value) {
            foreach ($session_cookies as $prefix) {
                if (strpos((string) $cookie_name, $prefix) === 0 && (string) $cookie_value !== '') {
                    return true;
                }
            }
        }

        if ($path !== '' && preg_match('#(^|/)(cart|checkout|my-account|register|lost-password|customer-logout|order-pay|order-received|view-order|edit-account|add-payment-method|payment-methods|set-default-payment-method|delete-payment-method)(/|$)#i', $path)) {
            return true;
        }

        if (isset($_GET['wc-ajax']) || isset($_GET['add-to-cart']) || isset($_GET['remove_item']) || isset($_GET['undo_item'])) {
            return true;
        }

        if (
            (isset($_GET['action']) && in_array((string) $_GET['action'], ['register', 'lostpassword', 'resetpass', 'logout'], true)) ||
            isset($_GET['password-reset']) ||
            isset($_GET['key'])
        ) {
            return true;
        }

        if ($request_uri !== '' && preg_match('#[?&](wc-ajax|add-to-cart|remove_item|undo_item|password-reset|key)=#i', $request_uri)) {
            return true;
        }

        return false;
    }

    private function is_sitemap_request() {
        if (isset($_GET['sitemap']) || isset($_GET['sitemap-stylesheet'])) {
            return true;
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($request_uri === '') {
            return false;
        }

        if (strpos($request_uri, 'wp-sitemap') !== false || strpos($request_uri, 'sitemap') !== false) {
            return true;
        }

        if (function_exists('ace_sitemap_powertools_custom_routes')) {
            $path = wp_parse_url($request_uri, PHP_URL_PATH);
            $path = trim((string) $path, '/');
            if ($path === 'sitemap.xml' || $path === 'sitemap.xsl' || $path === 'sitemap-index.xsl') {
                return true;
            }
            if ($path !== '') {
                $routes = ace_sitemap_powertools_custom_routes();
                foreach (array_keys($routes) as $slug) {
                    $slug = trim((string) $slug, '/');
                    if ($slug === '') {
                        continue;
                    }
                    if ($path === $slug . '.xml') {
                        return true;
                    }
                    if (preg_match('~^' . preg_quote($slug, '~') . '-\\d+\\.xml$~i', $path)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * True when request is an admin/auth/system endpoint where plugin cache URL/header mutation must not run.
     */
    private function is_admin_auth_or_system_request() {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return true;
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($request_uri === '') {
            return false;
        }

        return (bool) preg_match('#/(wp-login\.php|wp-admin(?:/|$)|xmlrpc\.php|wp-cron\.php)#i', $request_uri);
    }

    /**
     * Detect local/dev-only URLs in rendered HTML that should never be persisted in shared page cache.
     */
    private function contains_local_dev_asset_references($html) {
        if (!is_string($html) || $html === '') {
            return false;
        }

        // Local filesystem URLs and local dev-server assets can leak broken references to other visitors.
        return (bool) preg_match(
            '#(?:file://|ws://(?:localhost|127\.0\.0\.1)|wss://(?:localhost|127\.0\.0\.1)|(?:localhost|127\.0\.0\.1):\d{2,5}/|/refresh\.js(?:\?|["\'\s>]))#i',
            $html
        );
    }
    
    /**
     * Attempt to serve compressed cache directly without decompression if no dynamic placeholders exist.
     *
     * @param string $cache_key Cache key
     * @return string|null Compressed bytes ready for output, or null to use the standard path
     */
    private function try_serve_compressed_directly($cache_key) {
        if ($this->is_admin_auth_or_system_request()) {
            return null;
        }
        if (empty($this->settings['enable_compression'])) return null;
        if (!$this->dynamic_placeholders_enabled && !$this->enable_dynamic_block_placeholders) {
            $redis = null;
            if ($this->cache_manager && method_exists($this->cache_manager, 'get_raw_client')) {
                $redis = $this->cache_manager->get_raw_client();
            }
            if (!$redis) return null;

            if (!empty($this->settings['enable_minification'])) {
                $minified_key = 'page_cache_min:' . $cache_key;
                $compressed = $redis->get($minified_key);
                $compressed = $this->normalize_cached_payload_for_marker_parse($compressed);
                if ($compressed !== false && is_string($compressed) && preg_match('/^(br\\d{0,2}|gz\\d{0,2}|br|gz):/', $compressed, $m) === 1) {
                    $prefix_len = strlen($m[0]);
                    $compressed_bytes = substr($compressed, $prefix_len);
                    if (preg_match('/^br/', $m[1]) && $this->client_accepts_encoding('br')) {
                        if (!headers_sent()) {
                            header('Content-Encoding: br');
                            header('Vary: Accept-Encoding');
                            if (function_exists('header_remove')) { header_remove('Content-Length'); }
                            header('Content-Length: ' . strlen($compressed_bytes));
                        }
                        return $compressed_bytes;
                    }
                    // Serve direct gzip bytes only if payload is true gzip stream (not zlib deflate wrapper).
                    if (preg_match('/^gz/', $m[1]) && $this->client_accepts_encoding('gzip') && $this->is_gzip_stream($compressed_bytes)) {
                        if (!headers_sent()) {
                            header('Content-Encoding: gzip');
                            header('Vary: Accept-Encoding');
                            if (function_exists('header_remove')) { header_remove('Content-Length'); }
                            header('Content-Length: ' . strlen($compressed_bytes));
                        }
                        return $compressed_bytes;
                    }
                }
            }

            $regular_key = 'page_cache:' . $cache_key;
            $compressed = $redis->get($regular_key);
            $compressed = $this->normalize_cached_payload_for_marker_parse($compressed);
            if ($compressed !== false && is_string($compressed) && preg_match('/^(br\\d{0,2}|gz\\d{0,2}|br|gz):/', $compressed, $m) === 1) {
                $prefix_len = strlen($m[0]);
                $compressed_bytes = substr($compressed, $prefix_len);
                if (preg_match('/^br/', $m[1]) && $this->client_accepts_encoding('br')) {
                    if (!headers_sent()) {
                        header('Content-Encoding: br');
                        header('Vary: Accept-Encoding');
                        if (function_exists('header_remove')) { header_remove('Content-Length'); }
                        header('Content-Length: ' . strlen($compressed_bytes));
                    }
                    return $compressed_bytes;
                }
                if (preg_match('/^gz/', $m[1]) && $this->client_accepts_encoding('gzip') && $this->is_gzip_stream($compressed_bytes)) {
                    if (!headers_sent()) {
                        header('Content-Encoding: gzip');
                        header('Vary: Accept-Encoding');
                        if (function_exists('header_remove')) { header_remove('Content-Length'); }
                        header('Content-Length: ' . strlen($compressed_bytes));
                    }
                    return $compressed_bytes;
                }
            }
        }

        return null;
    }

    /**
     * Normalize cached payload values so marker parsing works even if a serializer wrapped strings.
     *
     * @param mixed $payload
     * @return mixed
     */
    private function normalize_cached_payload_for_marker_parse($payload) {
        if (!is_string($payload)) {
            return $payload;
        }

        if (preg_match('/^(?:br\\d{0,2}|gz\\d{0,2}|br|gz|raw):/', $payload) === 1) {
            return $payload;
        }

        $decoded = null;
        if (function_exists('maybe_unserialize')) {
            $decoded = maybe_unserialize($payload);
        } else {
            $decoded = @unserialize($payload);
        }

        if (is_string($decoded)) {
            return $decoded;
        }

        return $payload;
    }

    /**
     * Case-insensitive Accept-Encoding check.
     */
    private function client_accepts_encoding($encoding) {
        $accept = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        return stripos((string) $accept, (string) $encoding) !== false;
    }

    /**
     * True when byte sequence looks like gzip stream.
     */
    private function is_gzip_stream($bytes) {
        return is_string($bytes) && strlen($bytes) >= 2 && substr($bytes, 0, 2) === "\x1f\x8b";
    }

    /**
     * Start full page cache output buffering
     */
    public function start_full_page_cache() {
        if ($this->is_admin_auth_or_system_request()) {
            return;
        }
        // Respect no-cache warm window transient
        if (get_transient('ace_rc_no_cache_window')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('AceRedisCache: skip start_full_page_cache due to no_cache_window');
            }
            return;
        }
        // Allow explicit bypass for benchmarking: ?ace_nocache=1
        if (isset($_GET['ace_nocache']) && $_GET['ace_nocache'] == '1') {
            if (!headers_sent()) { header('X-AceRedisCache: BYPASS param'); }
            return; // don't start buffering or touch cache
        }
        $cache_key = $this->generate_page_cache_key();

        $direct_compressed = null;
        if (empty($this->settings['enable_static_asset_cache'])) {
            $direct_compressed = $this->try_serve_compressed_directly($cache_key);
        }
        if ($direct_compressed !== null) {
            if (!headers_sent()) {
                header('X-AceRedisCache: HIT-COMPRESSED');
                $this->emit_browser_cache_headers('hit', $cache_key);
            }
            echo $direct_compressed;
            if (defined('ACE_RC_EXIT_ON_HIT') ? ACE_RC_EXIT_ON_HIT : true) { exit; }
            return;
        }
        
        // Standard path: fetch raw (decompressed) so we can safely perform placeholder expansion when needed
        $cached_content = $this->cache_manager->get_with_minification($cache_key, false);
        if ($cached_content !== null) {
            // Prevent serving cached page for now non-published singular content
            if (is_singular()) {
                $post_obj = get_post();
                if ($post_obj && $post_obj->post_status !== 'publish') {
                    if (defined('WP_DEBUG') && WP_DEBUG) { $cached_content .= "\n<!-- AceRedisCache: discard HIT non-publish status={$post_obj->post_status} id={$post_obj->ID} -->"; }
                    $cached_content = null; // force MISS path
                }
            }
        }
        if ($cached_content !== null) {
            if ($this->enable_dynamic_block_placeholders) {
                $cached_content = $this->expand_dynamic_block_placeholders($cached_content);
            }
            if ($this->dynamic_placeholders_enabled) {
                $cached_content = $this->expand_allowlisted_dynamic_placeholders($cached_content);
                if (defined('WP_DEBUG') && WP_DEBUG && strpos($cached_content, 'AceRedisCache:') === false) {
                    $cached_content .= "\n<!-- AceRedisCache: page_cache=HIT dynamic=on -->";
                }
            }
            $cached_content = $this->rewrite_image_urls_for_cache_busting($cached_content);
            if (!headers_sent()) {
                header('X-AceRedisCache: HIT');
                $this->emit_browser_cache_headers('hit', $cache_key);
                if ($this->dynamic_microcache_enabled) {
                    header('X-AceRedisCache-Dynamic-MicroHits: ' . $this->dynamic_microcache_hits);
                }
            }
            // Re-apply compression for delivery if enabled and client accepts it
            $cached_content = $this->maybe_recompress_for_output($cached_content);
            echo $cached_content;
            if (defined('ACE_RC_EXIT_ON_HIT') ? ACE_RC_EXIT_ON_HIT : true) { exit; }
            return;
        }
        
        // Cache miss: optionally enable dynamic placeholder capture
        if ($this->enable_dynamic_block_placeholders) {
            $this->placeholder_blocks = [];
            add_filter('render_block', [$this, 'filter_render_block_for_placeholders'], 5, 2);
        }
        if ($this->dynamic_placeholders_enabled) {
            add_filter('render_block', [$this, 'filter_render_block_allowlist_dynamic'], 9, 2);
        }
        
        // Start output buffering
        ob_start(function($content) use ($cache_key) {
            // Canonical host enforcement & poison prevention
            $home_host = parse_url(home_url(), PHP_URL_HOST);
            $req_host = $_SERVER['HTTP_HOST'] ?? $home_host;
            $is_ip = preg_match('/^\d+\.\d+\.\d+\.\d+$/', $req_host);
            $host_mismatch = ($home_host && strcasecmp($home_host, $req_host) !== 0);
            $skip_cache_reason = null;
            if ($is_ip) { $skip_cache_reason = 'ip_host'; }
            // Optional: allow override via filter
            $skip_cache = apply_filters('ace_rc_skip_page_cache_store', ($is_ip || $host_mismatch), [
                'home_host' => $home_host,
                'request_host' => $req_host,
                'is_ip' => $is_ip,
                'host_mismatch' => $host_mismatch,
            ]);
            // Two-phase warm up: when transient/object cache layer is disabled, avoid storing the very first
            // render for a given path so late-emitted styles / block support attributes are stabilized by the
            // second view. This prevents capturing an incompletely styled DOM (observed when transient caching off).
            $warm_gate_enabled = apply_filters('ace_rc_enable_first_pass_skip', empty($this->settings['enable_transient_cache']), $cache_key, $this->settings);
            $path = $_SERVER['REQUEST_URI'] ?? '/';
            $path_id = md5(parse_url($path, PHP_URL_PATH) ?: '/');
            $warmed_option_key = 'ace_rc_warmed_pages';
            $warmed = ($warm_gate_enabled) ? get_option($warmed_option_key, []) : [];
            $is_first_pass = false;
            if ($warm_gate_enabled && is_array($warmed)) {
                if (empty($warmed[$path_id])) {
                    // Mark warmed (so subsequent request can store) but skip storing this pass.
                    $is_first_pass = true;
                    $warmed[$path_id] = time();
                    // Prune to last 300 entries to avoid unbounded growth
                    if (count($warmed) > 300) {
                        asort($warmed); // oldest first
                        $warmed = array_slice($warmed, -300, null, true);
                    }
                    update_option($warmed_option_key, $warmed, false);
                }
            }
            // If this request is for a single post that has a pending first-pass marker (post just updated), treat like first pass.
            if (!$is_first_pass && is_singular() && ($post_obj = get_post()) && $this->has_post_first_pass_flag($post_obj->ID)) {
                if ($this->consume_post_first_pass_flag($post_obj->ID)) {
                    $is_first_pass = true;
                    if (defined('WP_DEBUG') && WP_DEBUG) { $content .= "\n<!-- AceRedisCache: post_first_pass_skip id={$post_obj->ID} -->"; }
                }
            }
            // Global warm window skip: during short window after any post update avoid storing if style assets incomplete.
            if (!$is_first_pass) {
                $global_until = (int) get_option('ace_rc_global_asset_warm_until', 0);
                if ($global_until && time() < $global_until) {
                    if (!$this->is_style_asset_complete($content)) {
                        $is_first_pass = true;
                        if (defined('WP_DEBUG') && WP_DEBUG) { $content .= "\n<!-- AceRedisCache: global_asset_warm_skip until={$global_until} -->"; }
                    } else {
                        // Assets look complete; end warm window early
                        delete_option('ace_rc_global_asset_warm_until');
                    }
                }
            }
            if ($host_mismatch && !$is_ip) {
                // If mismatch but not IP, we can attempt canonical rewrite before caching
                // Replace absolute URLs using the request host with the home host
                if ($home_host && $req_host) {
                    $content = str_replace('//' . $req_host . '/', '//' . $home_host . '/', $content);
                }
            }
            $content = $this->rewrite_image_urls_for_cache_busting($content);
            // Build cache version separate from user output if we have placeholders
            $cache_version = $content;
            if ($this->enable_dynamic_block_placeholders && !empty($this->placeholder_blocks)) {
                foreach ($this->placeholder_blocks as $id => $blockData) {
                    $pattern = '/<!--ACE_BLOCK_START:' . preg_quote($id, '/') . '-->.*?<!--ACE_BLOCK_END:' . preg_quote($id, '/') . '-->/s';
                    $encoded = base64_encode(wp_json_encode($blockData['block']));
                    if ($encoded === false) { $encoded = ''; }
                    $placeholder = '<!--ACE_BLOCK_DYNAMIC:' . $encoded . '-->';
                    $cache_version = preg_replace($pattern, $placeholder, $cache_version);
                }
            }
            if ($this->dynamic_placeholders_enabled && !empty($this->dynamic_placeholder_hashes)) {
                foreach (array_keys($this->dynamic_placeholder_hashes) as $hash) {
                    $pattern = '/<!--ACE_DYNAMIC_START:' . preg_quote($hash, '/') . '-->.*?<!--ACE_DYNAMIC_END:' . preg_quote($hash, '/') . '-->/s';
                    $placeholder = '<!--ACE_DYNAMIC blk:' . $hash . ':v1-->';
                    $cache_version = preg_replace($pattern, $placeholder, $cache_version);
                }
            }
            $has_local_dev_refs = $this->contains_local_dev_asset_references($cache_version);

            // Cache the content with intelligent minification handling
            if (!$skip_cache && !$is_first_pass && !$has_local_dev_refs && !empty($content)) {
                $this->cache_manager->set_with_minification($cache_key, $cache_version, $this->minification);
                // Store precise stored_at meta side key (sparse small JSON)
                try {
                    if ($this->cache_manager && method_exists($this->cache_manager, 'set')) {
                        $meta_key = $this->page_cache_meta_prefix . $cache_key;
                        $this->cache_manager->set($meta_key, [ 'stored_at' => time() ], $this->settings['ttl_page'] ?? 3600);
                    }
                } catch (\Throwable $t) {}
            } elseif ($skip_cache && defined('WP_DEBUG') && WP_DEBUG) {
                $content .= "\n<!-- AceRedisCache: page_cache=SKIP host={$req_host} reason=" . ($skip_cache_reason ?: 'mismatch') . " -->";
            } elseif ($is_first_pass && defined('WP_DEBUG') && WP_DEBUG) {
                $content .= "\n<!-- AceRedisCache: first_pass_skip path_id={$path_id} -->";
            } elseif ($has_local_dev_refs && defined('WP_DEBUG') && WP_DEBUG) {
                $content .= "\n<!-- AceRedisCache: page_cache=SKIP reason=local_dev_asset_reference -->";
            }
            // For the live response, strip only the wrapper markers (leave real dynamic content rendered)
            if ($this->enable_dynamic_block_placeholders && !empty($this->placeholder_blocks)) {
                foreach ($this->placeholder_blocks as $id => $blockData) {
                    $content = str_replace(["<!--ACE_BLOCK_START:$id-->", "<!--ACE_BLOCK_END:$id-->"] , '', $content);
                }
                remove_filter('render_block', [$this, 'filter_render_block_for_placeholders'], 5);
            }
            if ($this->dynamic_placeholders_enabled && !empty($this->dynamic_placeholder_hashes)) {
                foreach (array_keys($this->dynamic_placeholder_hashes) as $hash) {
                    $content = str_replace(["<!--ACE_DYNAMIC_START:$hash-->", "<!--ACE_DYNAMIC_END:$hash-->"] , '', $content);
                }
                remove_filter('render_block', [$this, 'filter_render_block_allowlist_dynamic'], 9);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $content .= "\n<!-- AceRedisCache: page_cache=MISS dynamic_used=" . $this->dynamic_placeholder_stats['count']
                        . '/limit=' . $this->dynamic_placeholder_limit
                        . ($this->dynamic_placeholder_stats['skipped_over_limit'] ? ' capped=1' : '') . ' -->';
                }
            }
            if (!headers_sent()) { header('X-AceRedisCache: MISS'); $this->emit_browser_cache_headers('miss', $cache_key); }
            // Compress final output if enabled before sending to client
            $content = $this->maybe_recompress_for_output($content);
            return $content;
        });
    }

    /**
     * Re-compress dynamic-expanded HTML for output honoring settings & client Accept-Encoding.
     * We do NOT overwrite the stored cache (already stored compressed or raw earlier); this is a response-layer concern.
     */
    private function maybe_recompress_for_output($html) {
        if ($this->is_admin_auth_or_system_request()) {
            return $html;
        }
        if (empty($this->settings['enable_compression']) || !is_string($html) || $html === '') {
            return $html;
        }
        $accept = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        $method = $this->settings['compression_method'] ?? 'brotli';
        // Prefer brotli if configured & accepted
        if ($method === 'brotli' && strpos($accept, 'br') !== false && function_exists('brotli_compress')) {
            $level = (int) apply_filters('ace_rc_brotli_level_page', $this->settings['brotli_level_page'] ?? 9);
            $compressed = @brotli_compress($html, max(0,min(11,$level)));
            if ($compressed !== false) {
                if (!headers_sent()) {
                    header('Content-Encoding: br');
                    header('Vary: Accept-Encoding');
                    if (function_exists('header_remove')) { header_remove('Content-Length'); }
                    header('Content-Length: ' . strlen($compressed));
                }
                return $compressed;
            }
        }
        // Fallback to gzip if accepted
        if (strpos($accept, 'gzip') !== false && (function_exists('gzencode') || function_exists('gzcompress'))) {
            $level = (int) apply_filters('ace_rc_gzip_level_page', $this->settings['gzip_level_page'] ?? 6);
            $level = max(0,min(9,$level));
            $compressed = false;
            if (function_exists('gzencode')) { $compressed = @gzencode($html, $level); }
            elseif (function_exists('gzcompress')) { $compressed = @gzcompress($html, $level); }
            if ($compressed !== false) {
                if (!headers_sent()) {
                    header('Content-Encoding: gzip');
                    header('Vary: Accept-Encoding');
                    if (function_exists('header_remove')) { header_remove('Content-Length'); }
                    header('Content-Length: ' . strlen($compressed));
                }
                return $compressed;
            }
        }
        return $html;
    }

    /**
     * Emit browser cache + diagnostic meta headers.
     */
    private function emit_browser_cache_headers($state, $cache_key) {
        $send_meta = !empty($this->settings['send_cache_meta_headers']);
        $browser_cache = !empty($this->settings['enable_browser_cache_headers']);
        $now = time();
        // Browser cache headers only on HITs (MISS sends no-cache to avoid double-store) unless explicitly allowed
        if ($browser_cache) {
            $max_age = intval($this->settings['browser_cache_max_age'] ?? ($this->settings['ttl_page'] ?? 3600));
            $max_age = max(60, min(604800, $max_age));
            if ($state === 'hit') {
                header('Cache-Control: public, max-age=' . $max_age . ', s-maxage=' . $max_age);
                header('Expires: ' . gmdate('D, d M Y H:i:s', $now + $max_age) . ' GMT');
            } else { // miss -> conservative so intermediaries wait for populated version
                header('Cache-Control: no-cache, max-age=0');
            }
        }
        if ($send_meta) {
            // Compute age prefer exact stored_at side key; fallback to TTL math
            $age = 0; $ttl_remaining = null; $orig_ttl = intval($this->settings['ttl_page'] ?? 3600); $stored_at = null;
            try {
                if ($this->cache_manager && method_exists($this->cache_manager, 'get_redis_connection')) {
                    $conn = $this->cache_manager->get_redis_connection();
                    if ($conn && method_exists($conn, 'get_connection')) {
                        $r = $conn->get_connection();
                        if ($r) {
                            $prefixed = 'page_cache:' . $cache_key;
                            $ttl_remaining = $r->ttl($prefixed);
                            if (is_int($ttl_remaining) && $ttl_remaining > 0) {
                                $age_calc = $orig_ttl - $ttl_remaining; if ($age_calc >= 0) { $age = $age_calc; }
                            }
                            // Side meta key precise timestamp
                            $meta_key = 'page_cache_meta:' . $cache_key; // raw redis key because set() adds no prefix for custom keys
                            try {
                                $meta_raw = $r->get($meta_key);
                                if ($meta_raw !== false) {
                                    $meta_dec = @maybe_unserialize($meta_raw);
                                    if (is_array($meta_dec) && isset($meta_dec['stored_at'])) {
                                        $stored_at = (int)$meta_dec['stored_at'];
                                    } else {
                                        // JSON attempt
                                        $try_json = json_decode($meta_raw, true);
                                        if (is_array($try_json) && isset($try_json['stored_at'])) { $stored_at = (int)$try_json['stored_at']; }
                                    }
                                }
                            } catch (\Throwable $t2) {}
                        }
                    }
                }
            } catch (\Throwable $t) {}
            if ($stored_at) { $age = max(0, time() - $stored_at); }
            header('X-AceRedisCache-Age: ' . $age);
            // Standard Age header (RFC 9111) for broader CDN/tooling compatibility (only meaningful on HIT)
            if ($state === 'hit') {
                header('Age: ' . $age);
            }
            if (is_int($ttl_remaining) && $ttl_remaining >= 0) { header('X-AceRedisCache-TTL-Remaining: ' . $ttl_remaining); }
            header('X-AceRedisCache-Orig-TTL: ' . $orig_ttl);
            $comp = (!empty($this->settings['enable_compression'])) ? ($this->settings['compression_method'] ?? 'brotli') : 'off';
            header('X-AceRedisCache-Compression: ' . $comp);
            if ($this->dynamic_placeholders_enabled) {
                header('X-AceRedisCache-Dynamic: on');
                header('X-AceRedisCache-Dynamic-Count: ' . $this->dynamic_placeholder_stats['count']);
                header('X-AceRedisCache-Dynamic-Miss: ' . $this->dynamic_placeholder_stats['render_miss']);
                if ($this->dynamic_placeholder_stats['skipped_over_limit']) {
                    header('X-AceRedisCache-Dynamic-Capped: 1');
                }
                if ($this->dynamic_microcache_enabled && $this->dynamic_microcache_hits > 0) {
                    header('X-AceRedisCache-Dynamic-MicroHits: ' . $this->dynamic_microcache_hits);
                }
            }
        }
    }

    /**
     * Initialize runtime state for second-iteration dynamic placeholders.
     */
    private function init_dynamic_placeholder_runtime() {
        // Setting + filter gate
        $enabled_setting = !empty($this->settings['dynamic_excluded_blocks']);
        $enabled = apply_filters('ace_rc_enable_partial_dynamic', $enabled_setting, $this->settings);
        if (!$enabled) { return; }
        // Guest frontend only
        if (is_admin() || is_user_logged_in() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) { return; }
        // Use excluded_blocks as dynamic patterns
        $raw = $this->settings['excluded_blocks'] ?? '';
        $patterns = [];
        if (!empty($raw)) {
            $lines = preg_split('/\n+/', $raw);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                $patterns[] = $line;
            }
        }
        // Early exit if no patterns
        if (empty($patterns)) return;
        $patterns = apply_filters('ace_rc_dynamic_block_patterns', array_unique($patterns), $this->settings);
        $this->dynamic_placeholder_patterns = $patterns;
        $this->dynamic_placeholder_regexes = $this->compile_dynamic_placeholder_regexes($patterns);
        $this->dynamic_placeholder_limit = (int) apply_filters('ace_rc_dynamic_placeholder_limit', $this->dynamic_placeholder_limit, $this->settings);
        $this->dynamic_placeholders_enabled = true; // always render mode
    }

    private function compile_dynamic_placeholder_regexes($patterns) {
        $regexes = [];
        foreach ((array) $patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            $quoted = preg_quote($pattern, '/');
            $quoted = str_replace('\\*', '.*', $quoted);
            $quoted = str_replace('\\?', '.', $quoted);
            $regexes[] = '/^' . $quoted . '$/';
        }
        return $regexes;
    }

    private function is_dynamic_placeholder_block_name($name) {
        if ($name === '' || empty($this->dynamic_placeholder_regexes)) {
            return false;
        }
        foreach ($this->dynamic_placeholder_regexes as $regex) {
            if (preg_match($regex, $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Wrap allowlisted block output with deterministic markers and micro-cache its HTML.
     */
    public function filter_render_block_allowlist_dynamic($block_content, $block) {
        if (!$this->dynamic_placeholders_enabled) { return $block_content; }
        if ($this->dynamic_placeholder_stats['skipped_over_limit']) { return $block_content; }
        $name = $block['blockName'] ?? '';
        if (!$name) { return $block_content; }
        if (!$this->is_dynamic_placeholder_block_name($name)) { return $block_content; }
        if (empty($block_content)) { return $block_content; }
        $post_id = (int) (get_the_ID() ?: 0);
        // Include a light fingerprint of innerBlocks (names only) so structural changes bust hash
        $inner_fingerprint = '';
        if (!empty($block['innerBlocks'])) {
            $names = [];
            foreach ($block['innerBlocks'] as $ib) {
                if (!empty($ib['blockName'])) { $names[] = $ib['blockName']; }
            }
            if ($names) { $inner_fingerprint = '|' . md5(implode(',', $names)); }
        }
        $hash_basis = $name . '|' . wp_json_encode($block['attrs'] ?? []) . '|' . $post_id . $inner_fingerprint;
        $hash = md5($hash_basis);
        $redis_key = 'dyn_block:' . $hash;
        // Always (re)store descriptor so inner block changes propagate (descriptor-only or full structure for containers)
        $payload = [
            'html' => $block_content, // store original HTML as fallback if re-render fails or lacks context
            'name' => $name,
            'attrs' => $block['attrs'] ?? [],
            'post_id' => $post_id,
            'stored_at' => time(),
            'always_render' => 1,
        ];
        // If this block has innerBlocks, store full structure so we can re-render nested content
        if (!empty($block['innerBlocks'])) {
            // Remove potentially large computed keys we don't need
            $full = $block;
            // Strip raw innerHTML if present to encourage fresh render callbacks for inner blocks
            unset($full['innerHTML']);
            $payload['full'] = $full;
        }
        $ttl = isset($this->settings['ttl_object']) ? (int)$this->settings['ttl_object'] : 3600;
        $ttl = max(300, min($ttl, 3600));
        $this->cache_manager->set($redis_key, $payload, $ttl);
        $this->dynamic_placeholder_hashes[$hash] = true;
        $this->dynamic_placeholder_stats['count']++;
        if ($this->dynamic_placeholder_stats['count'] > $this->dynamic_placeholder_limit) {
            $this->dynamic_placeholder_stats['skipped_over_limit'] = true;
            return $block_content; // stop wrapping further blocks
        }
        return "<!--ACE_DYNAMIC_START:$hash-->$block_content<!--ACE_DYNAMIC_END:$hash-->";
    }

    /**
     * Replace deterministic placeholders with cached block HTML (or attempt re-render) on cache HIT.
     */
    private function expand_allowlisted_dynamic_placeholders($content) {
        if (strpos($content, 'ACE_DYNAMIC blk:') === false) { return $content; }
        return preg_replace_callback('/<!--ACE_DYNAMIC blk:([a-f0-9]{32}):v1-->/', function($m) {
            $hash = $m[1];
            $redis_key = 'dyn_block:' . $hash;
            $payload = $this->cache_manager->get($redis_key);
            // Always dynamic mode (descriptor only)
            if (is_array($payload) && isset($payload['name'])) {
                // Microcache fetch (raw HTML) if enabled
                if ($this->dynamic_microcache_enabled) {
                    $mc_key = 'dyn_block_mc:' . $hash;
                    $mc_html = $this->cache_manager->get($mc_key);
                    if (is_string($mc_html) && $mc_html !== '') {
                        $this->dynamic_microcache_hits++;
                        return $mc_html;
                    }
                }
                try {
                    global $post;
                    $original_post = $post;
                    if (!empty($payload['post_id'])) {
                        $post_obj = get_post($payload['post_id']);
                        if ($post_obj) { $post = $post_obj; setup_postdata($post); }
                    }
                    if (!empty($payload['full']) && is_array($payload['full'])) {
                        $struct = $payload['full'];
                    } else {
                        $struct = [
                            'blockName' => $payload['name'],
                            'attrs' => $payload['attrs'] ?? [],
                            'innerBlocks' => [],
                            'innerHTML' => $payload['html'] ?? '',
                            'innerContent' => [$payload['html'] ?? ''],
                        ];
                    }
                    $rendered = function_exists('render_block') ? render_block($struct) : '';
                    if (trim($rendered) === '') {
                        // Fallback to original captured HTML if fresh render is empty (context-dependent inner block)
                        $rendered = $payload['html'] ?? '';
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            $rendered .= "<!-- AceRedisCache dynamic fallback used -->";
                        }
                    }
                    if ($original_post) { $post = $original_post; setup_postdata($post); }
                    // Store in microcache
                    if ($this->dynamic_microcache_enabled && !empty($rendered)) {
                        try { $this->cache_manager->set('dyn_block_mc:' . $hash, $rendered, $this->dynamic_microcache_ttl); } catch (\Throwable $t) {}
                    }
                    return $rendered;
                } catch (\Throwable $t) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('AceRedisCache dynamic always-render failed: ' . $t->getMessage());
                    }
                    return '';
                }
            }
            $this->dynamic_placeholder_stats['render_miss']++;
            return '';
        }, $content);
    }

    /**
     * Intercept block rendering to mark excluded/dynamic blocks as placeholders for page cache.
     * We wrap the real content so the live request sees it, but we can swap it out in the cached version.
     *
     * @param string $block_content The rendered block HTML
     * @param array  $block         Parsed block array
     * @return string Possibly wrapped content
     */
    public function filter_render_block_for_placeholders($block_content, $block) {
        // Only apply when page cache is enabled and request qualifies for caching (guest frontend)
        if (empty($this->settings['enable_page_cache']) || is_user_logged_in() || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return $block_content;
        }
        $block_name = $block['blockName'] ?? '';
        if (empty($block_name)) {
            return $block_content;
        }
        if (!$this->is_block_excluded_for_page_cache($block_name)) {
            return $block_content;
        }
        // Generate stable id for this block instance based on attributes + name + index
        $id = 'b' . substr(md5($block_name . wp_json_encode($block['attrs'] ?? []) . microtime(true) . rand()), 0, 12);
        $this->placeholder_blocks[$id] = [
            'block' => $block,
        ];
        return "<!--ACE_BLOCK_START:$id-->" . $block_content . "<!--ACE_BLOCK_END:$id-->";
    }

    /**
     * Determine if a block should be treated as dynamic placeholder in page cache (excluded list or inherently dynamic).
     * Mirrors (not duplicates exactly) logic from BlockCaching exclusions while keeping scope local.
     *
     * @param string $block_name
     * @return bool
     */
    private function is_block_excluded_for_page_cache($block_name) {
        if (empty($block_name)) return false;
        // Always treat inherently dynamic core blocks as placeholders
        $dynamic_blocks = [
            'core/query','core/post-template','core/query-loop','core/post-content','core/post-excerpt','core/post-date',
            'core/post-title','core/post-author','core/post-featured-image','core/comments','core/comment-template',
            'core/latest-posts','core/latest-comments','core/calendar','core/archives','core/categories','core/tag-cloud',
            'core/search','core/loginout',
            'ace-popular-posts/popular-posts'
        ];
        if (in_array($block_name, $dynamic_blocks, true)) {
            return true;
        }
        // User excluded patterns from settings
        $patterns = [];
        if (!empty($this->settings['excluded_blocks'])) {
            $lines = explode("\n", $this->settings['excluded_blocks']);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && !str_starts_with($line, '#')) { $patterns[] = $line; }
            }
        }
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $block_name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Expand dynamic block placeholders in cached page content back into freshly rendered blocks.
     *
     * @param string $content Cached content possibly containing placeholders
     * @return string
     */
    private function expand_dynamic_block_placeholders($content) {
        if (strpos($content, 'ACE_BLOCK_DYNAMIC:') === false) {
            return $content; // fast path
        }
        return preg_replace_callback('/<!--ACE_BLOCK_DYNAMIC:([A-Za-z0-9+\/=]+)-->/','[$this, "render_placeholder_block"]',$content);
    }

    /**
     * Callback to render a single placeholder block.
     * @param array $matches
     * @return string
     */
    private function render_placeholder_block($matches) {
        $encoded = $matches[1] ?? '';
        if ($encoded === '') return '';
        $json = base64_decode($encoded, true);
        if ($json === false) return '';
        $block = json_decode($json, true);
        if (!is_array($block)) return '';
        // Render with core function; suppress errors from malformed data
        try {
            if (function_exists('render_block')) {
                return render_block($block);
            }
        } catch (\Throwable $t) {
            // Fail quietly
        }
        return '';
    }
    
    /**
     * Generate cache key for current page
     *
     * @return string Cache key
     */
    private function generate_page_cache_key() {
        $version = $this->get_site_cache_version();
        $host = $this->normalize_cache_host($_SERVER['HTTP_HOST'] ?? '');
        $request_path = $_SERVER['REQUEST_URI'] ?? '/';
        $key_parts = [
            'page_cache',
            $request_path,
            \is_ssl() ? 'https' : 'http',
            \wp_is_mobile() ? 'mobile' : 'desktop',
            $host,
            'v' . $version,
        ];

        $key_parts = apply_filters('ace_redis_cache_page_cache_key_parts', $key_parts, [
            'request_uri' => $request_path,
            'scheme' => \is_ssl() ? 'https' : 'http',
            'device' => \wp_is_mobile() ? 'mobile' : 'desktop',
            'host' => $host,
            'version' => (int) $version,
        ]);

        return implode(':', array_map('strval', (array) $key_parts));
    }

    private function get_site_cache_version() {
        if ($this->site_cache_version !== null) {
            return (int) $this->site_cache_version;
        }
        $version = 0;
        try { $version = (int) wp_cache_get('site_version', 'version'); } catch (\Throwable $t) { $version = 0; }
        $this->site_cache_version = $version;
        return $version;
    }

    /**
     * Normalize host for cache key composition.
     */
    private function normalize_cache_host($host) {
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return '';
        }

        $host = preg_replace('/:\\d+$/', '', $host);
        return $host ?: '';
    }

    /**
     * Build a page cache core key (without redis prefix) for an arbitrary relative path.
     * Mirrors generate_page_cache_key logic but parameterized.
     * @param string $path Relative URI path beginning with '/'
     * @param string $scheme 'http'|'https'
     * @param string $device 'desktop'|'mobile'
     * @return string
     */
    private function build_page_cache_core_key($path, $scheme, $device, $version = null, $host = null) {
        if ($version === null) { $version = $this->get_site_cache_version(); }
        if ($host === null) { $host = parse_url(home_url(), PHP_URL_HOST) ?: ''; }
        $host = $this->normalize_cache_host($host);

        $key_parts = [
            'page_cache',
            $path ?: '/',
            $scheme,
            $device,
            $host,
            'v' . (int) $version,
        ];

        $key_parts = apply_filters('ace_redis_cache_page_cache_key_parts', $key_parts, [
            'request_uri' => $path ?: '/',
            'scheme' => $scheme,
            'device' => $device,
            'host' => $host,
            'version' => (int) $version,
        ]);

        return implode(':', array_map('strval', (array) $key_parts));
    }

    /**
     * Invalidate page cache entries for a specific post ID (its permalink variants).
     * Deletes both original and minified variants. Optionally primes fresh cache.
     * @param int $post_id
     * @param bool $schedule_prime Whether to schedule a prime request after invalidation
     */
    private function invalidate_post_page_cache($post_id, $schedule_prime = true) {
        $post_id = (int)$post_id;
        if (!$post_id) return;
        static $processed = [];
        if (isset($processed[$post_id])) {
            return; // already handled this post in current request (prevents double from status + save_post)
        }
        $permalink = get_permalink($post_id);
        if (!$permalink) return;
        $path = parse_url($permalink, PHP_URL_PATH) ?: '/';
        if ($path === '') { $path = '/'; }
        if ($path[0] !== '/') { $path = '/' . $path; }
        $paths = [$path];
        // Add with / without trailing slash variants
        if ($path !== '/') {
            if (substr($path, -1) === '/') {
                $paths[] = rtrim($path, '/');
            } else {
                $paths[] = $path . '/';
            }
        }
        // Allow 3rd parties to add related URIs (archives, feeds, etc.)
        $paths = apply_filters('ace_rc_post_invalidation_paths', array_unique($paths), $post_id, $permalink, $this->settings);
    $schemes = ['http','https'];
    $devices = ['desktop','mobile'];
    // Collect current & previous version numbers (previous = version-1) to aggressively purge both
    $versions = [];
    try { $cv = (int) wp_cache_get('site_version', 'version'); if ($cv >= 0) { $versions[] = $cv; if ($cv > 0) { $versions[] = $cv-1; } } } catch (\Throwable $t) { $versions[] = 0; }
        $redis = null;
        if ($this->cache_manager && method_exists($this->cache_manager, 'get_raw_client')) {
            $redis = $this->cache_manager->get_raw_client();
        }
        $deleted = 0;
        foreach ($paths as $p) {
            foreach ($schemes as $scheme) {
                foreach ($devices as $device) {
                    foreach ($versions as $ver) {
                        $core = $this->build_page_cache_core_key($p, $scheme, $device, $ver);
                        // Stored keys: page_cache:<core> and page_cache_min:<core>
                        $raw_key = 'page_cache:' . $core;
                        $min_key = 'page_cache_min:' . $core;
                        // Legacy (unversioned) variants (fallback purge)
                        $legacy_core = str_replace(':v' . $ver, '', $core);
                        $legacy_key = $legacy_core;
                        $legacy_raw = 'page_cache:' . $legacy_core;
                        $legacy_min = 'page_cache_min:' . $legacy_core;
                        try {
                            if ($redis) {
                                if (method_exists($redis, 'del')) {
                                    $deleted += (int)$redis->del($raw_key);
                                    $deleted += (int)$redis->del($min_key);
                                    $deleted += (int)$redis->del($legacy_key);
                                    $deleted += (int)$redis->del($legacy_raw);
                                    $deleted += (int)$redis->del($legacy_min);
                                } elseif (method_exists($redis, 'unlink')) {
                                    $deleted += (int)$redis->unlink($raw_key);
                                    $deleted += (int)$redis->unlink($min_key);
                                    $deleted += (int)$redis->unlink($legacy_key);
                                    $deleted += (int)$redis->unlink($legacy_raw);
                                    $deleted += (int)$redis->unlink($legacy_min);
                                }
                            }
                        } catch (\Throwable $t) {
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log('AceRedisCache post cache invalidation error: ' . $t->getMessage());
                            }
                        }
                    }
                }
            }
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("AceRedisCache invalidated page cache for post $post_id (deleted_keys=$deleted paths=" . count($paths) . ")");
        }
        if ($schedule_prime) {
            $prime_enabled = apply_filters('ace_rc_enable_cache_priming', true, $post_id, $this->settings);
            if ($prime_enabled) {
                if (!wp_next_scheduled('ace_rc_prime_post_cache', [$post_id])) {
                    wp_schedule_single_event(time() + 5, 'ace_rc_prime_post_cache', [$post_id]);
                }
            }
        }
        $processed[$post_id] = true;
    }

    /**
     * Cron handler to prime a single post page cache after invalidation.
     * @param int $post_id
     */
    public function prime_post_cache($post_id) {
        $post_id = (int)$post_id;
        if (!$post_id) return;
        $permalink = get_permalink($post_id);
        if (!$permalink) return;
        $args = [
            'timeout' => 8,
            'redirection' => 2,
            'user-agent' => 'AceRedisCache-Primer/1.0',
            'headers' => [ 'X-AceRedis-Prime' => '1' ],
            'cookies' => [],
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ];
        try {
            $resp = wp_remote_get($permalink, $args);
            if (is_wp_error($resp) && defined('WP_DEBUG') && WP_DEBUG) {
                error_log('AceRedisCache prime fetch failed: ' . $resp->get_error_message());
            }
        } catch (\Throwable $t) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('AceRedisCache prime exception: ' . $t->getMessage());
            }
        }
    }

    /* ============================= OPcache Helpers ============================= */
    /** Optionally reset OPcache (if enabled in settings) after settings change or manual call */
    public function maybe_opcache_reset() {
        if (!$this->opcache_runtime_enabled) return false;
        if (function_exists('opcache_reset')) {
            return @opcache_reset();
        }
        return false;
    }

    /** Optionally precompile a small list of hot files (index, theme functions) */
    public function maybe_opcache_prime() {
        if (!$this->opcache_runtime_enabled) return;
        if (!function_exists('opcache_compile_file')) return;
        $candidates = apply_filters('ace_rc_opcache_prime_files', [
            ABSPATH . 'index.php',
            get_stylesheet_directory() . '/functions.php',
            get_template_directory() . '/functions.php',
        ]);
        foreach ($candidates as $file) {
            if (is_string($file) && file_exists($file)) {
                try { @opcache_compile_file($file); } catch (\Throwable $t) {}
            }
        }
    }

    /**
     * Hook: save_post (and similar) – decide whether to invalidate page cache.
     * ALWAYS defer transient flushes to background/shutdown to prevent editorial blocking.
     */
    public function maybe_invalidate_post_page_cache($post_id, $post, $update) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!$post) return;
        if (!is_post_type_viewable($post->post_type)) return;
        // Bump site_version for every legitimate save to segregate cached listings immediately
        try { $cv = wp_cache_get('site_version', 'version'); if ($cv) { wp_cache_incr('site_version', 1, 'version'); } else { wp_cache_set('site_version', 1, 'version'); } } catch (\Throwable $t) {}
        if ($this->should_defer_post_invalidation()) {
            $this->enqueue_deferred_post($post_id, ['page'=>true,'archives'=>true,'transients'=>true,'blocks_full'=>true]);
            return;
        }
        $this->invalidate_post_page_cache($post_id, true);
        $this->enqueue_deferred_post($post_id, ['transients'=>true]);
        $this->maybe_invalidate_related_archive_page_cache($post_id, $post);
    }

    /**
     * Hook: deleted_post
     */
    public function maybe_invalidate_post_page_cache_deleted($post_id) {
        if (wp_is_post_revision($post_id)) return;
        $post = get_post($post_id);
        if ($post && is_post_type_viewable($post->post_type)) {
            if ($this->should_defer_post_invalidation()) {
                $this->enqueue_deferred_post($post_id, ['page'=>true,'archives'=>true,'transients'=>true,'blocks_full'=>true]);
                return;
            }
            $this->invalidate_post_page_cache($post_id, false); // no priming for deleted
            $this->enqueue_deferred_post($post_id, ['transients'=>true]);
            $this->maybe_invalidate_related_archive_page_cache($post_id, $post, false);
        }
    }

    /**
     * Hook: transition_post_status – catch status changes to/from publish.
     */
    public function maybe_invalidate_post_page_cache_status($new_status, $old_status, $post) {
        if (!$post || wp_is_post_revision($post->ID)) return;
        if (!is_post_type_viewable($post->post_type)) return;
        if ($new_status === 'publish' || $old_status === 'publish') {
            // Let save_post handle the publish case to avoid double runs.
            if ($new_status !== 'publish') { // leaving publish -> something else
                // Bump site_version so any cached aggregate/listing pages receive a new key namespace
                try { $current_v = wp_cache_get('site_version', 'version'); if ($current_v) { wp_cache_incr('site_version', 1, 'version'); } else { wp_cache_set('site_version', 1, 'version'); } } catch (\Throwable $t) {}
                if ($this->should_defer_post_invalidation()) {
                    $this->enqueue_deferred_post($post->ID, ['page'=>true,'archives'=>true,'transients'=>true,'blocks_full'=>true]);
                    return;
                }
                $this->invalidate_post_page_cache($post->ID, true);
                $this->enqueue_deferred_post($post->ID, ['transients'=>true]);
                $this->maybe_invalidate_related_archive_page_cache($post->ID, $post);
            }
        }
    }

    /**
     * Invalidate page cache entries for archives / home / listings that could include this post.
     * Builds a set of paths (URIs) and deletes their cached variants. Optionally primes only the single post; archives not primed by default.
     */
    private function maybe_invalidate_related_archive_page_cache($post_id, $post, $include_archives = true) {
        if (empty($this->settings['enable_page_cache']) || !$this->cache_manager) return;
        $post_id = (int)$post_id; if (!$post_id) return;
        if (!$post || !is_post_type_viewable($post->post_type)) return;
        // Allow broad purge override
        $broad = apply_filters('ace_rc_broad_page_cache_purge_on_post_update', false, $post_id, $post, $this->settings);
        if ($broad) {
            // Complete Redis flush for maximum compatibility with block caching
            if ($this->cache_manager && method_exists($this->cache_manager, 'get_raw_client')) {
                try {
                    $redis = $this->cache_manager->get_raw_client();
                    if ($redis && method_exists($redis, 'flushDB')) {
                        $redis->flushDB();
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('AceRedisCache: Complete Redis flush after post update ID=' . $post_id);
                        }
                        return;
                    }
                } catch (\Throwable $e) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('AceRedisCache: Redis flush failed: ' . $e->getMessage());
                    }
                }
            }
            
            // Fallback to clearing all cache namespaces if flushDB not available
            $prefixes = ['page_cache:', 'page_cache_min:', 'page_cache_meta:', 'block_cache:', 'ace:'];
            foreach ($prefixes as $prefix) {
                $keys = $this->cache_manager->scan_keys($prefix . '*');
                if (!empty($keys)) {
                    $this->cache_manager->delete_keys_chunked($keys, 1000);
                }
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('AceRedisCache: Complete cache purge after post update ID=' . $post_id);
            }
            return;
        }
        $paths = [];
        // Home/front
        $home_url = home_url('/');
        $home_path = parse_url($home_url, PHP_URL_PATH) ?: '/';
        $paths[] = $home_path;
        // If front page is static and different from posts page
        $page_for_posts = (int) get_option('page_for_posts');
        if ($page_for_posts) {
            $blog_link = get_permalink($page_for_posts);
            if ($blog_link) {
                $blog_path = parse_url($blog_link, PHP_URL_PATH) ?: '/';
                $paths[] = $blog_path;
            }
        }
        // Post type archive (if any)
        if (post_type_exists($post->post_type)) {
            $ptype_obj = get_post_type_object($post->post_type);
            if ($ptype_obj && !empty($ptype_obj->has_archive)) {
                $archive_link = get_post_type_archive_link($post->post_type);
                if ($archive_link) {
                    $archive_path = parse_url($archive_link, PHP_URL_PATH) ?: '/';
                    $paths[] = $archive_path;
                }
            }
        }
        // Taxonomy term archives (categories, tags, custom)
        $taxes = get_object_taxonomies($post->post_type, 'objects');
        if (!empty($taxes)) {
            foreach ($taxes as $tax) {
                if (!$tax->public || !$tax->show_ui) continue;
                $terms = wp_get_post_terms($post_id, $tax->name, [ 'fields' => 'all' ]);
                if (is_wp_error($terms) || empty($terms)) continue;
                foreach ($terms as $term) {
                    $tlink = get_term_link($term);
                    if (!is_wp_error($tlink) && $tlink) {
                        $tpath = parse_url($tlink, PHP_URL_PATH) ?: '/';
                        $paths[] = $tpath;
                    }
                }
            }
        }
        // Author archive
        $author_link = get_author_posts_url($post->post_author);
        if ($author_link) {
            $apath = parse_url($author_link, PHP_URL_PATH) ?: '/';
            $paths[] = $apath;
        }
        // Date archives (year/month) - only if published
        if ($post->post_date_gmt && $post->post_status === 'publish') {
            $ts = strtotime($post->post_date_gmt . ' GMT');
            if ($ts) {
                $y = gmdate('Y', $ts);
                $m = gmdate('m', $ts);
                $paths[] = '/' . $y . '/';
                $paths[] = '/' . $y . '/' . $m . '/';
            }
        }
        $paths = array_unique(array_filter($paths));
        // Normalize variants (with/without trailing slash)
        $norm = [];
        foreach ($paths as $p) {
            if ($p === '') $p = '/';
            if ($p[0] !== '/') $p = '/' . $p;
            $norm[] = $p;
            if ($p !== '/') {
                if (substr($p, -1) === '/') {
                    $norm[] = rtrim($p, '/');
                } else {
                    $norm[] = $p . '/';
                }
            }
        }
        $paths = array_unique($norm);
        // Add feed variants (/feed/) for each base path
        $feed_paths = [];
        foreach ($paths as $base) {
            if ($base === '') continue;
            $canonical = rtrim($base, '/');
            if ($canonical === '') { $canonical = '/'; }
            $feed_paths[] = ($canonical === '/' ? '/feed/' : $canonical . '/feed/');
        }
        $paths = array_unique(array_merge($paths, $feed_paths));
        // Allow external filters to adjust
        $paths = apply_filters('ace_rc_post_archive_invalidation_paths', $paths, $post_id, $post, $this->settings);
        if (empty($paths)) return;
        $schemes = ['http','https'];
        $devices = ['desktop','mobile'];
        $redis = $this->cache_manager && method_exists($this->cache_manager, 'get_raw_client') ? $this->cache_manager->get_raw_client() : null;
        $deleted = 0; $examined = 0;
        foreach ($paths as $p) {
            foreach ($schemes as $scheme) {
                foreach ($devices as $device) {
                    $core = $this->build_page_cache_core_key($p, $scheme, $device);
                    $raw_key = 'page_cache:' . $core;
                    $min_key = 'page_cache_min:' . $core;
                    $legacy_key = $core;
                    $examined += 3;
                    try {
                        if ($redis) {
                            if (method_exists($redis, 'del')) {
                                $deleted += (int)$redis->del($raw_key);
                                $deleted += (int)$redis->del($min_key);
                                $deleted += (int)$redis->del($legacy_key);
                            } elseif (method_exists($redis, 'unlink')) {
                                $deleted += (int)$redis->unlink($raw_key);
                                $deleted += (int)$redis->unlink($min_key);
                                $deleted += (int)$redis->unlink($legacy_key);
                            }
                        }
                    } catch (\Throwable $t) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('AceRedisCache archive cache invalidation error: ' . $t->getMessage());
                        }
                    }
                }
            }
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('AceRedisCache archive/home page cache purge post_id=' . $post_id . ' paths=' . count($paths) . ' deleted_keys=' . $deleted . ' examined=' . $examined);
        }
    }

    /**
     * Hook: post_updated – detect title or slug changes; if targeted transient flush finds nothing, optionally flush all transients.
     */
    public function on_post_updated($post_ID, $post_after, $post_before) {
        if (wp_is_post_revision($post_ID)) return;
        if (!$post_after || !$post_before) return; // need both snapshots
        $was_published = ($post_before->post_status === 'publish');
        $is_published  = ($post_after->post_status === 'publish');
        if (!$was_published && !$is_published) return; // neither side published
        if (!is_post_type_viewable($post_after->post_type)) return;
        // Critical change detection: a transition into publish OR a slug change while published must invalidate immediately.
        // Deferring these allows stale transients (old slug / draft permalink) to survive long enough to cause
        // redirect_canonical() to send guests to a non-public draft URL (404). Force immediate purge to prevent that.
        $critical_publish_transition = (!$was_published && $is_published);
        $slug_changed = ($post_before->post_name !== $post_after->post_name);
        $force_immediate = apply_filters('ace_rc_force_immediate_invalidation_on_critical_change', ($critical_publish_transition || $slug_changed), $post_ID, $post_after, $post_before, $this->settings);
        if ($this->should_defer_post_invalidation() && !$force_immediate) {
            $this->enqueue_deferred_post($post_ID, ['page'=>true,'archives'=>true,'transients'=>true,'blocks_full'=>true]);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Ace-Redis-Cache: deferred post update invalidation queued post_id=' . $post_ID . ' was_published=' . ($was_published?'1':'0') . ' is_published=' . ($is_published?'1':'0') . ' slug_changed=' . ($slug_changed?'1':'0'));
            }
            return;
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('Ace-Redis-Cache: on_post_updated post_id=%d was_published=%s is_published=%s slug_changed=%s immediate=%s', $post_ID, $was_published?'yes':'no', $is_published?'yes':'no', $slug_changed?'yes':'no', $force_immediate?'yes':'no'));
        }
        $deleted = $this->maybe_flush_post_transients($post_ID, $post_after->post_type);
        if ($deleted === 0 && !empty($this->settings['enable_transient_cache'])) {
            $allow_global = apply_filters('ace_rc_transient_global_flush_on_post_change', true, $post_ID, $post_after, $post_before);
            if ($allow_global) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Ace-Redis-Cache: fallback global transient flush (no targeted matches) post_id=' . $post_ID);
                }
                $this->flush_all_transients('fallback_post_update_any_change');
            }
        }
        // Mark this post so the next anonymous render skips page & block cache storage (style-safe warm pass)
        $this->mark_post_requires_first_pass($post_ID);
        // Set global warm window so other pages also avoid premature capture if shared aggregated styles regenerate.
        $warm_window = (int) apply_filters('ace_rc_global_asset_warm_window', 30, $post_ID, $post_after, $post_before, $this->settings);
        if ($warm_window > 0) {
            update_option('ace_rc_global_asset_warm_until', time() + $warm_window, false);
            if (defined('WP_DEBUG') && WP_DEBUG) { error_log('Ace-Redis-Cache: global asset warm window +' . $warm_window . 's'); }
        }
    }

    /**
     * Flush transients that plausibly cache data for a specific post (title, permalink, meta) to prevent stale draft URLs / titles.
     * Strategy: pattern match common transient naming schemes and custom plugin prefixes, plus allow filters for site-specific patterns.
     * Uses SCAN for safety. Only runs if transient caching feature is on.
     */
    private function maybe_flush_post_transients($post_id, $post_type) {
        if (empty($this->settings['enable_transient_cache']) || !$this->cache_manager) return 0;
        
        // Performance optimization: Skip expensive SCAN operations in admin contexts
        // This prevents admin slowdown when transient cache is enabled
        if (!empty($this->settings['enable_admin_optimization']) && $this->is_admin_context() && !$this->should_force_transient_flush()) {
            // Optionally run lightweight cleanup instead of full scan
            if (!empty($this->settings['enable_lightweight_cleanup'])) {
                return $this->lightweight_transient_cleanup($post_id, $post_type);
            }
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('Ace-Redis-Cache: skipped transient scan for post_id=%d (admin optimization)', $post_id));
            }
            return 0;
        }
        
        $post_id = (int)$post_id; if ($post_id <= 0) return 0;
        $compiled_patterns = $this->compile_batched_transient_patterns($post_id);
        if (empty($compiled_patterns)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('Ace-Redis-Cache: no transient patterns for post_id=%d', $post_id));
            }
            return 0;
        }
        $redis = $this->cache_manager ? $this->cache_manager : null;
        if (!$redis) return 0;
        $to_delete = [];
        $use_object_cache = wp_using_ext_object_cache();
        $scan_count = 0;
        foreach ($compiled_patterns as $pattern) {
            $keys = $this->cache_manager->scan_keys($pattern);
            if (!empty($keys)) {
                $to_delete = array_merge($to_delete, $keys);
                $scan_count++;
            }
        }
        if ($use_object_cache) {
            $obj_patterns = [
                'ace:*:transient:%d:*',
                'ace:*:transient:post_%d_*',
                'ace:*:transient:post-%d-*',
                'ace:g:site-transient:post_%d_*',
            ];
            foreach ($obj_patterns as $pattern) {
                $keys = $this->cache_manager->scan_keys(sprintf($pattern, $post_id));
                if (!empty($keys)) {
                    $to_delete = array_merge($to_delete, $keys);
                    $scan_count++;
                }
            }
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('Ace-Redis-Cache: transient-batch-scan post_id=%d scans=%d keys_found=%d', $post_id, $scan_count, count($to_delete)));
        }
        $to_delete = array_unique($to_delete);
        if (!empty($to_delete)) {
            $this->cache_manager->delete_keys_chunked($to_delete, 500);
            do_action('ace_rc_post_transients_flushed', $post_id, $post_type, count($to_delete), $to_delete);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('Ace-Redis-Cache: deleted %d transient keys for post_id=%d', count($to_delete), $post_id));
            }
            return count($to_delete);
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('Ace-Redis-Cache: no targeted transient keys matched for post_id=%d', $post_id));
        }
        return 0;
    }

    /**
     * Flush all transients (single + site) managed by plugin. Heavy operation, used only as fallback.
     */
    private function flush_all_transients($reason = 'manual') {
        if (empty($this->settings['enable_transient_cache']) || !$this->cache_manager) return 0;
        
        // Performance check: avoid expensive operations in admin contexts unless forced
        if (!empty($this->settings['enable_admin_optimization']) && $this->is_admin_context() && !$this->should_force_transient_flush() && $reason !== 'manual') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Ace-Redis-Cache: skipped full transient flush (admin optimization, reason=' . $reason . ')');
            }
            return 0;
        }
        
        $prefix_sets = [ 'transient:', 'site_transient:' ];
        // When an external object cache is active, include its group-based key namespaces.
        if (wp_using_ext_object_cache()) {
            $prefix_sets[] = 'ace:*:transient:';       // blog-scoped transient group (wildcard blog id)
            $prefix_sets[] = 'ace:g:site-transient:';  // global site transients
        }
        $all = [];
        foreach ($prefix_sets as $pfx) {
            $keys = $this->cache_manager->scan_keys($pfx . '*');
            if (!empty($keys)) $all = array_merge($all, $keys);
        }
        $all = array_unique($all);
        if (!empty($all)) {
            $this->cache_manager->delete_keys_chunked($all, 1000);
            do_action('ace_rc_all_transients_flushed', $reason, count($all));
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Ace-Redis-Cache: global transient flush reason=' . $reason . ' deleted=' . count($all));
            }
            return count($all);
        }
        return 0;
    }

    /* ============================= Post First-Pass Warm Markers ============================= */
    private $first_pass_marker_option = 'ace_rc_pending_first_pass_posts';

    private function mark_post_requires_first_pass($post_id) {
        $post_id = (int)$post_id; if ($post_id <= 0) return;
        $list = get_option($this->first_pass_marker_option, []);
        if (!is_array($list)) { $list = []; }
        $list[$post_id] = time();
        // prune if grows too large
        if (count($list) > 200) {
            asort($list); // oldest first
            $list = array_slice($list, -150, null, true);
        }
        update_option($this->first_pass_marker_option, $list, false);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Ace-Redis-Cache: mark post first-pass skip id=' . $post_id);
        }
    }

    private function consume_post_first_pass_flag($post_id) {
        $post_id = (int)$post_id; if ($post_id <= 0) return false;
        $list = get_option($this->first_pass_marker_option, []);
        if (!is_array($list) || empty($list[$post_id])) return false;
        unset($list[$post_id]);
        update_option($this->first_pass_marker_option, $list, false);
        return true;
    }

    private function has_post_first_pass_flag($post_id) {
        $post_id = (int)$post_id; if ($post_id <= 0) return false;
        $list = get_option($this->first_pass_marker_option, []);
        return is_array($list) && isset($list[$post_id]);
    }

    /**
     * Heuristic: decide whether inline and enqueued style assets look "complete".
     * We avoid caching during warm window if critical markers absent.
     * - Look for common inline style blocks (global styles, theme JSON output) and key plugin handles.
     * - Filter allows site-specific extension.
     */
    private function is_style_asset_complete($html) {
        if (!is_string($html) || $html === '') return false;
        $markers = [
            'id="global-styles-inline-css"', // core global styles
            'wp-block-library-theme-inline-css',
            'wp-block-library-inline-css',
            'rel="stylesheet" href', // at least one stylesheet
        ];
        $found = 0; $need = 2; // require at least two markers
        foreach ($markers as $m) { if (strpos($html, $m) !== false) { $found++; if ($found >= $need) break; } }
        $result = ($found >= $need);
        return apply_filters('ace_rc_style_asset_complete', $result, $found, $markers, $html);
    }

    private function compile_batched_transient_patterns($post_id) {
        $post_id = (int)$post_id;
        if ($post_id <= 0) return [];

        $default_patterns = [
            'transient:_transient_timeout_%d_*',
            'transient:%d:*',
            'transient:post_%d_*',
            'transient:post-%d-*',
            'transient:wp_rest_cache_*_%d*',
            'transient:acf_field_group_location_*_%d*',
            'transient:related_posts_%d*',
            'transient:seo_meta_%d*',
            'transient:query_%d_*',
            'transient:loop_%d_*',
            'transient:wpseo_permalink_slug_%d',
            'transient:wpseo_sitemap_*_%d*',
            'transient:wpseo_meta_%d*',
            'transient:rank_math_permalink_%d',
            'transient:rank_math_seo_%d*',
            'transient:jetpack_related_posts_%d*',
            'transient:jetpack_stats_%d*',
            'transient:jetpack_sharing_%d*',
            'transient:post_link_%d*',
            'transient:get_permalink_%d*',
            'transient:acf_post_%d*',
            'transient:acf_fields_%d*',
            'transient:elementor_post_%d*',
            'transient:wp_rocket_post_%d*',
            'transient:litespeed_post_%d*',
        ];

        $patterns = apply_filters('ace_rc_post_transient_patterns', $default_patterns, $post_id, '', $this->settings);
        $compiled = [];
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || strpos($pattern, '%d') === false) continue;
            $compiled[] = sprintf($pattern, $post_id);
        }

        return array_unique($compiled);
    }

    /* ============================= Post-Save Option Priming ============================= */
    public function post_save_prime_schedule($post_id, $post, $update) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!is_admin()) return; // only run from admin-originating saves
        // Set short no-cache window (60s) to avoid storing incomplete styles
        set_transient('ace_rc_no_cache_window', 1, 60);
        // Defer priming until shutdown to ensure core finished updating options
        add_action('shutdown', [$this, 'prime_critical_options_once']);
    }

    public function prime_critical_options_once() {
        static $ran = false; if ($ran) return; $ran = true;
        // Flush page cache broadly only if warm window heuristics not already active
        if (method_exists($this->cache_manager, 'scan_keys')) {
            $keys = $this->cache_manager->scan_keys('page_cache:*');
            if (!empty($keys)) { $this->cache_manager->delete_keys_chunked($keys, 1000); }
            $min = $this->cache_manager->scan_keys('page_cache_min:*');
            if (!empty($min)) { $this->cache_manager->delete_keys_chunked($min, 1000); }
        }
        // Load fresh coherent values
        $rewrite_rules = get_option('rewrite_rules');
        $permalink_structure = get_option('permalink_structure');
        $alloptions = function_exists('wp_load_alloptions') ? wp_load_alloptions() : [];
        // Push into object cache forcibly (prime_set is custom method on our drop-in)
        if (function_exists('wp_cache_set')) {
            global $wp_object_cache;
            if ($wp_object_cache && method_exists($wp_object_cache, 'prime_set')) {
                $wp_object_cache->prime_set('rewrite_rules', $rewrite_rules, 'options');
                $wp_object_cache->prime_set('permalink_structure', $permalink_structure, 'options');
                $wp_object_cache->prime_set('alloptions', $alloptions, 'options');
            } else {
                wp_cache_set('rewrite_rules', $rewrite_rules, 'options');
                wp_cache_set('permalink_structure', $permalink_structure, 'options');
                wp_cache_set('alloptions', $alloptions, 'options');
            }
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('AceRedisCache: primed critical options (rewrite_rules len=' . (is_string($rewrite_rules)? strlen($rewrite_rules):0) . ')');
        }
    }

    /* ============================= Deferred Invalidation ============================= */
    private $deferred_queue_option = 'ace_rc_deferred_invalidation_queue';
    private $deferred_processing = false;
    private $deferred_shutdown_hooked = false;

    private function should_defer_post_invalidation() {
        return apply_filters('ace_rc_defer_post_invalidation', true, $this->settings) && !$this->deferred_processing;
    }

    private function enqueue_deferred_post($post_id, array $ops) {
        $post_id = (int)$post_id; if (!$post_id) return;
        $queue = get_option($this->deferred_queue_option, []);
        if (!is_array($queue)) { $queue = []; }
        if (empty($queue[$post_id])) { $queue[$post_id] = $ops; }
        else { $queue[$post_id] = array_merge($queue[$post_id], $ops); }
        update_option($this->deferred_queue_option, $queue, false);
        $this->schedule_deferred_runner();
        $this->maybe_hook_shutdown_fallback();
    }

    private function schedule_deferred_runner() {
        if (!wp_next_scheduled('ace_rc_run_deferred_invalidation')) {
            @wp_schedule_single_event(time() + 3, 'ace_rc_run_deferred_invalidation');
        }
    }

    /**
     * If WP-Cron is disabled or unlikely to run (local dev), register a shutdown fallback to process queue immediately.
     */
    private function maybe_hook_shutdown_fallback() {
        if ($this->deferred_shutdown_hooked) return;
        $cron_disabled = (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);
        $is_cli = (defined('WP_CLI') && WP_CLI);
        // If cron disabled OR we're in admin and likely no subsequent hit soon, fallback.
        if ($cron_disabled || is_admin() || $is_cli) {
            add_action('shutdown', function() {
                // Only run if queue still present and not already processing (avoid recursion)
                if ($this->deferred_processing) return;
                $queue = get_option($this->deferred_queue_option, []);
                if (!empty($queue)) {
                    $this->deferred_processing = true;
                    $this->run_deferred_invalidation();
                }
            }, 9999);
            $this->deferred_shutdown_hooked = true;
        }
    }

    /**
     * Opportunistically process deferred queue early on any request if it lingers (e.g., cron did not fire yet).
     */
    private function maybe_process_lingering_deferred_queue() {
        if ($this->deferred_processing) return;
        $queue = get_option($this->deferred_queue_option, []);
        if (empty($queue) || !is_array($queue)) return;
        // Check age by storing timestamp inside queue pseudo-key __ts
        $ts = isset($queue['__ts']) ? (int)$queue['__ts'] : 0;
        if (!$ts) {
            $queue['__ts'] = time();
            update_option($this->deferred_queue_option, $queue, false);
            return;
        }
        if (time() - $ts > 10) { // more than 10s waiting -> process now
            $this->run_deferred_invalidation();
        }
    }

    public function run_deferred_invalidation() {
        $queue = get_option($this->deferred_queue_option, []);
        if (empty($queue) || !is_array($queue)) return;
        // Remove timestamp marker if present
        if (isset($queue['__ts'])) { unset($queue['__ts']); }
        $this->deferred_processing = true;
        foreach ($queue as $pid => $ops) {
            $pid = (int)$pid; if (!$pid) continue;
            $post = get_post($pid);
            if (!$post) continue;
            if (!empty($ops['page'])) { $this->invalidate_post_page_cache($pid, true); }
            if (!empty($ops['archives'])) { $this->maybe_invalidate_related_archive_page_cache($pid, $post); }
            if (!empty($ops['transients'])) { $this->maybe_flush_post_transients($pid, $post->post_type); }
        }
        $needs_block_full = false;
        foreach ($queue as $ops) { if (!empty($ops['blocks_full'])) { $needs_block_full = true; break; } }
        if ($needs_block_full && $this->cache_manager) {
            $keys = $this->cache_manager->scan_keys('block_cache:*');
            if (!empty($keys)) {
                $this->cache_manager->delete_keys_chunked($keys, 1000);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Ace-Redis-Cache: deferred FULL block cache purge deleted=' . count($keys));
                }
            }
        }
        delete_option($this->deferred_queue_option);
        $this->deferred_processing = false;
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
            return false; // Continue with WordPress default
        }
        
        $cached = $this->get_transient($value, $transient);
        // Only short-circuit when we actually have a cached value; otherwise allow WP to proceed
        return ($cached !== null) ? $cached : false;
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
     * Site transient handlers (network-aware)
     */
    public function get_site_transient($value, $transient) {
        if ($this->cache_manager->should_exclude_transient($transient)) {
            return $value;
        }
        $cache_key = 'site_transient:' . $transient;
        return $this->cache_manager->get($cache_key);
    }

    public function set_site_transient($value, $transient, $expiration) {
        if ($this->cache_manager->should_exclude_transient($transient)) {
            return $value;
        }
        $cache_key = 'site_transient:' . $transient;
        $this->cache_manager->set($cache_key, $value, $expiration);
        return $value;
    }

    public function delete_site_transient($value, $transient) {
        if ($this->cache_manager->should_exclude_transient($transient)) {
            return $value;
        }
        $cache_key = 'site_transient:' . $transient;
        $this->cache_manager->delete($cache_key);
        return $value;
    }

    public function filter_set_site_transient($value, $transient, $expiration) {
        if ($this->cache_manager->should_exclude_transient($transient)) {
            return null;
        }
        return $this->set_site_transient($value, $transient, $expiration);
    }

    public function filter_get_site_transient($value, $transient) {
        if ($this->cache_manager->should_exclude_transient($transient)) {
            return false;
        }
        $cached = $this->get_site_transient($value, $transient);
        return ($cached !== null) ? $cached : false;
    }

    public function filter_delete_site_transient($value, $transient) {
        if ($this->cache_manager->should_exclude_transient($transient)) {
            return null;
        }
        return $this->delete_site_transient($value, $transient);
    }

    /**
     * Decide if a WP_Query should be cached (guests only), with safe exclusions
     */
    
    /**
     * Handle plugin activation
     */
    public function on_activation($network_wide = false) {
        // Ensure option exists in the active scope.
        $use_network_scope = is_multisite() && (bool) $network_wide;
        $existing = $use_network_scope ? get_site_option('ace_redis_cache_settings', null) : SettingsStore::get_settings(null);
        if ($existing === null) {
            // Build defaults (mirrors load_settings defaults)
            $defaults = [
                'host' => '127.0.0.1',
                'port' => 6379,
                'password' => '',
                'ttl' => 3600,
                'mode' => 'full',
                'enabled' => 1,
                'enable_tls' => 0,
                'enable_transient_cache' => 0,
                'enable_minification' => 0,
                'enable_compression' => 0,
                'compression_method' => 'brotli',
                'brotli_level_object' => 5,
                'brotli_level_page' => 9,
                'gzip_level_object' => 6,
                'gzip_level_page' => 6,
                'min_compress_size' => 512,
                'custom_cache_exclusions' => '',
                'custom_transient_exclusions' => '',
                'custom_content_exclusions' => '',
                'exclude_sitemaps' => 0,
                'enable_page_cache' => 1,
                'enable_object_cache' => 0,
                'ttl_page' => 3600,
                'ttl_object' => 3600,
                'enable_browser_cache_headers' => 0,
                'browser_cache_max_age' => 3600,
                'send_cache_meta_headers' => 0,
                'enable_dynamic_microcache' => 0,
                'dynamic_microcache_ttl' => 10,
                'enable_opcache_helpers' => 0,
                'enable_static_asset_cache' => 0,
                'static_asset_cache_ttl' => 604800,
                'enable_asset_proxy_cache' => 0,
                'asset_proxy_cache_ttl' => 604800,
                'manage_static_cache_via_htaccess' => 0,
                'prefer_existing_static_cache_headers' => 1,
            ];
            if ($use_network_scope) {
                add_site_option('ace_redis_cache_settings', $defaults);
            } else {
                SettingsStore::ensure_settings_exists($defaults);
            }
            if (!SettingsStore::is_network_mode() && function_exists('wp_cache_delete')) {
                @wp_cache_delete('notoptions', 'options');
                @wp_cache_delete('ace_redis_cache_settings', 'options');
                @wp_cache_delete('alloptions', 'options');
            }
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
     * Handle settings updates.
     */
    public function on_settings_updated($old_value, $new_value) {
        // Reload settings.
        if (is_string($new_value)) {
            $decoded = json_decode($new_value, true);
            $this->settings = is_array($decoded) ? $decoded : [];
        } else {
            $this->settings = is_array($new_value) ? $new_value : [];
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $old_flag = is_array($old_value) ? ($old_value['enable_transient_cache'] ?? 'MISS') : 'NA';
            $new_flag = is_array($new_value) ? ($new_value['enable_transient_cache'] ?? 'MISS') : 'NA';
            error_log('Ace-Redis-Cache: on_settings_updated old_transient=' . $old_flag . ' new_transient=' . $new_flag);
        }

        // Proactively clear any cached copy (defensive; our drop-in now bypasses but keep for other caches).
        if (!SettingsStore::is_network_mode() && function_exists('wp_cache_delete')) {
            @wp_cache_delete('ace_redis_cache_settings', 'options');
        }

        // Reinitialize components with new settings.
        $this->init_components();

        // Clear cache when settings change.
        if ($this->cache_manager) {
            $this->cache_manager->clear_all_cache();
        }

        // Manage object-cache.php drop-in based on setting.
        try {
            $this->maybe_manage_dropin();
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Ace-Redis-Cache drop-in management error: ' . $e->getMessage());
            }
        }

        try {
            $this->maybe_manage_advanced_dropin();
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Ace-Redis-Cache advanced drop-in management error: ' . $e->getMessage());
            }
        }

        try {
            $this->maybe_manage_static_cache_htaccess();
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Ace-Redis-Cache .htaccess management error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Wrapper for update_site_option_ace_redis_cache_settings action.
     */
    public function on_site_settings_updated($option, $value, $old_value, $network_id = null) {
        $this->on_settings_updated($old_value, $value);
    }

    private function maybe_manage_static_cache_htaccess() {
        $static_enabled = !empty($this->settings['enable_static_asset_cache']);
        $manage_htaccess = !empty($this->settings['manage_static_cache_via_htaccess']);
        $prefer_existing = !empty($this->settings['prefer_existing_static_cache_headers']);

        $htaccess_path = $this->get_site_root_htaccess_path();
        if (!$htaccess_path) {
            return;
        }

        $ttl = isset($this->settings['static_asset_cache_ttl']) ? (int) $this->settings['static_asset_cache_ttl'] : 604800;
        if ($ttl < 86400) { $ttl = 86400; }
        if ($ttl > 31536000) { $ttl = 31536000; }

        if (!$static_enabled || !$manage_htaccess) {
            $this->remove_managed_static_cache_block($htaccess_path);
            return;
        }

        if ($prefer_existing && $this->detect_existing_static_cache_headers($ttl)) {
            $this->remove_managed_static_cache_block($htaccess_path);
            return;
        }

        $this->upsert_managed_static_cache_block($htaccess_path, $ttl);
    }

    private function get_site_root_htaccess_path() {
        $site_root = rtrim(dirname(ABSPATH), '/');
        if ($site_root === '') {
            return null;
        }
        return $site_root . '/.htaccess';
    }

    private function get_managed_static_cache_block($ttl) {
        $ttl = (int) $ttl;
        return "# BEGIN Ace Redis Cache Static Assets\n"
            . "<IfModule mod_headers.c>\n"
            . "  <FilesMatch \"\\.(?:png|jpe?g|gif|webp|avif|svg|ico|css|js|mjs|woff2?|ttf|eot|otf|json|map)$\">\n"
            . "    Header set Cache-Control \"public, max-age={$ttl}, immutable\"\n"
            . "  </FilesMatch>\n"
            . "</IfModule>\n"
            . "<IfModule mod_expires.c>\n"
            . "  ExpiresActive On\n"
            . "  ExpiresByType text/css \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType text/javascript \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType application/javascript \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType application/x-javascript \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType application/json \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType application/font-woff \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType application/font-woff2 \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType font/woff \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType font/woff2 \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType image/svg+xml \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType image/png \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType image/jpeg \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType image/gif \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType image/webp \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType image/avif \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType image/x-icon \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType application/vnd.ms-fontobject \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType font/ttf \"access plus {$ttl} seconds\"\n"
            . "  ExpiresByType font/otf \"access plus {$ttl} seconds\"\n"
            . "</IfModule>\n"
            . "# END Ace Redis Cache Static Assets";
    }

    private function remove_managed_static_cache_block($htaccess_path) {
        if (!file_exists($htaccess_path) || !is_readable($htaccess_path) || !is_writable($htaccess_path)) {
            return;
        }

        $current = (string) file_get_contents($htaccess_path);
        $updated = preg_replace('/\n?# BEGIN Ace Redis Cache Static Assets.*?# END Ace Redis Cache Static Assets\n?/s', "\n", $current);
        if ($updated !== null && $updated !== $current) {
            file_put_contents($htaccess_path, trim($updated) . "\n", LOCK_EX);
        }
    }

    private function upsert_managed_static_cache_block($htaccess_path, $ttl) {
        $block = $this->get_managed_static_cache_block($ttl);

        if (!file_exists($htaccess_path)) {
            $dir = dirname($htaccess_path);
            if (!is_dir($dir) || !is_writable($dir)) {
                return;
            }
            file_put_contents($htaccess_path, $block . "\n", LOCK_EX);
            return;
        }

        if (!is_readable($htaccess_path) || !is_writable($htaccess_path)) {
            return;
        }

        $current = (string) file_get_contents($htaccess_path);
        $stripped = preg_replace('/\n?# BEGIN Ace Redis Cache Static Assets.*?# END Ace Redis Cache Static Assets\n?/s', "\n", $current);

        if ($stripped === null) {
            return;
        }

        $new_content = rtrim($stripped) . "\n\n" . $block . "\n";
        if ($new_content !== $current) {
            file_put_contents($htaccess_path, $new_content, LOCK_EX);
        }
    }

    private function detect_existing_static_cache_headers($ttl) {
        if (!function_exists('wp_remote_head')) {
            return false;
        }

        $probe_candidates = array_filter(array_unique([
            get_stylesheet_uri(),
            trailingslashit(get_template_directory_uri()) . 'style.css',
            includes_url('css/dashicons.min.css'),
        ]));

        foreach ($probe_candidates as $probe_url) {
            $response = wp_remote_head(add_query_arg('ace_rc_probe', (string) time(), $probe_url), [
                'timeout' => 4,
                'redirection' => 2,
                'sslverify' => true,
            ]);

            if (is_wp_error($response)) {
                continue;
            }

            $cache_control = wp_remote_retrieve_header($response, 'cache-control');
            if (!is_string($cache_control) || $cache_control === '') {
                continue;
            }

            if (strpos(strtolower($cache_control), 'immutable') !== false) {
                return true;
            }

            if (preg_match('/max-age=(\d+)/i', $cache_control, $m)) {
                return ((int) $m[1]) >= (int) $ttl;
            }
        }

        return false;
    }

    /**
     * Deploy or remove object-cache.php drop-in in WP_CONTENT_DIR based on settings
     */
    private function maybe_manage_dropin() {
        $enabled = (bool)($this->settings['enable_object_cache_dropin'] ?? 0);
        $content_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : WP_CONTENT_DIR; // WP sets this
        $dropin_target = trailingslashit($content_dir) . 'object-cache.php';
        $dropin_source = trailingslashit($this->plugin_path) . '/assets/dropins/object-cache.php';
        $did_install_or_remove = false; // track change for wp_cache_flush()

        if ($enabled) {
            // Ensure source exists; if not, provide guidance via error_log
            if (!file_exists($dropin_source)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Ace-Redis-Cache: drop-in source not found: ' . $dropin_source);
                }
                return;
            }
            $need_copy = true;
            $existing_is_ours = false;
            $existing_same_hash = false;
            if (file_exists($dropin_target)) {
                $existing = @file_get_contents($dropin_target);
                if (is_string($existing)) {
                    $existing_is_ours = strpos($existing, 'Ace Redis Cache Drop-In') !== false;
                    $src_hash = @md5_file($dropin_source);
                    $tgt_hash = md5($existing);
                    if ($src_hash && $tgt_hash && $src_hash === $tgt_hash) {
                        $existing_same_hash = true;
                    }
                }
            }
            if ($existing_is_ours && $existing_same_hash) {
                // Already deployed and identical — nothing to do
                $need_copy = false;
            }
            if ($need_copy) {
                if (!@copy($dropin_source, $dropin_target)) {
                    $msg = 'Ace-Redis-Cache: Failed to deploy object-cache.php. Try manually: '
                        . 'cp ' . escapeshellarg($dropin_source) . ' ' . escapeshellarg($dropin_target);
                    if (defined('WP_DEBUG') && WP_DEBUG) { error_log($msg); }
                } else { $did_install_or_remove = true; }
            }
        } else {
            // Remove drop-in if we own it (basic heuristic: look for our signature header)
            if (file_exists($dropin_target)) {
                $contents = @file_get_contents($dropin_target);
                if ($contents !== false && strpos($contents, 'Ace Redis Cache Drop-In') !== false) {
                    if (!@unlink($dropin_target)) {
                        $msg = 'Ace-Redis-Cache: Failed to remove object-cache.php. Try manually:\n'
                            . 'rm ' . escapeshellarg($dropin_target);
                        if (defined('WP_DEBUG') && WP_DEBUG) { error_log($msg); }
                    } else { $did_install_or_remove = true; }
                }
            }
        }
        // If a drop-in was installed or removed during this settings change request, flush object cache once.
        if ($did_install_or_remove && function_exists('wp_cache_flush')) {
            @wp_cache_flush();
        }
    }

    /**
     * Deploy or remove advanced-cache.php in WP_CONTENT_DIR based on page-cache setting.
     */
    private function maybe_manage_advanced_dropin() {
        $enabled = !empty($this->settings['enable_page_cache']);
        $content_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : WP_CONTENT_DIR;
        $dropin_target = trailingslashit($content_dir) . 'advanced-cache.php';
        $dropin_source = trailingslashit($this->plugin_path) . '/assets/dropins/advanced-cache.php';

        if ($enabled) {
            if (!file_exists($dropin_source)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Ace-Redis-Cache: advanced drop-in source not found: ' . $dropin_source);
                }
                return;
            }

            $need_copy = true;
            if (file_exists($dropin_target)) {
                $existing = @file_get_contents($dropin_target);
                if (is_string($existing) && strpos($existing, 'Ace Redis Cache advanced-cache drop-in') !== false) {
                    $src_hash = @md5_file($dropin_source);
                    $tgt_hash = md5($existing);
                    if ($src_hash && $tgt_hash && $src_hash === $tgt_hash) {
                        $need_copy = false;
                    }
                }
            }

            if ($need_copy && !@copy($dropin_source, $dropin_target) && defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Ace-Redis-Cache: Failed to deploy advanced-cache.php');
            }
            return;
        }

        if (file_exists($dropin_target)) {
            $contents = @file_get_contents($dropin_target);
            if ($contents !== false && strpos($contents, 'Ace Redis Cache advanced-cache drop-in') !== false) {
                if (!@unlink($dropin_target) && defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Ace-Redis-Cache: Failed to remove advanced-cache.php');
                }
            }
        }
    }

    /**
     * pre_update_option filter to log and preserve transient flag if being unintentionally cleared.
     * @param mixed $new_value Proposed new option value
     * @param mixed $old_value Existing value
     * @return mixed Filtered value
     */
    public function pre_update_settings_trace($new_value, $old_value) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $old_flag = is_array($old_value) ? ($old_value['enable_transient_cache'] ?? 'MISS') : 'NA';
            $incoming_flag = is_array($new_value) ? ($new_value['enable_transient_cache'] ?? 'MISS') : 'NA';
            error_log('Ace-Redis-Cache: pre_update_settings incoming_transient=' . $incoming_flag . ' old_transient=' . $old_flag);
        }
        if (is_array($new_value)) {
            $this->debug_pre_update_transient_early = $new_value['enable_transient_cache'] ?? null;
        } else {
            $this->debug_pre_update_transient_early = null;
        }
        // If old had transient=1 and new array omits the key entirely, preserve it (defensive)
        if (is_array($old_value) && is_array($new_value)) {
            if (!array_key_exists('enable_transient_cache', $new_value) && !empty($old_value['enable_transient_cache'])) {
                $new_value['enable_transient_cache'] = 1;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Ace-Redis-Cache: pre_update_settings preserved missing transient=1');
                }
            }
        }
        return $new_value;
    }

    /**
     * Wrapper for pre_update_site_option_ace_redis_cache_settings filter.
     */
    public function pre_update_site_settings_trace($new_value, $old_value, $option = null) {
        return $this->pre_update_settings_trace($new_value, $old_value);
    }

    /**
     * Late pre_update hook to see if another filter changed transient flag after our early trace.
     */
    public function pre_update_settings_trace_late($new_value, $old_value) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $late_flag = is_array($new_value) ? ($new_value['enable_transient_cache'] ?? 'MISS') : 'NA';
            $old_flag = is_array($old_value) ? ($old_value['enable_transient_cache'] ?? 'MISS') : 'NA';
            error_log('Ace-Redis-Cache: pre_update_settings_late transient=' . $late_flag . ' old=' . $old_flag . ' early=' . var_export($this->debug_pre_update_transient_early, true));
        }
        // If early wanted 1 but late now 0, force it back to 1 and log backtrace
        if ($this->debug_pre_update_transient_early === 1 && is_array($new_value)) {
            $late_flag = $new_value['enable_transient_cache'] ?? 0;
            if ((int)$late_flag === 0) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
                    // sanitize backtrace to function/class only
                    $simple = [];
                    foreach ($bt as $frame) {
                        $f = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
                        if ($f) { $simple[] = $f; }
                    }
                    error_log('Ace-Redis-Cache: transient flag overridden to 0 by another filter - restoring to 1. Backtrace: ' . implode(' <= ', $simple));
                }
                $new_value['enable_transient_cache'] = 1;
            }
        }
        return $new_value;
    }

    /**
     * Trace reads of the option to detect if value flips after save due to external actor.
     * @param mixed $value
     * @return mixed
     */
    public function trace_option_read($value) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            if (is_array($value)) {
                $flag = $value['enable_transient_cache'] ?? 'MISS';
            } else {
                $flag = 'NA';
            }
            if ((int)$flag === 0) {
                $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
                $simple = [];
                foreach ($bt as $frame) {
                    $f = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
                    if ($f && stripos($f, 'trace_option_read') === false) { $simple[] = $f; }
                }
                error_log('Ace-Redis-Cache: option read transient=0 (trace_option_read) stack=' . implode(' <= ', $simple));
            } else {
                error_log('Ace-Redis-Cache: option read transient=' . $flag . ' (trace_option_read)');
            }
        }
        return $value;
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

    public function maybe_serve_asset_proxy_request() {
        $asset = isset($_GET['ace_rc_asset']) ? wp_unslash((string) $_GET['ace_rc_asset']) : '';
        if ($asset === '') {
            return;
        }

        $redirect_target = $this->normalize_same_origin_asset_url($asset);
        if (!$redirect_target) {
            status_header(404);
            exit;
        }

        wp_safe_redirect($redirect_target, 302);
        exit;
    }

    private function rewrite_image_urls_for_cache_busting($content) {
        if (empty($this->settings['enable_static_asset_cache']) || !is_string($content) || $content === '') {
            return $content;
        }

        if (!preg_match('/\.(?:png|jpe?g|gif|webp|avif|svg|ico)(?:\?(?:[^"\'\s>]+))?/i', $content)) {
            return $content;
        }

        $content = preg_replace_callback('/\b(src|href)=(["\'])([^"\']+)\2/i', function ($matches) {
            $versioned = $this->build_image_cache_busted_url($matches[3]);
            if ($versioned === null) {
                return $matches[0];
            }
            return $matches[1] . '=' . $matches[2] . esc_url($versioned) . $matches[2];
        }, $content);

        $content = preg_replace_callback('/\bsrcset=(["\'])([^"\']+)\1/i', function ($matches) {
            $entries = array_map('trim', explode(',', $matches[2]));
            $rewritten = [];
            foreach ($entries as $entry) {
                if ($entry === '') {
                    continue;
                }
                $parts = preg_split('/\s+/', $entry, 2);
                $url = $parts[0] ?? '';
                $descriptor = $parts[1] ?? '';
                $versioned = $this->build_image_cache_busted_url($url);
                $rewritten[] = ($versioned ?: $url) . ($descriptor !== '' ? ' ' . $descriptor : '');
            }
            return 'srcset=' . $matches[1] . implode(', ', $rewritten) . $matches[1];
        }, $content);

        return $content;
    }

    private function build_image_cache_busted_url($url) {
        if (!is_string($url) || $url === '' || str_starts_with($url, 'data:')) {
            return null;
        }

        $resolved = $this->resolve_asset_proxy_file($url);
        if (!$resolved) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '' || !preg_match('/\.(?:png|jpe?g|gif|webp|avif|svg|ico)$/i', $path)) {
            return null;
        }

        $versioned = $this->append_static_asset_version($url);
        return is_string($versioned) && $versioned !== '' ? $versioned : null;
    }

    private function normalize_same_origin_asset_url($asset) {
        $asset = trim(rawurldecode($asset));
        if ($asset === '') {
            return null;
        }

        $home_host = (string) parse_url(home_url('/'), PHP_URL_HOST);
        $path = (string) parse_url($asset, PHP_URL_PATH);
        if ($path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $asset)) {
            $asset_host = (string) parse_url($asset, PHP_URL_HOST);
            if ($asset_host === '' || strcasecmp($asset_host, $home_host) !== 0) {
                return null;
            }
            return $asset;
        }

        if (!str_starts_with($asset, '/')) {
            return null;
        }

        return home_url($asset);
    }

    private function resolve_asset_proxy_file($asset) {
        $asset = rawurldecode(trim($asset));
        $path = (string) parse_url($asset, PHP_URL_PATH);
        if ($path === '') {
            return null;
        }

        $home_host = (string) parse_url(home_url('/'), PHP_URL_HOST);
        if (preg_match('#^https?://#i', $asset)) {
            $asset_host = (string) parse_url($asset, PHP_URL_HOST);
            if ($asset_host === '' || strcasecmp($asset_host, $home_host) !== 0) {
                return null;
            }
        }

        if (!preg_match('/\.(?:png|jpe?g|gif|webp|avif|svg|woff2?|ttf|eot|otf|ico)$/i', $path)) {
            return null;
        }

        $root = rtrim(dirname(ABSPATH), '/');
        $core = rtrim(ABSPATH, '/');
        $candidates = [];

        if (str_starts_with($path, '/assets/') || str_starts_with($path, '/core/')) {
            $candidates[] = $root . $path;
        }
        if (str_starts_with($path, '/wp-includes/') || str_starts_with($path, '/wp-admin/') || str_starts_with($path, '/wp-content/')) {
            $candidates[] = $core . $path;
        }
        $candidates[] = $root . $path;

        foreach (array_unique($candidates) as $candidate) {
            $real = realpath($candidate);
            if ($real && is_file($real) && (str_starts_with($real, $root . '/') || $real === $root)) {
                $mime = $this->get_asset_proxy_mime_type($real);
                return [
                    'file' => $real,
                    'mime' => $mime,
                ];
            }
        }

        return null;
    }

    private function get_asset_proxy_mime_type($file) {
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $map = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'mjs' => 'application/javascript',
            'json' => 'application/json',
            'map' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
        ];

        if (isset($map[$ext])) {
            return $map[$ext];
        }

        $ft = wp_check_filetype($file);
        if (!empty($ft['type'])) {
            return $ft['type'];
        }

        return 'application/octet-stream';
    }

    /**
     * Add long-lived Cache-Control headers to static assets when enabled.
     * @param array $headers
     * @return array
     */
    public function set_static_cache_headers($headers) {
        if (empty($this->settings['enable_static_asset_cache'])) { return $headers; }
        // Avoid altering login, admin ajax endpoints, REST
        if ($this->is_admin_auth_or_system_request()) { return $headers; }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($uri === '') { return $headers; }
        if (preg_match('#/(wp-admin/load-(?:styles|scripts)\.php|wp-login\.php)#i', $uri)) {
            return $headers;
        }
        $path = parse_url($uri, PHP_URL_PATH);
        if (!$path) { return $headers; }
        // Match common static file extensions (allow .gz/.br suffixes)
        if (!preg_match('/\.(?:png|jpe?g|gif|webp|avif|svg|css|js|mjs|woff2?|ttf|eot|otf|ico|json|map)(?:\.(?:gz|br))?$/i', $path)) {
            return $headers;
        }
        $ttl = isset($this->settings['static_asset_cache_ttl']) ? (int)$this->settings['static_asset_cache_ttl'] : 604800;
        if ($ttl < 86400) { $ttl = 86400; }
        if ($ttl > 31536000) { $ttl = 31536000; }
        $headers['Cache-Control'] = 'public, max-age=' . $ttl . ', immutable';
        $headers['Expires'] = gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT';
        return $headers;
    }

    public function version_attachment_url($url, $attachment_id) {
        if (empty($this->settings['enable_static_asset_cache']) || $this->is_admin_auth_or_system_request()) {
            return $url;
        }
        return $this->append_static_asset_version($url);
    }

    public function version_attachment_image_attributes($attr, $attachment, $size) {
        if (empty($this->settings['enable_static_asset_cache']) || !is_array($attr) || $this->is_admin_auth_or_system_request()) {
            return $attr;
        }

        if (!empty($attr['src'])) {
            $attr['src'] = $this->append_static_asset_version($attr['src']);
        }

        if (!empty($attr['srcset']) && is_string($attr['srcset'])) {
            $entries = array_map('trim', explode(',', $attr['srcset']));
            $rebuilt = [];
            foreach ($entries as $entry) {
                if ($entry === '') {
                    continue;
                }
                $parts = preg_split('/\s+/', $entry, 2);
                $candidate_url = $parts[0] ?? '';
                $descriptor = $parts[1] ?? '';
                $candidate_url = $this->append_static_asset_version($candidate_url);
                $rebuilt[] = $candidate_url . ($descriptor !== '' ? ' ' . $descriptor : '');
            }

            if (!empty($rebuilt)) {
                $attr['srcset'] = implode(', ', $rebuilt);
            }
        }

        return $attr;
    }

    public function version_attachment_image_src($image, $attachment_id, $size, $icon) {
        if (empty($this->settings['enable_static_asset_cache']) || !is_array($image) || empty($image[0]) || $this->is_admin_auth_or_system_request()) {
            return $image;
        }

        $image[0] = $this->append_static_asset_version($image[0]);
        return $image;
    }

    public function version_post_thumbnail_url($url, $post, $size) {
        if (empty($this->settings['enable_static_asset_cache']) || $this->is_admin_auth_or_system_request()) {
            return $url;
        }
        return $this->append_static_asset_version($url);
    }

    public function version_image_srcset_sources($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (empty($this->settings['enable_static_asset_cache']) || !is_array($sources) || $this->is_admin_auth_or_system_request()) {
            return $sources;
        }

        foreach ($sources as $width => $source) {
            if (empty($source['url']) || !is_string($source['url'])) {
                continue;
            }
            $sources[$width]['url'] = $this->append_static_asset_version($source['url']);
        }

        return $sources;
    }

    public function version_avatar_url($url, $id_or_email, $args) {
        if (empty($this->settings['enable_static_asset_cache']) || $this->is_admin_auth_or_system_request()) {
            return $url;
        }
        return $this->append_static_asset_version($url);
    }

    public function version_enqueued_asset_url($src, $handle) {
        if (empty($this->settings['enable_static_asset_cache']) || !is_string($src) || $src === '') {
            return $src;
        }

        // Never rewrite admin/auth/system asset URLs.
        if ($this->is_admin_auth_or_system_request()) {
            return $src;
        }

        return $this->append_static_asset_version($src);
    }

    private function append_static_asset_version($url) {
        if (!is_string($url) || $url === '' || str_starts_with($url, 'data:')) {
            return $url;
        }

        $resolved = $this->resolve_asset_proxy_file($url);
        if (!$resolved || empty($resolved['file']) || !is_file($resolved['file'])) {
            return $url;
        }

        $mtime = @filemtime($resolved['file']);
        if (!$mtime) {
            return $url;
        }

        $base = remove_query_arg('acev', $url);
        return add_query_arg('acev', (string) $mtime, $base);
    }

    /**
     * Prime critical options immediately after permalink structure changes
     */
    public function prime_critical_options_after_permalink_change() {
        $this->prime_critical_options_now();
    }

    /**
     * Prime critical options immediately after rewrite rules are flushed
     */
    public function prime_critical_options_after_rewrite_flush() {
        $this->prime_critical_options_now();
    }

    /**
     * Prime critical options immediately to Redis cache
     */
    private function prime_critical_options_now() {
        global $wp_object_cache;
        if ($wp_object_cache && method_exists($wp_object_cache, 'prime_set')) {
            $wp_object_cache->prime_set('rewrite_rules', get_option('rewrite_rules'), 'options');
            $wp_object_cache->prime_set('permalink_structure', get_option('permalink_structure'), 'options');
            $wp_object_cache->prime_set('alloptions', wp_load_alloptions(), 'options');
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Ace-Redis-Cache: Primed critical options after permalink/rewrite change');
            }
        }
    }

    /**
     * Guard canonical redirects to prevent users being sent to draft URLs
     * 
     * @param string $redirect_url The redirect URL
     * @param string $requested_url The requested URL 
     * @return string|false Modified redirect URL or false to prevent redirect
     */
    public function guard_canonical_redirect($redirect_url, $requested_url) {
        // If no redirect is happening, do nothing
        if (!$redirect_url || $redirect_url === $requested_url) {
            return $redirect_url;
        }

        // Check if redirect destination is a numeric ?p=ID URL (could be draft)
        $parsed_redirect = parse_url($redirect_url);
        if (isset($parsed_redirect['query'])) {
            parse_str($parsed_redirect['query'], $query_params);
            if (isset($query_params['p']) && is_numeric($query_params['p'])) {
                $post_id = intval($query_params['p']);
                $post = get_post($post_id);
                
                // If post exists but is not published, prevent redirect
                if ($post && $post->post_status !== 'publish') {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("Ace-Redis-Cache: Blocked canonical redirect to draft/private post ID {$post_id}");
                    }
                    return false;
                }
            }
        }

        // Check if redirect is from an old slug to current slug
        // If the requested URL contains a different slug than current, it might be outdated
        $current_url_parts = parse_url($requested_url);
        $redirect_url_parts = parse_url($redirect_url);
        
        if (isset($current_url_parts['path']) && isset($redirect_url_parts['path'])) {
            $current_path = trim($current_url_parts['path'], '/');
            $redirect_path = trim($redirect_url_parts['path'], '/');
            
            // If paths are different, this might be an old slug redirect
            if ($current_path !== $redirect_path) {
                // Try to find post by the requested path to check its status
                $post_id = url_to_postid($requested_url);
                if ($post_id > 0) {
                    $post = get_post($post_id);
                    if ($post && $post->post_status !== 'publish') {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log("Ace-Redis-Cache: Blocked canonical redirect from old slug to non-published post ID {$post_id}");
                        }
                        return false;
                    }
                }
            }
        }

        return $redirect_url;
    }

    /**
     * Track transient set operations when external object cache is active
     * This helps maintain invalidation tracking even when we're not intercepting transients directly
     * 
     * @param mixed $data The cached data
     * @param string $key Cache key
     * @param string $group Cache group
     * @param int $expire Expiration time
     * @param object $cache The cache object
     */
    public function track_transient_set_for_invalidation($data, $key, $group, $expire, $cache) {
        // Only track transient groups
        if (!in_array($group, ['transient', 'site-transient'])) {
            return;
        }

        // Store reference for potential future invalidation
        // This could be used to maintain a mapping of transients for targeted deletion
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Ace-Redis-Cache: Tracked transient set - key: {$key}, group: {$group}");
        }
        
        // Potential enhancement: maintain a registry of active transients
        // $registry = get_option('ace_redis_cache_transient_registry', []);
        // $registry["{$group}:{$key}"] = time();
        // update_option('ace_redis_cache_transient_registry', $registry, false);
    }

    /**
     * Track transient delete operations when external object cache is active
     * 
     * @param string $key Cache key
     * @param string $group Cache group
     */
    public function track_transient_delete_for_invalidation($key, $group) {
        // Only track transient groups
        if (!in_array($group, ['transient', 'site-transient'])) {
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Ace-Redis-Cache: Tracked transient delete - key: {$key}, group: {$group}");
        }
        
        // Potential enhancement: remove from registry
        // $registry = get_option('ace_redis_cache_transient_registry', []);
        // unset($registry["{$group}:{$key}"]);
        // update_option('ace_redis_cache_transient_registry', $registry, false);
    }

    /**
     * Check if current request is in an admin context that should skip expensive operations
     * 
     * @return bool True if in admin context that should skip expensive operations
     */
    private function is_admin_context() {
        // Always skip for admin pages, AJAX requests, REST API, and CLI
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)) {
            return true;
        }
        
        // Skip for logged-in users performing admin-like actions
        if (is_user_logged_in()) {
            // Skip for users with edit capabilities during content operations
            if (current_user_can('edit_posts') || current_user_can('edit_pages')) {
                return true;
            }
        }
        
        // Skip for specific admin-related query variables
        if (isset($_REQUEST['action']) || isset($_REQUEST['preview']) || isset($_REQUEST['customize_changeset_uuid'])) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if transient flush should be forced even in admin contexts
     * 
     * @return bool True if flush should be forced
     */
    private function should_force_transient_flush() {
        // Force flush when explicitly requested
        if (defined('ACE_FORCE_TRANSIENT_FLUSH') && ACE_FORCE_TRANSIENT_FLUSH) {
            return true;
        }
        
        // Force flush for specific high-impact scenarios
        $force_scenarios = [
            'publish',    // Publishing posts
            'future',     // Scheduling posts
            'trash',      // Trashing posts
            'delete'      // Deleting posts
        ];
        
        $current_action = $_REQUEST['action'] ?? '';
        if (in_array($current_action, $force_scenarios)) {
            return true;
        }
        
        // Allow filtering for custom scenarios
        return apply_filters('ace_rc_force_transient_flush', false, $_REQUEST);
    }

    /**
     * Lightweight alternative for transient cleanup in admin contexts
     * Targets only the most critical transient patterns without expensive SCAN operations
     * 
     * @param int $post_id Post ID
     * @param string $post_type Post type
     * @return int Number of keys deleted
     */
    private function lightweight_transient_cleanup($post_id, $post_type) {
        if (empty($this->settings['enable_transient_cache']) || !$this->cache_manager) return 0;
        
        $post_id = (int)$post_id;
        if ($post_id <= 0) return 0;
        
        // Only target the most critical, high-impact transient keys
        $critical_patterns = [
            'transient:post_link_' . $post_id,
            'transient:get_permalink_' . $post_id,
            'transient:wpseo_permalink_slug_' . $post_id,
            'transient:rank_math_permalink_' . $post_id,
        ];
        
        $critical_patterns = apply_filters('ace_rc_critical_transient_patterns', $critical_patterns, $post_id, $post_type);
        
        $deleted = 0;
        foreach ($critical_patterns as $key) {
            if ($this->cache_manager->delete_key($key)) {
                $deleted++;
            }
        }
        
        if ($deleted > 0 && defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('Ace-Redis-Cache: lightweight cleanup deleted %d critical transients for post_id=%d', $deleted, $post_id));
        }
        
        return $deleted;
    }
}
