<?php
/**
 * Block cache: eligibility, context rules, keys, reverse index and stats.
 *
 * @package AceMedia\RedisCache
 */

use PHPUnit\Framework\TestCase;
use AceMedia\RedisCache\BlockCache;

if (!function_exists('apply_filters')) {
    function apply_filters($hook_name, $value) {
        return $value;
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) {
        return json_encode($data);
    }
}

class BlockCacheTest extends TestCase {

    private function req(array $over = []) {
        return array_merge([
            'method' => 'GET', 'cli' => false, 'admin' => false, 'ajax' => false, 'rest' => false, 'preview' => false,
            'get' => [], 'cookies' => [], 'woo' => true, 'cart_empty' => null,
            'logged_in' => false, 'logged_in_allowed' => false, 'role_safe' => true,
        ], $over);
    }

    private function profiles() {
        return [
            'woo' => ['setting' => 'block_cache_woo', 'blocks' => ['woocommerce/product-collection', 'woocommerce/product-new']],
            'query' => ['setting' => 'block_cache_query', 'blocks' => ['core/query']],
            'latest_posts' => ['setting' => 'block_cache_latest_posts', 'blocks' => ['core/latest-posts']],
        ];
    }

    private function settings(array $over = []) {
        return array_merge(BlockCache::default_settings(), ['block_cache_enabled' => 1], $over);
    }

    public function testAnonymousGetIsEligible() {
        $this->assertSame('', BlockCache::request_reason($this->req()));
    }

    public function testCartCookiesForceLiveRender() {
        $this->assertSame('cart', BlockCache::request_reason($this->req(['cookies' => ['woocommerce_items_in_cart' => '1']])));
        $this->assertSame('cart', BlockCache::request_reason($this->req(['cookies' => ['woocommerce_cart_hash' => 'abc']])));
        $this->assertSame('cart', BlockCache::request_reason($this->req(['cookies' => ['wp_woocommerce_session_abc' => 'x']])));
        $this->assertSame('cart', BlockCache::request_reason($this->req(['cart_empty' => false])));
        $this->assertSame('', BlockCache::request_reason($this->req(['cookies' => ['woocommerce_items_in_cart' => '0', 'other' => '1']])));
    }

    public function testLoggedInNeedsOptionAndRoleSafeProfile() {
        $this->assertSame('logged-in', BlockCache::request_reason($this->req(['logged_in' => true])));
        $this->assertSame('logged-in', BlockCache::request_reason($this->req(['logged_in' => true, 'logged_in_allowed' => true, 'role_safe' => false])));
        $this->assertSame('', BlockCache::request_reason($this->req(['logged_in' => true, 'logged_in_allowed' => true, 'role_safe' => true])));
    }

    public function testNonGetAdminAndQueryArgsAreLive() {
        $this->assertSame('method', BlockCache::request_reason($this->req(['method' => 'POST'])));
        $this->assertSame('context', BlockCache::request_reason($this->req(['rest' => true])));
        foreach (['query-3-page', 'filter_colour', 'orderby', 'paged', 'product-page', 's', 'min_price', 'add-to-cart'] as $arg) {
            $this->assertSame('query-arg', BlockCache::request_reason($this->req(['get' => [$arg => '2']])), $arg);
        }
        $this->assertSame('', BlockCache::request_reason($this->req(['get' => ['utm_source' => 'x', 'gclid' => 'y']])));
    }

    public function testProfileMatchingAndPerProfileToggle() {
        $b = ['blockName' => 'woocommerce/product-collection', 'attrs' => ['collection' => 'woocommerce/product-collection/featured'], 'innerBlocks' => []];
        $this->assertSame('woo', BlockCache::block_profile($b, $this->profiles(), $this->settings()));
        $this->assertNull(BlockCache::block_profile($b, $this->profiles(), $this->settings(['block_cache_woo' => 0])));
        $this->assertNull(BlockCache::block_profile(['blockName' => 'core/paragraph'], $this->profiles(), $this->settings()));
        $this->assertSame('latest_posts', BlockCache::block_profile(['blockName' => 'core/latest-posts', 'attrs' => []], $this->profiles(), $this->settings()));
    }

    public function testContextDependentBlocksAreSkipped() {
        $s = $this->settings();
        $inherit = ['blockName' => 'core/query', 'attrs' => ['query' => ['inherit' => true]], 'innerBlocks' => []];
        $this->assertNull(BlockCache::block_profile($inherit, $this->profiles(), $s));
        foreach (['related', 'upsells', 'cross-sells', 'product-catalog'] as $c) {
            $b = ['blockName' => 'woocommerce/product-collection', 'attrs' => ['collection' => 'woocommerce/product-collection/' . $c]];
            $this->assertNull(BlockCache::block_profile($b, $this->profiles(), $s), $c);
        }
        $filterable = ['blockName' => 'woocommerce/product-collection', 'attrs' => ['query' => ['filterable' => true]]];
        $this->assertNull(BlockCache::block_profile($filterable, $this->profiles(), $s));
        $nested = ['blockName' => 'core/query', 'attrs' => ['query' => []], 'innerBlocks' => [
            ['blockName' => 'core/group', 'innerBlocks' => [['blockName' => 'woocommerce/mini-cart', 'innerBlocks' => []]]],
        ]];
        $this->assertNull(BlockCache::block_profile($nested, $this->profiles(), $s));
    }

    public function testPaginationDetection() {
        $b = ['blockName' => 'core/query', 'innerBlocks' => [['blockName' => 'core/query-pagination', 'innerBlocks' => []]]];
        $this->assertTrue(BlockCache::has_pagination($b));
        $this->assertFalse(BlockCache::has_pagination(['blockName' => 'core/query', 'innerBlocks' => []]));
    }

    public function testKeysChangeWithGenerationVersionAndVary() {
        $k = BlockCache::build_key('ace_bc:1:example.com:', 0, 'woo', 0, ['a']);
        $this->assertStringStartsWith('ace_bc:1:example.com:b:woo:0.0:', $k);
        $this->assertNotSame($k, BlockCache::build_key('ace_bc:1:example.com:', 1, 'woo', 0, ['a']));
        $this->assertNotSame($k, BlockCache::build_key('ace_bc:1:example.com:', 0, 'woo', 1, ['a']));
        $this->assertNotSame($k, BlockCache::build_key('ace_bc:1:example.com:', 0, 'woo', 0, ['b']));
        $this->assertNotSame($k, BlockCache::build_key('ace_bc:2:example.com:', 0, 'woo', 0, ['a']));
        $this->assertSame($k, BlockCache::build_key('ace_bc:1:example.com:', 0, 'woo', 0, ['a']));
    }

    public function testReverseIndexMembersRoundTrip() {
        $members = [
            BlockCache::index_member('ace_bc:1:h:b:woo:0.0:abc', '/'),
            BlockCache::index_member('ace_bc:1:h:b:woo:0.0:abc', '/shop/'),
            BlockCache::index_member('ace_bc:1:h:b:query:0.0:def', '/'),
            'garbage',
        ];
        list($keys, $paths) = BlockCache::parse_members($members);
        $this->assertSame(['ace_bc:1:h:b:woo:0.0:abc', 'ace_bc:1:h:b:query:0.0:def'], $keys);
        $this->assertSame(['/', '/shop/'], $paths);
    }

    public function testNonceDetection() {
        $this->assertTrue(BlockCache::contains_nonce('<input name="_wpnonce" value="x"> <a href="?_wpnonce=1a2b3c4d5e">'));
        $this->assertTrue(BlockCache::contains_nonce('', [['woocommerce' => ['nonce' => 'abcdef0123']]]));
        $this->assertFalse(BlockCache::contains_nonce('<div class="wc-block-grid">nonce-free 1a2b3c4d5e6f7</div>'));
    }

    public function testOrderChangeDetection() {
        $a = (object) ['post_date' => '2026-01-01', 'post_title' => 'A', 'menu_order' => 0];
        $this->assertFalse(BlockCache::order_changed($a, clone $a));
        $b = clone $a;
        $b->post_title = 'B';
        $this->assertTrue(BlockCache::order_changed($a, $b));
    }

    public function testStatsAggregation() {
        $out = BlockCache::aggregate_stats([
            '20261002' => ['woo|woocommerce/product-collection|h' => '10', 'woo|woocommerce/product-collection|m' => '2', 'woo|woocommerce/product-collection|ms' => '700', 'woo|woocommerce/product-collection|q' => '80'],
            '20261001' => ['query|core/query|h' => '4', 'query|core/query|m' => '1', 'query|core/query|ms' => '50', 'query|core/query|q' => '6'],
        ]);
        $this->assertSame(10, $out['days']['20261002']['hits']);
        $this->assertSame(3500, $out['days']['20261002']['saved_ms']);
        $this->assertSame(400, $out['days']['20261002']['saved_queries']);
        $this->assertSame(14, $out['totals']['hits']);
        $this->assertSame(3500 + 200, $out['totals']['saved_ms']);
        $this->assertSame(350.0, $out['blocks']['woo|woocommerce/product-collection']['avg_ms']);
    }

    public function testSanitizeDefaultsOffAndClampsTtl() {
        $d = BlockCache::default_settings();
        $this->assertSame(0, $d['block_cache_enabled']);
        $this->assertSame(0, $d['block_cache_logged_in']);
        $s = BlockCache::sanitize_settings(['block_cache_enabled' => '1', 'block_cache_ttl' => '5']);
        $this->assertSame(1, $s['block_cache_enabled']);
        $this->assertSame(300, $s['block_cache_ttl']);
        $this->assertSame(0, $s['block_cache_woo']);
    }

    public function testDeltaRoundTrip() {
        $before = ['products' => [1, 2]];
        $after = ['products' => [1, 2, 3], 'x' => ['y' => 1]];
        $d = BlockCache::delta($after, $before);
        $this->assertSame($after, BlockCache::apply_delta($before, $d));
    }
}
