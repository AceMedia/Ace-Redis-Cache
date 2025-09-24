<?php
namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) { exit; }

/**
 * Lightweight HTTP client wrapper with circuit breaker + transient micro-cache.
 * Usage: Http_Wrapper::get( 'https://api.example.com/data', [ 'timeout' => 1, 'cache_ttl' => 120 ] );
 */
class Http_Wrapper {
    const CB_OPTION = 'ace_rc_http_cb_state';
    const FAIL_THRESHOLD = 5;          // consecutive failures to open circuit
    const HALF_OPEN_AFTER = 30;        // seconds before allowing a test request
    const OPEN_RESET_AFTER = 300;      // full reset after five minutes regardless

    /**
     * Perform GET with circuit breaker and transient cache.
     * Args: timeout (default 1.0), cache_ttl (seconds), headers (array), force_refresh (bool)
     */
    public static function get($url, $args = []) {
        $args = is_array($args) ? $args : [];
        $cache_ttl = isset($args['cache_ttl']) ? (int)$args['cache_ttl'] : 0;
        $timeout = isset($args['timeout']) ? (float)$args['timeout'] : 1.0;
        $force_refresh = !empty($args['force_refresh']);
        $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
        $ckey = 'ace_http_' . md5($url . serialize($headers));

        // Cached response
        if ($cache_ttl > 0 && !$force_refresh) {
            $cached = get_transient($ckey);
            if ($cached !== false) { return $cached; }
        }

        // Circuit breaker state
        $state = get_option(self::CB_OPTION, [ 'failures' => 0, 'state' => 'closed', 'changed' => time() ]);
        $now = time();
        if (!is_array($state)) { $state = [ 'failures' => 0, 'state' => 'closed', 'changed' => $now ]; }
        if ($state['state'] === 'open') {
            // Allow half-open trial after HALF_OPEN_AFTER seconds
            if (($now - $state['changed']) > self::HALF_OPEN_AFTER) {
                $state['state'] = 'half';
            } else {
                return new \WP_Error('ace_rc_cb_open', 'Circuit open for remote HTTP');
            }
        }
        if ($state['state'] === 'half' && ($now - $state['changed']) > self::OPEN_RESET_AFTER) {
            $state = [ 'failures' => 0, 'state' => 'closed', 'changed' => $now ];
        }

        $resp = wp_remote_get($url, [ 'timeout' => $timeout, 'headers' => $headers ]);
        if (is_wp_error($resp)) {
            $state['failures']++;
            if ($state['failures'] >= self::FAIL_THRESHOLD) {
                $state['state'] = 'open';
                $state['changed'] = $now;
            }
            update_option(self::CB_OPTION, $state, 'no');
            return $resp;
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code >= 500 || $code === 429) {
            $state['failures']++;
            if ($state['failures'] >= self::FAIL_THRESHOLD) {
                $state['state'] = 'open';
                $state['changed'] = $now;
            }
            update_option(self::CB_OPTION, $state, 'no');
            return new \WP_Error('ace_rc_remote_error', 'Upstream error code ' . $code);
        }

        // Success path resets failures; handle half-open promote to closed
        $state['failures'] = 0;
        if ($state['state'] !== 'closed') { $state['state'] = 'closed'; $state['changed'] = $now; }
        update_option(self::CB_OPTION, $state, 'no');

        $body = wp_remote_retrieve_body($resp);
        if ($cache_ttl > 0) { set_transient($ckey, $body, $cache_ttl); }
        return $body;
    }
}
