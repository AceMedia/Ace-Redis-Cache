<?php
/**
 * Varnish bridge: when the page cache is invalidated, ban the same pages in a
 * Varnish sitting in front of the site, so the two layers never disagree.
 *
 * Auto-detects Varnish on 127.0.0.1:6081 (CloudPanel's default). Sites not
 * behind Varnish are unaffected: a ban for a host Varnish never cached is a no-op.
 *
 * Config (wp-config.php):
 *   ACE_RC_VARNISH       true | false | 'auto' (default 'auto')
 *   ACE_RC_VARNISH_HOST  'host:port' (default '127.0.0.1:6081')
 * Filters: ace_rc_varnish_enabled, ace_rc_varnish_hosts (Host headers to ban for).
 *
 * The VCL turns `PURGE <regex>` into `ban(req.http.host == <host> && req.url ~ <regex>)`,
 * so the request-target is sent raw (an HTTP client would prefix it with "/").
 *
 * @package AceMedia\RedisCache
 */

namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) {
    exit;
}

class VarnishBridge {

    /** @var array<string,true> Pending ban regexes, sent once at shutdown. */
    private static $pending = [];

    private static $booted = false;

    private static $available = null;

    public static function boot() {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        add_action('ace_rc_paths_invalidated', [__CLASS__, 'on_paths_invalidated'], 10, 1);
        add_action('ace_rc_cache_cleared', [__CLASS__, 'ban_site'], 10, 0);
        add_action('ace_rc_page_epoch_bumped', [__CLASS__, 'ban_site'], 10, 0);
        add_action('ace_redis_cache_varnish_ban_site', [__CLASS__, 'ban_site'], 10, 0);
    }

    public static function on_paths_invalidated($paths) {
        foreach ((array) $paths as $path) {
            $regex = self::path_regex((string) $path);
            if ($regex !== null) {
                self::queue($regex);
            }
        }
    }

    /** Ban every URL for this site. */
    public static function ban_site() {
        self::queue('.');
    }

    /**
     * Anchored regex for one path, ignoring a trailing slash and any query string.
     * Returns null for anything that could not be sent safely as a request-target.
     */
    public static function path_regex($path) {
        $path = (string) parse_url($path, PHP_URL_PATH);
        if ($path === '' || $path[0] !== '/' || preg_match('/[\s\x00-\x1f\x7f]/', $path)) {
            return null;
        }
        $path = rtrim($path, '/');
        return '^' . preg_quote($path, '') . '/?(\?.*)?$';
    }

    private static function queue($regex) {
        if (empty(self::$pending)) {
            add_action('shutdown', [__CLASS__, 'flush'], 100);
        }
        self::$pending[$regex] = true;
    }

    public static function flush() {
        $regexes = array_keys(self::$pending);
        self::$pending = [];
        if (!$regexes || !self::enabled()) {
            return;
        }
        // A site-wide ban covers everything else.
        if (in_array('.', $regexes, true)) {
            $regexes = ['.'];
        }
        foreach (self::hosts() as $host) {
            foreach ($regexes as $regex) {
                self::send($host, $regex);
            }
        }
    }

    private static function enabled() {
        $mode = defined('ACE_RC_VARNISH') ? ACE_RC_VARNISH : 'auto';
        if ($mode === 'auto') {
            $mode = self::detect();
        }
        return (bool) apply_filters('ace_rc_varnish_enabled', (bool) $mode);
    }

    /** Is something listening on the Varnish port? Cached for 10 minutes. */
    private static function detect() {
        if (self::$available !== null) {
            return self::$available;
        }
        $cached = get_transient('ace_rc_varnish_present');
        if ($cached !== false) {
            return self::$available = ($cached === 'yes');
        }
        [$addr, $port] = self::endpoint();
        $sock = @fsockopen($addr, $port, $errno, $errstr, 0.1);
        self::$available = (bool) $sock;
        if ($sock) {
            fclose($sock);
        }
        set_transient('ace_rc_varnish_present', self::$available ? 'yes' : 'no', 10 * MINUTE_IN_SECONDS);
        return self::$available;
    }

    private static function endpoint() {
        $hp = defined('ACE_RC_VARNISH_HOST') ? (string) ACE_RC_VARNISH_HOST : '127.0.0.1:6081';
        $port = 6081;
        if (preg_match('/^(.+):(\d+)$/', $hp, $m)) {
            $hp = $m[1];
            $port = (int) $m[2];
        }
        return [$hp, $port];
    }

    private static function hosts() {
        $host = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
        $hosts = $host !== '' ? [$host] : [];
        if ($host !== '' && strpos($host, 'www.') !== 0) {
            $hosts[] = 'www.' . $host;
        }
        return array_values(array_unique(array_filter((array) apply_filters('ace_rc_varnish_hosts', $hosts))));
    }

    private static function send($host, $regex) {
        if (preg_match('/[\s\x00-\x1f\x7f]/', $host . $regex)) {
            return false;
        }
        [$addr, $port] = self::endpoint();
        $sock = @fsockopen($addr, $port, $errno, $errstr, 0.5);
        if (!$sock) {
            return false;
        }
        stream_set_timeout($sock, 1);
        fwrite($sock, "PURGE {$regex} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
        $status = (string) fgets($sock, 64);
        fclose($sock);
        $ok = (bool) preg_match('#^HTTP/\S+ 200#', $status);
        if (!$ok && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('AceRedisCache Varnish ban failed for ' . $host . ' ' . $regex . ': ' . trim($status));
        }
        return $ok;
    }
}
