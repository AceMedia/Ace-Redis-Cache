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

// LOGGED_IN_COOKIE is NOT defined this early (wp-settings defines cookie constants
// after advanced-cache loads), so a constant-gated check is dead code — which served
// cached guest pages to logged-in users the moment early-serve went live. Match the
// literal WP auth-cookie prefixes instead; they are stable across WP versions.
if (!empty($_COOKIE)) {
    foreach (array_keys($_COOKIE) as $cookie_name) {
        if (preg_match('/^(wordpress_logged_in_|wordpress_sec_|wordpressuser_|wp-postpass_|comment_author_)/', (string) $cookie_name)) {
            return;
        }
    }
}

if (isset($_COOKIE['woocommerce_cart_hash']) && $_COOKIE['woocommerce_cart_hash'] !== '') {
    return;
}

if (isset($_COOKIE['woocommerce_items_in_cart']) && $_COOKIE['woocommerce_items_in_cart'] !== '') {
    return;
}

foreach (array_keys($_COOKIE) as $cookie_name) {
    if (strpos((string) $cookie_name, 'wp_woocommerce_session_') === 0) {
        return;
    }
}

if (isset($_GET['wc-ajax']) || isset($_GET['add-to-cart']) || isset($_GET['remove_item']) || isset($_GET['undo_item'])) {
    return;
}

if (
    (isset($_GET['action']) && in_array((string) $_GET['action'], ['register', 'lostpassword', 'resetpass', 'logout'], true)) ||
    isset($_GET['password-reset']) ||
    isset($_GET['key'])
) {
    return;
}

if ($request_uri !== '' && preg_match('#[?&](wc-ajax|add-to-cart|remove_item|undo_item|password-reset|key)=#i', $request_uri)) {
    return;
}

$request_path = (string) parse_url($request_uri, PHP_URL_PATH);
$rest_route = isset($_GET['rest_route']) ? urldecode((string) $_GET['rest_route']) : '';
if ($request_path !== '' && preg_match('#(^|/)(cart|checkout|my-account|register|lost-password|customer-logout|order-pay|order-received|view-order|edit-account|add-payment-method|payment-methods|set-default-payment-method|delete-payment-method)(/|$)#i', $request_path)) {
    return;
}

if (
    ($request_path !== '' && preg_match('#/(?:wp-json/)?wc/store/v1/(cart|checkout)(?:/|$)#i', $request_path)) ||
    ($rest_route !== '' && preg_match('#^/wc/store/v1/(cart|checkout)(?:/|$)#i', $rest_route)) ||
    ($request_uri !== '' && preg_match('#[?&]rest_route=(?:%2F|/)?wc(?:%2F|/)store(?:%2F|/)v1(?:%2F|/)(cart|checkout)(?:%2F|/|[&\#]|$)#i', $request_uri))
) {
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
$host = preg_replace('/:\d+$/', '', $host);

$redis_host = defined('ACE_REDIS_HOST') ? ACE_REDIS_HOST : (defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1');
$redis_port = defined('ACE_REDIS_PORT') ? (int) ACE_REDIS_PORT : (defined('WP_REDIS_PORT') ? (int) WP_REDIS_PORT : 6379);
$redis_pass = defined('ACE_REDIS_PASSWORD') ? ACE_REDIS_PASSWORD : (defined('WP_REDIS_PASSWORD') ? WP_REDIS_PASSWORD : '');
$redis_timeout = defined('ACE_REDIS_TIMEOUT') ? (float) ACE_REDIS_TIMEOUT : 0.5;
$redis_db = defined('ACE_REDIS_DB') ? (int) ACE_REDIS_DB : 0;
$redis_socket = defined('ACE_REDIS_SOCKET') ? ACE_REDIS_SOCKET : '/var/run/redis/redis.sock';

if (!class_exists('Redis')) {
    return;
}

// Early-serve is DARK-LAUNCHED by default: advanced-cache reconstructs the key, looks it up, and emits an
// X-Ace-Early: HIT/MISS diagnostic header — but only short-circuits WordPress when ACE_REDIS_EARLY_SERVE is
// defined and true. This lets the key-match rate be validated on live (and requires the page entries to be
// raw, i.e. the SERIALIZER_NONE end-state) before any request is actually served pre-boot.
$early_serve = defined('ACE_REDIS_EARLY_SERVE') && ACE_REDIS_EARLY_SERVE;
$emit = function($label) use ($early_serve) {
    if (!headers_sent()) {
        header('X-Ace-Early: ' . $label . ($early_serve ? '' : '; dark-launch'));
    }
};

try {
    $redis = new Redis();
    // Mirror the object cache's connect order: unix socket first, then TCP. A TCP-only connect can land
    // on a different/empty endpoint than where the plugin writes (the object cache prefers the socket),
    // which is why every reconstructed key missed. ($redis_socket defaults to /var/run/redis/redis.sock.)
    $connected = false;
    if ($redis_socket !== '' && @is_readable($redis_socket)) {
        $connected = @$redis->connect($redis_socket, 0, $redis_timeout);
    }
    if (!$connected) {
        $connected = @$redis->connect($redis_host, $redis_port, $redis_timeout);
    }
    if (!$connected) {
        $emit('MISS no-connection');
        return;
    }

    if ($redis_pass !== '') {
        @$redis->auth($redis_pass);
    }
    if ($redis_db > 0) {
        @$redis->select($redis_db);
    }

    // Inputs published raw by the plugin (rawCommand SET, no serializer) so they read as plain strings.
    // The suffix carries the global ace-te-*/ace-pc-hl-* key parts the writer appends via filter.
    // Use === false (key absent), NOT a falsy/!is_string check: site_version is legitimately "0" on
    // this site, and the suffix can be an empty string — both are valid published values.
    $site_version = $redis->get('ace:1:pagekey:site_version');
    $suffix = $redis->get('ace:1:pagekey:suffix');
    if ($site_version === false || $suffix === false) {
        $emit('MISS no-tokens');
        return;
    }

    $core_key = 'page_cache:' . $request_uri . ':' . $scheme . ':' . $device . ':' . $host . ':v' . (int) $site_version;
    if ($suffix !== '') {
        $core_key .= ':' . $suffix;
    }

    $candidates = [
        'page_cache_min:' . $core_key,
        'page_cache:' . $core_key,
        $core_key, // minification-off sites store under the bare core key
    ];

    $payload = false;
    foreach ($candidates as $candidate) {
        $payload = $redis->get($candidate);
        if ($payload !== false) {
            break;
        }
    }

    if (!is_string($payload) || $payload === '') {
        $emit('MISS no-key');
        return;
    }

    if (preg_match('/^s:\d+:/', $payload) === 1) {
        $decoded = @unserialize($payload);
        if (is_string($decoded)) {
            $payload = $decoded;
        }
    }

    // Decode the self-describing marker into [body, encoding]; foreign/undecodable (e.g. an igbinary-wrapped
    // value before the NONE cutover) leaves $body null -> treated as a miss, never served.
    $body = null;
    $encoding = '';
    if (preg_match('/^br\d{0,2}:(.*)$/s', $payload, $m) === 1) {
        $bytes = $m[1];
        if ($accepts_br) {
            $body = $bytes;
            $encoding = 'br';
        } elseif (function_exists('brotli_uncompress')) {
            $plain = @brotli_uncompress($bytes);
            if (is_string($plain)) {
                $body = $plain;
            }
        }
    } elseif (preg_match('/^gz\d{0,2}:(.*)$/s', $payload, $m) === 1) {
        $bytes = $m[1];
        if ($accepts_gzip && strlen($bytes) >= 2 && substr($bytes, 0, 2) === "\x1f\x8b") {
            $body = $bytes;
            $encoding = 'gzip';
        } else {
            $plain = @gzdecode($bytes);
            if (!is_string($plain) && function_exists('gzuncompress')) {
                $plain = @gzuncompress($bytes);
            }
            if (is_string($plain)) {
                $body = $plain;
            }
        }
    } elseif (str_starts_with($payload, 'raw:')) {
        $body = substr($payload, 4);
    }

    if ($body === null) {
        $emit('MISS undecodable');
        return;
    }

    if (!$early_serve) {
        // Dark-launch: a real early-serve hit was confirmed — record it and let WordPress serve normally.
        $emit('HIT');
        return;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
        if ($encoding !== '') {
            header('Content-Encoding: ' . $encoding);
            header('Vary: Accept-Encoding');
        }
        header('X-Ace-Early: HIT');
        header('X-AceRedisCache: HIT (advcache)');
        if (function_exists('header_remove')) {
            header_remove('Content-Length');
        }
        header('Content-Length: ' . strlen($body));
    }
    echo $body;
    exit;
} catch (Throwable $e) {
    return;
}
