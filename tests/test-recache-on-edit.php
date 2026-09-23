<?php
/**
 * Recache on edit (#21) tests.
 *
 * Like test-page-cache-guards.php this runs against a booted site with Redis (so a PHP whose
 * CLI has phpredis, e.g. php8.4 on the Pi) and page cache enabled:
 *
 *   php8.4 $(which wp) eval-file assets/plugins/Ace-Redis-Cache/tests/test-recache-on-edit.php
 *
 * It creates a synced pattern, a page that uses it and a template part, checks what an edit to
 * each queues and recaches, and deletes them again. The page-cache entry it plants is a fake
 * under a path nothing serves.
 *
 * @package AceMedia\RedisCache
 */

$plugin = function_exists('ace_redis_cache') ? ace_redis_cache() : null;
if (!$plugin || !$plugin->get_cache_manager()) {
    echo "SKIP  needs the plugin loaded with a Redis connection\n";
    return;
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
$reflection = new ReflectionClass($plugin);
$call = function ($method, array $args = []) use ($reflection, $plugin) {
    $m = $reflection->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($plugin, $args);
};
$redis = $plugin->get_cache_manager()->get_redis_connection()->get_connection();
$queue_key = $call('site_scoped_key', ['ace_rc_deferred_invalidation_queue']);
$prime_key = $call('site_scoped_key', ['ace_rc_sitemap_prime_queue']);
$saved_queue = $plugin->get_cache_manager()->get($queue_key);
$saved_prime = $plugin->get_cache_manager()->get($prime_key);
$plugin->get_cache_manager()->delete($queue_key);
$plugin->get_cache_manager()->delete($prime_key);
$posts_key = $call('site_scoped_key', ['ace_rc_recache_posts']);
$saved_posts = $redis->rawCommand('SMEMBERS', $posts_key);
$redis->rawCommand('DEL', $posts_key);
$queued = function () use ($plugin, $queue_key) {
    $q = $plugin->get_cache_manager()->get($queue_key);
    return is_array($q) ? $q : [];
};
$queued_post = function ($id) use ($redis, $posts_key) {
    return (bool) $redis->rawCommand('SISMEMBER', $posts_key, $id . ':1');
};

// Queues are per site: the key carries this site's host.
$host = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
$check('the deferred queue key is scoped to this site', substr($queue_key, -strlen(':' . $host)) === ':' . $host, true);

// Refresh URLs carry the device.
$check('#mobile marks the mobile copy', $call('split_prime_url', ['https://example.test/a/#mobile']), ['https://example.test/a/', 'mobile']);
$check('a plain URL is the desktop copy', $call('split_prime_url', ['https://example.test/a/']), ['https://example.test/a/', 'desktop']);

// Content: a synced pattern, a page that uses it, a template part.
$pattern = wp_insert_post(['post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'ace-rc-test pattern', 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->']);
$page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'ace-rc-test page', 'post_name' => 'ace-rc-test-page', 'post_content' => '<!-- wp:block {"ref":' . $pattern . '} /-->']);
$part = wp_insert_post(['post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_title' => 'ace-rc-test part', 'post_name' => 'ace-rc-test-part', 'post_content' => '<!-- wp:paragraph --><p>y</p><!-- /wp:paragraph -->']);
$draft = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'ace-rc-test draft']);

// What those saves queued (save_post runs queue_recache_on_save() in every request context).
$check('saving a synced pattern queues it', $queued_post($pattern), true);
$check('saving a published page queues it', $queued_post($page), true);
$check('saving a template part queues a site-wide recache', isset($queued()['__sitewide']), true);
$check('saving a draft queues no recache', $queued_post($draft), false);
$plugin->get_cache_manager()->delete($queue_key);
$redis->rawCommand('DEL', $posts_key);

// What each edit reaches.
$page_url = get_permalink($page);
list($urls, $wide) = $call('recache_targets', [get_post($pattern)]);
$check('a synced pattern reaches the page that uses it', in_array($page_url, $urls, true), true);
$check('... and that page\'s listings (home)', in_array(home_url('/'), $urls, true), true);
$check('... without making the whole site stale', $wide, false);
list($urls, $wide) = $call('recache_targets', [get_post($part)]);
$check('a template part makes the whole site stale', $wide, true);

// Recache in place: a cached copy is marked stale (not deleted) and queued for both devices
// that exist; a device with no copy is not rendered.
$core = $call('page_core_key_for_url', [$page_url]);
$redis->rawCommand('SET', 'page_cache:' . $core, 'raw:<html>old</html>', 'EX', '600');
$redis->rawCommand('SET', 'ace:1:fresh:' . $core, time() . ':' . (time() + 600), 'EX', '600');
$n = $call('recache_urls', [[$page_url]]);
$prime = $plugin->get_cache_manager()->get($prime_key);
$check('the cached copy is kept', $redis->rawCommand('GET', 'page_cache:' . $core), 'raw:<html>old</html>');
$check('... and marked stale for both cache layers', $redis->rawCommand('GET', 'ace:1:fresh:' . $core), '0:0');
$check('... and its refresh is queued first', is_array($prime) ? array_key_first($prime) : null, $page_url);
$check('a device with no cached copy is not queued', is_array($prime) && isset($prime[$page_url . '#mobile']), false);
$check('one refresh queued', $n, 1);
$redis->rawCommand('DEL', 'page_cache:' . $core, 'ace:1:fresh:' . $core);

// Clean up (and keep the CLI shutdown fallback from running the queue these deletes add).
$busy = $reflection->getProperty('deferred_processing');
$busy->setAccessible(true);
$busy->setValue($plugin, true);
foreach ([$page, $pattern, $part, $draft] as $id) {
    wp_delete_post($id, true);
}
$plugin->get_cache_manager()->delete($queue_key);
$plugin->get_cache_manager()->delete($prime_key);
$redis->rawCommand('DEL', $posts_key);
if (is_array($saved_posts) && $saved_posts) {
    $redis->rawCommand('SADD', $posts_key, ...$saved_posts);
}
if (is_array($saved_queue)) {
    $plugin->get_cache_manager()->set($queue_key, $saved_queue, 3600);
}
if (is_array($saved_prime)) {
    $plugin->get_cache_manager()->set($prime_key, $saved_prime, 21600);
}

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    exit(1);
}
