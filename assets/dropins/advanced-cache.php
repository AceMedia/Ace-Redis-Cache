<?php
/**
 * Ace Redis Cache advanced-cache drop-in.
 *
 * Install to WP_CONTENT_DIR/advanced-cache.php and set WP_CACHE=true.
 * Serves Redis page-cache hits before full WordPress bootstrap.
 */

if (!defined('ABSPATH')) {
    return;
}

if (php_sapi_name() === 'cli') {
    return;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    return;
}

$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
if ($request_uri === '') {
    $request_uri = '/';
}

if (preg_match('#/(wp-login\.php|wp-admin(?:/|$)|xmlrpc\.php|wp-cron\.php)#i', $request_uri)) {
    return;
}

if (!empty($_COOKIE) && defined('LOGGED_IN_COOKIE')) {
    foreach (array_keys($_COOKIE) as $cookie_name) {
        if (strpos($cookie_name, LOGGED_IN_COOKIE) === 0) {
            return;
        }
    }
}

if (isset($_COOKIE['woocommerce_cart_hash']) && $_COOKIE['woocommerce_cart_hash'] !== '') {
    return;
}

if (preg_match('#^/(cart|checkout|my-account)(/|$)#i', $request_uri)) {
    return;
}

$accept_encoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
$accepts_br = stripos($accept_encoding, 'br') !== false;
$accepts_gzip = stripos($accept_encoding, 'gzip') !== false;

$https = false;
if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
    $https = true;
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
    $https = true;
} elseif (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
    $https = true;
}
$scheme = $https ? 'https' : 'http';

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$mobile = preg_match('~Mobile|Android|Silk/|Kindle|BlackBerry|Opera Mini|Opera Mobi~i', (string) $ua) === 1;
$device = $mobile ? 'mobile' : 'desktop';

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\\d+$/', '', $host);

$redis_host = defined('ACE_REDIS_HOST') ? ACE_REDIS_HOST : (defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1');
$redis_port = defined('ACE_REDIS_PORT') ? (int) ACE_REDIS_PORT : (defined('WP_REDIS_PORT') ? (int) WP_REDIS_PORT : 6379);
$redis_pass = defined('ACE_REDIS_PASSWORD') ? ACE_REDIS_PASSWORD : (defined('WP_REDIS_PASSWORD') ? WP_REDIS_PASSWORD : '');
$redis_timeout = defined('ACE_REDIS_TIMEOUT') ? (float) ACE_REDIS_TIMEOUT : 0.5;
$redis_db = defined('ACE_REDIS_DB') ? (int) ACE_REDIS_DB : 0;

if (!class_exists('Redis')) {
    return;
}

try {
    $redis = new Redis();
    $redis->connect($redis_host, $redis_port, $redis_timeout);

    if ($redis_pass !== '') {
        $redis->auth($redis_pass);
    }
    if ($redis_db > 0) {
        $redis->select($redis_db);
    }

    $site_version = (int) $redis->get('ace:1:version:site_version');
    $core_key = 'page_cache:' . $request_uri . ':' . $scheme . ':' . $device . ':' . $host . ':v' . $site_version;

    $candidates = [
        'page_cache_min:' . $core_key,
        'page_cache:' . $core_key,
    ];

    $payload = false;
    foreach ($candidates as $candidate) {
        $payload = $redis->get($candidate);
        if ($payload !== false) {
            break;
        }
    }

    if ($payload === false || !is_string($payload) || $payload === '') {
        return;
    }

    if (preg_match('/^s:\\d+:/', $payload) === 1) {
        $decoded = @unserialize($payload);
        if (is_string($decoded)) {
            $payload = $decoded;
        }
    }

    $send = function($body, $encoding, $hit_label) {
        if (!headers_sent()) {
            if ($encoding !== '') {
                header('Content-Encoding: ' . $encoding);
                header('Vary: Accept-Encoding');
            }
            header('X-AceRedisCache: HIT (' . $hit_label . ')');
            if (function_exists('header_remove')) {
                header_remove('Content-Length');
            }
            header('Content-Length: ' . strlen($body));
        }
        echo $body;
        exit;
    };

    if (preg_match('/^br\\d{0,2}:(.*)$/s', $payload, $m) === 1) {
        $bytes = $m[1];
        if ($accepts_br) {
            $send($bytes, 'br', 'advcache-br');
        }
        if (function_exists('brotli_uncompress')) {
            $plain = @brotli_uncompress($bytes);
            if (is_string($plain)) {
                $send($plain, '', 'advcache-plain');
            }
        }
        return;
    }

    if (preg_match('/^gz\\d{0,2}:(.*)$/s', $payload, $m) === 1) {
        $bytes = $m[1];
        $is_gzip_stream = strlen($bytes) >= 2 && substr($bytes, 0, 2) === "\x1f\x8b";
        if ($accepts_gzip && $is_gzip_stream) {
            $send($bytes, 'gzip', 'advcache-gz');
        }
        $plain = @gzdecode($bytes);
        if (!is_string($plain) && function_exists('gzuncompress')) {
            $plain = @gzuncompress($bytes);
        }
        if (is_string($plain)) {
            $send($plain, '', 'advcache-plain');
        }
        return;
    }

    if (str_starts_with($payload, 'raw:')) {
        $send(substr($payload, 4), '', 'advcache-plain');
    }

    // Unknown payload format: do not emit it directly.
    return;
} catch (Throwable $e) {
    return;
}
