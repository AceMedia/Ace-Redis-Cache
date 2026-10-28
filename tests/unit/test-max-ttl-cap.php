<?php
/**
 * Maximum TTL cap (0.8.14). Every object-cache write path stores for at most the cap,
 * shorter expiries are kept, the cap can be turned off, and the generation key keeps no TTL.
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

if (!defined('DAY_IN_SECONDS_T')) { define('DAY_IN_SECONDS_T', 86400); }
$GLOBALS['wp_version'] = $GLOBALS['wp_version'] ?? '7.1.3';

class MaxTtlCapTest extends TestCase {

    private $redis;

    private function redis() {
        if (!class_exists('Redis')) { $this->markTestSkipped('phpredis not loaded'); }
        try {
            $r = new \Redis();
            if (!@$r->connect('127.0.0.1', 6379, 0.5)) { $this->markTestSkipped('no local Redis'); }
            $r->select(9);
            $r->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
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

    private function cache($redis, $max_ttl = null, $extra = []) {
        $ref = new \ReflectionClass('WP_Object_Cache');
        $cache = $ref->newInstanceWithoutConstructor();
        $props = array_merge([
            'redis' => $redis, 'connected' => true, 'namespace' => 'acetest:cap:', 'blog_prefix' => '1:',
            'bypass' => false, 'suspend_persistent_writes' => false, 'runtime' => [], 'max_ttl' => $max_ttl,
        ], $extra);
        foreach ($props as $prop => $value) {
            $p = $ref->getProperty($prop);
            if (PHP_VERSION_ID < 80100) { $p->setAccessible(true); }
            $p->setValue($cache, $value);
        }
        return $cache;
    }

    private function ttl_of($pattern) {
        $it = null;
        $found = [];
        do {
            foreach ((array) $this->redis->scan($it, $pattern, 1000) as $k) { $found[] = $k; }
        } while ($it > 0);
        $this->assertCount(1, $found, "expected one key for $pattern");
        return (int) $this->redis->ttl($found[0]);
    }

    public function testDefaultCapClampsAWeekToADay() {
        $r = $this->redis();
        $c = $this->cache($r); // null = resolve: no constant, no setting -> MAX_TTL
        $c->set('week', 'x', 'post_meta', 7 * DAY_IN_SECONDS_T);
        $ttl = $this->ttl_of('acetest:cap:v*:1:post_meta:week');
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(86400, $ttl);
    }

    public function testShortExpiryKept() {
        $r = $this->redis();
        $c = $this->cache($r);
        $c->set('short', 'x', 'post_meta', 600);
        $ttl = $this->ttl_of('acetest:cap:v*:1:post_meta:short');
        $this->assertGreaterThan(590, $ttl);
        $this->assertLessThanOrEqual(600, $ttl);
    }

    public function testCapOffKeepsAWeek() {
        $r = $this->redis();
        $c = $this->cache($r, 0);
        $c->set('week', 'x', 'post_meta', 7 * DAY_IN_SECONDS_T);
        $this->assertGreaterThan(6 * DAY_IN_SECONDS_T, $this->ttl_of('acetest:cap:v*:1:post_meta:week'));
    }

    public function testAddAndSetMultipleCapped() {
        $r = $this->redis();
        $c = $this->cache($r, 3600);
        $c->add('a', 'x', 'post_meta', 7 * DAY_IN_SECONDS_T);
        $c->set_multiple(['m1' => 1], 'post_meta', 7 * DAY_IN_SECONDS_T);
        $c->set('none', 'x', 'post_meta'); // no expiry: DEFAULT_TTL, then the cap
        foreach (['a', 'm1', 'none'] as $k) {
            $this->assertLessThanOrEqual(3600, $this->ttl_of("acetest:cap:v*:1:post_meta:$k"), $k);
        }
    }

    public function testIncrDecrGetAtMostTheCap() {
        $r = $this->redis();
        $c = $this->cache($r, 3600);
        $c->incr('n', 1, 'post_meta');
        $c->decr('d', 1, 'post_meta');
        foreach (['n', 'd'] as $k) {
            $ttl = $this->ttl_of("acetest:cap:v*:1:post_meta:$k");
            $this->assertGreaterThan(0, $ttl, $k);
            $this->assertLessThanOrEqual(3600, $ttl, $k);
        }
        // A counter that already carries a longer TTL is pulled down to the cap.
        $key = 'acetest:cap:v' . $r->rawCommand('GET', 'acetest:cap:gen') . ':1:post_meta:long';
        $r->rawCommand('SET', $key, '5', 'EX', (string) (7 * DAY_IN_SECONDS_T)); // raw counter, as INCRBY leaves it
        $c->incr('long', 1, 'post_meta');
        $this->assertLessThanOrEqual(3600, (int) $r->ttl($key));
    }

    public function testGenerationKeyKeepsNoTtl() {
        $r = $this->redis();
        $c = $this->cache($r, 60);
        $c->set('k', 'x', 'post_meta', 7 * DAY_IN_SECONDS_T);
        $c->flush(true);
        $c->set('k', 'y', 'post_meta');
        $this->assertSame(-1, (int) $r->ttl('acetest:cap:gen'));
    }

    public function testSharedTransientsCapped() {
        $r = $this->redis();
        $c = $this->cache($r, null, ['runtime_only_mode' => true]);
        $c->set('big_remote_call', 'payload', 'transient', 7 * DAY_IN_SECONDS_T);
        $c->add('other', 'payload', 'site-transient', 7 * DAY_IN_SECONDS_T);
        $this->assertLessThanOrEqual(86400, $this->ttl_of('acetest:cap:*transient:big_remote_call'));
        $this->assertLessThanOrEqual(86400, $this->ttl_of('acetest:cap:*site-transient:other'));
    }
}
