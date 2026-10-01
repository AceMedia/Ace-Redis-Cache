<?php
/**
 * Varnish bridge ban regex tests
 *
 * @package AceMedia\RedisCache
 */

use PHPUnit\Framework\TestCase;
use AceMedia\RedisCache\VarnishBridge;

class VarnishBridgeTest extends TestCase {

    private function matches($path, $url) {
        return (bool) preg_match('#' . str_replace('#', '\#', VarnishBridge::path_regex($path)) . '#', $url);
    }

    public function testPathRegexMatchesPageWithAndWithoutSlashOrQuery() {
        foreach (['/events/foo/', '/events/foo', '/events/foo/?utm=1', '/events/foo?x=y'] as $url) {
            $this->assertTrue($this->matches('/events/foo/', $url), $url);
        }
    }

    public function testPathRegexDoesNotMatchOtherPages() {
        foreach (['/events/foo-bar/', '/events/foo/child/', '/x/events/foo/'] as $url) {
            $this->assertFalse($this->matches('/events/foo/', $url), $url);
        }
    }

    public function testHomeOnlyMatchesHome() {
        $this->assertTrue($this->matches('/', '/'));
        $this->assertTrue($this->matches('/', '/?s=x'));
        $this->assertFalse($this->matches('/', '/about/'));
    }

    public function testRegexCharactersInPathAreEscaped() {
        $this->assertTrue($this->matches('/a.b+c/', '/a.b+c/'));
        $this->assertFalse($this->matches('/a.b+c/', '/axbbc/'));
    }

    public function testUnsafePathsAreRejected() {
        $this->assertNull(VarnishBridge::path_regex("/a b/"));
        $this->assertNull(VarnishBridge::path_regex("/a\r\nX: y"));
        $this->assertNull(VarnishBridge::path_regex(''));
    }
}
