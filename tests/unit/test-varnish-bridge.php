<?php
/**
 * Varnish bridge ban regex tests
 *
 * @package AceMedia\RedisCache
 */

use PHPUnit\Framework\TestCase;
use AceMedia\RedisCache\VarnishBridge;

class VarnishBridgeTest extends TestCase {

    private function pathMatches($path, $url) {
        return (bool) preg_match('#' . str_replace('#', '\#', VarnishBridge::path_regex($path)) . '#', $url);
    }

    public function testPathRegexMatchesPageWithAndWithoutSlashOrQuery() {
        foreach (['/events/foo/', '/events/foo', '/events/foo/?utm=1', '/events/foo?x=y'] as $url) {
            $this->assertTrue($this->pathMatches('/events/foo/', $url), $url);
        }
    }

    public function testPathRegexDoesNotMatchOtherPages() {
        foreach (['/events/foo-bar/', '/events/foo/child/', '/x/events/foo/'] as $url) {
            $this->assertFalse($this->pathMatches('/events/foo/', $url), $url);
        }
    }

    public function testHomeOnlyMatchesHome() {
        $this->assertTrue($this->pathMatches('/', '/'));
        $this->assertTrue($this->pathMatches('/', '/?s=x'));
        $this->assertFalse($this->pathMatches('/', '/about/'));
    }

    public function testRegexCharactersInPathAreEscaped() {
        $this->assertTrue($this->pathMatches('/a.b+c/', '/a.b+c/'));
        $this->assertFalse($this->pathMatches('/a.b+c/', '/axbbc/'));
    }

    public function testUnsafePathsAreRejected() {
        $this->assertNull(VarnishBridge::path_regex("/a b/"));
        $this->assertNull(VarnishBridge::path_regex("/a\r\nX: y"));
        $this->assertNull(VarnishBridge::path_regex(''));
    }
}
