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

    private function cache($redis, $namespace) {
        $ref = new \ReflectionClass('WP_Object_Cache');
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
}
