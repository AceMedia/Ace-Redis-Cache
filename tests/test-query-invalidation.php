<?php
/** Real Redis regression, isolated local fixture port. Never connects to a live site. */
require __DIR__ . '/bootstrap-isolated.php';
use AceMedia\RedisCache\AceRedisCache;
use AceMedia\RedisCache\PagePathIndex;
function apply_filters($name, $value, ...$args) {
    if ($name === 'ace_redis_cache_page_cache_key_parts') $value[] = 'local-suffix';
    return $value;
}
function get_permalink($id) { return home_url('/?p=' . $id); }
function wp_cache_get($key, $group = '') { return 8; }
function do_action($name, ...$args) { $GLOBALS['arc_actions'][$name] = $args; }
$r = new Redis();
$r->connect('127.0.0.1', 16379, 1);
$host = 'arc-query-' . bin2hex(random_bytes(5)) . '.test';
$GLOBALS['arc_test_home'] = 'https://' . $host;
$home = home_url();
$ref = new ReflectionClass(AceRedisCache::class);
$plugin = $ref->newInstanceWithoutConstructor();
$manager = new class($r) {
    private $r;
    function __construct($r) { $this->r = $r; }
    function get_raw_client() { return $this->r; }
    function get_redis_connection() { return $this; }
    function get_connection() { return $this->r; }
};
$prop = $ref->getProperty('cache_manager'); $prop->setAccessible(true); $prop->setValue($plugin, $manager);
$assertions = 0; $cleanup = [];
function check($condition, $message) { global $assertions; $assertions++; if (!$condition) throw new RuntimeException($message); }
function seed($uri, $alternate_host = null) {
    global $r, $host, $ref, $plugin, $cleanup;
    $target = $alternate_host ?: $host;
    $keys = AceRedisCache::early_serve_keys([$uri], $target, 13, 'published-suffix');
    if (!$alternate_host) {
        $method = $ref->getMethod('page_invalidation_keys'); $method->setAccessible(true);
        $keys = array_unique(array_merge($keys, $method->invoke($plugin, [$uri])));
    }
    foreach ($keys as $key) { $r->setex($key, 300, 'old'); $cleanup[] = $key; }
    return $keys;
}
function gone($keys) { global $r; foreach ($keys as $key) check(!$r->exists($key), 'Not purged: ' . $key); }
function intact($keys) { global $r; foreach ($keys as $key) check((bool) $r->exists($key), 'Unexpected purge: ' . $key); }
function register_uri($uri, $ttl = 300, $scope = null) {
    global $r, $home, $cleanup;
    $scope = $scope ?: $home;
    $cleanup[] = PagePathIndex::key($scope, $uri);
    return PagePathIndex::register($r, $scope, $uri, $ttl);
}
try {
    foreach (['site_version' => '13', 'suffix' => 'published-suffix'] as $name => $value) {
        $key = 'ace:1:pagekey:' . $host . ':' . $name;
        $r->setex($key, 300, $value); $cleanup[] = $key;
    }
    $target = '/news/?a=1&beta=2'; $other = '/news/?beta=3';
    check(register_uri($target), 'Register target'); check(register_uri($other), 'Register sibling');
    $keys = seed($target); $slash = seed('/news?a=1&beta=2');
    $sibling = seed($other); $unrelated = array_merge(seed('/elsewhere/?beta=2'), seed($target, 'other.' . $host));
    $keep = array_merge($sibling, $unrelated);
    $plugin->purge_url($home . '/news/?beta=2&utm_source=test&a=1'); gone(array_merge($keys, $slash)); intact($keep);
    $keys = seed($target); $plugin->purge_url($home . '/news/?utm_source=test'); gone(array_merge($keys, $sibling)); intact($unrelated);
    gone(AceRedisCache::early_serve_keys([$other], $host, 13, 'published-suffix'));
    intact(AceRedisCache::early_serve_keys([$target], 'other.' . $host, 13, 'published-suffix'));
    // Plain permalinks: exact ?p= targets one post; clean root intentionally covers every root variant.
    check(register_uri('/?p=10'), 'Register plain post'); check(register_uri('/?p=11'), 'Register other post');
    $a = seed('/?p=10'); $b = seed('/?p=11');
    $plugin->purge_url($home . '/?p=10'); gone($a); intact($b);
    $plugin->purge_url($home . '/'); gone($b);
    $foreign = seed('/safe/'); check($plugin->purge_url('https://other.' . $host . '/safe/') === 0, 'Reject foreign host'); intact($foreign);
    $post_key_method = $ref->getMethod('singular_path_index_key'); $post_key_method->setAccessible(true);
    $post_key = $post_key_method->invoke($plugin, 42); $cleanup[] = $post_key;
    check(PagePathIndex::register($r, $home, '/?p=42&view=compact', 300, $post_key), 'Record post identity alias');
    $post_variant = seed('/?p=42&view=compact'); $other_post = seed('/?p=43&view=compact');
    $post_purge = $ref->getMethod('invalidate_post_page_cache'); $post_purge->setAccessible(true);
    $post_purge->invoke($plugin, 42, false); gone($post_variant); intact($other_post);
    check(register_uri('/foreign-check/?view=compact'), 'Record local variant');
    $local_variant = seed('/foreign-check/?view=compact');
    $recache = $ref->getMethod('recache_urls'); $recache->setAccessible(true);
    check($recache->invoke($plugin, ['https://foreign.' . $host . '/foreign-check/']) === 0, 'Reject foreign archive recache');
    intact($local_variant);
    // Bounded admission, refresh at capacity, failed Redis, expiry and TTL extension.
    for ($i = 0; $i < PagePathIndex::LIMIT; $i++) check(register_uri('/bounded/?n=' . $i), 'Register bounded member');
    check(!register_uri('/bounded/?overflow=1'), 'Overflow must refuse storage');
    check(register_uri('/bounded/?n=0', 600), 'Existing member may refresh at capacity');
    $key = PagePathIndex::key($home, '/bounded/');
    check($r->zCard($key) === PagePathIndex::LIMIT, 'Hard bound'); check($r->ttl($key) >= 659, 'TTL extends past page lifetime');
    check(register_uri('/bounded/?n=0', 1), 'Short refresh'); check($r->ttl($key) >= 659, 'Short TTL never shrinks index');
    $failure = new class { function rawCommand(...$args) { return false; } };
    check(!PagePathIndex::register($failure, $home, '/fail/', 300), 'Redis failure refuses storage');
    $failure = new class { function rawCommand(...$args) { throw new RuntimeException('unavailable'); } };
    check(!PagePathIndex::register($failure, $home, '/fail/', 300), 'Redis exception refuses storage');
    $r->zAdd($key, time() - 1, '/bounded/?n=1');
    check(register_uri('/bounded/?replacement=1'), 'Expired member makes room on a busy path');
    check($r->zScore($key, '/bounded/?n=1') === false, 'Expired member pruned');
    $r->expireAt($key, time() - 1); check(register_uri('/bounded/?new=1'), 'Expired index accepts new member'); check($r->zCard($key) === 1, 'Expired members gone');
    check(PagePathIndex::key($home, '/news/') !== PagePathIndex::key($home . '/second-site', '/news/'), 'Multisite path isolation');
    check(PagePathIndex::key($home, '/news/') !== PagePathIndex::key('https://mapped.' . $host, '/news/'), 'Mapped host isolation');
    check(PagePathIndex::key($home, '/news/') !== PagePathIndex::key($home . ':8443', '/news/'), 'Port isolation');
    echo json_encode(['passed'=>true, 'assertions'=>$assertions, 'redis'=>'127.0.0.1:16379']) . "\n";
} finally {
    foreach (array_unique($cleanup) as $key) $r->del($key);
}
