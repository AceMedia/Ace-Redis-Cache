<?php
/** Visitor-triggered refreshes must not wait behind a bulk import's queue. */
require __DIR__ . '/bootstrap-isolated.php';
require dirname(__DIR__) . '/includes/class-ace-redis-cache.php';
use AceMedia\RedisCache\AceRedisCache;
function wp_next_scheduled($hook) { return $GLOBALS['arc_next'] ?? false; }
function wp_unschedule_event($time, $hook) { $GLOBALS['arc_next'] = false; }
function wp_schedule_single_event($time, $hook) { $GLOBALS['arc_next'] = $time; }
function apply_filters($hook, $value, ...$args) { return $value; }
$_SERVER['HTTP_HOST'] = 'example.com';
$r = new class {
    public $pending = ['https://example.com/#mobile', 'https://example.com/', 'https://foreign.test/'];
    function rawCommand($cmd, ...$args) { if ($cmd === 'SPOP') { $out = $this->pending; $this->pending = []; return $out; } }
};
$m = new class($r) {
    public $queue = ['https://example.com/old-import/' => 1, 'https://example.com/' => 2, 'https://example.com/other/' => 3];
    private $redis;
    function __construct($r) { $this->redis = $r; }
    function get_redis_connection() { return $this; }
    function get_connection() { return $this->redis; }
    function get($key) { return $this->queue; }
    function set($key, $value, $ttl) { $this->queue = $value; }
};
$ref = new ReflectionClass(AceRedisCache::class);
$p = $ref->newInstanceWithoutConstructor();
foreach (['settings' => ['enable_page_cache' => 1], 'cache_manager' => $m] as $name => $value) {
    $prop = $ref->getProperty($name); $prop->setAccessible(true); $prop->setValue($p, $value);
}
$GLOBALS['arc_next'] = time() + 300;
$p->drain_early_refresh_queue();
$expected = ['https://example.com/#mobile', 'https://example.com/', 'https://example.com/old-import/', 'https://example.com/other/'];
if (array_keys($m->queue) !== $expected) throw new RuntimeException('Hot desktop/mobile pages did not move ahead of the bulk queue, or a foreign URL was admitted');
if ($GLOBALS['arc_next'] > time() + 6) throw new RuntimeException('Refresh was left on the bulk schedule');
if ($m->queue['https://example.com/old-import/'] !== 1) throw new RuntimeException('Bulk work was lost');
$before = $m->queue; $next = $GLOBALS['arc_next']; $p->drain_early_refresh_queue();
if ($m->queue !== $before || $GLOBALS['arc_next'] !== $next) throw new RuntimeException('Empty drain changed the queue');
echo "PASS: hot pages first, both devices, foreign host rejected, bulk work retained, urgent schedule, empty drain unchanged\n";
