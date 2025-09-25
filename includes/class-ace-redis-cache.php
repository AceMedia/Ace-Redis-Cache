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
        $loaded = get_option('ace_redis_cache_settings', [
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            // Back-compat defaults
            'ttl' => 3600,
            'mode' => 'full',
            'enabled' => 1,
            'enable_tls' => 0, // Changed default to 0 (disabled)
            'enable_block_caching' => 0,
            'enable_transient_cache' => 0,
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
            'excluded_blocks' => '',
            'exclude_basic_blocks' => 0,
            'include_rendered_block_hash' => 0,
            // New dual-cache settings
            'enable_page_cache' => 1,
            'enable_object_cache' => 0,
            'ttl_page' => 3600,
            'ttl_object' => 3600,
            // Unified dynamic runtime option (applies to excluded blocks)
            'dynamic_excluded_blocks' => 0,
            'enable_browser_cache_headers' => 0,
            'browser_cache_max_age' => 3600,
            'send_cache_meta_headers' => 0,
            'enable_dynamic_microcache' => 0,
            'dynamic_microcache_ttl' => 10,
            'enable_opcache_helpers' => 0,
            'enable_static_asset_cache' => 0,
            'static_asset_cache_ttl' => 604800,
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
            'enable_block_caching' => 0,
            'enable_transient_cache' => 0,
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
            'excluded_blocks' => '',
            'exclude_basic_blocks' => 0,
            'include_rendered_block_hash' => 0,
            // New dual-cache settings
            'enable_page_cache' => 1,
            'enable_object_cache' => 0,
            'ttl_page' => 3600,
            'ttl_object' => 3600,
            'dynamic_excluded_blocks' => 0,
            'enable_browser_cache_headers' => 0,
            'browser_cache_max_age' => 3600,
            'send_cache_meta_headers' => 0,
            'enable_dynamic_microcache' => 0,
            'dynamic_microcache_ttl' => 10,
            'enable_opcache_helpers' => 0,
        ];
        $this->settings = array_merge($defaults, $loaded);

        // Normalize microcache settings
        $this->dynamic_microcache_enabled = !empty($this->settings['enable_dynamic_microcache']);
        $ttl = (int)($this->settings['dynamic_microcache_ttl'] ?? 10);
        $this->dynamic_microcache_ttl = max(1, min(60, $ttl));
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

        // Register transient filters for all users when enabled and cache manager exists
        $this->register_transient_filters();
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
        // Pre-update filter (fires before DB write) to trace and preserve transient flag if it mysteriously drops
        add_filter('pre_update_option_ace_redis_cache_settings', [$this, 'pre_update_settings_trace'], 10, 2);
    // Late-stage tracker to detect other filters mutating value after ours
    add_filter('pre_update_option_ace_redis_cache_settings', [$this, 'pre_update_settings_trace_late'], 9999, 2);
        // Trace reads to detect external mutation (low priority to run after other filters)
        add_filter('option_ace_redis_cache_settings', [$this, 'trace_option_read'], 9999, 1);
            add_action('post_updated', [$this, 'on_post_updated'], 10, 3);
        
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
        // Static asset headers (apply for all requests including admin media loads)
        if (!empty($this->settings['enable_static_asset_cache'])) {
            add_filter('wp_headers', [$this, 'set_static_cache_headers']);
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
        // Full page cache (if enabled)
        if (!empty($this->settings['enable_page_cache'])) {
            $this->setup_full_page_cache();
        }

        // Object-level caching (transients + blocks) if enabled
        if (!empty($this->settings['enable_object_cache'])) {
            $this->setup_object_cache();
            // Setup block caching only when explicitly enabled
            if (!empty($this->settings['enable_block_caching']) && $this->block_caching) {
                $this->block_caching->setup_hooks();
            }
        }

        // Setup minification if enabled (the Minification class will avoid double-processing when page cache is active)
        if (!empty($this->settings['enable_minification']) && $this->minification) {
            $this->minification->setup_hooks();
        }

        // Setup exclusion filters for transients and cache operations
        $this->setup_exclusion_filters();

        // Initialize dynamic placeholders runtime (after settings loaded) only if page cache enabled
        if (!empty($this->settings['enable_page_cache'])) {
            $this->init_dynamic_placeholder_runtime();
            // Register per-post page cache invalidation hooks
            add_action('save_post', [$this, 'maybe_invalidate_post_page_cache'], 50, 3);
            add_action('deleted_post', [$this, 'maybe_invalidate_post_page_cache_deleted'], 50, 1);
            add_action('transition_post_status', [$this, 'maybe_invalidate_post_page_cache_status'], 50, 3);
            // Priming cron handler
            add_action('ace_rc_prime_post_cache', [$this, 'prime_post_cache'], 10, 1);
        }
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

        // Only intercept transients for guest frontend traffic
        // Avoid affecting wp-admin, logged-in sessions, AJAX, and REST requests
        if (\is_admin() || \is_user_logged_in() || \wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        // If a persistent object cache drop-in is present, WP core will handle
        // transients through wp_cache_* for all users. Avoid double-intercepting.
        if (wp_using_ext_object_cache()) {
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
        // Allow explicit bypass for benchmarking: ?ace_nocache=1
        if (isset($_GET['ace_nocache']) && $_GET['ace_nocache'] == '1') {
            if (!headers_sent()) { header('X-AceRedisCache: BYPASS param'); }
            return; // don't start buffering or touch cache
        }
        $cache_key = $this->generate_page_cache_key();
        
        // Try to get cached version (with minification handling)
        // Fetch raw (decompressed) so we can safely perform placeholder expansion when needed
        $cached_content = $this->cache_manager->get_with_minification($cache_key, false);
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
            exit;
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
            if ($host_mismatch && !$is_ip) {
                // If mismatch but not IP, we can attempt canonical rewrite before caching
                // Replace absolute URLs using the request host with the home host
                if ($home_host && $req_host) {
                    $content = str_replace('//' . $req_host . '/', '//' . $home_host . '/', $content);
                }
            }
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
            // Cache the content with intelligent minification handling
            if (!$skip_cache && !empty($content)) {
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
        $this->dynamic_placeholder_limit = (int) apply_filters('ace_rc_dynamic_placeholder_limit', $this->dynamic_placeholder_limit, $this->settings);
        $this->dynamic_placeholders_enabled = true; // always render mode
    }

    /**
     * Wrap allowlisted block output with deterministic markers and micro-cache its HTML.
     */
    public function filter_render_block_allowlist_dynamic($block_content, $block) {
        if (!$this->dynamic_placeholders_enabled) { return $block_content; }
        if ($this->dynamic_placeholder_stats['skipped_over_limit']) { return $block_content; }
        $name = $block['blockName'] ?? '';
        if (!$name) { return $block_content; }
        $matched = false;
        foreach ($this->dynamic_placeholder_patterns as $pat) {
            if (fnmatch($pat, $name)) { $matched = true; break; }
        }
        if (!$matched) { return $block_content; }
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
            'core/search','core/loginout'
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
        $key_parts = [
            'page_cache',
            $_SERVER['REQUEST_URI'] ?? '/',
            \is_ssl() ? 'https' : 'http',
            \wp_is_mobile() ? 'mobile' : 'desktop'
        ];
        
        return implode(':', $key_parts);
    }

    /**
     * Build a page cache core key (without redis prefix) for an arbitrary relative path.
     * Mirrors generate_page_cache_key logic but parameterized.
     * @param string $path Relative URI path beginning with '/'
     * @param string $scheme 'http'|'https'
     * @param string $device 'desktop'|'mobile'
     * @return string
     */
    private function build_page_cache_core_key($path, $scheme, $device) {
        return implode(':', ['page_cache', $path ?: '/', $scheme, $device]);
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
        $redis = null;
        if ($this->cache_manager && method_exists($this->cache_manager, 'get_raw_client')) {
            $redis = $this->cache_manager->get_raw_client();
        }
        $deleted = 0;
        foreach ($paths as $p) {
            foreach ($schemes as $scheme) {
                foreach ($devices as $device) {
                    $core = $this->build_page_cache_core_key($p, $scheme, $device);
                    // Redis stored keys: page_cache:<core> and page_cache_min:<core>
                    $raw_key = 'page_cache:' . $core;
                    $min_key = 'page_cache_min:' . $core;
                    // Legacy (unprefixed) key variant
                    $legacy_key = $core; // e.g. page_cache:/slug:https:desktop
                    try {
                        if ($redis) {
                            // Support both PhpRedis/Predis del(), fallback to unlink()
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
                            error_log('AceRedisCache post cache invalidation error: ' . $t->getMessage());
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
     */
    public function maybe_invalidate_post_page_cache($post_id, $post, $update) {
        if (wp_is_post_revision($post_id)) return;
        if (!$post || $post->post_status !== 'publish') return; // only published posts
        if (!is_post_type_viewable($post->post_type)) return;
        if ($this->should_defer_post_invalidation()) {
            $this->enqueue_deferred_post($post_id, ['page'=>true,'archives'=>true,'transients'=>true,'blocks_full'=>true]);
            return;
        }
        $this->invalidate_post_page_cache($post_id, true);
        // Also clear related transients if transient caching enabled (best-effort)
        $this->maybe_flush_post_transients($post_id, $post->post_type);
        // Invalidate archive/home/blog related page cache entries that may list this post
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
            $this->maybe_flush_post_transients($post_id, $post->post_type);
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
                if ($this->should_defer_post_invalidation()) {
                    $this->enqueue_deferred_post($post->ID, ['page'=>true,'archives'=>true,'transients'=>true,'blocks_full'=>true]);
                    return;
                }
                $this->invalidate_post_page_cache($post->ID, true);
                $this->maybe_flush_post_transients($post->ID, $post->post_type);
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
            $keys = $this->cache_manager->scan_keys('page_cache:*');
            $min_keys = $this->cache_manager->scan_keys('page_cache_min:*');
            $all = array_merge($keys ?: [], $min_keys ?: []);
            if (!empty($all)) {
                $this->cache_manager->delete_keys_chunked($all, 1000);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('AceRedisCache broad page cache purge after post update id=' . $post_id . ' deleted=' . count($all));
                }
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
        if ($this->should_defer_post_invalidation()) {
            $this->enqueue_deferred_post($post_ID, ['page'=>true,'archives'=>true,'transients'=>true,'blocks_full'=>true]);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Ace-Redis-Cache: deferred post update invalidation queued post_id=' . $post_ID);
            }
            return;
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('Ace-Redis-Cache: on_post_updated post_id=%d was_published=%s is_published=%s (aggressive flush mode)', $post_ID, $was_published?'yes':'no', $is_published?'yes':'no'));
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
    }

    /**
     * Flush transients that plausibly cache data for a specific post (title, permalink, meta) to prevent stale draft URLs / titles.
     * Strategy: pattern match common transient naming schemes and custom plugin prefixes, plus allow filters for site-specific patterns.
     * Uses SCAN for safety. Only runs if transient caching feature is on.
     */
    private function maybe_flush_post_transients($post_id, $post_type) {
        if (empty($this->settings['enable_transient_cache']) || !$this->cache_manager) return 0;
        $post_id = (int)$post_id; if ($post_id <= 0) return;
        // Allow developers to short-circuit or supply additional patterns
        $patterns = apply_filters('ace_rc_post_transient_patterns', [
            'transient:_transient_timeout_%d_*', // safety (WordPress internal timeouts) though we will convert to raw key forms
            'transient:%d:*',                    // generic id prefix
            'transient:post_%d_*',
            'transient:post-%d-*',
            'transient:wp_rest_cache_*_%d*',     // typical REST cache patterns
            'transient:acf_field_group_location_*_%d*',
            'transient:related_posts_%d*',
            'transient:seo_meta_%d*',
            'transient:query_%d_*',
            'transient:loop_%d_*',
        ], $post_id, $post_type, $this->settings);
        $redis = $this->cache_manager ? $this->cache_manager : null;
        if (!$redis) return 0;
        $conn = $this->cache_manager; // reuse manager scan API
        $to_delete = [];
        foreach ($patterns as $pat) {
            if (!is_string($pat) || strpos($pat, '%d') === false) continue;
            $redis_pattern = sprintf($pat, $post_id);
            // Normalize: our stored keys are raw like transient:NAME so wildcards should already align
            // Replace any accidental double colons
            $redis_pattern = str_replace('::', ':', $redis_pattern);
            $keys = $this->cache_manager->scan_keys(str_replace('*', '*', $redis_pattern));
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('Ace-Redis-Cache: scan pattern=%s found=%d', $redis_pattern, is_array($keys)?count($keys):0));
            }
            if (!empty($keys)) { $to_delete = array_merge($to_delete, $keys); }
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
        $prefix_sets = [ 'transient:', 'site_transient:' ];
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
    public function on_activation() {
        // Ensure option exists and is non-autoloaded to prevent notoptions poisoning
        $existing = get_option('ace_redis_cache_settings', null);
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
                'enable_block_caching' => 0,
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
                'excluded_blocks' => '',
                'exclude_basic_blocks' => 0,
                'enable_page_cache' => 1,
                'enable_object_cache' => 0,
                'ttl_page' => 3600,
                'ttl_object' => 3600,
                'dynamic_excluded_blocks' => 0,
                'enable_browser_cache_headers' => 0,
                'browser_cache_max_age' => 3600,
                'send_cache_meta_headers' => 0,
                'enable_dynamic_microcache' => 0,
                'dynamic_microcache_ttl' => 10,
                'enable_opcache_helpers' => 0,
            ];
            add_option('ace_redis_cache_settings', $defaults, '', 'no');
            if (function_exists('wp_cache_delete')) {
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
     * Handle settings updates
     */
    public function on_settings_updated($old_value, $new_value) {
        // Reload settings
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

        // Proactively clear any cached copy (defensive; our drop-in now bypasses but keep for other caches)
        if (function_exists('wp_cache_delete')) { @wp_cache_delete('ace_redis_cache_settings', 'options'); }
        
        // Reinitialize components with new settings
        $this->init_components();
        
        // Clear cache when settings change
        $this->cache_manager->clear_all_cache();

        // Manage object-cache.php drop-in based on setting
        try {
            $this->maybe_manage_dropin();
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Ace-Redis-Cache drop-in management error: ' . $e->getMessage());
            }
        }
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
            // Only backup if file exists and is NOT ours or differs (avoid piling up backups of identical file)
            if (file_exists($dropin_target) && (!$existing_is_ours || !$existing_same_hash)) {
                $backup = $dropin_target . '.bak.' . date('YmdHis');
                @rename($dropin_target, $backup);
            } else if ($existing_is_ours && $existing_same_hash) {
                // Nothing to do; already deployed and identical
                $need_copy = false;
            }
            if ($need_copy) {
                if (!@copy($dropin_source, $dropin_target)) {
                    $msg = 'Ace-Redis-Cache: Failed to deploy object-cache.php. Try manually:\n'
                        . 'cp ' . escapeshellarg($dropin_source) . ' ' . escapeshellarg($dropin_target);
                    if (defined('WP_DEBUG') && WP_DEBUG) { error_log($msg); }
                } else { $did_install_or_remove = true; }
            }
            // Cleanup: keep only the latest 2 backups created by our plugin pattern
            $pattern = $dropin_target . '.bak.';
            $dir = dirname($dropin_target);
            if (is_dir($dir)) {
                $files = @scandir($dir);
                if ($files) {
                    $bak = [];
                    foreach ($files as $f) {
                        if (strpos($f, 'object-cache.php.bak.') === 0 || str_starts_with($f, 'object-cache.php.bak.')) { // handles local naming
                            if ($f === 'object-cache.php.bak.' ) continue;
                        }
                        if (strpos($f, 'object-cache.php.bak.') === 0) {
                            $bak[] = $f;
                        }
                    }
                    if (count($bak) > 2) {
                        // Sort descending (latest first) by timestamp portion
                        usort($bak, function($a,$b){ return strcmp($b,$a); });
                        $to_delete = array_slice($bak, 2); // keep first two
                        foreach ($to_delete as $del) {
                            @unlink(trailingslashit($dir) . $del);
                        }
                    }
                }
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

    /**
     * Add long-lived Cache-Control headers to static assets when enabled.
     * @param array $headers
     * @return array
     */
    public function set_static_cache_headers($headers) {
        if (empty($this->settings['enable_static_asset_cache'])) { return $headers; }
        // Avoid altering login, admin ajax endpoints, REST
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_ajax()) { return $headers; }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($uri === '') { return $headers; }
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
}
