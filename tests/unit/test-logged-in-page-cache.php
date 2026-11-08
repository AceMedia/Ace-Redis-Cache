<?php
/**
 * Signed-in page cache: groups, the stored copy's tokens and the identity guard.
 *
 * @package AceMedia\RedisCache
 */

use PHPUnit\Framework\TestCase;
use AceMedia\RedisCache\LoggedInPageCache;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}
require_once dirname(__DIR__, 2) . '/includes/class-logged-in-page-cache.php';

class LoggedInPageCacheTest extends TestCase {

    private function can(array $caps) {
        return function ($cap) use ($caps) { return in_array($cap, $caps, true); };
    }

    private function all_on() {
        return ['page_cache_li_admins' => 1, 'page_cache_li_editors' => 1, 'page_cache_li_readers' => 1, 'wc_page_cache_customers' => 1];
    }

    public function testGroupsFollowCapabilities() {
        $s = $this->all_on();
        $this->assertSame('admin', LoggedInPageCache::group($s, $this->can(['manage_options', 'edit_posts'])));
        $this->assertSame('editor', LoggedInPageCache::group($s, $this->can(['edit_others_posts', 'edit_others_pages', 'edit_posts'])));
        $this->assertSame('reader', LoggedInPageCache::group($s, $this->can(['read'])));
        $this->assertSame('customer', LoggedInPageCache::group($s, $this->can(['read']), true));
    }

    public function testAuthorsAndContributorsAreNeverCached() {
        $this->assertNull(LoggedInPageCache::group($this->all_on(), $this->can(['edit_posts'])));
        $this->assertNull(LoggedInPageCache::group($this->all_on(), $this->can(['edit_posts', 'edit_others_posts'])));
    }

    public function testGroupsCanBeTurnedOff() {
        $this->assertNull(LoggedInPageCache::group(['page_cache_li_admins' => 0], $this->can(['manage_options'])));
        // WooCommerce customers need their own switch, off by default.
        $this->assertNull(LoggedInPageCache::group(['page_cache_li_readers' => 1], $this->can(['read']), true));
    }

    public function testEveryVariantIsListedForPurges() {
        $all = LoggedInPageCache::all_variants();
        foreach (LoggedInPageCache::GROUPS as $g) {
            $this->assertContains(LoggedInPageCache::variant($g, true), $all);
            $this->assertContains(LoggedInPageCache::variant($g, false), $all);
        }
        $this->assertSame('li-editor-ab', LoggedInPageCache::variant('editor', true));
    }

    public function testNoncesAreSwappedForTheViewersOwn() {
        $alice = function ($action) { return ['wp_rest' => 'aaaaaaaaa1', 'log-out' => 'aaaaaaaaa2'][$action] ?? ''; };
        $bob = function ($action) { return ['wp_rest' => 'bbbbbbbbb1', 'log-out' => 'bbbbbbbbb2'][$action] ?? ''; };
        $html = '<script>var wpApiSettings={"nonce":"aaaaaaaaa1"};</script><a href="?_wpnonce=aaaaaaaaa2">out</a>';
        $stored = LoggedInPageCache::to_stored($html, ['wp_rest', 'log-out', 'unused'], $alice);
        $this->assertStringNotContainsString('aaaaaaaaa1', $stored);
        $this->assertStringNotContainsString('aaaaaaaaa2', $stored);
        $served = LoggedInPageCache::from_stored($stored, $bob, '');
        $this->assertSame('<script>var wpApiSettings={"nonce":"bbbbbbbbb1"};</script><a href="?_wpnonce=bbbbbbbbb2">out</a>', $served);
    }

    public function testToolbarIsCutOutAndPutBack() {
        $html = '<body>' . LoggedInPageCache::BAR_START . '<div id="wpadminbar">Howdy, Alice</div>' . LoggedInPageCache::BAR_END . '<main>x</main></body>';
        $none = function () { return ''; };
        $stored = LoggedInPageCache::to_stored($html, [], $none);
        $this->assertStringNotContainsString('Alice', $stored);
        $this->assertNull(LoggedInPageCache::identity_block_reason($stored, (object) ['user_email' => 'alice@example.com']));
        $this->assertSame('<body><div id="wpadminbar">Howdy, Bob</div><main>x</main></body>', LoggedInPageCache::from_stored($stored, $none, '<div id="wpadminbar">Howdy, Bob</div>'));
        $this->assertSame('<body><div id="wpadminbar">Howdy, Alice</div><main>x</main></body>', LoggedInPageCache::strip_markers($html));
    }

    public function testCopiesThatStillNameTheUserAreRefused() {
        $u = (object) ['user_email' => 'alice@example.com'];
        $this->assertSame('li_user_email', LoggedInPageCache::identity_block_reason('<p>alice@example.com</p>', $u));
        $this->assertSame('li_comment_form', LoggedInPageCache::identity_block_reason('<p class="logged-in-as">Logged in as Alice</p>', $u));
        $this->assertSame('li_toolbar_left', LoggedInPageCache::identity_block_reason('<div id="wpadminbar"></div>', $u));
        $this->assertNull(LoggedInPageCache::identity_block_reason('<p>By Alice Smith</p>', $u));
    }
}
