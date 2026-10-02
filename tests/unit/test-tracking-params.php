<?php
/**
 * Tracking parameters never split the page cache key.
 *
 * @package AceMedia\RedisCache
 */

use PHPUnit\Framework\TestCase;
use AceMedia\RedisCache\AceRedisCache;

class TrackingParamsTest extends TestCase {

    public function testAdAndShoppingClickIdsAreDropped() {
        $uris = [
            '/?gad_source=1&gad_campaignid=22912345678&gclid=Cj0KCQ',
            '/?gclsrc=aw.ds&gbraid=0AAAAA&wbraid=CjkK&dclid=CJ',
            '/?srsltid=AfmBOoq',
            '/?hsa_acc=1&hsa_cam=2&_hsenc=p2A&_hsmi=9&_kx=abc',
            '/?li_fat_id=1&epik=dj0&yclid=7&utm_source=google&utm_medium=cpc',
        ];
        foreach ($uris as $uri) {
            $this->assertSame('/', AceRedisCache::normalize_request_uri($uri), $uri);
        }
    }

    public function testRealParametersAreKeptAndSorted() {
        $this->assertSame(
            '/shop/?orderby=price&page=2',
            AceRedisCache::normalize_request_uri('/shop/?srsltid=x&page=2&gad_source=1&orderby=price')
        );
        // Prefix matches are anchored: a parameter that merely starts like one is kept.
        $this->assertSame('/?gad_sourcex=1', AceRedisCache::normalize_request_uri('/?gad_sourcex=1'));
    }

    public function testDropinCarriesTheSameList() {
        $root = dirname(__DIR__, 2);
        $pattern = '#preg_match\(\'(/\^\(utm_[^\']+/i)\'#';
        preg_match($pattern, file_get_contents($root . '/includes/class-ace-redis-cache.php'), $class);
        preg_match($pattern, file_get_contents($root . '/assets/dropins/advanced-cache.php'), $dropin);
        $this->assertNotEmpty($class[1] ?? '');
        $this->assertSame($class[1], $dropin[1] ?? '');
    }
}
