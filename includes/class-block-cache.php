<?php
/**
 * Block HTML cache: keeps the rendered HTML of expensive dynamic blocks (WooCommerce product
 * grids, Query Loops, Latest Posts, opted-in custom blocks) in Redis and replays it on later
 * renders, together with everything the render added to the page outside its own HTML
 * (Interactivity API state/config, enqueued styles/scripts/script modules, wcSettings data,
 * block-supports CSS, unique id counters, analytics loop trackers).
 *
 * Generalised from the iegemea.com mu-plugin (EgbertTaylor#38).
 *
 * Safety rules
 * - Only GET front-end renders, never admin/AJAX/REST/previews or URLs with paging/filter/sort args.
 * - Never with anything in the WooCommerce cart (cookies, session cookie or a loaded cart).
 * - Signed-in users get a live render unless "cache for logged-in users" is on AND the profile is
 *   role-safe (no role/B2B/membership pricing, no tax by customer address). Then the key includes the roles.
 * - Blocks that depend on the current page or URL (inherit query, related/upsells/cross-sells,
 *   filterable collections, carts/account blocks inside) are never cached.
 * - Only cached when the block is the Interactivity API root, and never when the render produced a
 *   nonce (per-request token) in its HTML or state.
 *
 * Invalidation
 * - Every post/product read during a render is a dependency, kept in a Redis set per post id
 *   (reverse index). Changing that post deletes just those entries and purges the pages that showed them.
 * - Events that change which posts a block shows (publish/unpublish, terms, order, featured...) bump
 *   that profile's version, which retires all its entries at once. TTL is the safety net.
 * - Purges go through AceRedisCache::purge_url(), which also bans the page in Varnish.
 *
 * Off switches: ACE_RC_BLOCK_CACHE (true/false, overrides the setting), the ace_rc_block_cache_enabled
 * filter, and ace_rc_block_cache_cacheable (per block). Debug: X-Ace-Block-Cache response header.
 *
 * @package AceMedia\RedisCache
 */

namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) {
    exit;
}

class BlockCache {

    const VER = 1;
    const CLOSURE = '__ace_bc_closure__';
    const DEFAULT_TTL = 43200;

    /** @var BlockCache|null */
    private static $instance = null;

    private $plugin;
    private $cache_manager;
    private $settings;
    private $ttl;

    private $depth = 0;
    private $open_interactive = 0;
    private $versions = null;
    private $base_reason = null;
    private $notes = [];
    private $stats = [];
    private $purge_paths = [];
    private $shutdown_hooked = false;

    /* ------------------------------------------------------------ settings */

    public static function default_settings() {
        return [
            'block_cache_enabled'      => 0,
            'block_cache_ttl'          => self::DEFAULT_TTL,
            'block_cache_woo'          => 1,
            'block_cache_query'        => 1,
            'block_cache_latest_posts' => 1,
            'block_cache_custom'       => 1,
            'block_cache_logged_in'    => 0,
        ];
    }

    /** Shared by every settings sanitiser (REST, Settings API, AJAX). */
    public static function sanitize_settings($input) {
        $input = is_array($input) ? $input : [];
        $out = [];
        foreach (self::default_settings() as $k => $default) {
            if ($k === 'block_cache_ttl') {
                $out[$k] = max(300, min(7 * 86400, (int) ($input[$k] ?? $default)));
            } else {
                $out[$k] = !empty($input[$k]) ? 1 : 0;
            }
        }
        return $out;
    }

    /** The setting, overridden by ACE_RC_BLOCK_CACHE and the ace_rc_block_cache_enabled filter. */
    public static function is_enabled(array $settings) {
        $on = !empty($settings['block_cache_enabled']);
        if (defined('ACE_RC_BLOCK_CACHE')) {
            $on = (bool) ACE_RC_BLOCK_CACHE;
        }
        return (bool) apply_filters('ace_rc_block_cache_enabled', $on);
    }

    /** The iegemea.com mu-plugin does the same job; stay out of its way while it is loaded. */
    public static function legacy_module_active() {
        return function_exists('ace_bhc_pre_render');
    }

    public static function instance() {
        return self::$instance;
    }

    /* ------------------------------------------------------------ boot */

    public static function boot($plugin, $cache_manager, array $settings) {
        if (self::$instance || !$cache_manager || self::legacy_module_active()) {
            return;
        }
        $self = new self();
        $self->plugin = $plugin;
        $self->cache_manager = $cache_manager;
        $self->settings = array_merge(self::default_settings(), $settings);
        $self->ttl = max(300, (int) $self->settings['block_cache_ttl']);
        self::$instance = $self;

        // A full cache clear also retires every block entry (no SCAN: the generation moves on).
        add_action('ace_rc_cache_cleared', static function () use ($self) { $self->flush(false); }, 5, 0);
        add_action('update_option_' . SettingsStore::SETTINGS_OPTION, static function () use ($self) { $self->flush(false); }, 20, 0);
        add_action('update_site_option_' . SettingsStore::SETTINGS_OPTION, static function () use ($self) { $self->flush(false); }, 20, 0);

        if (!self::is_enabled($self->settings)) {
            return;
        }
        $self->register_invalidation_hooks();
        if (!is_admin()) {
            add_filter('pre_render_block', [$self, 'pre_render'], 20, 3);
            add_filter('render_block_data', [$self, 'track_open'], PHP_INT_MAX, 1);
            add_filter('render_block', [$self, 'track_close'], PHP_INT_MAX, 2);
        }
    }

    /* ------------------------------------------------------------ profiles */

    public static function woo_active() {
        return class_exists('WooCommerce');
    }

    /**
     * Profiles: which blocks they cache, which post types count as dependencies, and whether
     * the output only varies by role (so it may be cached per role for signed-in users).
     */
    public static function profiles() {
        $profiles = [];
        if (self::woo_active()) {
            $profiles['woo'] = [
                'label' => 'WooCommerce product blocks',
                'setting' => 'block_cache_woo',
                // woocommerce/all-products renders client-side from the Store API: nothing to cache.
                'blocks' => (array) apply_filters('ace_rc_block_cache_woo_blocks', [
                    'woocommerce/product-collection',
                    'woocommerce/handpicked-products',
                    'woocommerce/product-new',
                    'woocommerce/product-on-sale',
                    'woocommerce/product-best-sellers',
                    'woocommerce/product-top-rated',
                    'woocommerce/product-category',
                    // AceMedia's A.C.E. Checkout Engine product block (uni-carts): one fixed product, nonces live in enqueued script data.
                    'ace/product-variations',
                ]),
                'dep_types' => ['product', 'product_variation'],
                'role_safe' => self::woo_role_safe(),
                'bump_on_update' => false,
            ];
        }
        $profiles['query'] = [
            'label' => 'Query Loop blocks',
            'setting' => 'block_cache_query',
            'blocks' => ['core/query'],
            'dep_types' => null,
            'role_safe' => true,
            'bump_on_update' => false,
        ];
        $profiles['latest_posts'] = [
            'label' => 'Latest Posts',
            'setting' => 'block_cache_latest_posts',
            'blocks' => ['core/latest-posts'],
            'dep_types' => null,
            'role_safe' => true,
            'bump_on_update' => false,
        ];
        /**
         * Custom blocks to cache: [ 'ns/block' , ... ]. Listing order often depends on meta
         * (event dates), so any update to a published post retires the whole profile.
         */
        $custom = array_values(array_filter((array) apply_filters('ace_rc_block_cache_custom_blocks', []), 'is_string'));
        $profiles['custom'] = [
            'label' => 'Custom blocks',
            'setting' => 'block_cache_custom',
            'blocks' => $custom,
            'dep_types' => null,
            'role_safe' => (bool) apply_filters('ace_rc_block_cache_custom_role_safe', false),
            'bump_on_update' => true,
        ];
        return apply_filters('ace_rc_block_cache_profiles', $profiles);
    }

    /**
     * WooCommerce prices only vary by role if nothing per-customer is in play. Role/B2B/membership
     * pricing plugins and tax by customer address make it per user: not role-safe.
     */
    public static function woo_role_safe() {
        static $safe = null;
        if ($safe !== null) {
            return $safe;
        }
        $safe = true;
        foreach (['WC_Memberships', 'B2bkingcore', 'B2bking', 'WooCommerceWholeSalePrices', 'WWP_Wholesale_Prices', 'Addify_B2B_Plugin', 'WCB2B', 'Wholesale_Market'] as $class) {
            if (class_exists($class)) {
                $safe = false;
            }
        }
        $plugins = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $plugins = array_merge($plugins, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }
        foreach ($plugins as $p) {
            if (preg_match('/wholesale|b2b|role-based-pric|price-by-role|prices-by-user-role|memberships|dynamic-pricing|finance-role|customer-specific-pric/i', (string) $p)) {
                $safe = false;
            }
        }
        if (get_option('woocommerce_calc_taxes') === 'yes' && get_option('woocommerce_tax_based_on', 'shipping') !== 'base') {
            $safe = false;
        }
        $safe = (bool) apply_filters('ace_rc_block_cache_woo_role_safe', $safe);
        return $safe;
    }

    /** Blocks that read the visitor, the cart or the current URL: a tree holding one is never cached. */
    public static function context_blocks() {
        return apply_filters('ace_rc_block_cache_context_blocks', [
            'woocommerce/mini-cart', 'woocommerce/cart', 'woocommerce/checkout', 'woocommerce/customer-account',
            'woocommerce/product-filters', 'woocommerce/active-filters', 'woocommerce/attribute-filter',
            'woocommerce/price-filter', 'woocommerce/stock-filter', 'woocommerce/rating-filter',
            'woocommerce/catalog-sorting', 'woocommerce/product-results-count', 'woocommerce/store-notices',
            'core/loginout', 'core/post-comments-form', 'core/comments',
        ]);
    }

    /**
     * Which profile caches this block, or null. Pure apart from filters, so it can be unit tested.
     *
     * @param array $block    Parsed block.
     * @param array $profiles profiles() output.
     * @param array $settings Plugin settings.
     */
    public static function block_profile(array $block, array $profiles, array $settings) {
        $name = (string) ($block['blockName'] ?? '');
        if ($name === '') {
            return null;
        }
        foreach ($profiles as $id => $profile) {
            if (!in_array($name, (array) $profile['blocks'], true)) {
                continue;
            }
            if (empty($settings[$profile['setting']])) {
                return null;
            }
            return self::context_dependent($block) ? null : $id;
        }
        return null;
    }

    /** True when the block's output depends on the page being viewed or the visitor. */
    public static function context_dependent(array $block) {
        $attrs = $block['attrs'] ?? [];
        if (!empty($attrs['query']['inherit']) || !empty($attrs['query']['filterable'])) {
            return true;
        }
        $collection = (string) ($attrs['collection'] ?? '');
        if (preg_match('#/(related|upsells|cross-sells|by-brand|product-catalog|by-category|by-tag)$#', $collection)) {
            // by-category/by-tag/by-brand read the current term when shown on an archive.
            return true;
        }
        // Product Collection "for the current product/term" rely on location context.
        if (!empty($attrs['__privateProductCollectionPreviewState']) || (isset($attrs['query']['woocommerceHandPickedProducts']) && !empty($attrs['dimensions']['inherit']))) {
            return true;
        }
        $context_blocks = self::context_blocks();
        $stack = [$block];
        while ($stack) {
            $b = array_pop($stack);
            if (in_array($b['blockName'] ?? '', $context_blocks, true)) {
                return true;
            }
            foreach ((array) ($b['innerBlocks'] ?? []) as $inner) {
                $stack[] = $inner;
            }
        }
        return false;
    }

    /** True when any block in the tree is a pagination block (its links use the current URL). */
    public static function has_pagination(array $block) {
        $stack = [$block];
        while ($stack) {
            $b = array_pop($stack);
            if (strpos((string) ($b['blockName'] ?? ''), 'pagination') !== false) {
                return true;
            }
            foreach ((array) ($b['innerBlocks'] ?? []) as $inner) {
                $stack[] = $inner;
            }
        }
        return false;
    }

    /* ------------------------------------------------------------ request eligibility */

    /**
     * Why this request must render live, or '' when it may use the cache. Pure, for tests.
     *
     * $r keys: method, cli, admin, ajax, rest, preview, get (array), cookies (array),
     * woo (bool), cart_empty (bool|null, null = cart not loaded), logged_in, logged_in_allowed, role_safe.
     */
    public static function request_reason(array $r) {
        if (!empty($r['admin']) || !empty($r['ajax']) || !empty($r['rest']) || !empty($r['preview'])) {
            return 'context';
        }
        if (strtoupper((string) ($r['method'] ?? 'GET')) !== 'GET' && empty($r['cli'])) {
            return 'method';
        }
        foreach (array_keys((array) ($r['get'] ?? [])) as $param) {
            if (preg_match('/^(query-|filter_|min_price|max_price|rating_filter|orderby|order$|paged|page$|product-page|s$|add-to-cart|post_type|cat$|tag$|author|preview|_wpnonce)/', (string) $param)) {
                return 'query-arg';
            }
        }
        if (!empty($r['woo'])) {
            foreach ((array) ($r['cookies'] ?? []) as $name => $value) {
                $name = (string) $name;
                if (($name === 'woocommerce_items_in_cart' || $name === 'woocommerce_cart_hash') && (string) $value !== '' && (string) $value !== '0') {
                    return 'cart';
                }
                if (strpos($name, 'wp_woocommerce_session_') === 0) {
                    return 'cart';
                }
            }
            if (($r['cart_empty'] ?? null) === false) {
                return 'cart';
            }
        }
        if (!empty($r['logged_in']) && (empty($r['logged_in_allowed']) || empty($r['role_safe']))) {
            return 'logged-in';
        }
        return '';
    }

    private function current_request_reason($profile) {
        if ($this->base_reason === null) {
            $cart_empty = null;
            if (self::woo_active() && function_exists('WC') && did_action('woocommerce_cart_loaded_from_session') && WC()->cart) {
                $cart_empty = WC()->cart->is_empty();
            }
            $this->base_reason = [
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                'cli' => defined('WP_CLI') && WP_CLI,
                'admin' => is_admin(),
                'ajax' => wp_doing_ajax(),
                'rest' => (defined('REST_REQUEST') && REST_REQUEST) || strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), '/' . rest_get_url_prefix() . '/') !== false,
                'preview' => is_preview() || is_customize_preview(),
                'get' => $_GET, // phpcs:ignore WordPress.Security.NonceVerification
                'cookies' => $_COOKIE,
                'woo' => self::woo_active(),
                'cart_empty' => $cart_empty,
                'logged_in' => is_user_logged_in(),
                'logged_in_allowed' => !empty($this->settings['block_cache_logged_in']),
            ];
        }
        $profiles = self::profiles();
        $r = $this->base_reason;
        $r['role_safe'] = !empty($profiles[$profile]['role_safe']);
        return self::request_reason($r);
    }

    /* ------------------------------------------------------------ keys */

    private function prefix() {
        $host = strtolower((string) (parse_url(home_url('/'), PHP_URL_HOST) ?: ''));
        return 'ace_bc:' . get_current_blog_id() . ':' . $host . ':';
    }

    /** Pure key builder, for tests. */
    public static function build_key($prefix, $generation, $profile, $profile_version, array $vary) {
        return $prefix . 'b:' . $profile . ':' . (int) $generation . '.' . (int) $profile_version . ':' . md5(serialize($vary));
    }

    private function versions() {
        if ($this->versions !== null) {
            return $this->versions;
        }
        $this->versions = ['gen' => 0];
        $names = ['gen'];
        foreach (array_keys(self::profiles()) as $p) {
            $names[] = 'ver:' . $p;
        }
        $redis = $this->redis();
        if ($redis) {
            try {
                $vals = $redis->mget(array_map(function ($n) { return $this->prefix() . $n; }, $names));
                foreach ($names as $i => $n) {
                    $this->versions[$n] = (int) ($vals[$i] ?? 0);
                }
            } catch (\Throwable $e) {
                $this->versions = null;
                return ['gen' => 0];
            }
        }
        return $this->versions;
    }

    private function key(array $block, $profile) {
        $v = $this->versions();
        $roles = 'anon';
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $r = (array) $user->roles;
            sort($r);
            $roles = implode(',', $r);
        }
        $vary = [
            self::VER,
            $block,
            determine_locale(),
            $roles,
            $GLOBALS['wp_version'] ?? '',
            get_stylesheet(),
        ];
        if ($profile === 'woo') {
            $vary[] = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
            $vary[] = get_option('woocommerce_tax_display_shop');
            $vary[] = defined('WC_VERSION') ? WC_VERSION : '';
        }
        if (self::has_pagination($block)) {
            $vary[] = $this->current_path(); // Pagination links point at the current page.
        }
        $vary = apply_filters('ace_rc_block_cache_vary', $vary, $block, $profile);
        return self::build_key($this->prefix(), $v['gen'] ?? 0, $profile, $v['ver:' . $profile] ?? 0, $vary);
    }

    /** Reverse-index member for one stored entry: "<entry key>|<page path>". */
    public static function index_member($key, $path) {
        return $key . '|' . $path;
    }

    /** Split reverse-index members into entry keys and page paths. Pure, for tests. */
    public static function parse_members(array $members) {
        $keys = [];
        $paths = [];
        foreach ($members as $m) {
            $pos = strrpos((string) $m, '|');
            if ($pos === false) {
                continue;
            }
            $keys[substr($m, 0, $pos)] = true;
            $paths[substr($m, $pos + 1)] = true;
        }
        return [array_keys($keys), array_keys($paths)];
    }

    private function redis() {
        try {
            $r = $this->cache_manager->get_raw_client();
            return ($r && method_exists($r, 'sAdd')) ? $r : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function current_path() {
        $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return $path ? $path : '/';
    }

    /* ------------------------------------------------------------ rendering */

    private static function is_interactive($name) {
        $type = $name ? \WP_Block_Type_Registry::get_instance()->get_registered($name) : null;
        $i = $type ? ($type->supports['interactivity'] ?? null) : null;
        return true === $i || (is_array($i) && !empty($i['interactive']));
    }

    /** Counts interactive blocks being rendered: above zero means an ancestor is the interactive root. */
    public function track_open($block) {
        if (is_array($block) && self::is_interactive($block['blockName'] ?? '')) {
            $this->open_interactive++;
        }
        return $block;
    }

    public function track_close($content, $block) {
        if ($this->open_interactive > 0 && self::is_interactive($block['blockName'] ?? '')) {
            $this->open_interactive--;
        }
        return $content;
    }

    public function pre_render($pre_render, $parsed_block, $parent_block = null) {
        if (null !== $pre_render || $this->depth > 0 || !is_array($parsed_block)) {
            return $pre_render;
        }
        $profile = self::block_profile($parsed_block, self::profiles(), $this->settings);
        if ($profile === null || !apply_filters('ace_rc_block_cache_cacheable', true, $parsed_block, $profile)) {
            return $pre_render;
        }
        $name = (string) $parsed_block['blockName'];
        $reason = $this->current_request_reason($profile);
        if ($reason !== '') {
            $this->note('live-' . $reason, $name);
            return $pre_render;
        }

        $key = $this->key($parsed_block, $profile);
        $entry = $this->cache_manager->get($key);
        if (is_array($entry) && isset($entry['html'])) {
            $this->replay($entry);
            $this->count($profile, $name, 'h', 1);
            $this->note('hit', $name);
            return $entry['html'];
        }

        // Miss: render it here, the way core would, and capture what it did.
        global $wpdb;
        $this->depth++;
        $deps = [];
        $collect = static function ($value, $object_id) use (&$deps) {
            $deps[(int) $object_id] = true;
            return $value;
        };
        $collect_post = static function ($post) use (&$deps) {
            if (is_object($post) && isset($post->ID)) {
                $deps[(int) $post->ID] = true;
            }
        };
        $nonces = 0;
        $count_nonce = static function ($life) use (&$nonces) {
            $nonces++;
            return $life;
        };
        $started = microtime(true);
        $q0 = (int) $wpdb->num_queries;
        $before = $this->snapshot();
        $uid0 = (int) wp_unique_id();
        $el0 = (int) substr(wp_unique_prefixed_id('wp-elements-'), 12);
        add_filter('get_post_metadata', $collect, 1, 2);
        add_action('the_post', $collect_post, 1, 1);
        add_filter('nonce_life', $count_nonce, 1, 1);
        try {
            $html = $this->render_now($parsed_block, $parent_block);
        } finally {
            remove_filter('get_post_metadata', $collect, 1);
            remove_action('the_post', $collect_post, 1);
            remove_filter('nonce_life', $count_nonce, 1);
            $this->depth--;
        }
        if (null === $html) {
            return $pre_render;
        }
        $ms = (int) round((microtime(true) - $started) * 1000);
        $queries = max(0, (int) $wpdb->num_queries - $q0);
        $uid1 = (int) wp_unique_id();
        $el1 = (int) substr(wp_unique_prefixed_id('wp-elements-'), 12);
        $after = $this->snapshot();

        if ($this->open_interactive > 0 || '' === trim((string) $html)) {
            $this->note('skip', $name);
            return $html;
        }

        $entry = [
            't' => $started,
            'html' => $html,
            'state' => [],
            'config' => [],
            'derived' => self::diff($after['derived'], $before['derived']),
            'styles' => array_values(array_diff($after['styles'], $before['styles'], ['wp-interactivity-router-animations'])),
            'scripts' => array_values(array_diff($after['scripts'], $before['scripts'])),
            'modules' => array_values(array_diff($after['modules'], $before['modules'])),
            'wcdata' => array_diff_key($after['wcdata'], $before['wcdata']),
            'rules' => array_values(array_diff_key($after['rules'], $before['rules'])),
            'router' => $after['router'] && !$before['router'],
            'loop' => [],
            'uid' => max(0, $uid1 - $uid0 - 1),
            'el' => max(0, $el1 - $el0 - 1),
        ];
        foreach ($after['loop'] as $id => $props) {
            foreach ($props as $prop => $val) {
                $d = self::delta($val, $before['loop'][$id][$prop] ?? []);
                if (null !== $d) {
                    $entry['loop'][$id][$prop] = $d;
                }
            }
        }
        foreach (['state', 'config'] as $kind) {
            foreach ($after[$kind] as $ns => $data) {
                $d = self::diff($data, $before[$kind][$ns] ?? []);
                if ('state' === $kind && 'woocommerce' === $ns) {
                    // Shared cart/store state is per visitor: loaded fresh on every hit.
                    unset($d['cart'], $d['noticeId'], $d['restUrl'], $d['nonce']);
                }
                if ('config' === $kind && 'woocommerce' === $ns) {
                    unset($d['nonce']);
                }
                if ('state' === $kind && 'core/router' === $ns) {
                    unset($d['url']);
                }
                if ($d) {
                    $entry[$kind][$ns] = $d;
                }
            }
        }
        if (self::has_closure($entry['wcdata']) || self::has_closure($entry['config'])) {
            $this->note('skip', $name);
            return $html;
        }
        if ($nonces > 0 && self::contains_nonce($entry['html'], [$entry['state'], $entry['config'], $entry['wcdata']])) {
            $this->note('skip-nonce', $name);
            return $html;
        }

        $deps = $this->filter_deps(array_keys($deps), self::profiles()[$profile]['dep_types'] ?? null);
        $this->store($key, $entry, $deps, $profile, $started);
        $this->count($profile, $name, 'm', 1);
        $this->count($profile, $name, 'ms', $ms);
        $this->count($profile, $name, 'q', $queries);
        $this->note('miss', $name);
        return $html;
    }

    /** A per-request token in the HTML or the state that goes with it. Pure, for tests. */
    public static function contains_nonce($html, array $data = []) {
        $haystack = (string) $html . ' ' . wp_json_encode($data);
        return (bool) preg_match('/(nonce|_wpnonce|security)(\\\\?["\'])?\s*[:=]\s*(\\\\?["\'])?[0-9a-f]{10}(?![0-9a-f])/i', $haystack);
    }

    private function filter_deps(array $ids, $types) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        global $wpdb;
        $in = implode(',', $ids);
        if ($types) {
            $types_in = "'" . implode("','", array_map('esc_sql', $types)) . "'";
        } else {
            $types_in = "'" . implode("','", array_map('esc_sql', array_values(get_post_types(['public' => true])))) . "'";
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ints and escaped type names only.
        return array_map('intval', (array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE ID IN ($in) AND post_type IN ($types_in)"));
    }

    private function store($key, array $entry, array $deps, $profile, $started) {
        $redis = $this->redis();
        if (!$redis) {
            return;
        }
        $p = $this->prefix();
        try {
            // A dependency that changed while we were rendering: do not keep the stale result.
            if ($deps) {
                $touched = $redis->mget(array_map(static function ($id) use ($p) { return $p . 't:' . $id; }, $deps));
                foreach ((array) $touched as $t) {
                    if ($t !== false && $t !== null && (float) $t >= $started) {
                        return;
                    }
                }
            }
            $this->cache_manager->set($key, $entry, $this->ttl);
            $path = $this->current_path();
            $member = self::index_member($key, $path);
            $pipe = $redis->multi(\Redis::PIPELINE);
            foreach ($deps as $id) {
                $pipe->sAdd($p . 'idx:' . $id, $member);
                $pipe->expire($p . 'idx:' . $id, $this->ttl);
            }
            $pipe->sAdd($p . 'paths:' . $profile, $path);
            $pipe->expire($p . 'paths:' . $profile, $this->ttl);
            $pipe->exec();
        } catch (\Throwable $e) {
            // Cache is best effort.
        }
    }

    /** Render the block now, exactly as core would, so we keep the post-directive-processing HTML. */
    private function render_now(array $parsed_block, $parent_block) {
        if ($parent_block instanceof \WP_Block) {
            foreach ($parent_block->inner_blocks as $inner_block) {
                if ($inner_block->parsed_block !== $parsed_block) {
                    continue;
                }
                $source_block = $inner_block->parsed_block;
                $inner_block_context = $inner_block->context;
                $inner_block->parsed_block = apply_filters('render_block_data', $inner_block->parsed_block, $source_block, $parent_block);
                $inner_block->context = apply_filters('render_block_context', $inner_block->context, $inner_block->parsed_block, $parent_block);
                if ($inner_block->context !== $inner_block_context) {
                    $inner_block->refresh_context_dependents();
                } elseif ($inner_block->parsed_block !== $source_block) {
                    $inner_block->refresh_parsed_block_dependents();
                }
                return $inner_block->render();
            }
            return null;
        }
        global $post;
        $source_block = $parsed_block;
        $parsed_block = apply_filters('render_block_data', $parsed_block, $source_block, $parent_block);
        $context = [];
        if ($post instanceof \WP_Post) {
            $context['postId'] = $post->ID;
            $context['postType'] = $post->post_type;
        }
        $context = apply_filters('render_block_context', $context, $parsed_block, $parent_block);
        $block = new \WP_Block($parsed_block, $context);
        return $block->render();
    }

    /* ------------------------------------------------------------ capture and replay */

    private static function prop($object, $prop) {
        $r = new \ReflectionProperty($object, $prop);
        $r->setAccessible(true);
        return $r->getValue($object);
    }

    private static function set_prop($object, $prop, $value) {
        $r = new \ReflectionProperty($object, $prop);
        $r->setAccessible(true);
        $r->setValue($object, $value);
    }

    private static function wc_registry() {
        if (!class_exists('\Automattic\WooCommerce\Blocks\Package')) {
            return null;
        }
        try {
            return \Automattic\WooCommerce\Blocks\Package::container()->get(\Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry::class);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function snapshot() {
        $ia = wp_interactivity();
        $snap = [
            'state' => self::prop($ia, 'state_data'),
            'config' => self::prop($ia, 'config_data'),
            'derived' => property_exists($ia, 'derived_state_closures') ? self::prop($ia, 'derived_state_closures') : [],
            'styles' => wp_styles()->queue,
            'scripts' => wp_scripts()->queue,
            'modules' => function_exists('wp_script_modules') && method_exists(wp_script_modules(), 'get_queue') ? array_values((array) wp_script_modules()->get_queue()) : [],
            'wcdata' => [],
            'rules' => [],
            'router' => property_exists($ia, 'has_processed_router_region') ? (bool) self::prop($ia, 'has_processed_router_region') : false,
            'loop' => self::loop_trackers(),
        ];
        $reg = self::wc_registry();
        if ($reg) {
            $snap['wcdata'] = self::prop($reg, 'data');
        }
        foreach (\WP_Style_Engine_CSS_Rules_Store::get_stores() as $store_name => $store) {
            foreach ($store->get_all_rules() as $selector => $rule) {
                $snap['rules'][$store_name . "\0" . $selector] = [
                    'store' => $store_name,
                    'selector' => $rule->get_selector(),
                    'declarations' => $rule->get_declarations()->get_declarations(),
                    'rules_group' => method_exists($rule, 'get_rules_group') ? (string) $rule->get_rules_group() : '',
                ];
            }
        }
        return $snap;
    }

    /**
     * Analytics plugins (Site Kit, Google for WooCommerce, WooCommerce Google Analytics) collect the
     * products shown on a page from woocommerce_loop_add_to_cart_link into an array property of the
     * object their callback is bound to. Returns [ "Class#n" => [ prop => array ] ] (or the objects).
     */
    private static function loop_trackers($objects_only = false) {
        $out = [];
        $hook = $GLOBALS['wp_filter']['woocommerce_loop_add_to_cart_link'] ?? null;
        if (!$hook) {
            return $out;
        }
        $seen = [];
        $done = [];
        foreach ($hook->callbacks as $callbacks) {
            foreach ($callbacks as $cb) {
                $fn = $cb['function'];
                $obj = $fn instanceof \Closure ? (new \ReflectionFunction($fn))->getClosureThis() : (is_array($fn) && is_object($fn[0]) ? $fn[0] : null);
                if (!$obj || isset($done[spl_object_id($obj)])) {
                    continue;
                }
                $done[spl_object_id($obj)] = true;
                $class = get_class($obj);
                $seen[$class] = ($seen[$class] ?? -1) + 1;
                $id = $class . '#' . $seen[$class];
                foreach (['products', 'script_data'] as $prop) {
                    if (!property_exists($obj, $prop)) {
                        continue;
                    }
                    try {
                        $val = self::prop($obj, $prop);
                    } catch (\Throwable $e) {
                        continue;
                    }
                    if (is_array($val)) {
                        if ($objects_only) {
                            $out[$id] = $obj;
                        } else {
                            $out[$id][$prop] = $val;
                        }
                    }
                }
            }
        }
        return $out;
    }

    /** What $after added to $before: list tails, new or changed keys (recursively). */
    public static function delta($after, $before) {
        if (!is_array($after) || !is_array($before)) {
            return $after === $before ? null : ['__set' => $after];
        }
        if (self::is_list($after) && self::is_list($before) && count($after) >= count($before)) {
            $tail = array_slice($after, count($before));
            return $tail ? ['__append' => $tail] : null;
        }
        $out = [];
        foreach ($after as $k => $v) {
            $d = array_key_exists($k, $before) ? self::delta($v, $before[$k]) : ['__set' => $v];
            if (null !== $d) {
                $out[$k] = $d;
            }
        }
        return $out ? ['__keys' => $out] : null;
    }

    public static function apply_delta($current, array $delta) {
        if (array_key_exists('__set', $delta)) {
            return $delta['__set'];
        }
        if (isset($delta['__append'])) {
            return array_merge(is_array($current) ? $current : [], $delta['__append']);
        }
        $current = is_array($current) ? $current : [];
        foreach ($delta['__keys'] ?? [] as $k => $d) {
            $current[$k] = self::apply_delta($current[$k] ?? null, $d);
        }
        return $current;
    }

    private static function is_list(array $a) {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }

    /** Recursive "what is new or different in $after", with closures swapped for a marker. */
    public static function diff($after, $before) {
        $out = [];
        foreach ((array) $after as $k => $v) {
            $has = is_array($before) && array_key_exists($k, $before);
            if ($v instanceof \Closure) {
                if (!$has || !($before[$k] instanceof \Closure)) {
                    $out[$k] = self::CLOSURE;
                }
                continue;
            }
            if (is_array($v) && $has && is_array($before[$k])) {
                $d = self::diff($v, $before[$k]);
                if ($d) {
                    $out[$k] = $d;
                }
            } elseif (!$has || $before[$k] !== $v) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private static function restore_closures($value) {
        if (self::CLOSURE === $value) {
            // Only used for server-side rendering, already baked into the cached HTML; serialises to {} like the original.
            return static function () {
                return null;
            };
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::restore_closures($v);
            }
        }
        return $value;
    }

    private static function has_closure($value) {
        if ($value instanceof \Closure) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                if (self::has_closure($v)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function replay(array $entry) {
        foreach ((array) ($entry['styles'] ?? []) as $h) {
            wp_enqueue_style($h);
        }
        foreach ((array) ($entry['scripts'] ?? []) as $h) {
            wp_enqueue_script($h);
        }
        foreach ((array) ($entry['modules'] ?? []) as $id) {
            wp_enqueue_script_module($id);
        }
        $reg = self::wc_registry();
        if ($reg && !empty($entry['wcdata'])) {
            foreach ($entry['wcdata'] as $k => $v) {
                if (!$reg->exists($k)) {
                    $reg->add($k, $v);
                }
            }
        }
        foreach ((array) ($entry['config'] ?? []) as $ns => $data) {
            wp_interactivity_config($ns, $data);
        }
        foreach ((array) ($entry['state'] ?? []) as $ns => $data) {
            wp_interactivity_state($ns, self::restore_closures($data));
        }
        $ia = wp_interactivity();
        if (!empty($entry['derived']) && property_exists($ia, 'derived_state_closures')) {
            self::set_prop($ia, 'derived_state_closures', array_replace_recursive(self::prop($ia, 'derived_state_closures'), $entry['derived']));
        }
        // Fresh per-visitor cart state, as the Product Button block would have loaded it.
        if (class_exists('\Automattic\WooCommerce\Blocks\Utils\BlocksSharedState')) {
            try {
                $r = new \ReflectionProperty('\Automattic\WooCommerce\Blocks\Utils\BlocksSharedState', 'consent_statement');
                $r->setAccessible(true);
                $consent = $r->getValue();
                \Automattic\WooCommerce\Blocks\Utils\BlocksSharedState::load_store_config($consent);
                \Automattic\WooCommerce\Blocks\Utils\BlocksSharedState::load_cart_state($consent);
            } catch (\Throwable $e) {
                // Older/newer WooCommerce: the client fetches the cart itself.
            }
        }
        if (!empty($entry['loop'])) {
            foreach (self::loop_trackers(true) as $id => $obj) {
                foreach ($entry['loop'][$id] ?? [] as $prop => $d) {
                    self::set_prop($obj, $prop, self::apply_delta(self::prop($obj, $prop), $d));
                }
            }
        }
        if (!empty($entry['router']) && property_exists($ia, 'has_processed_router_region') && !self::prop($ia, 'has_processed_router_region')) {
            self::set_prop($ia, 'has_processed_router_region', true);
            wp_interactivity_state('core/router', ['url' => get_self_link()]);
            if (method_exists($ia, 'get_router_animation_styles')) {
                $styles = new \ReflectionMethod($ia, 'get_router_animation_styles');
                $styles->setAccessible(true);
                wp_register_style('wp-interactivity-router-animations', false);
                wp_add_inline_style('wp-interactivity-router-animations', $styles->invoke($ia));
                wp_enqueue_style('wp-interactivity-router-animations');
            }
            if (method_exists($ia, 'print_router_markup')) {
                add_action('wp_footer', [$ia, 'print_router_markup']);
            }
        }
        foreach ((array) ($entry['rules'] ?? []) as $rule) {
            $store = \WP_Style_Engine_CSS_Rules_Store::get_store($rule['store']);
            $css = $store->add_rule($rule['selector'], $rule['rules_group']);
            $css->add_declarations($rule['declarations']);
        }
        // Advance the id counters as the real render did, so later blocks get the same ids.
        for ($i = 0; $i < (int) ($entry['uid'] ?? 0) + 2; $i++) {
            wp_unique_id();
        }
        for ($i = 0; $i < (int) ($entry['el'] ?? 0) + 2; $i++) {
            wp_unique_prefixed_id('wp-elements-');
        }
        // The Product Button block swaps the legacy add-to-cart script for the Interactivity API.
        if (in_array('woocommerce/product-button', (array) ($entry['modules'] ?? []), true) || false !== strpos($entry['html'], 'wp-block-woocommerce-product-button')) {
            $dequeue = static function () {
                wp_dequeue_script('wc-add-to-cart');
            };
            add_action('wp_enqueue_scripts', $dequeue);
            if (did_action('wp_enqueue_scripts')) {
                $dequeue();
            }
        }
    }

    private function note($what, $block = '') {
        // With an X-Ace-Block-Cache-Debug request header, name the block too.
        if ($block !== '' && !empty($_SERVER['HTTP_X_ACE_BLOCK_CACHE_DEBUG'])) {
            $what .= '(' . $block . ')';
        }
        $this->notes[] = $what;
        if (!headers_sent()) {
            header('X-Ace-Block-Cache: ' . implode(',', $this->notes));
        }
        $this->hook_shutdown();
    }

    /* ------------------------------------------------------------ stats */

    private function count($profile, $block, $field, $by) {
        $f = $profile . '|' . $block . '|' . $field;
        $this->stats[$f] = ($this->stats[$f] ?? 0) + (int) $by;
        $this->hook_shutdown();
    }

    private function hook_shutdown() {
        if (!$this->shutdown_hooked) {
            $this->shutdown_hooked = true;
            add_action('shutdown', [$this, 'on_shutdown'], 5);
        }
    }

    public function on_shutdown() {
        $redis = $this->redis();
        if ($redis && $this->stats) {
            try {
                $key = $this->prefix() . 'stats:' . gmdate('Ymd');
                $pipe = $redis->multi(\Redis::PIPELINE);
                foreach ($this->stats as $field => $by) {
                    if ($by) {
                        $pipe->hIncrBy($key, $field, $by);
                    }
                }
                $pipe->expire($key, 9 * 86400);
                $pipe->exec();
            } catch (\Throwable $e) {
            }
            $this->stats = [];
        }
        if ($this->purge_paths) {
            $paths = array_keys($this->purge_paths);
            $this->purge_paths = [];
            foreach ($paths as $path) {
                if ($this->plugin && method_exists($this->plugin, 'purge_url')) {
                    $this->plugin->purge_url(home_url($path));
                } else {
                    do_action('ace_rc_paths_invalidated', [$path], 0);
                }
            }
        }
    }

    /**
     * Aggregate the last $days days. Returns ['days' => [Ymd => totals], 'blocks' => [profile|block => totals], 'totals' => totals].
     * Totals: hits, misses, avg_ms, saved_ms, saved_queries.
     */
    public function get_stats($days = 7) {
        $redis = $this->redis();
        $raw = [];
        for ($i = 0; $i < $days; $i++) {
            $day = gmdate('Ymd', time() - $i * 86400);
            $raw[$day] = [];
            if ($redis) {
                try {
                    $raw[$day] = (array) $redis->hGetAll($this->prefix() . 'stats:' . $day);
                } catch (\Throwable $e) {
                }
            }
        }
        return self::aggregate_stats($raw);
    }

    /** Pure aggregation of raw day hashes, for tests. */
    public static function aggregate_stats(array $raw) {
        $sum = static function (array $fields) {
            $by = [];
            foreach ($fields as $f => $v) {
                $parts = explode('|', (string) $f);
                if (count($parts) !== 3) {
                    continue;
                }
                $by[$parts[0] . '|' . $parts[1]][$parts[2]] = ($by[$parts[0] . '|' . $parts[1]][$parts[2]] ?? 0) + (int) $v;
            }
            return $by;
        };
        $totals = static function (array $c) {
            $h = (int) ($c['h'] ?? 0);
            $m = (int) ($c['m'] ?? 0);
            $avg = $m ? ($c['ms'] ?? 0) / $m : 0;
            $avg_q = $m ? ($c['q'] ?? 0) / $m : 0;
            return [
                'hits' => $h,
                'misses' => $m,
                'avg_ms' => round($avg, 1),
                'saved_ms' => (int) round($h * $avg),
                'saved_queries' => (int) round($h * $avg_q),
            ];
        };
        $out = ['days' => [], 'blocks' => [], 'totals' => ['hits' => 0, 'misses' => 0, 'avg_ms' => 0, 'saved_ms' => 0, 'saved_queries' => 0]];
        $all = [];
        foreach ($raw as $day => $fields) {
            $day_totals = ['hits' => 0, 'misses' => 0, 'saved_ms' => 0, 'saved_queries' => 0];
            foreach ($sum((array) $fields) as $block => $c) {
                $t = $totals($c);
                foreach (['hits', 'misses', 'saved_ms', 'saved_queries'] as $k) {
                    $day_totals[$k] += $t[$k];
                    $out['totals'][$k] += $t[$k];
                }
                foreach ($c as $k => $v) {
                    $all[$block][$k] = ($all[$block][$k] ?? 0) + $v;
                }
            }
            $out['days'][$day] = $day_totals;
        }
        $ms = 0;
        foreach ($all as $block => $c) {
            $out['blocks'][$block] = $totals($c);
            $ms += (int) ($c['ms'] ?? 0);
        }
        $out['totals']['avg_ms'] = $out['totals']['misses'] ? round($ms / $out['totals']['misses'], 1) : 0;
        return $out;
    }

    /* ------------------------------------------------------------ invalidation */

    /** Drop the entries that used these posts and purge the pages that showed them. */
    public function touch(array $ids) {
        $redis = $this->redis();
        if (!$redis) {
            return;
        }
        $p = $this->prefix();
        $now = microtime(true);
        foreach ($ids as $id) {
            $id = (int) $id;
            if (!$id) {
                continue;
            }
            $all = [$id];
            $parent = (int) wp_get_post_parent_id($id);
            if ($parent) {
                $all[] = $parent;
            }
            foreach ($all as $one) {
                try {
                    $members = (array) $redis->sMembers($p . 'idx:' . $one);
                    list($keys, $paths) = self::parse_members($members);
                    $pipe = $redis->multi(\Redis::PIPELINE);
                    foreach ($keys as $k) {
                        $pipe->del($k);
                    }
                    $pipe->del($p . 'idx:' . $one);
                    $pipe->setex($p . 't:' . $one, $this->ttl, (string) $now);
                    $pipe->exec();
                    foreach ($paths as $path) {
                        $this->purge_paths[$path] = true;
                    }
                } catch (\Throwable $e) {
                }
            }
        }
        if ($this->purge_paths) {
            $this->hook_shutdown();
        }
    }

    /** Retire every entry of one profile (its membership may have changed). */
    public function bump($profile) {
        static $done = [];
        if (isset($done[$profile])) {
            return;
        }
        $done[$profile] = true;
        $redis = $this->redis();
        if (!$redis) {
            return;
        }
        $p = $this->prefix();
        try {
            $redis->incr($p . 'ver:' . $profile);
            foreach ((array) $redis->sMembers($p . 'paths:' . $profile) as $path) {
                $this->purge_paths[$path] = true;
            }
            $redis->del($p . 'paths:' . $profile);
        } catch (\Throwable $e) {
        }
        $this->versions = null;
        if ($this->purge_paths) {
            $this->hook_shutdown();
        }
    }

    /** Retire everything. $purge_pages: also purge the pages that showed cached blocks. */
    public function flush($purge_pages = true) {
        $redis = $this->redis();
        if (!$redis) {
            return false;
        }
        $p = $this->prefix();
        try {
            $redis->incr($p . 'gen');
            foreach (array_keys(self::profiles()) as $profile) {
                if ($purge_pages) {
                    foreach ((array) $redis->sMembers($p . 'paths:' . $profile) as $path) {
                        $this->purge_paths[$path] = true;
                    }
                }
                $redis->del($p . 'paths:' . $profile);
            }
        } catch (\Throwable $e) {
            return false;
        }
        $this->versions = null;
        if ($this->purge_paths) {
            $this->hook_shutdown();
        }
        return true;
    }

    private function post_profiles() {
        return array_values(array_diff(array_keys(self::profiles()), ['woo']));
    }

    private static function is_product($post_id) {
        return in_array(get_post_type($post_id), ['product', 'product_variation'], true);
    }

    private function register_invalidation_hooks() {
        $flush = function () { $this->flush(); };
        foreach (['switch_theme', 'customize_save_after', 'update_option_sticky_posts', 'wp_update_nav_menu'] as $hook) {
            add_action($hook, $flush, 20, 0);
        }

        // Posts (Query Loop, Latest Posts, custom listings).
        add_action('post_updated', function ($id, $after, $before) {
            if (wp_is_post_revision($id) || !is_post_type_viewable($after->post_type)) {
                return;
            }
            if (self::is_product($id)) {
                return; // Products are handled by the WooCommerce hooks below.
            }
            $this->touch([$id]);
            if ($after->post_status === 'publish' || $before->post_status === 'publish') {
                foreach ($this->post_profiles() as $profile) {
                    $p = self::profiles()[$profile];
                    if (!empty($p['bump_on_update']) || self::order_changed($after, $before)) {
                        $this->bump($profile);
                    }
                }
            }
        }, 20, 3);
        add_action('transition_post_status', function ($new, $old, $post) {
            if ($new === $old || ($new !== 'publish' && $old !== 'publish') || !is_post_type_viewable($post->post_type)) {
                return;
            }
            foreach ($this->post_profiles() as $profile) {
                $this->bump($profile);
            }
            if (in_array($post->post_type, ['product', 'product_variation'], true)) {
                $this->bump('woo');
            }
        }, 20, 3);
        foreach (['before_delete_post', 'wp_trash_post', 'untrashed_post'] as $hook) {
            add_action($hook, function ($post_id) {
                if (get_post_status($post_id) !== 'publish' && current_filter() !== 'untrashed_post') {
                    return;
                }
                if (self::is_product($post_id)) {
                    $this->bump('woo');
                }
                foreach ($this->post_profiles() as $profile) {
                    $this->bump($profile);
                }
            }, 20, 1);
        }
        add_action('set_object_terms', function ($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
            $a = array_map('intval', (array) $tt_ids);
            $b = array_map('intval', (array) $old_tt_ids);
            sort($a);
            sort($b);
            if ($a === $b || get_post_status($object_id) !== 'publish') {
                return;
            }
            if (self::is_product($object_id)) {
                if (in_array($taxonomy, ['product_cat', 'product_tag', 'product_visibility', 'product_type', 'product_brand'], true) || strpos($taxonomy, 'pa_') === 0) {
                    $this->bump('woo');
                }
                return;
            }
            foreach ($this->post_profiles() as $profile) {
                $this->bump($profile);
            }
        }, 20, 6);
        add_action('wp_update_comment_count', function ($post_id) {
            $this->touch([$post_id]);
        }, 20, 1);
        foreach (['edited_term', 'delete_term'] as $hook) {
            add_action($hook, function () {
                foreach ($this->post_profiles() as $profile) {
                    $this->bump($profile);
                }
            }, 20, 0);
        }

        if (!self::woo_active()) {
            return;
        }
        // A product's own data changed: refresh the blocks that showed or used it.
        $changed = function ($product) {
            $id = is_object($product) && method_exists($product, 'get_id') ? $product->get_id() : (int) $product;
            if ($id) {
                $this->touch([$id]);
            }
        };
        foreach ([
            'woocommerce_update_product', 'woocommerce_update_product_variation',
            'woocommerce_product_set_stock', 'woocommerce_variation_set_stock',
            'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status',
            'woocommerce_delete_product_transients',
        ] as $hook) {
            add_action($hook, $changed, 20, 1);
        }
        add_action('save_post', function ($post_id, $post) {
            if ($post && in_array($post->post_type, ['product', 'product_variation'], true) && !wp_is_post_revision($post_id)) {
                $this->touch([$post_id]);
            }
        }, 20, 2);
        // Changes that can alter which products a collection shows.
        add_action('woocommerce_product_object_updated_props', function ($product, $props) {
            if (array_intersect((array) $props, ['featured', 'catalog_visibility', 'date_on_sale_from', 'date_on_sale_to', 'sale_price', 'stock_status', 'menu_order', 'name'])) {
                $this->bump('woo');
            }
        }, 20, 2);
        foreach (['woocommerce_settings_saved', 'edited_product_cat', 'delete_product_cat', 'edited_product_tag'] as $hook) {
            add_action($hook, function () { $this->bump('woo'); }, 20, 0);
        }
    }

    /** Fields whose change can reorder or refilter a listing. Pure, for tests. */
    public static function order_changed($after, $before) {
        foreach (['post_date', 'post_title', 'menu_order', 'post_parent', 'post_author', 'post_name', 'post_password'] as $f) {
            if ((string) ($after->$f ?? '') !== (string) ($before->$f ?? '')) {
                return true;
            }
        }
        return false;
    }
}
