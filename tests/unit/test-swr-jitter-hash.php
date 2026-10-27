<?php
/**
 * #28: fresh TTL jitter bounds and the change-detection hash.
 *
 * @package AceMedia\RedisCache
 */

use PHPUnit\Framework\TestCase;
use AceMedia\RedisCache\AceRedisCache;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}
if (!class_exists(AceRedisCache::class)) {
    require_once dirname(__DIR__, 2) . '/includes/class-ace-redis-cache.php';
}

class SwrJitterHashTest extends TestCase {

    public function testJitterOffLeavesTtlAlone() {
        $this->assertSame(3600, AceRedisCache::jittered_ttl(3600, 0));
    }

    public function testJitterOnlyShortensWithinThePercentage() {
        $seen = [];
        for ($i = 0; $i < 500; $i++) {
            $ttl = AceRedisCache::jittered_ttl(3600, 10);
            $this->assertGreaterThanOrEqual(3240, $ttl);
            $this->assertLessThanOrEqual(3600, $ttl);
            $seen[$ttl] = true;
        }
        $this->assertGreaterThan(50, count($seen), 'jitter should spread values');
        // Clamped at 50%.
        for ($i = 0; $i < 200; $i++) {
            $this->assertGreaterThanOrEqual(1800, AceRedisCache::jittered_ttl(3600, 90));
        }
    }

    public function testChangeHashUsesXxh128WhereAvailable() {
        if (!class_exists('WP_Object_Cache')) {
            require_once dirname(__DIR__, 2) . '/assets/dropins/object-cache.php';
        }
        $value = ['a' => 1, 'b' => [true, 'x']];
        $expected = in_array('xxh128', hash_algos(), true) ? hash('xxh128', serialize($value)) : md5(serialize($value));
        $this->assertSame($expected, WP_Object_Cache::change_hash($value));
        $this->assertSame(WP_Object_Cache::change_hash($value), WP_Object_Cache::change_hash(['a' => 1, 'b' => [true, 'x']]));
        $this->assertNotSame(WP_Object_Cache::change_hash($value), WP_Object_Cache::change_hash(['a' => 2, 'b' => [true, 'x']]));
    }
}
