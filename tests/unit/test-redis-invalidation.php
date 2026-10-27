<?php
/**
 * Batched invalidation (#26): the assertions from the redis-invalidation experiment.
 * Page invalidation empties every page key in one pipelined round trip, the drop-in's
 * namespace flush empties the namespace in UNLINK batches, and unrelated keys keep their TTLs.
 *
 * The Redis cases need a local server (127.0.0.1:6379, DB 9) and touch only acetest:* keys.
 *
 * @package AceMedia\RedisCache
 */

use PHPUnit\Framework\TestCase;
use AceMedia\RedisCache\AceRedisCache;
use AceMedia\RedisCache\RedisBatch;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}
if (!class_exists(RedisBatch::class)) {
    require_once dirname(__DIR__, 2) . '/includes/class-redis-batch.php';
}
if (!class_exists(AceRedisCache::class)) {
    require_once dirname(__DIR__, 2) . '/includes/class-ace-redis-cache.php';
}

/** Records what a pipeline would send, so round trips and commands can be counted. */
class AceRcPipelineSpy {
    public $round_trips = 0;
    public $commands = [];
    private $queued = 0;
    public function pipeline() { return $this; }
    public function rawCommand(...$args) { $this->round_trips++; return 0; }
    public function unlink($key) { $this->commands[] = ['UNLINK', $key]; $this->queued++; return $this; }
    public function del($key) { $this->commands[] = ['DEL', $key]; $this->queued++; return $this; }
    public function exec() { $this->round_trips++; $n = $this->queued; $this->queued = 0; return array_fill(0, $n, 1); }
}

class RedisInvalidationTest extends TestCase {

    private $redis;

    private function cores($path, array $versions) {
        $cores = [];
        foreach (['http', 'https'] as $scheme) {
            foreach (['desktop', 'mobile'] as $device) {
                foreach ($versions as $ver) {
                    $cores[] = ['page_cache:acetest:' . $path . ':' . $scheme . ':' . $device . ':example.com:v' . $ver, $ver];
                }
            }
        }
        return $cores;
    }

    private function redis() {
        if (!class_exists('Redis')) {
            $this->markTestSkipped('phpredis not loaded');
        }
        try {
            $r = new \Redis();
            if (!@$r->connect('127.0.0.1', 6379, 0.5)) {
                $this->markTestSkipped('no local Redis');
            }
            $r->select(9);
        } catch (\Throwable $t) {
            $this->markTestSkipped('no local Redis');
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
        RedisBatch::reset_capability();
    }

    public function testKeysPerPathAndLegacyDuplicatesDropped() {
        // Two versions: 2 x 2 x 2 x 2 versioned keys plus the 3 legacy keys per scheme and
        // device once (they are identical for both versions), so 28 rather than 40.
        $keys = AceRedisCache::page_keys_for_cores($this->cores('/a/', [7, 6]));
        $this->assertSame(28, count($keys));
        $this->assertSame(count($keys), count(array_unique($keys)));
        $this->assertContains('page_cache_min:page_cache:acetest:/a/:https:mobile:example.com:v6', $keys);
        $this->assertContains('page_cache:acetest:/a/:http:desktop:example.com', $keys);
        // One version: 5 keys per scheme and device.
        $this->assertSame(20, count(AceRedisCache::page_keys_for_cores($this->cores('/a/', [0]))));
    }

    public function testOnePathIsOneRoundTripOfSingleKeyUnlinks() {
        $spy = new AceRcPipelineSpy();
        RedisBatch::reset_capability(true);
        $keys = AceRedisCache::page_keys_for_cores($this->cores('/a/', [7, 6]));
        $deleted = RedisBatch::delete_keys($spy, $keys);
        $this->assertSame(1, $spy->round_trips);
        $this->assertSame(count($keys), count($spy->commands));
        $this->assertSame(count($keys), $deleted);
        foreach ($spy->commands as $cmd) {
            $this->assertSame('UNLINK', $cmd[0]);
        }
    }

    public function testFallsBackToDelWithoutUnlink() {
        $spy = new AceRcPipelineSpy();
        RedisBatch::reset_capability(false);
        RedisBatch::delete_keys($spy, ['acetest:x', 'acetest:y', 'acetest:x']);
        $this->assertSame([['DEL', 'acetest:x'], ['DEL', 'acetest:y']], $spy->commands);
    }

    public function testBatchesOfFiveHundred() {
        $spy = new AceRcPipelineSpy();
        RedisBatch::reset_capability(true);
        $keys = [];
        for ($i = 0; $i < 1201; $i++) { $keys[] = 'acetest:k' . $i; }
        RedisBatch::delete_keys($spy, $keys);
        $this->assertSame(3, $spy->round_trips);
    }

    public function testPageInvalidationAgainstRedis() {
        $r = $this->redis();
        $page_keys = [];
        foreach (['/a/', '/b'] as $path) {
            $page_keys = array_merge($page_keys, AceRedisCache::page_keys_for_cores($this->cores($path, [3, 2])));
        }
        foreach ($page_keys as $k) { $r->set($k, 'x', ['EX' => 3600]); }
        for ($i = 0; $i < 50; $i++) { $r->set('acetest:other:' . $i, 'keep', ['EX' => 3600]); }

        $this->assertSame(count($page_keys), RedisBatch::delete_keys($r, $page_keys));
        foreach ($page_keys as $k) {
            $this->assertSame(0, (int) $r->exists($k), $k);
        }
        $this->assertUnrelatedIntact($r);
    }

    public function testDropinNamespaceFlushAgainstRedis() {
        $r = $this->redis();
        if (!class_exists('WP_Object_Cache')) {
            require_once dirname(__DIR__, 2) . '/assets/dropins/object-cache.php';
        }
        for ($i = 0; $i < 1200; $i++) { $r->set('acetest:ns:' . $i, 'x', ['EX' => 3600]); }
        for ($i = 0; $i < 50; $i++) { $r->set('acetest:other:' . $i, 'keep', ['EX' => 3600]); }

        $ref = new \ReflectionClass('WP_Object_Cache');
        $cache = $ref->newInstanceWithoutConstructor();
        foreach (['redis' => $r, 'namespace' => 'acetest:ns:', 'bypass' => false, 'suspend_persistent_writes' => false, 'runtime' => [], 'use_generations' => false] as $prop => $value) {
            $p = $ref->getProperty($prop);
            if (PHP_VERSION_ID < 80100) { $p->setAccessible(true); }
            $p->setValue($cache, $value);
        }
        $this->assertTrue($cache->flush(true));

        $it = null;
        $left = [];
        do {
            $keys = $r->scan($it, 'acetest:ns:*', 1000);
            $left = array_merge($left, (array) $keys);
        } while ($it > 0);
        $this->assertSame([], $left);
        $this->assertUnrelatedIntact($r);
    }

    private function assertUnrelatedIntact($r) {
        for ($i = 0; $i < 50; $i++) {
            $this->assertSame('keep', $r->get('acetest:other:' . $i));
            $ttl = (int) $r->ttl('acetest:other:' . $i);
            $this->assertGreaterThan(3500, $ttl);
        }
    }
}
