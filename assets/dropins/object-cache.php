<?php
/**
 * Ace Redis Cache Drop-In (object-cache.php)
 *
 * Lean + safe: forwards to Redis via phpredis, excludes options,
 * and fully flushes our namespace on post save/status change.
 *
 * Signature: Ace Redis Cache Drop-In
 */

if (!defined('ABSPATH')) { exit; }

$uri    = $_SERVER['REQUEST_URI']  ?? '';
$script = $_SERVER['SCRIPT_NAME']  ?? '';
$php    = $_SERVER['PHP_SELF']     ?? '';

$is_admin_req = (
    strpos($uri, '/wp-admin/') !== false ||
    strpos($script, '/wp-admin/') !== false ||
    strpos($php, '/wp-admin/') !== false ||
    strpos($uri, '/wp-login.php') !== false
);

// If you truly want to stop the entire request for admin pages (not recommended), uncomment:
// if ($is_admin_req) { exit; }

// Safer: just force cache bypass for admin/login requests.
if ($is_admin_req && !defined('ACE_OC_BYPASS')) {
    define('ACE_OC_BYPASS', true);
}

if (!function_exists('ace_object_cache_is_woocommerce')) {
    function ace_object_cache_is_woocommerce() {
        if (defined('WC_ABSPATH')) {
            return true;
        }

        static $result = null;
        if ($result !== null) {
            return $result;
        }

        $result = file_exists(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php');

        return $result;
    }
}

if (!class_exists('WP_Object_Cache')) {
    class WP_Object_Cache {
        private const MAX_VALUE_BYTES_DEFAULT = 65536;
        private const MAX_VALUE_BYTES_WC = 32768;

        private const BYPASS_GROUPS_DEFAULT = [
            'woocommerce_sessions',
            'wc_session_id',
            'woocommerce_notices',
            'counts',
            'plugins',
            'woocommerce_transients',
        ];

        private const PERSISTENT_GROUPS_DEFAULT = [
            'post_meta',
            'posts',
            'terms',
            'term_meta',
            'term-queries',
            'term_taxonomy',
            'product_type_relationships',
            'product_visibility_relationships',
            'product_shipping_class_relationships',
            'options',
            'woocommerce',
            'wc_cache',
            'transient',
            'site-transient',
        ];

        protected $redis;
        protected $global_groups = [
            'users','userlogins','useremail','usermeta','site-transient',
            'blog-details','blog-id-cache','rss','comment','counts'
        ];
        protected $non_persistent_groups = [];
        protected $blog_prefix = '';
        protected $namespace  = 'ace:';  // top-level namespace
        protected $runtime    = [];      // in-request store
        protected $bypass     = false;   // request-scoped fail-open
        protected $slow_threshold_ms = 50; // Reduced from 100ms to 50ms

        // Write-through groups even when bypassing (for guest freshness)
        protected $write_through_groups = [
            'post_meta','terms','term_relationships','term_taxonomy',
            'comments','comment_meta','version','term_meta','categories',
            'category','tags','post_tag','nav_menu_item','wpseo_meta'
        ];

        // Never persist these groups (avoid theme/plugin UI issues)
        protected $excluded_cache_groups = ['options'];

        protected $igbinary = false;
        protected $serializer_active = false;
        protected $connected = false;
        protected $connect_via = null;
        protected $connect_error = null;
        protected $suspend_persistent_writes = false;
        protected $request_context = [];
        protected $runtime_only_mode = false;
        protected $max_value_bytes = self::MAX_VALUE_BYTES_DEFAULT;
        protected $bypass_groups = self::BYPASS_GROUPS_DEFAULT;
        protected $persistent_groups = self::PERSISTENT_GROUPS_DEFAULT;
        protected $stats = [
            'local_hits' => 0,
            'redis_hits' => 0,
            'redis_misses' => 0,
            'bypass_group_hits' => 0,
            'oversize_skips' => 0,
            'persist_writes' => 0,
            'persist_write_errors' => 0,
            'persist_deletes' => 0,
            'persist_delete_errors' => 0,
            'redis_errors' => 0,
            'bypass_short_circuit' => 0,
            'non_persistent_group_short_circuit' => 0,
        ];

        public function __construct() {
            // Emergency bypass
            $emergency_bypass = (defined('ACE_OC_BYPASS') && ACE_OC_BYPASS)
                || (isset($_GET['ace_oc_bypass']) && $_GET['ace_oc_bypass'] == '1');
            if ($emergency_bypass) { $this->bypass = true; }

            // Auto-bypass for admin/editor/REST/logged-in - improved early detection
            $logged_in_cookie = defined('LOGGED_IN_COOKIE') ? LOGGED_IN_COOKIE : 'wordpress_logged_in_';
            $is_logged_in_cookie = false;
            foreach ($_COOKIE as $ck => $val) { if (strpos($ck, $logged_in_cookie) === 0) { $is_logged_in_cookie = true; break; } }
            
            // Early admin detection based on REQUEST_URI and script name
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
            $php_self = $_SERVER['PHP_SELF'] ?? '';
            
            $is_admin_by_url = (
                strpos($request_uri, '/wp-admin/') !== false ||
                strpos($script_name, '/wp-admin/') !== false ||
                strpos($php_self, '/wp-admin/') !== false ||
                strpos($request_uri, '/wp-login.php') !== false ||
                (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], '/wp-admin/') !== false)
            );
            
            $is_ajax = (
                (defined('DOING_AJAX') && DOING_AJAX) ||
                strpos($request_uri, '/wp-admin/admin-ajax.php') !== false ||
                (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')
            );
            $request_path = (string) parse_url($request_uri, PHP_URL_PATH);
            $rest_route = isset($_GET['rest_route']) ? urldecode((string) $_GET['rest_route']) : '';
            $has_wc_session_cookie = false;
            $wc_cookie_prefixes = ['woocommerce_cart_hash', 'woocommerce_items_in_cart', 'wp_woocommerce_session_'];
            foreach ($_COOKIE as $ck => $val) {
                foreach ($wc_cookie_prefixes as $prefix) {
                    if (strpos((string) $ck, $prefix) === 0 && (string) $val !== '') {
                        $has_wc_session_cookie = true;
                        break 2;
                    }
                }
            }

            $is_wc_cart_endpoint = ($request_path !== '' && preg_match('#(^|/)(cart|checkout|my-account|register|lost-password|customer-logout|order-pay|order-received|view-order|edit-account|add-payment-method|payment-methods|set-default-payment-method|delete-payment-method)(/|$)#i', $request_path) === 1);
            $is_wc_store_api_request = (
                ($request_path !== '' && preg_match('#/(?:wp-json/)?wc/store/v1/(cart|checkout)(?:/|$)#i', $request_path) === 1) ||
                ($rest_route !== '' && preg_match('#^/wc/store/v1/(cart|checkout)(?:/|$)#i', $rest_route) === 1) ||
                preg_match('#[?&]rest_route=(?:%2F|/)?wc(?:%2F|/)store(?:%2F|/)v1(?:%2F|/)(cart|checkout)(?:%2F|/|[&#]|$)#i', (string) $request_uri) === 1
            );
            $is_wc_cart_action = (
                isset($_GET['wc-ajax']) ||
                isset($_GET['add-to-cart']) ||
                isset($_GET['remove_item']) ||
                isset($_GET['undo_item']) ||
                (isset($_GET['action']) && in_array((string) $_GET['action'], ['register', 'lostpassword', 'resetpass', 'logout'], true)) ||
                isset($_GET['password-reset']) ||
                isset($_GET['key']) ||
                preg_match('#[?&](wc-ajax|add-to-cart|remove_item|undo_item|password-reset|key)=#i', (string) $request_uri) === 1
            );
            $is_wc_customer_session_request = ($has_wc_session_cookie || $is_wc_cart_endpoint || $is_wc_store_api_request || $is_wc_cart_action);
            $request_method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            $is_cron = (defined('DOING_CRON') && DOING_CRON);
            $is_cli  = (defined('WP_CLI') && WP_CLI);
            $allow_methods = ['GET','HEAD'];
            
            // Traditional checks (may not work early, but keep as fallback)
            $is_admin_req    = function_exists('is_admin') && is_admin();
            $is_logged_in_fn = function_exists('is_user_logged_in') && is_user_logged_in();
            $is_rest         = defined('REST_REQUEST') && REST_REQUEST;
            
            // Bypass for WordPress update operations to prevent interference with plugin/theme update checks
            $is_update_operation = (
                (defined('DOING_CRON') && DOING_CRON) ||
                strpos($request_uri, 'wp-admin/update-core.php') !== false ||
                strpos($request_uri, 'wp-admin/update.php') !== false ||
                isset($_GET['action']) && in_array($_GET['action'], ['update-plugin', 'update-theme', 'upgrade-plugin', 'upgrade-theme']) ||
                (isset($_POST['action']) && strpos($_POST['action'], 'update') !== false)
            );
            
            $this->request_context = [
                'is_admin_url' => $is_admin_by_url,
                'is_admin'     => $is_admin_req,
                'is_ajax'      => $is_ajax,
                'is_cron'      => $is_cron,
                'is_cli'       => $is_cli,
                'is_rest'      => $is_rest,
                'is_wc_customer_session_request' => $is_wc_customer_session_request,
                'method'       => $request_method,
            ];

            // Admin navigation previously exhausted Redis by caching whole WP_Post objects during read-only loads.
            // Suspend persistence for classic wp-admin GET/HEAD requests (except AJAX/cron) so Redis remains
            // an optimisation layer instead of a dependency on the editorial backend.
            $admin_read_suspension = (
                ($is_admin_by_url || $is_admin_req) &&
                !$is_ajax &&
                !$is_cron &&
                !$is_cli &&
                in_array($request_method, $allow_methods, true) &&
                !($is_rest && !in_array($request_method, $allow_methods, true))
            );
            if ($admin_read_suspension) {
                $this->suspend_persistent_writes = true;
                if (!defined('ACE_OC_ADMIN_CACHE_SUSPENDED')) {
                    define('ACE_OC_ADMIN_CACHE_SUSPENDED', true);
                }
            }

            $editor_bypass   = ($is_admin_by_url || $is_ajax || $is_admin_req || $is_logged_in_fn || $is_logged_in_cookie || $is_rest || $is_update_operation || $is_wc_customer_session_request);
            $editor_bypass   = apply_filters('ace_rc_object_cache_bypass', $editor_bypass, [
                'is_admin_url'  => $is_admin_by_url,
                'is_ajax'       => $is_ajax,
                'is_admin'      => $is_admin_req,
                'is_logged_in'  => $is_logged_in_fn || $is_logged_in_cookie,
                'is_rest'       => $is_rest,
                'is_wc_customer_session_request' => $is_wc_customer_session_request,
            ]);
            if ($editor_bypass || $this->suspend_persistent_writes) { $this->bypass = true; }
            if ($editor_bypass) {
                $this->runtime_only_mode = true;
                if (!defined('ACE_OC_RUNTIME_ONLY')) {
                    define('ACE_OC_RUNTIME_ONLY', true);
                }
            }

            $is_admin_system_request = ($is_admin_by_url || $is_admin_req || $is_ajax || $is_rest);

            $blog_id           = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
            $this->blog_prefix = (is_multisite() ? $blog_id . ':' : '1:');

            if (function_exists('ace_object_cache_is_woocommerce') && ace_object_cache_is_woocommerce()) {
                $this->apply_woocommerce_profile();
            }

            // Only initialize Redis for cache-eligible requests.
            // Logged-in/admin/system/bypass requests should not pay Redis bootstrap cost.
            if (extension_loaded('redis') && !$is_admin_system_request && !$this->bypass) {
                $this->init_redis();
            }

            // Big switches (theme/customizer/core updates)
            add_action('upgrader_process_complete', [$this, 'flush']);
            add_action('switch_theme',               [$this, 'flush']);
            add_action('customize_save_after',       [$this, 'flush']);

            // Post changes: flush on edits of public posts and on visibility flips (public <-> non-public)
            add_action('save_post',              [$this, 'maybe_flush_on_save'], 10, 3);
            add_action('transition_post_status', [$this, 'maybe_flush_on_visibility_change'], 10, 3);
        }

        public function apply_woocommerce_profile() {
            $this->max_value_bytes = self::MAX_VALUE_BYTES_WC;
            $this->bypass_groups = apply_filters('ace_oc_bypass_groups', self::BYPASS_GROUPS_DEFAULT);
            $this->persistent_groups = apply_filters('ace_oc_persistent_groups', self::PERSISTENT_GROUPS_DEFAULT);
        }

        protected function is_bypass_group($group) {
            $g = $group ?: 'default';
            return in_array($g, (array) $this->bypass_groups, true);
        }

        protected function is_persistent_group($group) {
            $g = $group ?: 'default';
            return in_array($g, (array) $this->persistent_groups, true);
        }

        protected function exceeds_max_value_size($data) {
            if ($data === null) {
                return false;
            }

            if ($this->serializer_active) {
                // phpredis serializer runs inside extension; approximate by serialize size.
                $payload = @serialize($data);
            } else {
                $payload = is_scalar($data) ? (string) $data : @serialize($data);
            }

            if (!is_string($payload)) {
                return false;
            }

            return strlen($payload) > (int) $this->max_value_bytes;
        }

        protected function stat_inc($key, $by = 1) {
            if (!isset($this->stats[$key])) {
                $this->stats[$key] = 0;
            }
            $this->stats[$key] += (int) $by;
        }

        public function get_runtime_stats() {
            return $this->stats;
        }

        public function reset_runtime_stats() {
            foreach ($this->stats as $k => $v) {
                $this->stats[$k] = 0;
            }
            return true;
        }

        /** Is this a publicly visible status? */
        protected function is_public_status($status) {
            return in_array($status, ['publish','future'], true);
        }

        /** Is this post type public (or publicly queryable)? */
        protected function is_public_type($post_type) {
            $pto = function_exists('get_post_type_object') ? get_post_type_object($post_type) : null;
            if (!$pto) { return false; }
            return !empty($pto->public) || !empty($pto->publicly_queryable);
        }

        /** Skip autosaves/revisions */
        protected function should_skip_post($post) {
            if (!$post) return true;
            if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($post)) return true;
            if (function_exists('wp_is_post_revision') && wp_is_post_revision($post)) return true;
            return false;
        }

        /** 
         * Comprehensive cache flush for post changes.
         * This flushes ALL caches to ensure frontend reflects the changes immediately.
         * Uses complete Redis flush for maximum compatibility with block caching.
         */
        protected function flush_everything_for_post($post_id) {
            // Force complete cache flush (same as admin "Clear All Cache" button)
            // This ensures all cached content is refreshed when posts are published/updated
            $this->runtime = [];
            
            // Complete Redis flush for post updates to ensure block caching compatibility
            if ($this->redis !== null) {
                try {
                    // Always do complete flush for post updates to avoid any stale cache issues
                    $this->redis->flushDB();
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('AceRedisCache: Complete Redis flush after post update ID=' . $post_id);
                    }
                } catch (\Throwable $e) {
                    // If Redis fails, at least clear runtime cache
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('AceRedisCache: Redis flush failed: ' . $e->getMessage());
                    }
                }
            }
        }

        /**
         * Flush cache when posts are updated/saved, but ONLY for published posts.
         * This ensures:
         * - Editing/saving published posts → Cache flush happens
         * - Editing/saving drafts → No cache flush (performance optimization)
         */
        public function maybe_flush_on_save($post_id, $post, $update) {
            if (!$post || $this->should_skip_post($post)) return;
            if (!$this->is_public_type($post->post_type)) return;

            // Only flush for published/scheduled posts, not drafts
            if ($this->is_public_status($post->post_status)) {
                $this->flush_everything_for_post($post_id);
            }
        }

        /**
         * Flush when post visibility changes between public and non-public states.
         * This handles:
         * - Draft → Published (flush needed)
         * - Published → Draft/Trash (flush needed) 
         * - Draft → Draft (no flush, handled above)
         */
        public function maybe_flush_on_visibility_change($new_status, $old_status, $post) {
            if (!$post || $this->should_skip_post($post)) return;
            if (!$this->is_public_type($post->post_type)) return;

            $was_public = $this->is_public_status($old_status);
            $is_public  = $this->is_public_status($new_status);

            if ($was_public !== $is_public) {
                $this->flush_everything_for_post($post->ID);
            }
        }

        /**
         * If you have *specific* transient keys to purge, provide them via:
         *   add_filter('ace_known_query_transients', fn() => ['my_transient_key', ...]);
         * We use delete_transient()/delete_site_transient() (no DB scans).
         */
        protected function maybe_delete_known_transients() {
            $keys = (array) apply_filters('ace_known_query_transients', []);
            if (!$keys) { return; }
            foreach ($keys as $key) {
                if (function_exists('delete_transient')) { delete_transient($key); }
                if (function_exists('delete_site_transient')) { delete_site_transient($key); }
            }
        }

        protected function init_redis() {
            global $ace_redis_shared_connection;
            if ($ace_redis_shared_connection instanceof \Redis) {
                $this->redis = $ace_redis_shared_connection;
                $this->connected = true;
                $this->connect_via = 'shared';
                return;
            }

            // Get settings directly from database to avoid circular dependency with get_option().
            // During early bootstrap, table prefix info can be incomplete; skip DB lookup if table is ambiguous.
            $plugin_settings = null;
            if (isset($GLOBALS['wpdb']) && $GLOBALS['wpdb']) {
                global $wpdb;
                if ($wpdb && method_exists($wpdb, 'get_var')) {
                    $options_table = '';
                    if (!empty($wpdb->options) && is_string($wpdb->options) && $wpdb->options !== 'options') {
                        $options_table = $wpdb->options;
                    } elseif (!empty($wpdb->prefix) && is_string($wpdb->prefix)) {
                        $options_table = $wpdb->prefix . 'options';
                    }

                    $option_name = 'ace_redis_cache_settings';
                    if ($options_table !== '') {
                        try {
                            $option_value = $wpdb->get_var($wpdb->prepare(
                                "SELECT option_value FROM {$options_table} WHERE option_name = %s",
                                $option_name
                            ));
                            if ($option_value && is_string($option_value)) {
                                $plugin_settings = json_decode($option_value, true);
                                if (json_last_error() !== JSON_ERROR_NONE) {
                                    // Try unserialize for PHP serialized data
                                    $plugin_settings = @unserialize($option_value);
                                }
                            }
                        } catch (\Throwable $e) {
                            // Fallback to constants if database query fails
                            $plugin_settings = null;
                        }
                    }
                }
            }
            
            // Use plugin settings if available, otherwise fall back to constants and defaults
            if (is_array($plugin_settings)) {
                $host = $plugin_settings['host'] ?? '127.0.0.1';
                $port = (int)($plugin_settings['port'] ?? 6379);
                $pass = $plugin_settings['password'] ?? '';
                $use_tls = !empty($plugin_settings['enable_tls']);
            } else {
                // Fallback to constants/defaults if plugin settings not available
                $host = defined('ACE_REDIS_HOST') ? ACE_REDIS_HOST : (defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1');
                $port = defined('ACE_REDIS_PORT') ? (int)ACE_REDIS_PORT : (defined('WP_REDIS_PORT') ? (int)WP_REDIS_PORT : 6379);
                $pass = defined('ACE_REDIS_PASSWORD') ? ACE_REDIS_PASSWORD : (defined('WP_REDIS_PASSWORD') ? WP_REDIS_PASSWORD : '');
                $use_tls = (defined('ACE_REDIS_USE_TLS') && ACE_REDIS_USE_TLS) || (defined('WP_REDIS_SCHEME') && WP_REDIS_SCHEME === 'tls') || (defined('WP_REDIS_USE_TLS') && WP_REDIS_USE_TLS);
            }
            
            $socket = defined('ACE_REDIS_SOCKET') ? ACE_REDIS_SOCKET : '/var/run/redis/redis.sock';
            $timeout = 0.5; // Reduced from 1.0s to 0.5s for faster failover

            $attempted_socket = false;
            $use_tls_config = $use_tls;
            $persistent_id = 'ace-object-cache';

            try {
                $this->redis = new Redis();

                if (@is_readable($socket)) {
                    $attempted_socket = true;
                    @$this->redis->connect($socket, 0, $timeout);
                    if (method_exists($this->redis,'isConnected') && !$this->redis->isConnected()) { $this->redis->close(); }
                    else { $this->connect_via = 'socket'; }
                }

                if ($this->connect_via === null) {
                    $attempt_tls_first = $use_tls_config || (strpos($host, '.cache.amazonaws.com') !== false);
                    $plain_host        = $host;
                    $tls_host          = (strpos($host, 'tls://') === 0) ? $host : ('tls://' . $host);
                    $connected         = false;

                    if ($attempt_tls_first) {
                        try {
                            $ctx = (defined('ACE_REDIS_VERIFY_TLS') && ACE_REDIS_VERIFY_TLS)
                                ? ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]
                                : ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
                            @$this->redis->connect($tls_host, $port, $timeout, null, 0, 0, $ctx);
                            if (method_exists($this->redis,'isConnected') && $this->redis->isConnected()) { $this->connect_via = 'tls'; $connected = true; }
                        } catch (\Throwable $t) {}
                    }

                    if (!$connected) {
                        try {
                            @$this->redis->connect($plain_host, $port, $timeout);
                            if (method_exists($this->redis,'isConnected') && $this->redis->isConnected()) { $this->connect_via = 'tcp'; $connected = true; }
                        } catch (\Throwable $t) {}
                    }

                    if (!$connected && !$attempt_tls_first && $use_tls_config) {
                        try {
                            $ctx = (defined('ACE_REDIS_VERIFY_TLS') && ACE_REDIS_VERIFY_TLS)
                                ? ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]
                                : ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
                            @$this->redis->connect($tls_host, $port, $timeout, null, 0, 0, $ctx);
                            if (method_exists($this->redis,'isConnected') && $this->redis->isConnected()) { $this->connect_via = 'tls'; }
                        } catch (\Throwable $t) {}
                    }
                }

                if ($this->connect_via === null) {
                    throw new \RuntimeException('Redis connection failed via ' . ($attempted_socket ? 'socket+tcp' : 'tcp'));
                }

                // Aggressive timeout settings for performance
                try { $this->redis->setOption(Redis::OPT_READ_TIMEOUT, 0.3); } catch (\Throwable $t) {}
                try { $this->redis->setOption(Redis::OPT_TIMEOUT, 0.3); } catch (\Throwable $t) {}

                if (defined('Redis::SERIALIZER_IGBINARY') && function_exists('igbinary_serialize')) {
                    $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
                    $this->igbinary = true; $this->serializer_active = true;
                } else {
                    $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
                    $this->serializer_active = true;
                }

                if (!empty($pass)) { @ $this->redis->auth($pass); }
                try { $this->redis->client('SETNAME', 'ace-object-cache'); } catch (\Throwable $t) {}

                $this->connected = true;
                $ace_redis_shared_connection = $this->redis;
            } catch (\Throwable $e) {
                $this->connect_error = $e->getMessage();
                $this->bypass = true; $this->redis = null;
            }
        }

        protected function is_global_group($group) {
            return in_array($group, $this->global_groups, true) || $group === 'site-transient';
        }

        protected function k($key, $group) {
            $g     = $group ?: 'default';
            $scope = $this->is_global_group($g) ? 'g:' : $this->blog_prefix;
            return $this->namespace . $scope . $g . ':' . $key;
        }

        protected function prof_log($type, $key, $ms, $extra = '') {
            if (!defined('ACE_OC_PROF') || !ACE_OC_PROF) { return; }
            if ($ms < $this->slow_threshold_ms) { return; }
            error_log(sprintf('%s %s %.1fms%s', $type, $key, $ms, $extra));
        }

        protected function runtime_get($group, $key, &$found) {
            if (isset($this->runtime[$group]) && array_key_exists($key, $this->runtime[$group])) {
                $found = true; return $this->runtime[$group][$key];
            }
            $found = false; return false;
        }
        protected function runtime_set($group, $key, $val) {
            if (!isset($this->runtime[$group])) { $this->runtime[$group] = []; }
            $this->runtime[$group][$key] = $val;
        }

        protected function use_runtime_only_mode() {
            return (bool) $this->runtime_only_mode;
        }

        protected function should_write_through($group, $key = null) {
            if ($this->suspend_persistent_writes) return false;
            if (!$this->redis || !$this->connected) return false;
            $g = $group ?: 'default';
            $list = apply_filters('ace_rc_object_cache_write_through_groups', $this->write_through_groups);
            return in_array($g, (array)$list, true);
        }

                // NEW: helper the lean file was missing.
        protected function is_excluded_group($group) {
            $g = $group ?: 'default';
            
            // Don't exclude any groups at the group level
            // Use key-level exclusion instead for fine-grained control
            
            return false;
        }

        protected function is_excluded_key($group, $key) {
            $g = $group ?: 'default';
            
            // For options group: be selective about what we cache
            if ($g === 'options') {
                // Always cache alloptions - critical for performance
                if ($key === 'alloptions') {
                    return false;
                }
                // Cache plugin settings when not bypassing
                if ($key === 'ace_redis_cache_settings') {
                    return $this->bypass; // Only exclude during bypass
                }
                // Cache theme options and other important settings
                if (strpos($key, 'theme_mods_') === 0 || strpos($key, 'widget_') === 0) {
                    return false;
                }
                // Exclude most individual options to prevent cache pollution
                return true;
            }
            
            // For regular transients: cache important ones, exclude others
            if ($g === 'transient') {
                // Cache feed-related transients (important for performance)
                if (strpos($key, 'feed_') === 0 || strpos($key, '_feed_') !== false || 
                    strpos($key, 'rss_') === 0 || strpos($key, '_rss_') !== false ||
                    strpos($key, 'themeisle_sdk_feed') !== false) {
                    return false; // Don't exclude - cache these
                }
                // Cache sitemap and SEO transients (performance critical)
                if (strpos($key, 'wpseo_') === 0 || strpos($key, 'sitemap_') !== false ||
                    strpos($key, '_sitemap') !== false || strpos($key, 'yoast_') === 0) {
                    return false;
                }
                // Cache taxonomy and term-related transients
                if (strpos($key, 'get_terms') !== false || strpos($key, 'taxonomy_') !== false ||
                    strpos($key, '_terms') !== false || strpos($key, 'term_') !== false) {
                    return false;
                }
                // Cache PPCP/PayPal transients (prevent blocking API calls on every page)
                if (strpos($key, "ppcp") !== false || strpos($key, "paypal") !== false) {
                    return false;
                }
                // Exclude other transients to prevent cache pollution
                return true;
            }
            
            return false;
        }

        protected function should_block_post_object_persistence($group, $value) {
            if ($group !== 'posts') { return false; }
            if (!is_object($value)) { return false; }

            $looks_like_post = ($value instanceof \WP_Post) || (isset($value->ID) && isset($value->post_type));
            if (!$looks_like_post) { return false; }

            /**
             * Filter whether full WP_Post objects should be skipped when persisting to Redis.
             * Returning true avoids storing heavyweight objects and keeps Redis focused on frontend reuse.
             */
            $skip = apply_filters('ace_rc_skip_post_object_cache', true, $value, $group);
            return (bool) $skip;
        }

        protected function can_persist_to_redis($group, $key = null, $allow_override = false) {
            if ($this->redis === null || !$this->connected) { return false; }
            if ($this->suspend_persistent_writes) { return false; }
            if ($this->is_bypass_group($group)) { return false; }
            if (!$this->is_persistent_group($group)) { return false; }
            if ($this->bypass && !$this->should_write_through($group, $key) && !$allow_override) {
                return false;
            }
            return true;
        }

        // ------- Core cache API methods -------

        public function add($key, $data, $group = 'default', $expire = 0) {
            $group = $group ?: 'default';

            if ($this->use_runtime_only_mode()) {
                if (!isset($this->runtime[$group]) || !array_key_exists($key, $this->runtime[$group])) {
                    $this->runtime_set($group, $key, $data);
                    return true;
                }
                return false;
            }

            // For excluded groups: do a no-op but report success and keep runtime coherent
            if ($this->is_excluded_group($group)) {
                $this->runtime_set($group, $key, $data);
                return true;
            }

            if ($this->should_block_post_object_persistence($group, $data)) {
                if (!isset($this->runtime[$group][$key])) { $this->runtime_set($group, $key, $data); return true; }
                return false;
            }

            if ($this->exceeds_max_value_size($data)) {
                $this->stat_inc('oversize_skips');
                if (!isset($this->runtime[$group][$key])) { $this->runtime_set($group, $key, $data); return true; }
                return false;
            }

            if (!$this->can_persist_to_redis($group, $key)) {
                if (!isset($this->runtime[$group][$key])) { $this->runtime_set($group, $key, $data); return true; }
                return false;
            }
            $k = $this->k($key, $group);
            $payload = $this->serializer_active ? $data : (is_scalar($data) ? $data : serialize($data));
            try {
                $ok = $expire > 0 ? (bool)$this->redis->set($k, $payload, ['nx','ex'=>(int)$expire])
                                  : (bool)$this->redis->set($k, $payload, ['nx']);
                if ($ok) {
                    $this->runtime_set($group, $key, $data);
                    $this->stat_inc('persist_writes');
                }
                return $ok;
            } catch (\Throwable $e) { $this->bypass = true; $this->runtime_set($group, $key, $data); $this->stat_inc('persist_write_errors'); return true; }
        }

        public function set($key, $data, $group = 'default', $expire = 0) {
            $group = $group ?: 'default';
            $start_time = microtime(true);

            if ($this->use_runtime_only_mode()) {
                $this->runtime_set($group, $key, $data);
                return true;
            }

            // Check both group and key-level exclusions
            if ($this->is_excluded_group($group) || $this->is_excluded_key($group, $key)) {
                $this->runtime_set($group, $key, $data);
                return true;
            }

            $this->runtime_set($group, $key, $data);
            if ($this->should_block_post_object_persistence($group, $data)) {
                return true;
            }

            $is_bypass_group = $this->is_bypass_group($group);
            $is_oversize = $this->exceeds_max_value_size($data);

            if ($is_bypass_group || $is_oversize) {
                if ($this->is_bypass_group($group)) {
                    $this->stat_inc('bypass_group_hits');
                }
                if ($is_oversize) {
                    $this->stat_inc('oversize_skips');
                }
                return true;
            }
            
            // Special handling for site-transients: allow them even during bypass (admin)
            // This ensures WordPress update transients work properly
            $allow_during_bypass = ($group === 'site-transient') && !$this->suspend_persistent_writes;
            
            if (!$this->can_persist_to_redis($group, $key, $allow_during_bypass)) { 
                return true; 
            }
            $k = $this->k($key, $group);
            $payload = $this->serializer_active ? $data : (is_scalar($data) ? $data : serialize($data));
            try {
                $redis_start = microtime(true);
                $result = ($expire > 0) ? (bool)$this->redis->setex($k, (int)$expire, $payload)
                                        : (bool)$this->redis->set($k, $payload);
                $redis_time = (microtime(true) - $redis_start) * 1000;
                $total_time = (microtime(true) - $start_time) * 1000;
                if ($result) {
                    $this->stat_inc('persist_writes');
                }
                
                $this->prof_log('REDIS_SET', $group . ':' . $key, $total_time, sprintf(' redis=%.1fms exp=%d', $redis_time, $expire));
                return $result;
            } catch (\Throwable $e) { 
                $this->bypass = true; 
                $this->stat_inc('persist_write_errors');
                $this->prof_log('REDIS_SET_ERROR', $group . ':' . $key, (microtime(true) - $start_time) * 1000, ' err=' . $e->getMessage());
                return true; 
            }
        }

        public function get($key, $group = 'default', $force = false, &$found = null) {
            $group = $group ?: 'default';
            $start_time = microtime(true);

            if ($this->use_runtime_only_mode()) {
                $local = $this->runtime_get($group, $key, $local_found);
                if ($local_found && !$force) {
                    $found = true;
                    $this->stat_inc('local_hits');
                    return $local;
                }
                $found = false;
                return false;
            }

            // W3TC-style notoptions shim for WP 6.4-6.7 compatibility
            static $wp_version;
            if (null === $wp_version) {
                global $wp_version;
                if (!$wp_version && defined('ABSPATH')) {
                    include_once ABSPATH . WPINC . '/version.php';
                }
            }
            
            // Apply notoptions short-circuit for affected WP versions (6.4.0–6.7.x)
            if (
                $wp_version &&
                version_compare($wp_version, '6.4', '>=') &&
                version_compare($wp_version, '6.8', '<') &&
                'options' === $group &&
                'notoptions' !== $key
            ) {
                // Mirror WP 6.8's early notoptions lookup to avoid repeated external cache checks
                $notoptions = $this->runtime_get('options', 'notoptions', $noto_found);
                
                if (!$noto_found) {
                    // Don't persist notoptions to Redis, keep it only in runtime
                    $notoptions = [];
                    $this->runtime_set('options', 'notoptions', $notoptions);
                }
                
                if (!is_array($notoptions)) {
                    $notoptions = [];
                    $this->runtime_set('options', 'notoptions', $notoptions);
                }
                
                if (isset($notoptions[$key])) {
                    $found = false;
                    return false;
                }
            }

            // Check both group and key-level exclusions
            if ($this->is_excluded_group($group) || $this->is_excluded_key($group, $key)) { 
                $found = false; 
                return false; 
            }

            if ($this->is_bypass_group($group)) {
                $this->stat_inc('bypass_group_hits');
                $local = $this->runtime_get($group, $key, $local_found);
                if ($local_found) {
                    $found = true;
                    $this->stat_inc('local_hits');
                    return $local;
                }
                $found = false;
                return false;
            }

            $local = $this->runtime_get($group, $key, $local_found);
            if ($local_found && !$force) { 
                $found = true; 
                $this->stat_inc('local_hits');
                $this->prof_log('RUNTIME_HIT', $group . ':' . $key, (microtime(true) - $start_time) * 1000);
                return $local; 
            }

            if (!$this->is_persistent_group($group)) {
                $this->stat_inc('non_persistent_group_short_circuit');
                $found = false;
                return false;
            }

            // Special handling for site-transients: allow them even during bypass (admin)  
            $allow_during_bypass = ($group === 'site-transient') && !$this->suspend_persistent_writes;
            
            if (($this->bypass && !$allow_during_bypass) || $this->redis === null) { 
                $found = false; 
                $this->stat_inc('bypass_short_circuit');
                $this->prof_log('BYPASS', $group . ':' . $key, (microtime(true) - $start_time) * 1000);
                return false; 
            }
            if ($group === 'options' && $key === 'ace_redis_cache_settings') { $found = false; return false; }

            $k = $this->k($key, $group);
            try { 
                $redis_start = microtime(true);
                $val = $this->redis->get($k); 
                $redis_time = (microtime(true) - $redis_start) * 1000;
                
                if ($val === false || $val === null) { 
                    $found = false; 
                    $this->stat_inc('redis_misses');
                    $this->prof_log('REDIS_MISS', $group . ':' . $key, (microtime(true) - $start_time) * 1000, sprintf(' redis=%.1fms', $redis_time));
                    return false; 
                }
                
                $out = $this->decode_value($val);

                if ($this->should_block_post_object_persistence($group, $out)) {
                    $found = false;
                    $this->prof_log('SKIP_POST_OBJECT', $group . ':' . $key, (microtime(true) - $start_time) * 1000);
                    return false;
                }

                // Never serve non-published post objects to guests
                if (!$this->bypass && is_object($out) && isset($out->post_status) && $out->post_status !== 'publish') {
                    $found = false; 
                    $this->prof_log('UNPUBLISHED_POST', $group . ':' . $key, (microtime(true) - $start_time) * 1000);
                    return false;
                }

                $this->runtime_set($group, $key, $out);
                $found = true; 
                $this->stat_inc('redis_hits');
                $this->prof_log('REDIS_HIT', $group . ':' . $key, (microtime(true) - $start_time) * 1000, sprintf(' redis=%.1fms', $redis_time));
                return $out;
            } catch (\Throwable $e) { 
                $this->bypass = true; 
                $found = false; 
                $this->stat_inc('redis_errors');
                $this->prof_log('REDIS_ERROR', $group . ':' . $key, (microtime(true) - $start_time) * 1000, ' err=' . $e->getMessage());
                return false; 
            }
        }

        protected function looks_serialized($value) {
            if ($value === 'b:0;' || $value === 'b:1;') { return true; }
            if (!is_string($value) || strlen($value) < 4) { return false; }
            switch ($value[0]) {
                case 's': case 'a': case 'O': case 'i': case 'b': case 'd': case 'N':
                    return (bool)preg_match('/^(?:s:\d+:"[^"\\\\]*";|a:\d+:\{|O:\d+:"[^"\\\\]+":\d+:\{|i:\d+;|b:[01];|d:\d+\\.?\\d*;|N;)/', $value);
                default: return false;
            }
        }
        protected function decode_value($val) {
            if (!is_string($val)) { return $val; }
            $attempts = 0;
            while ($attempts < 3 && is_string($val) && $this->looks_serialized($val)) {
                $un = @unserialize($val);
                if ($un === false && $val !== 'b:0;') { break; }
                $val = $un; $attempts++;
            }
            return $val;
        }

        public function delete($key, $group = 'default', $time = 0) {
            $group = $group ?: 'default';

            if ($this->use_runtime_only_mode()) {
                unset($this->runtime[$group][$key]);
                return true;
            }

            // Excluded groups: no-op but return success
            if ($this->is_excluded_group($group)) {
                unset($this->runtime[$group][$key]);
                return true;
            }

            unset($this->runtime[$group][$key]);
            if ($this->is_bypass_group($group) || !$this->is_persistent_group($group)) {
                return true;
            }
            if (!$this->can_persist_to_redis($group, $key)) return true;
            $k = $this->k($key, $group);
            try {
                $deleted = (bool)$this->redis->del($k);
                if ($deleted) {
                    $this->stat_inc('persist_deletes');
                }
                return $deleted;
            } catch (\Throwable $e) {
                $this->bypass = true;
                $this->stat_inc('persist_delete_errors');
                return true;
            }
        }

        /**
         * Flush everything in our namespace. If ACE_OC_FLUSHDB_ON_SAVE is true, flush the entire DB.
         * 
         * @param bool $force Force flush even during bypass mode
         */
        public function flush($force = false) {
            $this->runtime = [];
            $respect_bypass = !$this->suspend_persistent_writes;
            if ((!$force && $this->bypass && $respect_bypass) || $this->redis === null) return true;

            if (defined('ACE_OC_FLUSHDB_ON_SAVE') && ACE_OC_FLUSHDB_ON_SAVE) {
                try { $this->redis->flushDB(); } catch (\Throwable $e) {}
                return true;
            }

            // Namespace-only flush (default, safer). Loop keys for older phpredis.
            $pattern = $this->namespace . '*';
            $it = null;
            try {
                do {
                    $keys = $this->redis->scan($it, $pattern, 1000);
                    if ($keys && is_array($keys) && !empty($keys)) {
                        foreach ($keys as $k) { $this->redis->del($k); }
                    }
                } while ($it > 0);
            } catch (\Throwable $e) { if (!$force) $this->bypass = true; }
            return true;
        }

        /**
         * Check if cache supports a particular feature
         * 
         * @param string $feature Feature name
         * @return bool
         */
        public function supports($feature) {
            return in_array($feature, ['flush_group', 'flush_runtime', 'get_multiple', 'set_multiple', 'delete_multiple'], true);
        }

        /**
         * Flush all cache items in runtime memory
         * 
         * @return bool
         */
        public function flush_runtime() {
            $this->runtime = [];
            return true;
        }

        /**
         * Flush all cache items in a specific group
         * 
         * @param string $group Cache group
         * @return bool
         */
        public function flush_group($group) {
            $group = $group ?: 'default';
            
            // Clear from runtime
            unset($this->runtime[$group]);
            
            if (($this->bypass && !$this->suspend_persistent_writes) || $this->redis === null) return true;
            
            // Clear from Redis - scan for both global and blog-scoped keys
            $patterns = [];
            if ($this->is_global_group($group)) {
                $patterns[] = $this->namespace . 'g:' . $group . ':*';
            } else {
                $patterns[] = $this->namespace . $this->blog_prefix . $group . ':*';
            }
            
            try {
                foreach ($patterns as $pattern) {
                    $it = null;
                    do {
                        $keys = $this->redis->scan($it, $pattern, 1000);
                        if ($keys && is_array($keys) && !empty($keys)) {
                            foreach ($keys as $k) { $this->redis->del($k); }
                        }
                    } while ($it > 0);
                }
            } catch (\Throwable $e) { $this->bypass = true; }
            
            return true;
        }

        /**
         * Get multiple values from cache
         * 
         * @param array $keys Array of cache keys
         * @param string $group Cache group 
         * @param bool $force Force fresh lookup
         * @return array Array of values keyed by cache key
         */
        public function get_multiple($keys, $group = 'default', $force = false) {
            $group = $group ?: 'default';
            $results = [];
            
            foreach ((array)$keys as $key) {
                $results[$key] = $this->get($key, $group, $force);
            }
            
            return $results;
        }

        /**
         * Set multiple values to cache
         * 
         * @param array $data Associative array of key => value pairs
         * @param string $group Cache group
         * @param int $expire Expiration time
         * @return array Array of success/failure results keyed by cache key
         */
        public function set_multiple($data, $group = 'default', $expire = 0) {
            $group = $group ?: 'default';
            $results = [];
            
            foreach ((array)$data as $key => $value) {
                $results[$key] = $this->set($key, $value, $group, $expire);
            }
            
            return $results;
        }

        /**
         * Delete multiple values from cache
         * 
         * @param array $keys Array of cache keys
         * @param string $group Cache group
         * @return array Array of success/failure results keyed by cache key
         */
        public function delete_multiple($keys, $group = 'default') {
            $group = $group ?: 'default';
            $results = [];
            
            foreach ((array)$keys as $key) {
                $results[$key] = $this->delete($key, $group);
            }
            
            return $results;
        }

        /**
         * Increment a numeric cache value
         * 
         * @param string $key Cache key
         * @param int $offset Amount to increment by
         * @param string $group Cache group
         * @return int|false New value on success, false on failure
         */
        public function incr($key, $offset = 1, $group = 'default') {
            $group = $group ?: 'default';
            
            // For excluded groups, try to get from runtime and increment
            if ($this->is_excluded_group($group)) {
                $current = $this->runtime_get($group, $key, $found);
                if ($found && is_numeric($current)) {
                    $new_value = $current + $offset;
                    $this->runtime_set($group, $key, $new_value);
                    return $new_value;
                }
                return false;
            }
            
            if (!$this->can_persist_to_redis($group, $key)) {
                // Fallback to runtime increment
                $current = $this->runtime_get($group, $key, $found);
                if ($found && is_numeric($current)) {
                    $new_value = $current + $offset;
                    $this->runtime_set($group, $key, $new_value);
                    return $new_value;
                }
                return false;
            }
            
            $k = $this->k($key, $group);
            try {
                $result = $this->redis->incrBy($k, $offset);
                if ($result !== false) {
                    $this->runtime_set($group, $key, $result);
                }
                return $result;
            } catch (\Throwable $e) {
                $this->bypass = true;
                return false;
            }
        }

        /**
         * Decrement a numeric cache value
         * 
         * @param string $key Cache key
         * @param int $offset Amount to decrement by
         * @param string $group Cache group
         * @return int|false New value on success, false on failure
         */
        public function decr($key, $offset = 1, $group = 'default') {
            $group = $group ?: 'default';
            
            // For excluded groups, try to get from runtime and decrement
            if ($this->is_excluded_group($group)) {
                $current = $this->runtime_get($group, $key, $found);
                if ($found && is_numeric($current)) {
                    $new_value = $current - $offset;
                    $this->runtime_set($group, $key, $new_value);
                    return $new_value;
                }
                return false;
            }
            
            if (!$this->can_persist_to_redis($group, $key)) {
                // Fallback to runtime decrement
                $current = $this->runtime_get($group, $key, $found);
                if ($found && is_numeric($current)) {
                    $new_value = $current - $offset;
                    $this->runtime_set($group, $key, $new_value);
                    return $new_value;
                }
                return false;
            }
            
            $k = $this->k($key, $group);
            try {
                $result = $this->redis->decrBy($k, $offset);
                if ($result !== false) {
                    $this->runtime_set($group, $key, $result);
                }
                return $result;
            } catch (\Throwable $e) {
                $this->bypass = true;
                return false;
            }
        }

        public function add_global_groups($groups) {
            foreach ((array)$groups as $g) { if (!in_array($g, $this->global_groups, true)) { $this->global_groups[] = $g; } }
        }
        public function add_non_persistent_groups($groups) {
            foreach ((array)$groups as $g) { if (!in_array($g, $this->non_persistent_groups, true)) { $this->non_persistent_groups[] = $g; } }
        }
        public function switch_to_blog($blog_id) {
            $this->blog_prefix = (is_multisite()? $blog_id . ':' : '1:');
            $this->runtime = [];
        }
        public function reset() {}
        public function close() { if ($this->redis) { try { $this->redis->close(); } catch (\Throwable $t) {} } }
        public function get_redis() { return $this->redis; }

        // Health helpers
        public function is_connected() { return (bool)$this->connected && ($this->redis instanceof \Redis); }
        public function is_active()    { return $this->is_connected() && !$this->bypass; }
        public function is_bypassed()  { return (bool)$this->bypass; }
        public function connection_details() {
            return [
                'connected' => $this->is_connected(),
                'active'    => $this->is_active(),
                'via'       => $this->connect_via,
                'bypassed'  => $this->is_bypassed(),
                'error'     => $this->connect_error,
                'stats'     => $this->get_runtime_stats(),
            ];
        }
    }
}

function wp_cache_init() {
    global $wp_object_cache, $ace_redis_shared_connection;
    $wp_object_cache = new WP_Object_Cache();
    $ace_redis_shared_connection = method_exists($wp_object_cache, 'get_redis') ? $wp_object_cache->get_redis() : null;
}

function wp_cache_get($key, $group = '', $force = false, &$found = null) {
    global $wp_object_cache;
    
    // W3TC-style notoptions handling is built into the class get() method
    return $wp_object_cache->get($key, $group, $force, $found);
}

function wp_cache_set($key, $data, $group = '', $expire = 0) { global $wp_object_cache; return $wp_object_cache->set($key, $data, $group, $expire); }
function wp_cache_add($key, $data, $group = '', $expire = 0) { global $wp_object_cache; return $wp_object_cache->add($key, $data, $group, $expire); }
function wp_cache_delete($key, $group = '') { global $wp_object_cache; return $wp_object_cache->delete($key, $group); }
function wp_cache_flush() { global $wp_object_cache; return $wp_object_cache->flush(); }

// Batch operation functions
function wp_cache_get_multiple($keys, $group = 'default', $force = false) {
    global $wp_object_cache;
    return $wp_object_cache->get_multiple($keys, $group, $force);
}

function wp_cache_set_multiple($data, $group = 'default', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->set_multiple($data, $group, $expire);
}

function wp_cache_delete_multiple($keys, $group = 'default') {
    global $wp_object_cache;
    return $wp_object_cache->delete_multiple($keys, $group);
}

// Group and runtime flush functions
function wp_cache_supports($feature) {
    global $wp_object_cache;
    return $wp_object_cache->supports($feature);
}

function wp_cache_flush_group($group) {
    global $wp_object_cache;
    return $wp_object_cache->flush_group($group);
}

function wp_cache_flush_runtime() {
    global $wp_object_cache;
    return $wp_object_cache->flush_runtime();
}

// Standard WordPress cache functions
function wp_cache_incr($key, $offset = 1, $group = '') { global $wp_object_cache; return $wp_object_cache->incr($key, $offset, $group); }
function wp_cache_decr($key, $offset = 1, $group = '') { global $wp_object_cache; return $wp_object_cache->decr($key, $offset, $group); }
function wp_cache_add_global_groups($groups) { global $wp_object_cache; return $wp_object_cache->add_global_groups($groups); }
function wp_cache_add_non_persistent_groups($groups) { global $wp_object_cache; return $wp_object_cache->add_non_persistent_groups($groups); }
function wp_cache_switch_to_blog($blog_id) { global $wp_object_cache; return $wp_object_cache->switch_to_blog($blog_id); }
function wp_cache_close() { global $wp_object_cache; return $wp_object_cache && method_exists($wp_object_cache, 'close') ? $wp_object_cache->close() : false; }
function wp_cache_reset() { global $wp_object_cache; return $wp_object_cache && method_exists($wp_object_cache, 'reset') ? $wp_object_cache->reset() : false; }

function wp_cache_get_runtime_stats() {
    global $wp_object_cache;
    if ($wp_object_cache && method_exists($wp_object_cache, 'get_runtime_stats')) {
        return $wp_object_cache->get_runtime_stats();
    }

    return [];
}

function wp_cache_reset_runtime_stats() {
    global $wp_object_cache;
    if ($wp_object_cache && method_exists($wp_object_cache, 'reset_runtime_stats')) {
        return $wp_object_cache->reset_runtime_stats();
    }

    return false;
}
