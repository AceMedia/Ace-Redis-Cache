<?php
/**
 * REST API Handler for Ace Redis Cache
 *
 * Provides REST API endpoints for plugin operations
 * as an alternative to admin-ajax.php
 *
 * @package AceMedia\RedisCache
 * @since 0.5.0
 *
 * NOTE: This is the canonical API handler implementation. A legacy file
 *       named class-api-handler.php (with a hyphen) previously existed and
 *       defined a simplified version of this class with a different
 *       constructor signature. That duplicate has been removed to prevent
 *       ambiguous autoload resolution and stale logic being executed. The
 *       autoloader maps API_Handler -> class-api_handler.php, so keeping
 *       this filename (underscore) preserves backward compatibility.
 */

namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) exit;

/**
 * REST API Handler Class
 */
class API_Handler {
    
    private $namespace = 'ace-redis-cache/v1';
    private $cache_manager;
    private $redis_connection;
    private $settings;
    private $saving_settings = false; // in-flight save guard
    private $last_saved_settings = null; // holds intended new settings during request
    private $after_update_option = false; // only begin overriding reads after update_option has executed
    
    /**
     * Constructor
     *
     * @param CacheManager $cache_manager Cache manager instance
     * @param RedisConnection $redis_connection Redis connection instance
     * @param array $settings Plugin settings
     */
    public function __construct($cache_manager, $redis_connection = null, $settings = null) {
        $this->cache_manager = $cache_manager;
        $this->redis_connection = $redis_connection;
        $this->settings = $settings;
        $this->init();
    }
    
    /**
     * Safely format uptime seconds as a human-readable string.
     * Examples: "45 seconds", "12 minutes", "3 hours", "2 days, 4 hours"
     *
     * @param mixed $seconds Uptime in seconds (may be numeric or null/'N/A')
     * @return string
     */
    private function format_uptime_safe($seconds) {
        if (!is_numeric($seconds)) {
            return '--';
        }
        $s = (int) $seconds;
        if ($s < 60) {
            return $s . 's';
        }
        if ($s < 3600) {
            $m = (int) floor($s / 60);
            return $m . 'm';
        }
        if ($s < 86400) {
            $h = (int) floor($s / 3600);
            $m = (int) floor(($s % 3600) / 60);
            return $h . 'h' . ($m > 0 ? ' ' . $m . 'm' : '');
        }
        $d = (int) floor($s / 86400);
        $h = (int) floor(($s % 86400) / 3600);
        return $d . 'd' . ($h > 0 ? ' ' . $h . 'h' : '');
    }

    /**
     * Initialize REST API endpoints
     */
    private function init() {
        add_action('rest_api_init', [$this, 'register_routes']);
    // Early option filter to provide freshly saved settings during the same request
    add_filter('option_ace_redis_cache_settings', [$this, 'override_option_during_save'], 1, 1);
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Save settings endpoint
        register_rest_route($this->namespace, '/settings', [
            'methods' => 'POST',
            'callback' => [$this, 'save_settings'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'settings' => [
                    'required' => true,
                    'type' => 'object',
                    'description' => 'Plugin settings to save'
                ],
                'nonce' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Security nonce'
                ]
            ]
        ]);
        
        // Test connection endpoint
        register_rest_route($this->namespace, '/test-connection', [
            'methods' => 'POST',
            'callback' => [$this, 'test_connection'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'nonce' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Security nonce'
                ]
            ]
        ]);
        
        // Test write/read endpoint
        register_rest_route($this->namespace, '/test-write-read', [
            'methods' => 'POST',
            'callback' => [$this, 'test_write_read'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'nonce' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Security nonce'
                ]
            ]
        ]);
        
        // Flush cache endpoint
        register_rest_route($this->namespace, '/flush-cache', [
            'methods' => 'POST',
            'callback' => [$this, 'flush_cache'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'type' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'all',
                    'enum' => ['all', 'blocks'],
                    'description' => 'Type of cache to flush'
                ],
                'nonce' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Security nonce'
                ]
            ]
        ]);
        
        // Get cache stats endpoint
        register_rest_route($this->namespace, '/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_cache_stats'],
            'permission_callback' => [$this, 'check_permissions']
        ]);
        
        // Run diagnostics endpoint
        register_rest_route($this->namespace, '/diagnostics', [
            'methods' => 'POST',
            'callback' => [$this, 'run_diagnostics'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'nonce' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Security nonce'
                ]
            ]
        ]);
        
        // Simple flush route (as requested in requirements)
        register_rest_route($this->namespace, '/flush', [
            'methods' => 'POST',
            'callback' => [$this, 'simple_flush_cache'],
            'permission_callback' => [$this, 'check_simple_permissions']
        ]);
        
        // Simple status route (as requested in requirements)
        register_rest_route($this->namespace, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_simple_status'],
            'permission_callback' => '__return_true'  // Temporary: make public for testing
        ]);
        
        // Simple metrics route for admin dashboard
        register_rest_route($this->namespace, '/metrics', [
            'methods' => 'GET',
            'callback' => [$this, 'get_simple_metrics'],
            'permission_callback' => [$this, 'check_simple_permissions']
        ]);

        // Plugin memory (on-demand heavy metric)
        register_rest_route($this->namespace, '/plugin-memory', [
            'methods' => 'GET',
            'callback' => [$this, 'get_plugin_memory_metrics'],
            'permission_callback' => [$this, 'check_simple_permissions']
        ]);

        // OPcache reset
        register_rest_route($this->namespace, '/opcache-reset', [
            'methods' => 'POST',
            'callback' => [$this, 'opcache_reset_route'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [ 'nonce' => [ 'required' => true, 'type' => 'string' ] ]
        ]);
        // OPcache prime
        register_rest_route($this->namespace, '/opcache-prime', [
            'methods' => 'POST',
            'callback' => [$this, 'opcache_prime_route'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [ 'nonce' => [ 'required' => true, 'type' => 'string' ] ]
        ]);
        // OPcache status (read-only)
        register_rest_route($this->namespace, '/opcache-status', [
            'methods' => 'GET',
            'callback' => [$this, 'opcache_status_route'],
            'permission_callback' => [$this, 'check_permissions']
        ]);

        // Cache health metrics (formerly separate tab) for transient tips UI
        register_rest_route($this->namespace, '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'health_route'],
            'permission_callback' => [$this, 'check_permissions']
        ]);
    }

    public function opcache_reset_route($request) {
        // Always fetch latest settings to avoid stale constructor snapshot
        $current = get_option('ace_redis_cache_settings', []);
        if (empty($current['enable_opcache_helpers'])) {
            return new \WP_REST_Response(['success' => false, 'message' => 'OPcache helpers disabled'], 400);
        }
        $ok = false;
        if (function_exists('opcache_reset')) {
            $ok = @opcache_reset();
        }
        return new \WP_REST_Response([
            'success' => (bool)$ok,
            'action' => 'reset',
            'message' => $ok ? 'OPcache reset executed' : 'OPcache reset unavailable'
        ], $ok ? 200 : 500);
    }

    public function opcache_prime_route($request) {
        $current = get_option('ace_redis_cache_settings', []);
        if (empty($current['enable_opcache_helpers'])) {
            return new \WP_REST_Response(['success' => false, 'message' => 'OPcache helpers disabled'], 400);
        }
        if (!function_exists('opcache_compile_file')) {
            return new \WP_REST_Response(['success' => false, 'message' => 'opcache_compile_file unavailable'], 500);
        }
        $files = apply_filters('ace_rc_opcache_prime_files', [ ABSPATH . 'index.php', get_stylesheet_directory() . '/functions.php', get_template_directory() . '/functions.php' ]);
        $compiled = [];
        foreach ($files as $f) {
            if (is_string($f) && file_exists($f)) {
                try { @opcache_compile_file($f); $compiled[] = basename($f); } catch (\Throwable $t) {}
            }
        }
        return new \WP_REST_Response([
            'success' => true,
            'action' => 'prime',
            'message' => 'OPcache prime attempted',
            'files' => $compiled
        ], 200);
    }

    public function opcache_status_route($request) {
        $data = [
            'enabled' => false,
            'can_reset' => function_exists('opcache_reset'),
            'can_compile' => function_exists('opcache_compile_file'),
            'memory_used' => null,
            'memory_free' => null,
            'memory_wasted' => null,
            'scripts' => null,
            'cached_scripts' => null,
            'hit_rate' => null,
        ];
        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            if (is_array($status)) {
                $data['enabled'] = !empty($status['opcache_enabled']);
                if (isset($status['memory_usage'])) {
                    $mu = $status['memory_usage'];
                    $data['memory_used'] = $mu['used_memory'] ?? null;
                    $data['memory_free'] = $mu['free_memory'] ?? null;
                    $data['memory_wasted'] = $mu['wasted_memory'] ?? null;
                }
                if (isset($status['scripts']) && is_array($status['scripts'])) {
                    $data['scripts'] = array_keys($status['scripts']);
                    $data['cached_scripts'] = count($status['scripts']);
                }
                if (isset($status['opcache_statistics']['opcache_hit_rate'])) {
                    $data['hit_rate'] = $status['opcache_statistics']['opcache_hit_rate'];
                }
            }
        }
        return new \WP_REST_Response(['success' => true, 'data' => $data], 200);
    }

    public function health_route($request) {
        global $wp_object_cache, $wpdb;
        $using_dropin = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        $dropin_connected = false; $conn_details = null; $via = null; $error = null; $bypass = false; $runtime_bypass = false; $profiling = (defined('ACE_OC_PROF') && ACE_OC_PROF);
        if ($using_dropin && isset($wp_object_cache) && is_object($wp_object_cache)) {
            if (method_exists($wp_object_cache,'is_connected')) { $dropin_connected = (bool)$wp_object_cache->is_connected(); }
            if (method_exists($wp_object_cache,'connection_details')) { $conn_details = $wp_object_cache->connection_details(); $via = $conn_details['via'] ?? null; $error = $conn_details['error'] ?? null; }
            if (method_exists($wp_object_cache,'is_bypassed')) { $runtime_bypass = $wp_object_cache->is_bypassed(); }
        }
        $const_bypass = defined('ACE_OC_BYPASS') && ACE_OC_BYPASS; $bypass = $const_bypass || $runtime_bypass;
        $slow_ops = get_transient('ace_rc_slow_op_count'); $slow_ops = $slow_ops!==false ? (int)$slow_ops : 0;
        $autoload_size = 0; if (isset($wpdb)) { $row = $wpdb->get_row("SELECT SUM(LENGTH(option_value)) AS sz FROM {$wpdb->options} WHERE autoload='yes'"); if ($row && isset($row->sz)) { $autoload_size = (int)$row->sz; } }
        $tips = [];
        if (!$using_dropin) {
            $tips[] = 'Object cache drop-in missing. Enable Transient Cache to deploy or manually copy assets/plugins/Ace-Redis-Cache/assets/dropins/object-cache.php to wp-content/object-cache.php';
        } elseif (!$dropin_connected) {
            $tips[] = 'Drop-in present but not connected. Check Redis host/port and credentials.';
        }
        if ($bypass) { $tips[] = 'Cache currently bypassed (fail-open). Investigate connection issues or ACE_OC_BYPASS constant.'; }
        if ($profiling && $slow_ops>0) { $tips[] = 'Detected ' . $slow_ops . ' slow ops (>=100ms). Consider inspecting heavy queries or raising threshold.'; }
        if ($autoload_size > 50*1024*1024) { $tips[] = 'Autoloaded options exceed 50MB (' . size_format($autoload_size) . '). This can hurt performance.'; }
        // Provide WP_CACHE guidance
        if (!defined('WP_CACHE') || !WP_CACHE) { $tips[] = 'WP_CACHE is not enabled. Add define(\'WP_CACHE\', true); to wp-config.php for full object cache effectiveness.'; }
        // Unique tips only
        $tips = array_values(array_unique($tips));
        return new \WP_REST_Response([
            'success' => true,
            'data' => [
                'using_dropin' => $using_dropin,
                'dropin_connected' => $dropin_connected,
                'bypass' => $bypass,
                'runtime_bypass' => $runtime_bypass,
                'via' => $via,
                'error' => $error,
                'slow_ops' => $slow_ops,
                'profiling' => $profiling,
                'autoload_size' => $autoload_size,
                'tips' => $tips
            ]
        ], 200);
    }
    
    /**
     * Check user permissions for API access
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function check_permissions($request) {
        // Check if user can manage options
        if (!current_user_can('manage_options')) {
            return false;
        }
        
        // For POST requests, verify nonce
        if ($request->get_method() === 'POST') {
            $nonce = $request->get_param('nonce');
            if (!wp_verify_nonce($nonce, 'ace_redis_admin_nonce')) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Simple permission check without nonce requirement
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function check_simple_permissions($request) {
        // Only check if user can manage options (no nonce required)
        return current_user_can('manage_options');
    }
    
    /**
     * Save plugin settings
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function save_settings($request) {
        try {
            // Clear potential negative cache (poison map) before any reads this request
            if (function_exists('wp_cache_delete')) { @wp_cache_delete('notoptions', 'options'); }
            $settings = $request->get_param('settings');
            // Optional flags outside settings for auxiliary actions
            $reset_slow = $request->get_param('reset_slow_ops');
            if ($reset_slow) { delete_transient('ace_rc_slow_op_count'); }
            // Managed plugins payload (comes in via __managed_plugins)
            if (isset($settings['__managed_plugins']) && is_array($settings['__managed_plugins'])) {
                $mp_store = ['plugins' => []];
                foreach ($settings['__managed_plugins'] as $file => $meta) {
                    $file = sanitize_text_field($file);
                    $mp_store['plugins'][$file] = [ 'enabled_on_init' => !empty($meta['enabled_on_init']) ? 1 : 0 ];
                }
                update_option(Plugin_Manager::OPTION, $mp_store, 'no');
                unset($settings['__managed_plugins']);
            }
            
            // Sanitize settings (use built-in sanitization logic)
            $sanitized_settings = $this->sanitize_settings($settings);
            $raw_transient_in = isset($settings['enable_transient_cache']);
            $raw_transient_val = !empty($settings['enable_transient_cache']);
            // If transient cache explicitly requested on, ensure we don't silently drop it
            if ($raw_transient_in && $raw_transient_val) {
                $sanitized_settings['enable_transient_cache'] = 1;
                // auto-enable object cache + dropin if not foreign
                if (empty($sanitized_settings['enable_object_cache'])) {
                    $sanitized_settings['enable_object_cache'] = 1;
                }
                if (empty($sanitized_settings['enable_object_cache_dropin'])) {
                    $sanitized_settings['enable_object_cache_dropin'] = 1; // will be validated by dropin manager later
                }
            }
            
            // Fetch current settings to detect no-op saves & diff
            // Mark in-flight save so any get_option during this request reflects new intent
            $this->saving_settings = true;
            $old_settings = get_option('ace_redis_cache_settings', []);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $old_flag_dbg = is_array($old_settings) ? ($old_settings['enable_transient_cache'] ?? 'MISS') : 'NA';
                $new_flag_dbg = $sanitized_settings['enable_transient_cache'] ?? 'MISS';
                $diff_keys = [];
                foreach ($sanitized_settings as $k => $v) {
                    $ov = $old_settings[$k] ?? null;
                    if ($ov !== $v) { $diff_keys[] = $k; }
                }
                error_log('Ace-Redis-Cache: pre-update diff transient_old=' . $old_flag_dbg . ' transient_new=' . $new_flag_dbg . ' diff_keys=' . implode(',', $diff_keys));
            }

            // Primary update attempt
            // IMPORTANT: Do NOT set last_saved_settings before update_option runs, or our override filter
            // will cause core to think nothing changed (old == new) and skip the actual DB write.
            $result = update_option('ace_redis_cache_settings', $sanitized_settings);
            // Purge related option caches after primary write (whether core performed a DB write or not)
            $this->purge_option_caches();
            // Now enable override for subsequent stale reads within this same request
            $this->last_saved_settings = $sanitized_settings;
            $this->after_update_option = true;

            // Immediately sync in-memory autoload snapshot to avoid stale read when enabling transient cache alone
            // NOTE: Do NOT repopulate $alloptions manually; let the next request rebuild it (per requirements)

            // Unconditional verification (even if $result is false)
            $stored = get_option('ace_redis_cache_settings', []);
            $raw_db = $this->raw_db_get_option('ace_redis_cache_settings');
            $stored_flag = is_array($stored) ? ($stored['enable_transient_cache'] ?? 'MISS') : 'NA';
            $raw_flag = is_array($raw_db) ? ($raw_db['enable_transient_cache'] ?? 'MISS') : 'NA';
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Ace-Redis-Cache: post-update verify result=' . var_export($result, true) . ' wanted=' . ($sanitized_settings['enable_transient_cache'] ?? 'NA') . ' stored=' . $stored_flag . ' raw=' . $raw_flag);
            }

            // If wanted 1 but stored/raw inconsistent, drive multiple corrective strategies
            if (($sanitized_settings['enable_transient_cache'] ?? 0) == 1 && (int)$stored_flag !== 1) {
                // Step 1: direct SQL update (in-place)
                $forced_sql = $this->force_update_option_row('ace_redis_cache_settings', $sanitized_settings);
                $this->purge_option_caches(); // After direct SQL path
                $stored2 = get_option('ace_redis_cache_settings', []);
                $stored2_flag = is_array($stored2) ? ($stored2['enable_transient_cache'] ?? 'MISS') : 'NA';
                $raw2 = $this->raw_db_get_option('ace_redis_cache_settings');
                $raw2_flag = is_array($raw2) ? ($raw2['enable_transient_cache'] ?? 'MISS') : 'NA';
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Ace-Redis-Cache: forced-sql transient stored=' . $stored2_flag . ' raw=' . $raw2_flag . ' forced_result=' . var_export($forced_sql, true));
                }
                // Step 2: if raw=1 but stored still 0, forcibly delete option and re-add (WordPress core path) to rebuild caches

    // (override_option_during_save moved to end of class)
                if ((int)$raw2_flag === 1 && (int)$stored2_flag !== 1) {
                    if (defined('WP_DEBUG') && WP_DEBUG) error_log('Ace-Redis-Cache: rewrite fallback delete+add because stored mismatch despite raw=1');
                    delete_option('ace_redis_cache_settings');
                    // Re-add explicitly non-autoloaded (autoload = 'no') per new policy
                    add_option('ace_redis_cache_settings', $raw2, '', 'no');
                    $this->purge_option_caches(); // After delete+add path
                    $stored3 = get_option('ace_redis_cache_settings', []);
                    $stored3_flag = is_array($stored3) ? ($stored3['enable_transient_cache'] ?? 'MISS') : 'NA';
                    if (defined('WP_DEBUG') && WP_DEBUG) error_log('Ace-Redis-Cache: rewrite fallback post-add stored=' . $stored3_flag);
                    // Step 2a: If still stale inside this request due to autoloaded $alloptions snapshot, update it manually
                    if ((int)$stored3_flag !== 1 && (int)$raw2_flag === 1) {
                        global $alloptions;
                        if (is_array($alloptions) && isset($alloptions['ace_redis_cache_settings'])) {
                            $alloptions['ace_redis_cache_settings'] = $raw2; // force in-memory sync
                            if (defined('WP_DEBUG') && WP_DEBUG) error_log('Ace-Redis-Cache: manually refreshed $alloptions cache for ace_redis_cache_settings');
                            // Re-read
                            $stored3b = get_option('ace_redis_cache_settings', []);
                            $stored3b_flag = is_array($stored3b) ? ($stored3b['enable_transient_cache'] ?? 'MISS') : 'NA';
                            if (defined('WP_DEBUG') && WP_DEBUG) error_log('Ace-Redis-Cache: post manual refresh stored=' . $stored3b_flag);
                        }
                    }
                }
                // Step 3: final attempt – if still not 1, override return payload but mark warning (so UI reflects true desired state even if get_option stale inside this request)
                $final_check = get_option('ace_redis_cache_settings', []);
                $final_flag_check = is_array($final_check) ? ($final_check['enable_transient_cache'] ?? 0) : 0;
                if ((int)$final_flag_check !== 1) {
                    if (defined('WP_DEBUG') && WP_DEBUG) error_log('Ace-Redis-Cache: final mismatch after all corrections – reporting soft success with warning');
                    $response_data = [
                        'message' => 'Saved with warning: transient flag may appear off until next request.',
                        'settings_changed' => true,
                        'settings' => array_merge($sanitized_settings, ['enable_transient_cache' => 1]),
                        'stored_flag' => $final_flag_check,
                        'raw_flag' => $raw2_flag,
                        'warning' => 'TRANSIENT_FLAG_STALE_READ'
                    ];
                    return new \WP_REST_Response([
                        'success' => true,
                        'data' => $response_data,
                    ], 200);
                }
                $this->saving_settings = false;
                $this->last_saved_settings = null;
                $this->after_update_option = false;
            }

            // Determine final response message
            $this->saving_settings = false;
            $this->last_saved_settings = null;
            $this->after_update_option = false;
            // Force option to be non-autoloaded; cache purges handled inside when change occurs
            $this->ensure_settings_not_autoloaded();
            // Always purge key + meta caches after save to ensure fresh read next request
            $this->purge_option_caches();
            $final_stored = get_option('ace_redis_cache_settings', []);
            $final_flag = is_array($final_stored) ? ($final_stored['enable_transient_cache'] ?? 'MISS') : 'NA';
            $changed = $old_settings != $final_stored;
            $msg = $changed ? 'Settings saved.' : 'No changes to save (or unchanged after write).';
            $response_data = [
                'message' => $msg,
                'settings_changed' => $changed,
                'settings' => $sanitized_settings,
                'transient_final' => $final_flag,
                'update_result' => $result,
            ];
            return new \WP_REST_Response([
                'success' => true,
                'data' => $response_data,
            ], 200);
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Error saving settings: ' . $e->getMessage(),
                'error' => 'EXCEPTION'
            ], 500);
        }
    }

    /**
     * Ensure the settings option is not autoloaded (autoload = 'no') to avoid
     * stale alloptions snapshots causing single-flag toggles to appear reverted.
     */
    private function ensure_settings_not_autoloaded() {
        global $wpdb;
        if (!is_object($wpdb)) return;
        $table = $wpdb->options;
        // Check current autoload status
        $row = $wpdb->get_row( $wpdb->prepare("SELECT autoload FROM {$table} WHERE option_name = %s LIMIT 1", 'ace_redis_cache_settings') );
        $before = $row ? $row->autoload : 'NA';
        if ($before !== 'no') {
            // Update autoload to no for any unexpected value (yes/on/true/empty)
            $wpdb->query( $wpdb->prepare("UPDATE {$table} SET autoload='no' WHERE option_name=%s", 'ace_redis_cache_settings') );
            if (function_exists('wp_cache_delete')) {
                @wp_cache_delete('alloptions', 'options');
                @wp_cache_delete('ace_redis_cache_settings', 'options');
                @wp_cache_delete('notoptions', 'options');
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Ace-Redis-Cache: normalised autoload (was ' . $before . ') -> no for ace_redis_cache_settings');
            }
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Ace-Redis-Cache: autoload already no for ace_redis_cache_settings');
        }
    }

    /**
     * During a save request, override stale in-memory option reads with the just-sanitized value
     * to avoid false mismatch logic and two-step enable behavior.
     */
    public function override_option_during_save($value) {
        if ($this->saving_settings && $this->after_update_option && is_array($this->last_saved_settings)) {
            if (!empty($this->last_saved_settings['enable_transient_cache'])) {
                if (defined('WP_DEBUG') && WP_DEBUG && (!is_array($value) || empty($value['enable_transient_cache']))) {
                    error_log('Ace-Redis-Cache: override_option_during_save supplying in-flight transient=1');
                }
                return $this->last_saved_settings;
            }
        }
        return $value;
    }

    /**
     * Raw DB fetch of an option (bypasses object cache) for debugging stale cache scenarios
     *
     * @param string $option
     * @return mixed|null
     */
    private function raw_db_get_option($option) {
        global $wpdb;
        if (!is_object($wpdb)) return null;
        $table = $wpdb->options;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared - option name is controlled
        $row = $wpdb->get_row( $wpdb->prepare("SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", $option) );
        if (!$row) return null;
        return maybe_unserialize($row->option_value);
    }

    /**
     * Direct SQL update of option row (bypasses update_option logic & object cache) for stubborn mismatch.
     */
    private function force_update_option_row($option, $value) {
        global $wpdb;
        if (!is_object($wpdb)) return false;
        $table = $wpdb->options;
        $serialized = maybe_serialize($value);
        $result = $wpdb->update($table, ['option_value' => $serialized], ['option_name' => $option]);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Ace-Redis-Cache: force_update_option_row update result=' . var_export($result, true));
        }
        // Bust object cache if present
        if (function_exists('wp_cache_delete')) { @wp_cache_delete($option, 'options'); }
        return $result !== false;
    }
    
    /**
     * Test Redis connection
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function test_connection($request) {
        try {
            $start_time = microtime(true);
            $connection = $this->cache_manager->get_redis_connection();
            $result = $connection->get_status();
            $response_time = round((microtime(true) - $start_time) * 1000, 2);
            
            // Add response time to the result
            if (is_array($result)) {
                $result['response_time'] = $response_time . 'ms';
                // Fallback: some providers (e.g., serverless Redis) restrict INFO/MEMORY and report 0 KB
                if (!empty($result['connected'])) {
                    $mem_unknown = empty($result['memory_usage']) || $result['memory_usage'] === 'N/A';
                    $kb_zero = !isset($result['size_kb']) || (is_numeric($result['size_kb']) && (float)$result['size_kb'] == 0.0);
                    if ($mem_unknown || $kb_zero) {
                        try {
                            $stats = $this->cache_manager->get_cache_stats();
                            if (!empty($stats['memory_usage'])) {
                                $plugin_kb = round($stats['memory_usage'] / 1024, 2);
                                // Only override if we have a meaningful non-zero estimate
                                if ($plugin_kb > 0) {
                                    $result['size_kb'] = $plugin_kb;
                                    // Preserve original memory_usage string if present; append hint
                                    $hint = 'plugin memory estimate';
                                    if (!empty($result['debug_info'])) {
                                        $result['debug_info'] .= ' | ' . $hint;
                                    } else {
                                        $result['debug_info'] = $hint;
                                    }
                                } else {
                                    // If still zero, at least tell the UI why
                                    $hint = 'memory metrics restricted';
                                    if (!empty($result['debug_info'])) {
                                        $result['debug_info'] .= ' | ' . $hint;
                                    } else {
                                        $result['debug_info'] = $hint;
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            // Ignore, leave as-is
                        }
                    }
                }
            } else {
                $result = [
                    'original_result' => $result,
                    'response_time' => $response_time . 'ms'
                ];
            }
            
            return new \WP_REST_Response([
                'success' => true,
                'data' => $result
            ], 200);
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
                'error' => 'CONNECTION_FAILED'
            ], 500);
        }
    }
    
    /**
     * Test Redis write/read operations
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function test_write_read($request) {
        try {
            // Always fetch fresh settings (avoid stale constructor snapshot)
            $settings_now = get_option('ace_redis_cache_settings', []);
            // Guard when cache is disabled or manager unavailable
            if (empty($settings_now['enabled']) || !$this->cache_manager) {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'Cache is disabled',
                    'error' => 'CACHE_DISABLED'
                ], 400);
            }
            $connection = $this->cache_manager->get_redis_connection();
            $result = $connection->test_operations();
            
            return new \WP_REST_Response([
                'success' => true,
                'data' => $result
            ], 200);
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Write/read test failed: ' . $e->getMessage(),
                'error' => 'WRITE_READ_FAILED'
            ], 500);
        }
    }
    
    /**
     * Flush cache
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function flush_cache($request) {
        try {
            $settings_now = get_option('ace_redis_cache_settings', []);
            // Guard when cache is disabled or manager unavailable
            if (empty($settings_now['enabled']) || !$this->cache_manager) {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'Cache is disabled, nothing to flush',
                    'error' => 'CACHE_DISABLED'
                ], 400);
            }
            $type = $request->get_param('type');
            
            if ($type === 'blocks') {
                $result = $this->cache_manager->clear_block_cache();
                $message = 'Block cache flushed successfully.';
            } else {
                $result = $this->cache_manager->clear_all_cache();
                $message = 'All cache flushed successfully.';
            }
            
            if ($result) {
                // Get updated stats
                $stats = $this->cache_manager->get_cache_stats();
                
                return new \WP_REST_Response([
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'cache_size' => $stats['memory_usage_human'] ?? 'Unknown',
                        'key_count' => $stats['cache_keys'] ?? 0
                    ]
                ], 200);
            } else {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'Failed to flush cache.',
                    'error' => 'FLUSH_FAILED'
                ], 500);
            }
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Cache flush failed: ' . $e->getMessage(),
                'error' => 'EXCEPTION'
            ], 500);
        }
    }
    
    /**
     * Get cache statistics
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_cache_stats($request) {
        try {
            $settings_now = get_option('ace_redis_cache_settings', []);
            // Guard when cache is disabled or manager unavailable
            if (empty($settings_now['enabled']) || !$this->cache_manager) {
                return new \WP_REST_Response([
                    'success' => true,
                    'data' => [
                        'total_keys' => 0,
                        'cache_keys' => 0,
                        'memory_usage' => 0,
                        'memory_usage_human' => '0 B',
                        'note' => 'Cache disabled'
                    ]
                ], 200);
            }
            $stats = $this->cache_manager->get_cache_stats();
            
            return new \WP_REST_Response([
                'success' => true,
                'data' => $stats
            ], 200);
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Failed to get cache stats: ' . $e->getMessage(),
                'error' => 'STATS_FAILED'
            ], 500);
        }
    }
    
    /**
     * Run system diagnostics
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function run_diagnostics($request) {
        try {
            $settings_now = get_option('ace_redis_cache_settings', []);
            $diagnostics = new Diagnostics($this->redis_connection, $this->cache_manager, $settings_now);
            $result = $diagnostics->get_full_diagnostics();
            
            return new \WP_REST_Response([
                'success' => true,
                'data' => $result
            ], 200);
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Diagnostics failed: ' . $e->getMessage(),
                'error' => 'DIAGNOSTICS_FAILED'
            ], 500);
        }
    }
    
    /**
     * Simple flush cache endpoint handler (as requested in requirements)
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function simple_flush_cache($request) {
        try {
            // Check if cache is enabled first
            $settings = get_option('ace_redis_cache_settings', []);
            if (empty($settings['enabled'])) {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'Cache is disabled, nothing to flush',
                    'timestamp' => current_time('mysql')
                ], 400);
            }
            
            // Clear all cache using the cache manager
            $result = $this->cache_manager->clear_all_cache();
            
            return new \WP_REST_Response([
                'success' => true,
                'message' => 'Cache flushed successfully',
                'timestamp' => current_time('mysql')
            ], 200);
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Failed to flush cache: ' . $e->getMessage(),
                'error' => 'CACHE_FLUSH_FAILED'
            ], 500);
        }
    }
    
    /**
     * Simple status endpoint handler (as requested in requirements)
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_simple_status($request) {
        try {
            // Check if cache is enabled first
            $settings = get_option('ace_redis_cache_settings', []);
            if (empty($settings['enabled'])) {
                return new \WP_REST_Response([
                    'success' => true,
                    'redis_connected' => false,
                    'cache_entries' => 0,
                    'message' => 'Cache is disabled',
                    'timestamp' => current_time('mysql')
                ], 200);
            }
            
            // Get Redis connection status
            $redis_connection = $this->cache_manager->get_redis_connection();
            $connection_status = $redis_connection->get_status();
            
            // Get cache statistics
            $cache_stats = $this->cache_manager->get_cache_stats();
            
            return new \WP_REST_Response([
                'success' => true,
                'redis_connected' => $connection_status['connected'],
                'cache_entries' => $cache_stats['total_keys'] ?? 0,
                'timestamp' => current_time('mysql')
            ], 200);
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Failed to get status: ' . $e->getMessage(),
                'error' => 'STATUS_CHECK_FAILED'
            ], 500);
        }
    }
    
    /**
     * Simple metrics endpoint handler for admin dashboard
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_simple_metrics($request) {
        try {
            // Get basic metrics without heavy operations
            $metrics = [
                'cache_hit_rate' => '--',
                'total_keys' => 0,
                'memory_usage' => '--',
                'uptime' => '--',
                'connected_clients' => '--',
                'ops_per_sec' => '--',
                'response_time' => '--',
                'cache_enabled' => true,
                // Plugin-specific counts (filled when CacheManager available)
                'plugin_total_keys' => 0,
                'plugin_page_keys' => 0,
                'plugin_block_keys' => 0
            ];
            
            // Optional lightweight mode to avoid heavy scans during auto-refresh
            $light = false;
            $scope = $request->get_param('scope');
            if ($scope && is_string($scope)) {
                $light = strtolower($scope) === 'basic' || strtolower($scope) === 'light';
            } else {
                $light = filter_var($request->get_param('light'), FILTER_VALIDATE_BOOLEAN);
            }
            
            // Check if cache is enabled before attempting any connections
            $settings = get_option('ace_redis_cache_settings', []);
            if (empty($settings['enabled'])) {
                $metrics['cache_enabled'] = false;
                return new \WP_REST_Response([
                    'success' => true,
                    'data' => $metrics,
                    'message' => 'Cache is disabled',
                    'timestamp' => current_time('mysql')
                ], 200);
            }
            
            // Try to get metrics if cache manager is available and cache is enabled
            if ($this->cache_manager) {
                try {
                    $connection = $this->cache_manager->get_redis_connection();
                    if ($connection) {
                        $redis_instance = $connection->get_connection();
                        if ($redis_instance) {
                            // Measure ping latency for response_time without heavy INFO calls
                            $start_time = microtime(true);
                            try { $redis_instance->ping(); } catch (\Exception $e) {}
                            $response_time = round((microtime(true) - $start_time) * 1000, 2);

                            // Use robust status helper that handles restricted providers
                            $status = $connection->get_status();

                            // Cache hit rate: attempt from INFO stats; if unavailable, leave '--'
                            $hits = 0; $misses = 0; $total_keys = 0; $ops = '--';
                            try {
                                $info_stats = $redis_instance->info('stats');
                                if (is_array($info_stats)) {
                                    $hits = (int)($info_stats['keyspace_hits'] ?? 0);
                                    $misses = (int)($info_stats['keyspace_misses'] ?? 0);
                                    $ops = $info_stats['instantaneous_ops_per_sec'] ?? '--';
                                }
                                $keyspace = $redis_instance->info('keyspace');
                                if (is_array($keyspace)) {
                                    foreach ($keyspace as $k => $v) {
                                        if (strpos((string)$k, 'db') === 0) {
                                            if (is_array($v) && isset($v['keys'])) { $total_keys += (int)$v['keys']; }
                                            elseif (is_string($v) && preg_match('/keys=(\d+)/', $v, $m)) { $total_keys += (int)$m[1]; }
                                        }
                                    }
                                }
                            } catch (\Exception $e) {}

                            $den = $hits + $misses;
                            $hit_rate = $den > 0 ? round(($hits / $den) * 100, 1) . '%' : '--';

                            $metrics['cache_hit_rate'] = $hit_rate;
                            $metrics['total_keys'] = $total_keys;
                            $metrics['memory_usage'] = $status['memory_usage'] ?? ($status['used_memory_human'] ?? '--');
                            // Convert uptime seconds to human-readable format
                            $metrics['uptime'] = $this->format_uptime_safe($status['uptime'] ?? null);
                            $metrics['connected_clients'] = $status['connected_clients'] ?? '--';
                            $metrics['ops_per_sec'] = $ops;
                            $metrics['response_time'] = $response_time . 'ms';

                            // Heavy plugin metrics only when not in lightweight mode
                            if (!$light) {
                                try {
                                    $page_keys = 0;
                                    $block_keys = 0;
                                    $page_keys += count($this->cache_manager->scan_keys('page_cache:*'));
                                    $page_keys += count($this->cache_manager->scan_keys('page_cache_min:*'));
                                    $block_keys = count($this->cache_manager->scan_keys('block_cache:*'));

                                    $plugin = [
                                        'plugin_page_keys' => $page_keys,
                                        'plugin_block_keys' => $block_keys,
                                        'plugin_total_keys' => $page_keys + $block_keys,
                                    ];

                                    $mem = $this->cache_manager->get_memory_usage_breakdown();
                                    $plugin['plugin_memory_total'] = $mem['total_human'];
                                    $plugin['plugin_memory_page'] = $mem['page_human'];
                                    $plugin['plugin_memory_minified'] = $mem['minified_human'];
                                    $plugin['plugin_memory_blocks'] = $mem['blocks_human'];
                                    $plugin['plugin_memory_transients'] = $mem['transients_human'];

                                    $metrics = array_merge($metrics, $plugin);
                                } catch (\Exception $e) {
                                    // Leave defaults if scan fails
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Log error but continue with default metrics
                }
            } else {
                // Try to create a temporary Redis connection directly only if cache is enabled
                if (!empty($settings['enabled'])) {
                    try {
                        if (!empty($settings['host']) && !empty($settings['port'])) {
                            $redis = new \Redis();
                            $connected = $redis->connect($settings['host'], $settings['port'], 2); // 2 second timeout
                            
                            if ($connected && !empty($settings['password'])) {
                                $auth_result = $redis->auth($settings['password']);
                            }
                            
                            if ($connected) {
                                $start_time = microtime(true);
                                $info = $redis->info();
                                $keyspace_info = $redis->info('keyspace');
                                $stats_info = $redis->info('stats');
                                $clients_info = $redis->info('clients');
                                $memory_info = $redis->info('memory');
                                $server_info = $redis->info('server');
                                $response_time = round((microtime(true) - $start_time) * 1000, 2);
                                
                                if ($info) {
                                    $stats = $info['Stats'] ?? $info['stats'] ?? $stats_info ?? [];
                                    $memory = $info['Memory'] ?? $info['memory'] ?? $memory_info ?? [];
                                    $clients = $info['Clients'] ?? $info['clients'] ?? $clients_info ?? [];
                                    $server = $info['Server'] ?? $info['server'] ?? $server_info ?? [];
                                    
                                    $hits = (int) ($stats['keyspace_hits'] ?? ($info['keyspace_hits'] ?? 0));
                                    $misses = (int) ($stats['keyspace_misses'] ?? ($info['keyspace_misses'] ?? 0));
                                    $den = $hits + $misses;
                                    $hit_rate = $den > 0 ? round(($hits / $den) * 100, 1) . '%' : '--';
                                    
                                    $total_keys = 0;
                                    if ($keyspace_info) {
                                        foreach ($keyspace_info as $key => $value) {
                                            if (strpos((string)$key, 'db') === 0) {
                                                if (is_array($value) && isset($value['keys'])) {
                                                    $total_keys += (int) $value['keys'];
                                                } elseif (is_string($value) && preg_match('/keys=(\d+)/', $value, $matches)) {
                                                    $total_keys += (int) $matches[1];
                                                }
                                            }
                                        }
                                    }
                                    
                                    $metrics = [
                                        'cache_hit_rate' => $hit_rate,
                                        'total_keys' => $total_keys,
                                        'memory_usage' => $memory['used_memory_human'] ?? ($info['used_memory_human'] ?? '--'),
                                        'uptime' => isset($server['uptime_in_seconds']) ? $this->format_uptime_safe($server['uptime_in_seconds']) : (isset($info['uptime_in_seconds']) ? $this->format_uptime_safe($info['uptime_in_seconds']) : '--'),
                                        'connected_clients' => $clients['connected_clients'] ?? ($info['connected_clients'] ?? '--'),
                                        'ops_per_sec' => $stats['instantaneous_ops_per_sec'] ?? ($info['instantaneous_ops_per_sec'] ?? '--'),
                                        'response_time' => $response_time . 'ms'
                                    ];

                                    // Without CacheManager we avoid heavy SCANs; leave plugin_* counts at defaults
                                }
                                $redis->close();
                            } else {
                                // Direct Redis connection failed
                            }
                        } else {
                            // Missing Redis host or port in settings
                        }
                    } catch (\Exception $e) {
                        // Log error but continue with default metrics
                    }
                } else {
                    // Cache is disabled, not attempting direct Redis connection
                }
            }
            
            return new \WP_REST_Response([
                'success' => true,
                'data' => $metrics,
                'timestamp' => current_time('mysql')
            ], 200);
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Failed to get metrics: ' . $e->getMessage(),
                'error' => 'METRICS_FAILED',
                'data' => [
                    'cache_hit_rate' => '--',
                    'total_keys' => 0,
                    'memory_usage' => '--',
                    'uptime' => '--',
                    'connected_clients' => '--',
                    'ops_per_sec' => '--',
                    'response_time' => '--'
                ]
            ], 500);
        }
    }

    /**
     * Heavy plugin memory metrics computed on-demand via separate endpoint
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_plugin_memory_metrics($request) {
        try {
            $settings_now = get_option('ace_redis_cache_settings', []);
            if (empty($settings_now['enabled']) || !$this->cache_manager) {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'Cache is disabled'
                ], 200);
            }

            // Compute key counts
            $page_keys = 0;
            $block_keys = 0;
            try {
                $page_keys += count($this->cache_manager->scan_keys('page_cache:*'));
                $page_keys += count($this->cache_manager->scan_keys('page_cache_min:*'));
                $block_keys = count($this->cache_manager->scan_keys('block_cache:*'));
            } catch (\Exception $e) {
                // continue; leave counts as 0
            }

            // Compute memory breakdown
            $plugin = [
                'plugin_page_keys' => $page_keys,
                'plugin_block_keys' => $block_keys,
                'plugin_total_keys' => $page_keys + $block_keys,
            ];
            try {
                $mem = $this->cache_manager->get_memory_usage_breakdown();
                $plugin['plugin_memory_total'] = $mem['total_human'] ?? '--';
                $plugin['plugin_memory_page'] = $mem['page_human'] ?? '';
                $plugin['plugin_memory_minified'] = $mem['minified_human'] ?? '';
                $plugin['plugin_memory_blocks'] = $mem['blocks_human'] ?? '';
                $plugin['plugin_memory_transients'] = $mem['transients_human'] ?? '';
            } catch (\Exception $e) {
                // leave defaults
            }

            return new \WP_REST_Response([
                'success' => true,
                'data' => $plugin,
                'timestamp' => current_time('mysql')
            ], 200);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Failed to compute plugin memory: ' . $e->getMessage(),
                'error' => 'PLUGIN_MEMORY_FAILED'
            ], 500);
        }
    }
    
    /**
     * Sanitize settings array
     *
     * @param array $input Raw input data
     * @return array Sanitized settings
     */
    private function sanitize_settings($input) {
        $sanitized = [];
        
        $sanitized['enabled'] = !empty($input['enabled']) ? 1 : 0;
        $sanitized['mode'] = in_array($input['mode'] ?? 'full', ['full', 'object']) ? ($input['mode'] ?? 'full') : 'full';
        // Provide safe defaults for potentially missing fields
        $sanitized['host'] = sanitize_text_field($input['host'] ?? '127.0.0.1');
        $sanitized['port'] = intval($input['port'] ?? 6379);
        $sanitized['password'] = sanitize_text_field($input['password'] ?? '');
        // Back-compat base TTL; UI may only send ttl_page/ttl_object
        $base_ttl = isset($input['ttl']) ? intval($input['ttl']) : 3600;
        $sanitized['ttl'] = max(60, $base_ttl);
    // Dual-cache support
    $sanitized['enable_page_cache'] = !empty($input['enable_page_cache']) ? 1 : 0;
    $sanitized['enable_object_cache'] = !empty($input['enable_object_cache']) ? 1 : 0;
    $sanitized['ttl_page'] = max(60, intval($input['ttl_page'] ?? $sanitized['ttl']));
    $sanitized['ttl_object'] = max(60, intval($input['ttl_object'] ?? $sanitized['ttl']));
        $sanitized['enable_tls'] = !empty($input['enable_tls']) ? 1 : 0;
        $sanitized['enable_block_caching'] = !empty($input['enable_block_caching']) ? 1 : 0;
        $sanitized['enable_transient_cache'] = !empty($input['enable_transient_cache']) ? 1 : 0;
        // Conditional object-cache.php deployment: only if we control or no foreign drop-in exists
        $sanitized['enable_object_cache_dropin'] = 0;
        if ($sanitized['enable_transient_cache']) {
            $dropin_target = trailingslashit(WP_CONTENT_DIR) . 'object-cache.php';
            $ours = false; $foreign = false;
            if (file_exists($dropin_target)) {
                $contents = @file_get_contents($dropin_target);
                if ($contents !== false) {
                    if (strpos($contents, 'Ace Redis Cache Drop-In') !== false) {
                        $ours = true;
                    } else {
                        $foreign = true; // another cache (e.g., W3TC, Redis Object Cache, etc.)
                    }
                }
            }
            if ($ours || !$foreign) {
                $sanitized['enable_object_cache_dropin'] = 1;
            } else {
                // Leave disabled to avoid clobbering existing drop-in
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Ace-Redis-Cache: Detected foreign object-cache.php drop-in; not replacing.');
                }
            }
        }
        $sanitized['enable_minification'] = !empty($input['enable_minification']) ? 1 : 0;
        $sanitized['enable_compression'] = !empty($input['enable_compression']) ? 1 : 0;
        $method = sanitize_text_field($input['compression_method'] ?? 'brotli');
        $sanitized['compression_method'] = in_array($method, ['brotli','gzip']) ? $method : 'brotli';
        
        // Sanitize exclusion patterns
        $sanitized['custom_cache_exclusions'] = sanitize_textarea_field($input['custom_cache_exclusions'] ?? '');
        $sanitized['custom_transient_exclusions'] = sanitize_textarea_field($input['custom_transient_exclusions'] ?? '');
        $sanitized['custom_content_exclusions'] = sanitize_textarea_field($input['custom_content_exclusions'] ?? '');
        $sanitized['excluded_blocks'] = sanitize_textarea_field($input['excluded_blocks'] ?? '');
        // New: allow excluding common basic blocks via checkbox
        $sanitized['exclude_basic_blocks'] = !empty($input['exclude_basic_blocks']) ? 1 : 0;

    // Unified dynamic runtime mode: use excluded blocks as dynamic placeholders
    $sanitized['dynamic_excluded_blocks'] = !empty($input['dynamic_excluded_blocks']) ? 1 : 0;
    // Browser cache control (public max-age headers for page cache hits)
    $sanitized['enable_browser_cache_headers'] = !empty($input['enable_browser_cache_headers']) ? 1 : 0;
    $sanitized['browser_cache_max_age'] = max(60, intval($input['browser_cache_max_age'] ?? ($sanitized['ttl_page'] ?? 3600)));
    $sanitized['send_cache_meta_headers'] = !empty($input['send_cache_meta_headers']) ? 1 : 0; // X-Ace* diagnostic headers
    // Dynamic microcache and OPcache helpers
    $sanitized['enable_dynamic_microcache'] = !empty($input['enable_dynamic_microcache']) ? 1 : 0;
    $sanitized['dynamic_microcache_ttl'] = max(1, min(60, intval($input['dynamic_microcache_ttl'] ?? 10)));
    $sanitized['enable_opcache_helpers'] = !empty($input['enable_opcache_helpers']) ? 1 : 0;
    // Static asset cache (long-lived cache-control headers for images/css/js/fonts)
    $sanitized['enable_static_asset_cache'] = !empty($input['enable_static_asset_cache']) ? 1 : 0;
    $static_ttl_in = isset($input['static_asset_cache_ttl']) ? intval($input['static_asset_cache_ttl']) : 604800; // 7 days default
    // Clamp to 1 day .. 1 year
    if ($static_ttl_in < 86400) { $static_ttl_in = 86400; }
    if ($static_ttl_in > 31536000) { $static_ttl_in = 31536000; }
    $sanitized['static_asset_cache_ttl'] = $static_ttl_in;

        // Optional compression level overrides
        if (isset($input['brotli_level_object'])) $sanitized['brotli_level_object'] = intval($input['brotli_level_object']);
        if (isset($input['brotli_level_page'])) $sanitized['brotli_level_page'] = intval($input['brotli_level_page']);
        if (isset($input['gzip_level_object'])) $sanitized['gzip_level_object'] = intval($input['gzip_level_object']);
        if (isset($input['gzip_level_page'])) $sanitized['gzip_level_page'] = intval($input['gzip_level_page']);
        if (isset($input['min_compress_size'])) $sanitized['min_compress_size'] = max(0, intval($input['min_compress_size']));
        
        return $sanitized;
    }

    /**
     * Purge related option/object cache keys after a settings mutation.
     * Required keys: ace_redis_cache_settings, alloptions, notoptions.
     */
    private function purge_option_caches() {
        if (!function_exists('wp_cache_delete')) { return; }
        @wp_cache_delete('ace_redis_cache_settings', 'options');
        @wp_cache_delete('alloptions', 'options');
        @wp_cache_delete('notoptions', 'options');
    }
}
