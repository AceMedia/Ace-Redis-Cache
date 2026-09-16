<?php
/**
 * Page cache store guards + browser cache header tests.
 *
 * The rest of the suite is PHPUnit, but PHPUnit is not installed on this box and these
 * guards need the real WordPress query object to assert against, so this one runs
 * against a booted site:
 *
 *   wp eval-file assets/plugins/Ace-Redis-Cache/tests/test-page-cache-guards.php --path=core
 *
 * Proves: a 404 is not stored, an empty-main 200 is not stored, an ordinary populated
 * page still is, and the headers come out as intended for each case.
 *
 * @package AceMedia\RedisCache
 */

use AceMedia\RedisCache\AceRedisCache;

if (!class_exists(AceRedisCache::class)) {
    $plugin_root = dirname(__DIR__);
    require_once $plugin_root . '/includes/class-ace-redis-cache.php';
}

$passed = 0;
$failed = 0;

$check = function ($label, $actual, $expected) use (&$passed, &$failed) {
    if ($actual === $expected) {
        $passed++;
        echo "PASS  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL  {$label}\n";
    echo "        expected: " . var_export($expected, true) . "\n";
    echo "        actual:   " . var_export($actual, true) . "\n";
};

$reflection = new ReflectionClass(AceRedisCache::class);
$instance = $reflection->newInstanceWithoutConstructor();

$set_settings = function (array $settings) use ($reflection, $instance) {
    $prop = $reflection->getProperty('settings');
    $prop->setAccessible(true);
    $prop->setValue($instance, $settings);
};
$call = function ($method, array $args) use ($reflection, $instance) {
    $m = $reflection->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($instance, $args);
};

$set_settings([
    'enable_browser_cache_headers' => 1,
    'browser_cache_max_age'        => 604800,
    'ttl_page'                     => 3600,
]);

// Query state control. The guards read the main query, so drive it directly.
$query = new WP_Query();
$GLOBALS['wp_query'] = $query;
$reset_query = function () use ($query) {
    $query->init_query_flags();
    $query->is_page = true;
    $query->is_singular = true;
};

// --- fixtures -----------------------------------------------------------------

$shell = function ($main_inner) {
    return '<!doctype html><html><head><title>Sheffield Events</title></head><body>'
        . '<header class="ace-shell__header">Sheffield Events</header>'
        . '<main class="ace-shell__main" id="main">' . $main_inner . '</main>'
        . '<footer class="ace-shell__footer">' . str_repeat('Footer navigation link. ', 40) . '</footer>'
        . '</body></html>';
};

$populated = $shell('<article><h1>Tramlines Fringe 2026</h1><p>'
    . str_repeat('Sheffield has a fringe programme running the length of the weekend, across venues in Kelham Island and the city centre. ', 8)
    . '</p></article>');

$empty_main = $shell('<div class="ace-shell__loading" aria-busy="true"></div>'
    . '<script type="application/json">{"state":{"items":[],"padding":"' . str_repeat('x', 2000) . '"}}</script>');

$no_main = '<!doctype html><html><body><div>Fatal error: Uncaught Error in a half-booted tree.</div></body></html>';

// --- store guards -------------------------------------------------------------

$reset_query();
$check('populated 200 page is stored', $call('page_cache_store_block_reason', [$populated, 200]), null);

$reset_query();
$query->set_404();
$check('404 is not stored', $call('page_cache_store_block_reason', [$populated, 200]), 'is_404');

$reset_query();
$check('empty-main 200 is not stored', $call('page_cache_store_block_reason', [$empty_main, 200]), 'empty_main');

$reset_query();
$check('page with no main element at all is not stored', $call('page_cache_store_block_reason', [$no_main, 200]), 'empty_main');

$reset_query();
$check('500 is not stored', $call('page_cache_store_block_reason', [$populated, 500]), 'status_not_200');

$reset_query();
$check('301 is not stored', $call('page_cache_store_block_reason', [$populated, 301]), 'status_not_200');

$reset_query();
$query->init_query_flags();
$query->is_search = true;
$check('search results are not stored', $call('page_cache_store_block_reason', [$populated, 200]), 'is_search');

$reset_query();
$query->init_query_flags();
$query->is_feed = true;
$check('feeds are not stored', $call('page_cache_store_block_reason', [$populated, 200]), 'is_feed');

$reset_query();
$check('empty body is not stored', $call('page_cache_store_block_reason', ['   ', 200]), 'empty_body');

// The hole that let the old ">= 300" guard through: off a web SAPI http_response_code()
// returns false, and `false >= 300` is false in PHP 8. WordPress's verdict must still win.
$reset_query();
$query->set_404();
$check('404 is caught even when the SAPI reports no status code', $call('page_cache_store_block_reason', [$populated, false]), 'is_404');
$reset_query();
$check('no status code from the SAPI is treated as 200', $call('current_response_code', [false]), 200);

// --- headers ------------------------------------------------------------------

$now = 1757980800; // fixed so Expires is assertable

$reset_query();
$check(
    'HIT on a 200 splits the lifetime and varies by cookie',
    $call('browser_cache_header_values', ['hit', $now, 200]),
    [
        'Vary'          => 'Cookie',
        'Cache-Control' => 'public, max-age=300, s-maxage=604800',
        'Expires'       => gmdate('D, d M Y H:i:s', $now + 300) . ' GMT',
    ]
);

$reset_query();
$query->set_404();
$check(
    'HIT on a 404 is never cacheable',
    $call('browser_cache_header_values', ['hit', $now, 200]),
    ['Vary' => 'Cookie', 'Cache-Control' => 'no-cache, max-age=0']
);

$reset_query();
$check(
    'HIT on a redirect is never cacheable',
    $call('browser_cache_header_values', ['hit', $now, 301]),
    ['Vary' => 'Cookie', 'Cache-Control' => 'no-cache, max-age=0']
);

$reset_query();
$check(
    'MISS stays conservative',
    $call('browser_cache_header_values', ['miss', $now, 200]),
    ['Vary' => 'Cookie', 'Cache-Control' => 'no-cache, max-age=0']
);

$reset_query();
$check(
    'browser max-age never exceeds the shared lifetime',
    (function () use ($set_settings, $call, $now) {
        $set_settings(['enable_browser_cache_headers' => 1, 'browser_cache_max_age' => 120, 'ttl_page' => 3600]);
        $headers = $call('browser_cache_header_values', ['hit', $now, 200]);
        $set_settings(['enable_browser_cache_headers' => 1, 'browser_cache_max_age' => 604800, 'ttl_page' => 3600]);
        return $headers['Cache-Control'];
    })(),
    'public, max-age=120, s-maxage=120'
);

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    exit(1);
}
