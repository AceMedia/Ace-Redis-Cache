<?php
/**
 * Ace Redis Cache Drop-In (object-cache.php)
 *
 * Minimal WP_Object_Cache implementation forwarding to Redis via phpredis.
 * This is intended for dev/staging usage. For production, consider a full-featured drop-in.
 *
 * Signature: Ace Redis Cache Drop-In
 */

if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_Object_Cache')) {
    class WP_Object_Cache {
        protected $redis;
        protected $global_groups = [
            // WordPress typical global groups
            'users', 'userlogins', 'useremail', 'usermeta', 'site-transient', 'blog-details', 'blog-id-cache', 'rss', 'comment', 'counts'
        ];
        protected $non_persistent_groups = [];
        protected $blog_prefix = '';
        protected $namespace = 'ace:'; // top-level namespace
        protected $runtime = [];       // in-process fallback store [group][key] => value
        protected $bypass = false;     // request-scoped fail-open flag
        protected $slow_threshold_ms = 100; // profiling threshold
    protected $igbinary = false;
    protected $serializer_active = false; // phpredis serializer engaged
    protected $connected = false;
    protected $connect_via = null; // 'socket' or 'tcp'
    protected $connect_error = null;

        public function __construct() {
            // Emergency bypass constant or query param
            $emergency_bypass = (defined('ACE_OC_BYPASS') && ACE_OC_BYPASS) || (isset($_GET['ace_oc_bypass']) && $_GET['ace_oc_bypass'] == '1');
            if ($emergency_bypass) { $this->bypass = true; }
            // Auto-bypass for logged-in / admin / REST (editor) interactions to avoid interfering with authoring.
            // We cannot always rely on pluggable functions this early, so fall back to cookie heuristic.
            $logged_in_cookie = defined('LOGGED_IN_COOKIE') ? (LOGGED_IN_COOKIE) : 'wordpress_logged_in_';
            $is_logged_in_cookie = false;
            foreach ($_COOKIE as $ck => $val) { if (strpos($ck, $logged_in_cookie) === 0) { $is_logged_in_cookie = true; break; } }
            $is_admin_req = (function_exists('is_admin') && is_admin());
            $is_logged_in_fn = (function_exists('is_user_logged_in') && is_user_logged_in());
            $is_rest = (defined('REST_REQUEST') && REST_REQUEST);
            $editor_bypass = ($is_admin_req || $is_logged_in_fn || $is_logged_in_cookie || $is_rest);
            // Allow site owners to override decision.
            $editor_bypass = apply_filters('ace_rc_object_cache_bypass', $editor_bypass, [
                'is_admin' => $is_admin_req,
                'is_logged_in' => $is_logged_in_fn || $is_logged_in_cookie,
                'is_rest' => $is_rest,
            ]);
            if ($editor_bypass) { $this->bypass = true; }
            $blog_id = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
            $this->blog_prefix = (is_multisite() ? $blog_id . ':' : '1:');
            if (extension_loaded('redis')) { $this->init_redis(); }
        }

        protected function init_redis() {
            $socket = defined('ACE_REDIS_SOCKET') ? ACE_REDIS_SOCKET : '/var/run/redis/redis.sock';
            $host = defined('ACE_REDIS_HOST') ? ACE_REDIS_HOST : (defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1');
            $port = defined('ACE_REDIS_PORT') ? (int)ACE_REDIS_PORT : (defined('WP_REDIS_PORT') ? (int)WP_REDIS_PORT : 6379);
            $pass = defined('ACE_REDIS_PASSWORD') ? ACE_REDIS_PASSWORD : (defined('WP_REDIS_PASSWORD') ? WP_REDIS_PASSWORD : '');
            $timeout = 1.0; // tight connect timeout
            $attempted_socket = false;
            $use_tls_config = false;
            if (defined('ACE_REDIS_USE_TLS') && ACE_REDIS_USE_TLS) { $use_tls_config = true; }
            if (defined('WP_REDIS_SCHEME') && WP_REDIS_SCHEME === 'tls') { $use_tls_config = true; }
            if (defined('WP_REDIS_USE_TLS') && WP_REDIS_USE_TLS) { $use_tls_config = true; }
            try {
                $this->redis = new Redis();
                if (is_readable($socket)) {
                    $attempted_socket = true;
                    @$this->redis->connect($socket, 0, $timeout);
                    if (method_exists($this->redis,'isConnected') ? !$this->redis->isConnected() : false) {
                        $this->redis->close();
                    } else { $this->connect_via = 'socket'; }
                }
                // Primary TCP/TLS connect strategy
                if ($this->connect_via === null) {
                    $attempt_tls_first = $use_tls_config || (strpos($host, '.cache.amazonaws.com') !== false) || (strpos($host, '.cache.amazonaws.com') !== false) || (strpos($host, '.cache.amazonaws.com') !== false);
                    $plain_host = $host;
                    $tls_host = (str_starts_with($host, 'tls://')) ? $host : ('tls://' . $host);
                    $connected = false;
                    if ($attempt_tls_first) {
                        // TLS attempt
                        try {
                            $ctx = [];
                            if (defined('ACE_REDIS_VERIFY_TLS') && ACE_REDIS_VERIFY_TLS) {
                                $ctx = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]];
                            } else {
                                $ctx = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
                            }
                            @$this->redis->connect($tls_host, $port, $timeout, null, 0, 0, $ctx);
                            if (method_exists($this->redis,'isConnected') && $this->redis->isConnected()) { $this->connect_via = 'tls'; $connected = true; }
                        } catch (\Throwable $t) {}
                    }
                    if (!$connected) {
                        // Plain TCP fallback
                        try {
                            @$this->redis->connect($plain_host, $port, $timeout);
                            if (method_exists($this->redis,'isConnected') && $this->redis->isConnected()) { $this->connect_via = 'tcp'; $connected = true; }
                        } catch (\Throwable $t) {}
                    }
                    // Final attempt: if plain failed but TLS config requested and not yet tried
                    if (!$connected && !$attempt_tls_first && $use_tls_config) {
                        try {
                            $ctx = (defined('ACE_REDIS_VERIFY_TLS') && ACE_REDIS_VERIFY_TLS)
                                ? ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]
                                : ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
                            @$this->redis->connect($tls_host, $port, $timeout, null, 0, 0, $ctx);
                            if (method_exists($this->redis,'isConnected') && $this->redis->isConnected()) { $this->connect_via = 'tls'; $connected = true; }
                        } catch (\Throwable $t) {}
                    }
                }
                if ($this->connect_via === null) {
                    throw new \RuntimeException('Redis connection failed via ' . ($attempted_socket ? 'socket+tcp' : 'tcp'));
                }
                try { $this->redis->setOption(Redis::OPT_READ_TIMEOUT, 1.0); } catch (\Throwable $t) {}
                try { $this->redis->setOption(Redis::OPT_TIMEOUT, 1.0); } catch (\Throwable $t) {}
                if (defined('Redis::SERIALIZER_IGBINARY') && function_exists('igbinary_serialize')) {
                    $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY); $this->igbinary = true; $this->serializer_active = true;
                } else { $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP); $this->serializer_active = true; }
                if (!empty($pass)) { @ $this->redis->auth($pass); }
                try { $this->redis->client('SETNAME', 'ace-object-cache'); } catch (\Throwable $t) {}
                $this->connected = true;
            } catch (\Throwable $e) {
                $this->connect_error = $e->getMessage();
                $this->bypass = true; $this->redis = null;
            }
        }

        protected function is_global_group($group) {
            return in_array($group, $this->global_groups, true) || $group === 'site-transient';
        }

        protected function k($key, $group) {
            $g = $group ?: 'default';
            $scope = $this->is_global_group($g) ? 'g:' : $this->blog_prefix;
            return $this->namespace . $scope . $g . ':' . $key;
        }

        protected function prof_log($type, $key, $ms, $extra = '') {
            if (!defined('ACE_OC_PROF') || !ACE_OC_PROF) { return; }
            if ($ms < $this->slow_threshold_ms) { return; }
            $msg = sprintf('%s %s %.1fms%s', $type, $key, $ms, $extra);
            error_log($msg);
            // Increment slow op counter (non-blocking best-effort)
            try {
                $c = get_transient('ace_rc_slow_op_count');
                if ($c === false) { $c = 0; }
                $c++;
                // Short TTL so it auto-resets daily-ish
                set_transient('ace_rc_slow_op_count', (int)$c, 24 * HOUR_IN_SECONDS);
            } catch (\Throwable $t) { /* ignore */ }
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

        public function add($key, $data, $group = 'default', $expire = 0) {
            $group = $group ?: 'default';
            if ($this->bypass || $this->redis === null) {
                if (!isset($this->runtime[$group][$key])) { $this->runtime_set($group, $key, $data); return true; }
                return false;
            }
            $k = $this->k($key, $group);
            // Let phpredis serializer handle complex types if active; otherwise serialize manually
            if ($this->serializer_active) {
                $payload = $data;
            } else {
                $payload = is_scalar($data) ? $data : serialize($data);
            }
            $start = microtime(true);
            try {
                $ok = $expire > 0 ? (bool)$this->redis->set($k, $payload, ['nx', 'ex' => (int)$expire]) : (bool)$this->redis->set($k, $payload, ['nx']);
                if ($ok) { $this->runtime_set($group, $key, $data); }
                $this->prof_log('OCADD', $k, (microtime(true)-$start)*1000, $ok? '' : ' miss');
                return $ok;
            } catch (\Throwable $e) {
                $this->bypass = true; $this->runtime_set($group, $key, $data); return true; }
        }

        public function set($key, $data, $group = 'default', $expire = 0) {
            $group = $group ?: 'default';
            $this->runtime_set($group, $key, $data); // always update runtime
            if ($this->bypass || $this->redis === null) { return true; }
            $k = $this->k($key, $group);
            if ($this->serializer_active) {
                $payload = $data;
            } else {
                $payload = is_scalar($data) ? $data : serialize($data);
            }
            $start = microtime(true);
            try {
                $ok = ($expire > 0) ? (bool)$this->redis->setex($k, (int)$expire, $payload) : (bool)$this->redis->set($k, $payload);
                $bytes = strlen($payload);
                $this->prof_log('OCSET', $k, (microtime(true)-$start)*1000, ' sz='.$bytes);
                return $ok;
            } catch (\Throwable $e) {
                $this->bypass = true; return true; }
        }

        public function get($key, $group = 'default', $force = false, &$found = null) {
            $group = $group ?: 'default';
            // runtime first
            $local = $this->runtime_get($group, $key, $local_found);
            if ($local_found && !$force) { $found = true; return $local; }
            if ($this->bypass || $this->redis === null) { $found = false; return false; }
            if ($group === 'options' && $key === 'ace_redis_cache_settings') { $found = false; return false; }
            $k = $this->k($key, $group);
            $start = microtime(true);
            try {
                $val = $this->redis->get($k);
            } catch (\Throwable $e) {
                $this->bypass = true; $found = false; return false; }
            $elapsed = (microtime(true)-$start)*1000;
            $this->prof_log('OCGET', $k, $elapsed);
            if ($val === false || $val === null) { $found = false; return false; }
            // Decode (handles legacy double-serialized values)
            $out = $this->decode_value($val);
            $this->runtime_set($group, $key, $out);
            $found = true; return $out;
        }

        // Minimal heuristic to detect serialized strings without relying on WP core early.
        protected function looks_serialized($value) {
            if ($value === 'b:0;' || $value === 'b:1;') { return true; }
            if (!is_string($value) || strlen($value) < 4) { return false; }
            switch ($value[0]) {
                case 's': case 'a': case 'O': case 'i': case 'b': case 'd': case 'N':
                    // Correct object pattern: O:<len>:"ClassName":<propcount>:{
                    return (bool)preg_match('/^(?:s:\\d+:"[^"\\]*";|a:\\d+:\{|O:\\d+:"[^"\\]+":\\d+:\{|i:\\d+;|b:[01];|d:\\d+\.?\\d*;|N;)/', $value);
                default:
                    return false;
            }
        }

        // Attempt up to 3 unserialize passes for legacy double/triple serialized strings
        protected function decode_value($val) {
            if (!is_string($val)) { return $val; }
            $attempts = 0;
            while ($attempts < 3 && is_string($val) && $this->looks_serialized($val)) {
                $un = @unserialize($val);
                if ($un === false && $val !== 'b:0;') {
                    break; // not valid serialized anymore
                }
                $val = $un; $attempts++;
            }
            return $val;
        }

        public function delete($key, $group = 'default', $time = 0) {
            $group = $group ?: 'default';
            unset($this->runtime[$group][$key]);
            if ($this->bypass || $this->redis === null) return true;
            $k = $this->k($key, $group);
            $start = microtime(true);
            try { $res = (bool)$this->redis->del($k); $this->prof_log('OCDEL', $k, (microtime(true)-$start)*1000); return $res; } catch (\Throwable $e) { $this->bypass = true; return true; }
        }

        public function incr($key, $offset = 1, $group = 'default') {
            $group = $group ?: 'default';
            if ($this->bypass || $this->redis === null) {
                $cur = $this->runtime_get($group, $key, $f);
                $cur = ($f && is_numeric($cur)) ? (int)$cur : 0;
                $cur += (int)$offset; $this->runtime_set($group, $key, $cur); return $cur;
            }
            try { return $this->redis->incrBy($this->k($key,$group), (int)$offset); } catch (\Throwable $e) { $this->bypass = true; return $this->incr($key,$offset,$group); }
        }

        public function decr($key, $offset = 1, $group = 'default') {
            $group = $group ?: 'default';
            if ($this->bypass || $this->redis === null) {
                $cur = $this->runtime_get($group, $key, $f);
                $cur = ($f && is_numeric($cur)) ? (int)$cur : 0;
                $cur -= (int)$offset; $this->runtime_set($group, $key, $cur); return $cur;
            }
            try { return $this->redis->decrBy($this->k($key,$group), (int)$offset); } catch (\Throwable $e) { $this->bypass = true; return $this->decr($key,$offset,$group); }
        }

        public function flush() {
            $this->runtime = [];
            if ($this->bypass || $this->redis === null) return true;
            $pattern = $this->namespace . '*';
            $it = null; $deleted = 0; $startAll = microtime(true);
            try {
                while (true) {
                    $keys = $this->redis->scan($it, $pattern, 1000);
                    if ($keys === false || empty($keys)) { break; }
                    foreach ($keys as $k) {
                        try { $this->redis->del($k); $deleted++; } catch (\Throwable $t) { $this->bypass = true; break 2; }
                    }
                    if ($it === 0) { break; }
                }
            } catch (\Throwable $e) { $this->bypass = true; }
            $this->prof_log('OCFLUSH', 'namespace', (microtime(true)-$startAll)*1000, ' deleted='.$deleted);
            return true;
        }

        public function add_global_groups($groups) {
            foreach ((array)$groups as $g) {
                if (!in_array($g, $this->global_groups, true)) {
                    $this->global_groups[] = $g;
                }
            }
        }
        public function add_non_persistent_groups($groups) {
            foreach ((array)$groups as $g) {
                if (!in_array($g, $this->non_persistent_groups, true)) {
                    $this->non_persistent_groups[] = $g;
                }
            }
        }
        public function switch_to_blog($blog_id) { $this->blog_prefix = (is_multisite()? $blog_id . ':' : '1:'); }
        public function reset() {}
        public function close() { if ($this->redis) { try { $this->redis->close(); } catch (\Throwable $t) {} } }
        
        // Public status helpers for admin UI / health checks
        // Physical connectivity regardless of request-scope bypass.
        public function is_connected() {
            return (bool)$this->connected && ($this->redis instanceof \Redis);
        }
        // Active means both connected and not bypassed (guest usage perspective)
        public function is_active() { return $this->is_connected() && !$this->bypass; }
        public function is_bypassed() {
            return (bool)$this->bypass;
        }
        public function connection_details() {
            return [
                'connected' => $this->is_connected(), // physical connectivity
                'active' => $this->is_active(),       // effective (not bypassed)
                'via' => $this->connect_via,
                'bypassed' => $this->is_bypassed(),
                'error' => $this->connect_error,
            ];
        }
    }
}

function wp_cache_init() {
    global $wp_object_cache;
    $wp_object_cache = new WP_Object_Cache();
}

function wp_cache_get($key, $group = '', $force = false, &$found = null) { global $wp_object_cache; return $wp_object_cache->get($key, $group, $force, $found); }
function wp_cache_set($key, $data, $group = '', $expire = 0) { global $wp_object_cache; return $wp_object_cache->set($key, $data, $group, $expire); }
function wp_cache_add($key, $data, $group = '', $expire = 0) { global $wp_object_cache; return $wp_object_cache->add($key, $data, $group, $expire); }
function wp_cache_delete($key, $group = '') { global $wp_object_cache; return $wp_object_cache->delete($key, $group); }
function wp_cache_incr($key, $offset = 1, $group = '') { global $wp_object_cache; return $wp_object_cache->incr($key, $offset, $group); }
function wp_cache_decr($key, $offset = 1, $group = '') { global $wp_object_cache; return $wp_object_cache->decr($key, $offset, $group); }
function wp_cache_flush() { global $wp_object_cache; return $wp_object_cache->flush(); }
function wp_cache_add_global_groups($groups) { global $wp_object_cache; return $wp_object_cache->add_global_groups($groups); }
function wp_cache_add_non_persistent_groups($groups) { global $wp_object_cache; return $wp_object_cache->add_non_persistent_groups($groups); }
function wp_cache_switch_to_blog($blog_id) { global $wp_object_cache; return $wp_object_cache->switch_to_blog($blog_id); }
function wp_cache_close() { global $wp_object_cache; return $wp_object_cache && method_exists($wp_object_cache, 'close') ? $wp_object_cache->close() : false; }
function wp_cache_reset() { global $wp_object_cache; return $wp_object_cache && method_exists($wp_object_cache, 'reset') ? $wp_object_cache->reset() : false; }
