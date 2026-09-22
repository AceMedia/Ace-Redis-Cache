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

if (!function_exists('ace_oc_is_public_ajax')) {
    /**
     * True for anonymous, non-mutating requests to admin-ajax.php / admin-post.php — the two
     * wp-admin endpoints that serve PUBLIC (wp_ajax_nopriv / admin_post_nopriv) traffic.
     *
     * Such requests are cacheable exactly like an anonymous front-end GET, but their /wp-admin/
     * URL otherwise makes every layer of this drop-in treat them as editorial and skip Redis, so
     * any wp_cache write in a nopriv handler (e.g. Ace-Community-Events' event feed + venue/event
     * detail) silently never persisted. This ONE predicate is the authoritative classifier shared
     * by the top-of-file bypass and the constructor's request classification so they can't drift.
     * It uses only super-globals (available before WP boots).
     *
     * Escape hatch: define ACE_OC_NO_PUBLIC_AJAX truthy in wp-config to force the old behaviour.
     */
    function ace_oc_is_public_ajax() {
        if (defined('ACE_OC_NO_PUBLIC_AJAX') && ACE_OC_NO_PUBLIC_AJAX) { return false; }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'GET' && $method !== 'HEAD') { return false; } // POST ajax = mutation

        $uri    = $_SERVER['REQUEST_URI'] ?? '';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $is_ep  = (strpos($uri, 'admin-ajax.php') !== false || strpos($script, 'admin-ajax.php') !== false
                || strpos($uri, 'admin-post.php') !== false || strpos($script, 'admin-post.php') !== false);
        if (!$is_ep) { return false; }

        // Anonymous only: no WP auth cookie, no WooCommerce customer session (cart-bearing guests
        // render dynamic markup). Cookie-name prefixes are stable across WP/Woo versions.
        $dynamic_prefixes = [
            'wordpress_logged_in_', 'wordpress_sec_', 'wordpressuser_', 'wp-postpass_',
            'wp_woocommerce_session_', 'woocommerce_items_in_cart', 'woocommerce_cart_hash',
        ];
        foreach (array_keys($_COOKIE) as $ck) {
            foreach ($dynamic_prefixes as $p) {
                if (strpos((string) $ck, $p) === 0) { return false; }
            }
        }
        return true;
    }
}

if (!function_exists('ace_oc_is_member_front_ajax')) {
    /**
     * A signed-in visitor's front-end admin-ajax GET (map layers, live panels, polling).
     *
     * admin-ajax.php sits under /wp-admin/, so these were treated as editorial and ran with no
     * Redis at all: every option, post and term the guests already cached was re-read from the
     * database on every poll (~1-3s each on sheff.events). They are reads of shared data, so
     * they get member read mode like a signed-in page view: read Redis, never persist. A call
     * made from a wp-admin screen (Referer under /wp-admin/) keeps the strict behaviour.
     */
    function ace_oc_is_member_front_ajax() {
        if (defined('ACE_OC_MEMBER_READ') && !ACE_OC_MEMBER_READ) { return false; }
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'GET' && $method !== 'HEAD') { return false; }
        $uri    = $_SERVER['REQUEST_URI'] ?? '';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if (strpos($uri, 'admin-ajax.php') === false && strpos($script, 'admin-ajax.php') === false) { return false; }
        if (strpos((string) ($_SERVER['HTTP_REFERER'] ?? ''), '/wp-admin/') !== false) { return false; }
        foreach (array_keys($_COOKIE) as $ck) {
            if (strpos((string) $ck, 'wordpress_logged_in_') === 0) { return true; }
        }
        return false;
    }
}

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

// Safer: just force cache bypass for admin/login requests — EXCEPT anonymous public AJAX, which is
// real guest traffic served through admin-ajax.php and must stay cache-eligible (see helper above).
if ($is_admin_req && !ace_oc_is_public_ajax() && !ace_oc_is_member_front_ajax() && !defined('ACE_OC_BYPASS')) {
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

        // The object-cache drop-in loads in wp-settings.php BEFORE WP_PLUGIN_DIR is
        // defined; referencing it directly is a fatal on PHP 8. Fall back to the
        // conventional plugin path derived from WP_CONTENT_DIR (or ABSPATH).
        if (defined('WP_PLUGIN_DIR')) {
            $plugin_dir = WP_PLUGIN_DIR;
        } elseif (defined('WP_CONTENT_DIR')) {
            $plugin_dir = WP_CONTENT_DIR . '/plugins';
        } else {
            $plugin_dir = ABSPATH . 'wp-content/plugins';
        }

        $result = file_exists($plugin_dir . '/woocommerce/woocommerce.php');

        return $result;
    }
}

if (!class_exists('WP_Object_Cache')) {
    class WP_Object_Cache {
        private const MAX_VALUE_BYTES_DEFAULT = 65536;
        private const MAX_VALUE_BYTES_WC = 32768;

        // Per-group ceilings that override the general guard. The general 64KB cap
        // exists to catch accidental bloat (full WP_Post dumps, runaway transients),
        // but some first-party groups legitimately store one large computed payload:
        // Ace-Community-Events caches its ENTIRE feed window (~1k events, several MB
        // serialised) under one versioned key so paged AJAX requests slice it instead
        // of re-running the query per page. Local Redis handles values this size in
        // single-digit ms; without the override the write is silently skipped and
        // every feed request repays the full multi-second compute.
        private const MAX_VALUE_BYTES_BY_GROUP = [
            'ace_events' => 8388608, // 8MB
            'ace_te'     => 1048576, // 1MB
            // 'alloptions' is the only options-group key that persists (see is_excluded_key)
            // and on any real site it is well over 64KB (sheff.events: 351 autoloaded rows,
            // ~150KB), so the general cap silently dropped it on every request and every
            // request re-read the whole options table from MySQL. Found 11 Sept 2026.
            'options'    => 1048576, // 1MB
        ];

        // On-wire format version. Every value we persist is wrapped as
        // ['__aceoc'=>WIRE_VERSION,'d'=>data] so reads can reject anything we did
        // not write (foreign serializer/compression, stale-format keys from a prior
        // deploy, corruption) as a cache MISS instead of returning a corrupt type.
        private const WIRE_VERSION = 2;

        // Backstop TTL (seconds) for writes that pass no explicit expiry. WP's object
        // cache normally stores with no TTL, so keys live forever in Redis/ElastiCache
        // and accumulate across deploys (the stale-key build-up behind the foreign-format
        // corruption above). Invalidation is still event-driven (namespace flush on save);
        // this only bounds how long an untouched key can linger. Persistent-group values
        // are all DB-derived, so expiry just forces a cheap regeneration on the next miss.
        private const DEFAULT_TTL = 604800; // 7 days

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
            // First-party context caches. These MUST live in the default const, not the
            // ace_oc_persistent_groups filter: that filter is applied in __construct()
            // (wp_start_object_cache, before plugins load), so a plugin add_filter() is too late.
            'ace_te', // ace-teams-events per-post event context (build_post_event_context)
            'ace_events', // Ace-Community-Events feed + venue/event detail cache (versioned, self::cache_get)
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
        protected $invalidate_connect_failed = false; // see invalidate_persistent()
        protected $wc_read_mode = false; // cart/checkout: read shared groups from Redis, never persist
        protected $member_read_mode = false; // signed-in front end: read shared groups from Redis, never persist
        protected $redis_loaded = []; // group => key => md5 of what Redis handed us this request
        protected $signed_in = false;
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
                preg_match('#[?&]rest_route=(?:%2F|/)?wc(?:%2F|/)store(?:%2F|/)v1(?:%2F|/)(cart|checkout)(?:%2F|/|[&\#]|$)#i', (string) $request_uri) === 1
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
                'member_read'  => false, // set below once the bypass decision is known
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
            // Anonymous public AJAX (admin-ajax.php nopriv, GET, no auth/wc cookie) is guest traffic,
            // not editorial — keep it cache-eligible. Shared classifier so gate 1 (top-of-file),
            // this, and the init gate below never disagree. The filter still has final say.
            if (ace_oc_is_public_ajax()) { $editor_bypass = false; }
            $editor_bypass   = apply_filters('ace_rc_object_cache_bypass', $editor_bypass, [
                'is_admin_url'  => $is_admin_by_url,
                'is_ajax'       => $is_ajax,
                'is_admin'      => $is_admin_req,
                'is_logged_in'  => $is_logged_in_fn || $is_logged_in_cookie,
                'is_rest'       => $is_rest,
                'is_wc_customer_session_request' => $is_wc_customer_session_request,
            ]);
            if ($editor_bypass || $this->suspend_persistent_writes) { $this->bypass = true; }

            // WC shared-read mode (opt-in via ACE_OC_WC_READ). Cart/checkout/Store-API requests are
            // session-bearing, so they bypass PERSISTENCE (bypass stays true — nothing cart-specific is
            // ever written to Redis, and sessions stay DB-backed via the woocommerce_sessions bypass
            // group). But without help they also skip Redis ENTIRELY and re-run ~150 queries per request
            // for shared data (products/options/terms/shipping/tax) that front-end traffic already
            // cached. Read-mode lets them CONNECT and READ those shared persistent groups from Redis
            // while still not persisting — collapsing the query storm to cache hits. Product/price
            // freshness equals the front end (same object-cache invalidation on save). Default OFF.
            $wc_read_mode = $is_wc_customer_session_request
                && defined('ACE_OC_WC_READ') && ACE_OC_WC_READ;
            $this->wc_read_mode = $wc_read_mode;

            if ($editor_bypass && !$wc_read_mode) {
                $this->runtime_only_mode = true;
                if (!defined('ACE_OC_RUNTIME_ONLY')) {
                    define('ACE_OC_RUNTIME_ONLY', true);
                }
            }

            // Member read mode (default on; define ACE_OC_MEMBER_READ false to turn it off). A
            // signed-in visitor reading the front end is runtime-only, and without help that means
            // no Redis at all: a page that costs a guest 20 queries costs a member 700, because
            // every post, term, option and transient the guests already cached is re-read from the
            // database. The data in the persistent groups is shared (posts, terms, options,
            // transients, first-party context caches) and identical for every visitor, so a member
            // page view may READ it. It still never persists: writes stay in this process and
            // invalidate as before, so nothing member-specific can ever reach the shared cache.
            // Admin, AJAX, REST, cron, update and cart requests keep the strict behaviour, except a
            // signed-in front-end admin-ajax GET (see ace_oc_is_member_front_ajax()).
            $is_member_front_ajax = ace_oc_is_member_front_ajax();
            $member_read_mode = $this->runtime_only_mode
                && ($is_logged_in_fn || $is_logged_in_cookie)
                && ($is_member_front_ajax || (!$is_admin_by_url && !$is_admin_req && !$is_ajax && !$is_rest))
                && !$is_cron
                && !$is_update_operation && !$is_wc_customer_session_request
                && in_array($request_method, $allow_methods, true)
                && (!defined('ACE_OC_MEMBER_READ') || ACE_OC_MEMBER_READ);
            $this->member_read_mode = (bool) apply_filters('ace_oc_member_read_mode', $member_read_mode, $this->request_context);
            $this->request_context['member_read'] = $this->member_read_mode;
            $this->signed_in = ($is_logged_in_fn || $is_logged_in_cookie);
            if (!$is_cli) { register_shutdown_function([$this, 'log_slow_request']); }

            // Anonymous public AJAX stays cache-eligible so init_redis() actually runs for it
            // (same shared classifier as the two bypass gates above).
            $is_admin_system_request = ($is_admin_by_url || $is_admin_req || $is_ajax || $is_rest) && !ace_oc_is_public_ajax() && !$wc_read_mode && !$this->member_read_mode;

            $blog_id           = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
            $this->blog_prefix = (is_multisite() ? $blog_id . ':' : '1:');

            // Per-site salt: multiple single-site installs can share one Redis DB,
            // so isolate this site's keys (and its `namespace.*` flush pattern) from
            // the others. Honour WP_CACHE_KEY_SALT, else derive from DB_NAME/prefix.
            if ($this->namespace === 'ace:') {
                $salt_src = (defined('WP_CACHE_KEY_SALT') ? WP_CACHE_KEY_SALT : '')
                    . '|' . (defined('DB_NAME') ? DB_NAME : '')
                    . '|' . (isset($GLOBALS['table_prefix']) ? $GLOBALS['table_prefix'] : '');
                if (trim($salt_src, '|') !== '') {
                    $this->namespace = 'ace:' . substr(md5($salt_src), 0, 10) . ':';
                }
            }

            if (function_exists('ace_object_cache_is_woocommerce') && ace_object_cache_is_woocommerce()) {
                $this->apply_woocommerce_profile();
            }

            // Only initialize Redis for cache-eligible requests.
            // Logged-in/admin/system/bypass requests should not pay Redis bootstrap cost.
            // WC read-mode connects despite bypass (to serve shared reads, no persistence).
            if (extension_loaded('redis') && !$is_admin_system_request && (!$this->bypass || $wc_read_mode || $this->member_read_mode)) {
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

        protected function exceeds_max_value_size($data, $group = null) {
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

            // First-party large-payload groups get their own ceiling (see const docs).
            $limit = ($group !== null && isset(self::MAX_VALUE_BYTES_BY_GROUP[$group]))
                ? self::MAX_VALUE_BYTES_BY_GROUP[$group]
                : (int) $this->max_value_bytes;

            return strlen($payload) > $limit;
        }

        /**
         * One line per slow request (ACE_OC_SLOW_LOG_MS, default 1500; 0 turns it off) in
         * ace-requests.log beside the pool's PHP error log, or ACE_OC_SLOW_LOG. Tab-separated:
         * time, ms, method, member|guest, cache mode, queries, Redis hits/misses/member reads,
         * peak memory, status, path (query string dropped except an AJAX action). The FPM slow
         * log says where a request stalled; this says who paid and whether the cache helped.
         */
        public function log_slow_request() {
            $min = defined('ACE_OC_SLOW_LOG_MS') ? (int) ACE_OC_SLOW_LOG_MS : 1500;
            $ms  = (int) round((microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000);
            if ($min <= 0 || $ms < $min) { return; }
            $file = defined('ACE_OC_SLOW_LOG') ? (string) ACE_OC_SLOW_LOG : '';
            if ($file === '') {
                $err = (string) ini_get('error_log');
                if ($err === '' || !is_dir(dirname($err))) { return; }
                $file = dirname($err) . '/ace-requests.log';
            }
            $mode = $this->member_read_mode ? 'member-read'
                : ($this->wc_read_mode ? 'wc-read'
                : ($this->runtime_only_mode || $this->bypass ? 'no-redis'
                : ($this->redis !== null && $this->connected ? 'redis' : 'no-conn')));
            $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            if (isset($_REQUEST['action']) && is_string($_REQUEST['action'])) { $path .= '?action=' . substr($_REQUEST['action'], 0, 60); }
            global $wpdb;
            $line = sprintf("%s\t%d\t%s\t%s\t%s\tq=%d\thit=%d\tmiss=%d\tmr=%d\tmem=%dM\t%d\t%s\n",
                gmdate('Y-m-d\TH:i:s\Z'), $ms, strtoupper($_SERVER['REQUEST_METHOD'] ?? '-'),
                $this->signed_in ? 'member' : 'guest', $mode,
                isset($wpdb->num_queries) ? (int) $wpdb->num_queries : -1,
                $this->stats['redis_hits'] ?? 0, $this->stats['redis_misses'] ?? 0, $this->stats['member_read_hits'] ?? 0,
                (int) round(memory_get_peak_usage(true) / 1048576), (int) http_response_code(), substr($path, 0, 200));
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
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
         * Cache flush for post changes — object-cache namespace only.
         * Invalidates our cached objects so the frontend reflects the change, WITHOUT
         * touching keys we don't own.
         */
        protected function flush_everything_for_post($post_id) {
            $this->runtime = [];

            // Do NOT flushDB(): ElastiCache Serverless is a single shared DB that also
            // holds the page-cache plugin keys (page_cache:/page_cache_min:/page_cache_meta:/
            // dyn_block:/ace_rc:). flushDB() here wipes the page cache on every publish →
            // stampede. Clear only our object-cache namespace; the page-cache plugin runs
            // its own save_post invalidation.
            $this->flush(true); // forced, namespace-only (scans/dels our namespace — see flush())
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

                // Pin to SERIALIZER_PHP unconditionally. Choosing igbinary-when-available split the
                // object cache by context: FPM (igbinary) vs any plain/8.2 WP-CLI entrypoint (PHP) wrote
                // formats the other couldn't read, so cross-context entries became foreign-format misses
                // → extra DB load. PHP serializer is available in every SAPI, so one format everywhere.
                // Cutover is safe without a perfectly-timed flush: existing igbinary entries simply read
                // as foreign-format misses and regenerate (a one-time ace:* flush off-peak just avoids the
                // gradual self-heal DB ramp). igbinary's compactness isn't worth the cross-context misses.
                $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
                $this->serializer_active = true;

                if (!empty($pass)) { @ $this->redis->auth($pass); }
                try { $this->redis->client('SETNAME', 'ace-object-cache'); } catch (\Throwable $t) {}

                // Per-site logical DB. ACE_REDIS_DB (wp-config constant) keeps this object-cache
                // connection, the page-cache connection, and advanced-cache in the SAME database for
                // this site. This IS the shared connection advanced-cache and the token-publish reuse,
                // so selecting here means the pre-boot early-serve reads hit the right DB too.
                $ace_db = defined('ACE_REDIS_DB') ? (int) ACE_REDIS_DB : 0;
                if ($ace_db > 0) { try { $this->redis->select($ace_db); } catch (\Throwable $t) {} }

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

        /**
         * A write made in runtime-only mode (cron, admin, update operations) lands in this
         * process only, and the copy in Redis stays as it was until it expires. Once
         * alloptions persisted (0.7.17) that meant a system-cron run rescheduled every event
         * in the database while wp-cli and the front end kept reading a cron array in which
         * all 49 sat overdue for hours, and any option a cron job saved was stale on the site
         * for the rest of its TTL. So a runtime-only write deletes the persistent key (and
         * alloptions, which carries every autoloaded option) and lets the next normal-mode
         * request rebuild it from the database. One lazy connection per process, only when
         * something persistent is actually written.
         */
        protected function invalidate_persistent($group, $key, $data = null, $is_write = false) {
            $group = $group ?: 'default';
            if (!$this->is_persistent_group($group) || $this->is_bypass_group($group) || $this->is_excluded_group($group)) return;
            // A cache fill that re-sets exactly what Redis handed this request is not a change:
            // deleting the key would only make the next guest rebuild it. Only a different value
            // (a real update) or a delete invalidates.
            if ($is_write && isset($this->redis_loaded[$group][$key]) && $this->redis_loaded[$group][$key] === md5(serialize($data))) {
                $this->stat_inc('runtime_only_fills_kept');
                return;
            }
            if (!extension_loaded('redis') || $this->invalidate_connect_failed) return;
            if ($this->redis === null) {
                try { $this->init_redis(); } catch (\Throwable $e) {}
                if ($this->redis === null) { $this->invalidate_connect_failed = true; return; }
            }
            try {
                $this->redis->del($this->k($key, $group));
                // alloptions carries every autoloaded option, so a change to one of those must drop
                // it too. WordPress rewrites alloptions itself on such an update (a set of the
                // 'alloptions' key, handled by the line above); this covers the option's own key
                // when it is one the loaded alloptions copy holds. A miss-then-fill of some other
                // option must not drop alloptions: that was hundreds of deletes per member page view.
                if ($group === 'options' && $key !== 'alloptions') {
                    $all = $this->runtime_get('options', 'alloptions', $all_found);
                    if (!$all_found || (is_array($all) && array_key_exists($key, $all))) {
                        $this->redis->del($this->k('alloptions', 'options'));
                    }
                }
                $this->stat_inc('runtime_only_invalidations');
            } catch (\Throwable $e) {}
        }

        protected function use_runtime_only_mode() {
            return (bool) $this->runtime_only_mode;
        }

        /**
         * Member read mode: one Redis read of a shared, persistent, non-bypass group key for a
         * signed-in front-end request. Never writes. Remembers what came back so a later
         * cache fill of the identical value is not mistaken for a change (see invalidate_persistent).
         */
        protected function member_read($group, $key, &$found) {
            $found = false;
            if ($this->redis === null || !$this->connected) { return false; }
            if (!$this->is_persistent_group($group) || $this->is_bypass_group($group) || $this->is_excluded_group($group)) { return false; }
            if ($group === 'options' && $key === 'ace_redis_cache_settings') { return false; }
            try {
                $raw = $this->redis->get($this->k($key, $group));
            } catch (\Throwable $e) {
                $this->stat_inc('redis_errors');
                return false;
            }
            if ($raw === false || $raw === null) { $this->stat_inc('redis_misses'); return false; }
            $val = $this->decode_from_store($raw);
            if ($val === $this->decode_miss_token()) { $this->stat_inc('foreign_format_misses'); return false; }
            if ($this->should_block_post_object_persistence($group, $val)) { return false; }
            $this->runtime_set($group, $key, $val);
            $this->redis_loaded[$group][$key] = md5(serialize($val));
            $this->stat_inc('member_read_hits');
            $found = true;
            return $val;
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
                // notoptions is per-request bookkeeping and must never be shared.
                if ($key === 'notoptions') {
                    return true;
                }
                // Every other option persists. Non-autoloaded options (600 of them on
                // sheff.events) were runtime-only "to prevent cache pollution", which meant
                // each one read on a page was a MySQL query on every uncached request. They are
                // plain DB rows like alloptions: update_option and delete_option keep the key
                // current, admin and cron writes invalidate it, and the backstop TTL bounds it.
                return false;
            }
            
            // Transients: persist by DEFAULT (denylist, not allowlist).
            //
            // The previous allowlist only kept feed/seo/term/paypal transients and dropped everything
            // else to runtime-only. That had two bad effects: (1) WP's 'doing_cron' lock was never
            // persisted, so wp-cron couldn't hold its mutex across runs (cron broke/stacked), and (2) the
            // vast majority of get_transient() calls became permanent misses that hammered the DB on every
            // page. Transients carry their own TTL, so persisting them is safe — exclude only specific
            // high-cardinality / per-request bloat.
            if ($g === 'transient') {
                // Infra locks/flags that MUST persist across requests (belt-and-braces; covered by the
                // default-persist below, but explicit so a future allowlist can't silently drop them).
                if ($key === 'doing_cron' || strpos($key, 'doing_cron') !== false) {
                    return false;
                }

                // Optional denylist of known per-request / high-cardinality bloat (extend after auditing).
                static $transient_excludes = array();
                foreach ($transient_excludes as $needle) {
                    if ($needle !== '' && strpos($key, $needle) !== false) {
                        return true; // runtime-only
                    }
                }

                return false; // persist
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
            // WC read-mode is strictly read-only: a cart/checkout request may READ shared data from
            // Redis but must NEVER write (not even write-through groups), so no cart/order/session
            // state can ever leak into the shared cache. The cache is warmed only by front-end traffic.
            if ($this->wc_read_mode) { return false; }
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
                    $this->invalidate_persistent($group, $key, $data, true);
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

            if ($this->exceeds_max_value_size($data, $group)) {
                $this->stat_inc('oversize_skips');
                if (!isset($this->runtime[$group][$key])) { $this->runtime_set($group, $key, $data); return true; }
                return false;
            }

            if (!$this->can_persist_to_redis($group, $key)) {
                if (!isset($this->runtime[$group][$key])) { $this->runtime_set($group, $key, $data); return true; }
                return false;
            }
            $k = $this->k($key, $group);
            $payload = $this->encode_for_store($data);
            try {
                $ok = (bool)$this->redis->set($k, $payload, ['nx','ex'=>$this->effective_ttl($expire)]);
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
                $this->invalidate_persistent($group, $key, $data, true);
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
            $is_oversize = $this->exceeds_max_value_size($data, $group);

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
            $payload = $this->encode_for_store($data);
            try {
                $redis_start = microtime(true);
                $result = (bool)$this->redis->setex($k, $this->effective_ttl($expire), $payload);
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
                // $force means "skip the local copy and re-read the persistent layer". In runtime-only
                // mode there is no persistent layer, so the local copy is the only truth and must be
                // returned. Honouring $force here answered false to wp-cron's _get_cron_lock() (a forced
                // read of 'doing_cron' straight after set_transient()), so wp-cron.php concluded another
                // process held the lock and exited without running a single event. Cron runs are
                // classified as update operations and land in this mode, so every scheduled job on a
                // site with DISABLE_WP_CRON and a system cron stalled silently.
                $local = $this->runtime_get($group, $key, $local_found);
                if ($local_found) {
                    $found = true;
                    $this->stat_inc('local_hits');
                    return $local;
                }
                if ($this->member_read_mode) {
                    $val = $this->member_read($group, $key, $found);
                    if ($found) { return $val; }
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

            // Excluded keys never touch Redis, but they must still answer from the in-request
            // store: returning a miss here meant every get_option() of a non-autoloaded option
            // hit MySQL again each time it was asked (one option 30 times in one request) and
            // WordPress's notoptions list never stuck, so 160 of a guest page's 260 queries
            // were the same handful of options over and over.
            if ($this->is_excluded_group($group) || $this->is_excluded_key($group, $key)) {
                $local = $this->runtime_get($group, $key, $local_found);
                if ($local_found) { $found = true; $this->stat_inc('local_hits'); return $local; }
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
            // wp-cron's lock is written by this process and read straight back with $force
            // (_get_cron_lock), and re-read after every hook. If the persistent layer cannot
            // answer (bypass, no connection, or a flush in between), the copy this process
            // wrote is still the truth; answering false tells wp-cron another process holds
            // the lock and it abandons the run.
            $cron_lock_fallback = ($local_found && $group === 'transient' && $key === 'doing_cron') ? $local : null;

            if (!$this->is_persistent_group($group)) {
                $this->stat_inc('non_persistent_group_short_circuit');
                $found = false;
                return false;
            }

            // Special handling for site-transients: allow them even during bypass (admin)
            $allow_during_bypass = ($group === 'site-transient') && !$this->suspend_persistent_writes;

            // WC read-mode: cart/checkout bypass PERSISTENCE but may still READ shared persistent
            // groups from Redis (products/options/terms/shipping). is_persistent_group() was already
            // asserted above, and session/cart groups are bypass groups (returned earlier), so this
            // only ever serves shared, front-end-cached data — never cart-specific state.
            $allow_read = $allow_during_bypass || $this->wc_read_mode;

            if (($this->bypass && !$allow_read) || $this->redis === null) {
                if ($cron_lock_fallback !== null) { $found = true; return $cron_lock_fallback; }
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
                    if ($cron_lock_fallback !== null) { $found = true; return $cron_lock_fallback; }
                    $found = false; 
                    $this->stat_inc('redis_misses');
                    $this->prof_log('REDIS_MISS', $group . ':' . $key, (microtime(true) - $start_time) * 1000, sprintf(' redis=%.1fms', $redis_time));
                    return false; 
                }
                
                $out = $this->decode_from_store($val);

                // Value was not written by this drop-in (foreign serializer/compression,
                // stale wire version, or corruption). Treat as a miss so WordPress
                // regenerates from the DB instead of receiving a corrupt type
                // (e.g. a string where a WP_Term/WP_Post object is expected).
                if ($out === $this->decode_miss_token()) {
                    $found = false;
                    $this->stat_inc('foreign_format_misses');
                    $this->prof_log('FOREIGN_FORMAT_MISS', $group . ':' . $key, (microtime(true) - $start_time) * 1000, sprintf(' redis=%.1fms', $redis_time));
                    return false;
                }

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

        // Unique sentinel returned by decode_from_store() when a stored value is not
        // one we wrote (and therefore must be treated as a miss). Compared by identity.
        protected function decode_miss_token() {
            static $t = null;
            if ($t === null) { $t = new \stdClass(); }
            return $t;
        }

        // Wrap a value for storage so reads can positively identify our own format.
        protected function encode_for_store($data) {
            return ['__aceoc' => self::WIRE_VERSION, 'd' => $data];
        }

        // Normalise an expiry: callers passing 0 / no expiry get the bounded DEFAULT_TTL
        // backstop rather than an immortal key.
        protected function effective_ttl($expire) {
            $expire = (int) $expire;
            return $expire > 0 ? $expire : self::DEFAULT_TTL;
        }

        // Unwrap a stored value. Returns the original data for values we wrote,
        // or the miss sentinel for anything unrecognized (foreign format, stale
        // wire version, corruption) — phpredis hands back raw strings/false for
        // bytes it cannot unserialize, which we reject here rather than pass to WP.
        protected function decode_from_store($val) {
            if (is_array($val)
                && array_key_exists('__aceoc', $val)
                && $val['__aceoc'] === self::WIRE_VERSION
                && array_key_exists('d', $val)) {
                return $val['d'];
            }
            return $this->decode_miss_token();
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
                $this->invalidate_persistent($group, $key);
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
        /**
         * Empty the runtime store but keep wp-cron's lock: a flush fired by a hook mid-run
         * (Action Scheduler and friends do) must not make the run look like it lost its lock.
         */
        protected function reset_runtime() {
            $lock = $this->runtime['transient']['doing_cron'] ?? null;
            $this->runtime = [];
            if ($lock !== null) { $this->runtime['transient']['doing_cron'] = $lock; }
        }

        public function flush($force = false) {
            $this->reset_runtime();
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
            $this->reset_runtime();
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
            
            // Clear from runtime (keeping wp-cron's lock, see reset_runtime)
            $lock = ($group === 'transient') ? ($this->runtime['transient']['doing_cron'] ?? null) : null;
            unset($this->runtime[$group]);
            if ($lock !== null) { $this->runtime['transient']['doing_cron'] = $lock; }
            
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
                    $this->invalidate_persistent($group, $key);
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
                    $this->invalidate_persistent($group, $key);
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
                    $this->invalidate_persistent($group, $key);
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
                    $this->invalidate_persistent($group, $key);
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
