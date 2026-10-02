<?php
/**
 * purge_url() reaches the keys advanced-cache.php serves early.
 *
 * @package AceMedia\RedisCache
 */

use PHPUnit\Framework\TestCase;
use AceMedia\RedisCache\AceRedisCache;

class PurgeUrlEarlyTest extends TestCase {

    /** The drop-in's own key recipe (assets/dropins/advanced-cache.php). */
    private function dropin_candidates($uri, $scheme, $device, $host, $ver, $suffix) {
        $core = 'page_cache:' . $uri . ':' . $scheme . ':' . $device . ':' . $host . ':v' . (int) $ver;
        if ($suffix !== '') {
            $core .= ':' . $suffix;
        }
        return ['page_cache_min:' . $core, 'page_cache:' . $core, $core, 'ace:1:fresh:' . $core];
    }

    public function testCoversEverySchemeDeviceAndCandidate() {
        $keys = AceRedisCache::early_serve_keys(['/topic/culture/', '/topic/culture'], 'talkfuse.com', 7, 'ace-te-3:ace-pc-hl-2');
        foreach (['/topic/culture/', '/topic/culture'] as $uri) {
            foreach (['http', 'https'] as $scheme) {
                foreach (['desktop', 'mobile'] as $device) {
                    foreach ($this->dropin_candidates($uri, $scheme, $device, 'talkfuse.com', 7, 'ace-te-3:ace-pc-hl-2') as $k) {
                        $this->assertTrue(in_array($k, $keys, true), $k);
                    }
                }
            }
        }
        $this->assertSame(32, count($keys));
    }

    public function testEmptySuffixAndVersionZero() {
        $keys = AceRedisCache::early_serve_keys(['/'], 'example.com', 0, '');
        $this->assertTrue(in_array('page_cache_min:page_cache:/:https:mobile:example.com:v0', $keys, true));
        $this->assertTrue(in_array('ace:1:fresh:page_cache:/:http:desktop:example.com:v0', $keys, true));
        $this->assertSame(16, count($keys));
    }
}
