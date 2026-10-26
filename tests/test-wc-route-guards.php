<?php
/**
 * Standalone regression checks for WooCommerce routes before WordPress boots.
 * Run: php -n tests/test-wc-route-guards.php
 */

define('ABSPATH', __DIR__ . '/');
define('ACE_OC_BYPASS', true);
define('ACE_OC_WC_READ', true);

function apply_filters($name, $value) { return $value; }
function add_action() {}
function is_multisite() { return false; }

require dirname(__DIR__) . '/assets/dropins/object-cache.php';
require dirname(__DIR__) . '/includes/class-ace-redis-cache.php';

// CLI skips advanced-cache.php before request classification. Replace only that
// guard in memory so the actual early-cache file can be exercised without FPM.
$early = file_get_contents(dirname(__DIR__) . '/assets/dropins/advanced-cache.php');
$guard = "if (php_sapi_name() === 'cli')";
if (substr_count($early, $guard) !== 1) {
    throw new RuntimeException('Advanced-cache CLI guard changed');
}
$early = str_replace($guard, 'if (false)', $early);

if (class_exists('Redis', false)) {
    throw new RuntimeException('Run this isolated test with a PHP CLI without phpredis');
}
eval('class Redis { public static $connects = 0; public function connect() { self::$connects++; return false; } }');

$plugin = new ReflectionClass(\AceMedia\RedisCache\AceRedisCache::class);
$instance = $plugin->newInstanceWithoutConstructor();
$plugin_guard = $plugin->getMethod('is_woocommerce_uncacheable_request');
$wc_mode = (new ReflectionClass('WP_Object_Cache'))->getProperty('wc_read_mode');
if (PHP_VERSION_ID < 80100) {
    $plugin_guard->setAccessible(true);
    $wc_mode->setAccessible(true);
}

$cases = [
    ['/basket', [], [], true, true],
    ['/basket/', [], [], true, true],
    ['/basket/?view=compact', ['view' => 'compact'], [], true, true],
    ['/shop/basket/', [], [], true, true],
    ['/cart/', [], [], true, true],
    ['/checkout/', [], [], true, true],
    ['/my-account/', [], [], true, true],
    ['/wp-json/wc/store/v1/cart', [], [], true, true],
    ['/?rest_route=/wc/store/v1/cart', ['rest_route' => '/wc/store/v1/cart'], [], true, true],
    ['/', [], ['woocommerce_items_in_cart' => '1'], true, true],
    ['/', [], ['wp_woocommerce_session_test' => 'session'], true, true],
    ['/basketball/', [], [], false, false],
    ['/product/basket-lid/', [], [], false, false],
    ['/?beta=1', ['beta' => '1'], [], false, false],
    ['/wp-admin/edit.php', [], [], false, false],
];

$passed = 0;
foreach ($cases as [$uri, $get, $cookies, $private, $shared_read]) {
    $_SERVER = [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => $uri,
        'HTTP_HOST' => 'example.test',
    ];
    $_GET = $get;
    $_COOKIE = $cookies;
    $path = (string) parse_url($uri, PHP_URL_PATH);

    $actual_plugin = $plugin_guard->invoke($instance, $uri, $path);
    $cache = new WP_Object_Cache();
    $actual_read = $wc_mode->getValue($cache);
    Redis::$connects = 0;
    eval('?>' . $early);
    $actual_early_bypass = Redis::$connects === 0;

    $expected_early_bypass = $private || $path === '/wp-admin/edit.php' || isset($get['beta']);
    if ($actual_plugin !== $private || $actual_read !== $shared_read || $actual_early_bypass !== $expected_early_bypass) {
        throw new RuntimeException(sprintf(
            '%s: plugin=%s read=%s early_bypass=%s; expected private=%s shared_read=%s',
            $uri,
            var_export($actual_plugin, true),
            var_export($actual_read, true),
            var_export($actual_early_bypass, true),
            var_export($private, true),
            var_export($shared_read, true)
        ));
    }
    $passed++;
}

echo "$passed Woo route guard cases passed across plugin, object cache and early cache\n";
