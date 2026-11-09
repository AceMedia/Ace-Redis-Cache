<?php
/**
 * Generation counter flush (#27). flush() moves the site to a new generation with one INCR,
 * keys of the old generation are unreadable at once, other sites sharing the Redis DB keep
 * their keys, and every write path leaves a TTL on what it writes.
 *
 * Needs a local Redis (127.0.0.1:6379, DB 9) and touches only acetest:* keys.
 *
 * @package AceMedia\RedisCache
 */

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}
foreach (['add_action', 'add_filter', 'do_action'] as $fn) {
    if (!function_exists($fn)) { eval("function $fn(...\$a) { return true; }"); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$rest) { return $value; }
}

$GLOBALS['wp_version'] = $GLOBALS['wp_version'] ?? '7.1.3';

/** Passes every call to a real Redis and records the command names. */
class AceRcCountingRedis {
    public $calls = [];
    private $r;
    public function __construct($r) { $this->r = $r; }
    public function __call($name, $args) {
        $this->calls[] = ($name === 'rawCommand') ? strtoupper((string) $args[0]) : strtoupper($name);
        return $this->r->$name(...$args);
    }
}

class GenerationFlushTest extends TestCase {

    private $redis;

    private function redis() {
        if (!class_exists('Redis')) { $this->markTestSkipped('phpredis not loaded'); }
        try {
            $r = new \Redis();
            if (!@$r->connect('127.0.0.1', 6379, 0.5)) { $this->markTestSkipped('no local Redis'); }
            $r->select(9);
            $r->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP); // as the drop-in runs it
        } catch (\Throwable $t) {
            $this->markTestSkipped('no local Redis');
        }
        if (!class_exists('WP_Object_Cache')) {
            require_once dirname(__DIR__, 2) . '/assets/dropins/object-cache.php';
        }
        return $this->redis = $r;
    }

    public function tearDown(): void {
        if ($this->redis) {
            $it = null;
            do {
                $keys = $this->redis->scan($it, 'acetest:*', 1000);
                foreach ((array) $keys as $k) { $this->redis->del($k); }
            } while ($it > 0);
            $this->redis->close();
            $this->redis = null;
        }
        $p = (new \ReflectionClass('WP_Object_Cache'))->getProperty('generation_memo');
        if (PHP_VERSION_ID < 80100) { $p->setAccessible(true); }
        $p->setValue(null, []);
    }

    private function cache($redis, $namespace, $class = 'WP_Object_Cache') {
        $ref = new \ReflectionClass($class);
        $cache = $ref->newInstanceWithoutConstructor();
        $props = [
            'redis' => $redis, 'connected' => true, 'namespace' => $namespace, 'blog_prefix' => '1:',
            'bypass' => false, 'suspend_persistent_writes' => false, 'runtime' => [],
        ];
        foreach ($props as $prop => $value) {
            $p = $ref->getProperty($prop);
            if (PHP_VERSION_ID < 80100) { $p->setAccessible(true); }
            $p->setValue($cache, $value);
        }
        return $cache;
    }

    private function keys($pattern) {
        $it = null;
        $all = [];
        do {
            $keys = $this->redis->scan($it, $pattern, 1000);
            $all = array_merge($all, (array) $keys);
        } while ($it > 0);
        return $all;
    }

    public function testFlushIsOneIncr() {
        $r = $this->redis();
        $site = $this->cache($r, 'acetest:a:');
        for ($i = 0; $i < 300; $i++) { $site->set('k' . $i, 'v' . $i, 'post_meta'); }

        $spy = new AceRcCountingRedis($r);
        $fresh = $this->cache($spy, 'acetest:a:');
        $this->assertSame('v1', $fresh->get('k1', 'post_meta')); // loads the generation
        $spy->calls = [];
        $this->assertTrue($fresh->flush(true));
        $this->assertSame(['INCR'], $spy->calls);
    }

    public function testOldGenerationUnreadableAndOtherSitesKept() {
        $r = $this->redis();
        $a = $this->cache($r, 'acetest:a:');
        $b = $this->cache($r, 'acetest:b:');
        $a->set('post', 'old', 'post_meta');
        $b->set('post', 'mine', 'post_meta');

        $a->flush(true);

        $this->assertFalse($a->get('post', 'post_meta', true));
        $reader = $this->cache($r, 'acetest:a:'); // another request, new process memo
        $this->assertFalse($reader->get('post', 'post_meta', true));

        $otherReader = $this->cache($r, 'acetest:b:');
        $this->assertSame('mine', $otherReader->get('post', 'post_meta', true));

        $a->set('post', 'new', 'post_meta');
        $this->assertSame('new', $this->cache($r, 'acetest:a:')->get('post', 'post_meta', true));
    }

    public function testEveryWriteCarriesATtl() {
        $r = $this->redis();
        $c = $this->cache($r, 'acetest:a:');
        $c->set('s', 'x', 'post_meta');
        $c->set('s2', 'x', 'post_meta', 60);
        $c->add('a', 'x', 'post_meta');
        $c->set_multiple(['m1' => 1, 'm2' => 2], 'post_meta');
        $c->incr('n', 1, 'post_meta');
        $c->decr('d', 1, 'post_meta');

        $keys = array_values(array_diff($this->keys('acetest:a:*'), ['acetest:a:gen']));
        $this->assertGreaterThanOrEqual(6, count($keys));
        foreach ($keys as $k) {
            $this->assertGreaterThan(0, (int) $r->ttl($k), "$k was written without a TTL");
        }
    }

    public function testPurgeRemovesOnlyStaleGenerations() {
        $r = $this->redis();
        $c = $this->cache($r, 'acetest:a:');
        for ($i = 0; $i < 20; $i++) { $c->set('k' . $i, 'x', 'post_meta'); }
        $r->set('acetest:a:1:post_meta:legacy', 'x', ['EX' => 3600]);
        $c->flush(true);
        $c->set('live', 'y', 'post_meta');
        $r->set('acetest:b:keep', 'x', ['EX' => 3600]);

        $this->assertSame(21, $c->purge_stale_generations());
        $this->assertSame('y', $c->get('live', 'post_meta', true));
        $this->assertSame('x', $r->get('acetest:b:keep'));
        $this->assertSame(1, (int) $r->exists('acetest:a:gen'));
    }

    public function testFallsBackToScanWhenGenerationUnavailable() {
        $r = $this->redis();
        $c = $this->cache($r, 'acetest:a:');
        $p = (new \ReflectionClass('WP_Object_Cache'))->getProperty('use_generations');
        if (PHP_VERSION_ID < 80100) { $p->setAccessible(true); }
        $p->setValue($c, false);
        $c->set('k', 'x', 'post_meta');
        $this->assertSame(['acetest:a:1:post_meta:k'], $this->keys('acetest:a:*'));
        $c->flush(true);
        $this->assertSame([], $this->keys('acetest:a:*'));
    }
    private function call($obj, $method, ...$args) {
        $m = new \ReflectionMethod($obj, $method);
        if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }
        return $m->invoke($obj, ...$args);
    }

    private function prop($obj, $name) {
        $p = new \ReflectionProperty($obj, $name);
        if (PHP_VERSION_ID < 80100) { $p->setAccessible(true); }
        return $p->getValue($obj);
    }

    public function testPostChangeInvalidatesThePostNotTheCache() {
        $r = $this->redis();
        $c = $this->cache($r, 'acetest:a:');
        $c->set(42, 'post 42', 'posts');
        $c->set(42, ['meta'], 'post_meta');
        $c->set(7, 'post 7', 'posts');
        $c->set('tfn_topic_found_x', ['count' => 10], 'transient');
        $c->set('last_changed', 'before', 'posts');
        $gen = $r->get('acetest:a:gen');

        $this->call($c, 'flush_everything_for_post', 42);

        $this->assertSame($gen, $r->get('acetest:a:gen'), 'a post change must not move the generation');
        $reader = $this->cache($r, 'acetest:a:');
        $this->assertFalse($reader->get(42, 'posts', true));
        $this->assertFalse($reader->get(42, 'post_meta', true));
        $this->assertNotSame('before', $reader->get('last_changed', 'posts', true));
        $this->assertSame('post 7', $reader->get(7, 'posts', true));
        $this->assertSame(['count' => 10], $reader->get('tfn_topic_found_x', 'transient', true));
    }

    public function testOptedInPostFlushesAreHeldToOnePerWindow() {
        $r = $this->redis();
        $class = get_class(new class extends WP_Object_Cache {
            public function __construct() {}
            protected function flush_on_post_change($post_id) { return true; }
        });
        $c = $this->cache($r, 'acetest:a:', $class);
        $c->set('k', 'x', 'post_meta');
        $start = (int) $r->get('acetest:a:gen');

        for ($i = 1; $i <= 25; $i++) { $this->call($c, 'flush_everything_for_post', $i); }
        $this->assertSame($start + 1, (int) $r->get('acetest:a:gen'), 'a burst of saves flushes once');

        $c->flush_pending_post_changes();
        $this->assertSame($start + 2, (int) $r->get('acetest:a:gen'), 'the held-back changes flush once at shutdown');
        $c->flush_pending_post_changes();
        $this->assertSame($start + 2, (int) $r->get('acetest:a:gen'));
    }

    public function testFlushAsksForAStalePurgeSoon() {
        $r = $this->redis();
        $c = $this->cache($r, 'acetest:a:');
        $c->set('k', 'x', 'post_meta');
        $this->assertFalse($this->prop($c, 'stale_purge_wanted'));
        $c->flush(true);
        $this->assertTrue($this->prop($c, 'stale_purge_wanted'));
    }

    public function testPurgeKeepsTheLiveGenerationWhenThisProcessIsBehind() {
        $r = $this->redis();
        $cron = $this->cache($r, 'acetest:a:');
        $cron->set('old', 'x', 'post_meta'); // the cron process memoises this generation

        $web = $this->cache($r, 'acetest:a:');
        $web->flush(true); // another process moves the site on
        $web->set('live', 'y', 'post_meta');

        $this->assertSame(1, $cron->purge_stale_generations());
        $this->assertSame('y', $this->cache($r, 'acetest:a:')->get('live', 'post_meta', true));
        $this->assertSame(0, (int) $r->exists('acetest:a:purge_lock'));
    }

    public function testOverlappingPurgesStandDown() {
        $r = $this->redis();
        $c = $this->cache($r, 'acetest:a:');
        $c->set('k', 'x', 'post_meta');
        $c->flush(true);
        $r->set('acetest:a:purge_lock', '1', ['EX' => 60]);
        $this->assertSame(0, $c->purge_stale_generations());
    }
}
