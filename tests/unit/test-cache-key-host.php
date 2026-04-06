<?php
/**
 * Cache key host normalization tests
 *
 * @package AceMedia\RedisCache
 */

use PHPUnit\Framework\TestCase;
use AceMedia\RedisCache\AceRedisCache;

if (!function_exists('apply_filters')) {
    function apply_filters($hook_name, $value) {
        return $value;
    }
}

class CacheKeyHostTest extends TestCase {

    public function testBuildPageCacheCoreKeyIncludesNormalizedHost() {
        $reflection = new ReflectionClass(AceRedisCache::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $method = $reflection->getMethod('build_page_cache_core_key');
        $method->setAccessible(true);

        $key = $method->invoke($instance, '/shop/', 'https', 'desktop', 7, 'Example.COM:8443');

        $this->assertSame('page_cache:/shop/:https:desktop:example.com:v7', $key);
    }
}
