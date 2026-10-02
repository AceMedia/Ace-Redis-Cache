# Ace Redis Cache

[![WordPress Plugin](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPLv2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.0.3-orange.svg)](https://github.com/acemedia/ace-crawl-enhancer)

High-performance full-page, block-aware, and object caching layer for WordPress with dynamic placeholder expansion, micro-caching, intelligent minification, and Brotli/Gzip compression. Designed for edge-grade throughput while preserving dynamic user-facing fragments safely.

> Enterprise-grade Redis caching with intelligent minification, circuit breaker reliability, AWS ElastiCache/Valkey TLS+SNI support, and advanced admin interface for WordPress.

---
## Plugin Metadata
- **Contributors:** AceMedia  
- **Donate link:** https://acemedia.ninja/donate/  
- **Tags:** cache, redis, performance, speed, optimization, object cache, page cache, full page cache, block cache, aws, elasticache, valkey, tls, sni, minification  
- **Requires at least:** 5.0  
- **Tested up to:** 6.8  
- **Requires PHP:** 7.4 (8.x recommended)  
- **Stable tag:** 1.0.3 (was 0.5.0 previously)  
- **License:** GPLv2 or later  
- **License URI:** https://www.gnu.org/licenses/gpl-2.0.html

---
## What Makes It Different
- Intelligent pre-minification (write-time; zero runtime overhead)
- Circuit breaker & graceful degradation (admin bypass + guest safety)
- Dual-store (original + minified; safe fallback)
- Dynamic placeholder expansion for block-level freshness
- AWS / ElastiCache / Valkey ready: TLS + SNI + pooling
- Comprehensive exclusion layers (URL, content, transients, blocks)
- Modern diagnostics-driven admin UI with live refresh
- SCAN + chunked operations (no blocking KEYS)

---
## Key Features
- Multisite network support: plugin can be network-activated and stores primary plugin settings in network scope when network mode is active.
- Full page cache with placeholder expansion for dynamic blocks: first uncached request captures a canonical HTML frame with marker placeholders; subsequent hits serve the cached frame and re-hydrate dynamic regions.
- Block-level dynamic placeholders: always-render model allows specific blocks (patterns or exact names) to bypass the static snapshot. Core dynamic blocks auto-detected; custom allow-list & wildcard patterns supported. Optional micro-cache (per-block TTL) evens load for hot fragments.
- Object cache toggles: integrates with Redis object caching drop-in; can enable/disable object layer independently from page layer.
- Minification strategy: HTML/CSS/JS content minified before storage (write-time). Dual-store logic keeps original safely for fallback if minified variant causes issues.
- Compression support: Brotli and Gzip pre-compressed variants stored; adaptive selection via Accept-Encoding. Configurable compression levels and minimum size threshold to avoid waste on tiny payloads.
- Secure Redis: TLS/SNI support (compatible with ElastiCache / Valkey / Redis managed services) and lightweight connection pooling / persistent connections.
- Invalidation: precise per-post/page purge on content changes; optional priming cron that warms key URLs; cache age and diagnostic headers emitted (e.g. X-Cache, X-Cache-Mode, X-Cache-Dynamic, Age).
- Exclusion system: granular exclusion by URL patterns (regex / glob), block names, content substrings, and transient key patterns; ensures sensitive or volatile content never cached.
- Admin UI: cohesive settings screen with save bar, diagnostics panel (connectivity, compression, Redis status), and live test actions. Debug headers toggleable.
- How it works: MISS -> render full page, identify dynamic blocks, strip them into placeholder markers, minify + compress stored variants; HIT -> serve cached frame instantly, expand placeholders by rendering just dynamic fragments (optionally micro-cached) and inject, then output.
- Configuration surfaces: page TTL, object TTL, dynamic micro-cache enable + TTL, excluded_blocks, excluded_urls, excluded_content_strings, enable_minification, compression_method (brotli|gzip|both|none), compression levels, min_size_bytes, browser cache headers, circuit-breaker thresholds.
- Compatibility: page cache coexists with object cache; dynamic expansion limited to guest / non-auth paths by default; bypass for logged-in & preview contexts.
- Safety & fallbacks: automatic bypass if Redis is unreachable (circuit-breaker), reverts to uncached passthrough; admin/editor requests never served stale dynamic placeholders; detects serialization/compression errors and falls back to original payload.
- Developer ergonomics: rich set of actions/filters to adjust key derivation, exclusion logic, TTLs, and dynamic placeholder handling.
- Performance optimizations: SCAN over KEYS, chunked purge batches, persistent pooled connections, adaptive timeouts, micro-cache for hot dynamic blocks.

---
## Advanced Feature Highlights
### Minification
Write-time pre-minification with dual-store (original + minified). Conservative transforms; automatic fallback on failure; filterable to skip per-response.

### Circuit Breaker
Failure counter + threshold triggers temporary bypass. Admin and local traffic always allowed; exponential backoff retry logic; emits debug headers (`X-Redis-Retry`).

### Admin Interface
Real-time diagnostics (connection status, compression availability, key stats), save bar UI, visual timers for auto-refresh, inline help text, structured sections for connection, caching, exclusions, minification, compression, diagnostics.

### Enterprise Infrastructure
Connection pooling & persistence, optional Unix socket support, TLS/SNI, adaptive connect/read timeouts, background/non-blocking maintenance operations.

### Caching Modes
- Full Page Cache (frame + dynamic placeholder expansion)
- Object Cache (transients, query/object results)
- Block-level dynamic placeholders with optional micro-cache
- Hybrid usage: Page + Object; Object + Dynamic Blocks.

### Exclusion System
URL, content substring, transient pattern, block name pattern, minification-specific exclusions, auto-detected dynamic core blocks (30+ patterns), comment-documentable lists.

### Performance & Scaling
SCAN batching (1000-key chunks), memory-efficient key derivation, pre-compressed Brotli/Gzip variants, micro-cache smoothing load, zero runtime minification overhead.

### Security & Reliability
TLS encryption, Redis AUTH, system CA trust, graceful degradation, connection validation (PING/INFO), isolation per WP install.

### Monitoring & Diagnostics
Debug headers: `X-Cache`, `X-Cache-Mode`, `X-Cache-Dynamic`, `X-Cache-Compression`, `X-Redis-Retry`. Optional logging of failures and circuit trips.

---
## How It Works (Flow)
1. Request arrives: key derived from normalized URL + vary signals (cookies, headers per config).
2. Lookup in Redis for compressed frame (Brotli preferred, else Gzip, else raw).
3. Cache MISS: full WP render; dynamic block detector scans content; extracts dynamic HTML segments and stores them individually or marks for real-time rendering; minifies + compresses; stores metadata manifest; returns response while asynchronously (optional) priming micro-caches.
4. Cache HIT: fetch cached frame, expand placeholders by rendering or micro-cache retrieval of each dynamic block; stream or echo final HTML.
5. Invalidation events purge relevant keys and associated dynamic manifests.

---
## Configuration (Selected Options & Defaults)
- page_cache_ttl: Default 300s (adjustable)
- object_cache_ttl: Default 600s (unless overridden)
- enable_dynamic_microcache: false (optional block fragment cache)
- dynamic_microcache_ttl: 15s default when enabled
- excluded_blocks: wildcard-capable; seeded with dynamic core blocks
- excluded_urls: regex or wildcard patterns
- excluded_content_strings: substrings to abort storage
- excluded_transient_patterns: transient key wildcards to bypass
- enable_minification: true (dual-store fallback)
- compression_method: both (brotli prioritized)
- brotli_level / gzip_level: balanced defaults (5 / 6)
- compression_min_size: 512 bytes
- browser_cache_headers: optional emission
- redis_tls: off by default
- redis_pooling: on by default (persistent)
- circuit_breaker_threshold: configurable failure tolerance

---
## Exclusions & Dynamic Handling
Any matching exclusion triggers bypass or block-level freshness:
- URL pattern match
- Block name pattern
- Content string trigger
- User context (logged-in, preview, nonce, query var)

Dynamic blocks always freshly rendered (or micro-cached) then substituted.

**Tracking parameters** never split the page cache: campaign and click IDs (`utm_*`, `gclid`, `gclsrc`, `gbraid`, `wbraid`, `gad_source`, `gad_campaignid`, `dclid`, `srsltid`, `fbclid`, `msclkid`, `twclid`, `ttclid`, `igshid`, `li_fat_id`, `epik`, `yclid`, `hsa_*`, `_hsenc`, `_hsmi`, `_kx`, `mc_cid`, `mc_eid`, `_ga`, `_gl`, `ref`, `v`) are dropped from the key and the rest sorted, so a paid landing is served the same entry as the clean URL. The list lives in `normalize_request_uri()` and its copy in `assets/dropins/advanced-cache.php`; a unit test keeps the two in step. `ace_rc_page_ttl` and `ace_rc_page_grace` receive this normalised URI (0.8.3).

---
## Reliability & Fallbacks
- Circuit breaker pauses page caching after consecutive failures
- Fallback to unminified/uncompressed originals on error
- Logged-in/admin requests bypass page layer automatically
- Graceful degradation: site continues if Redis unreachable

---
## Compression & Minification Details
- Pre-store minification improves compression ratios
- Original + minified kept (fail-safe)
- Brotli & Gzip variants negotiated via Accept-Encoding

---
## Developer Hooks (Representative)
Filters:
- `ace_redis_cache_enable_page_cache`
- `ace_redis_cache_page_cache_key_parts`
- `ace_redis_cache_page_ttl`
- `ace_redis_cache_dynamic_blocks`
- `ace_redis_cache_excluded_urls`
- `ace_redis_cache_should_minify`
- `ace_redis_cache_should_compress`
- `ace_redis_cache_block_microcache_ttl`
- `ace_redis_cache_headers`
- `ace_redis_cache_placeholder_html`

Actions:
- `ace_redis_cache_purge_post`
- `ace_redis_cache_miss`
- `ace_redis_cache_hit`
- `ace_redis_cache_bypass`
- `ace_redis_cache_purge_all`
- `ace_redis_cache_connection_failed`

See source for additional key derivation, compression and diagnostics hooks.

`ace_redis_cache_page_cache_key_parts` receives both key-part array and context (`request_uri`, `scheme`, `device`, `host`, `version`) and can be used to customize key derivation without forking.

---
## Page cache on a separate Redis
`advanced-cache.php` (early serve) normally reads the same Redis as the object cache. When pages live elsewhere, for example ElastiCache over TLS while the object cache stays on a local socket, define `ACE_REDIS_PAGE_HOST` (`'tls://host'` for TLS) and optionally `ACE_REDIS_PAGE_PORT`, `ACE_REDIS_PAGE_PASSWORD` and `ACE_REDIS_PAGE_DB`. Only the early-serve drop-in uses them, so the object cache doesn't move.

## Varnish
If Varnish sits in front of the site, every page-cache purge is mirrored as a Varnish ban for the same pages, so the two layers never disagree. A full or soft purge bans the whole host.

- Auto-detected on `127.0.0.1:6081` (CloudPanel default), checked at most every 10 minutes. Sites not behind Varnish are unaffected.
- `ACE_RC_VARNISH` (`true`, `false` or `'auto'`) and `ACE_RC_VARNISH_HOST` (`'host:port'`) override detection.
- Filters: `ace_rc_varnish_enabled`, `ace_rc_varnish_hosts`. Action: `ace_redis_cache_varnish_ban_site`.
- Expects a VCL that turns `PURGE <regex>` into `ban(req.http.host == ... && req.url ~ <regex>)` and accepts PURGE from localhost (CloudPanel's stock VCL does both).
- Recommended VCL additions: `return (pass)` for requests carrying `X-AceRedis-Prime`, so background refreshes reach PHP, and a short TTL (e.g. 10s) when `X-AceRedisCache` contains `stale`.

## Block cache
Since 0.8.0 the plugin can keep the rendered HTML of expensive dynamic blocks in Redis and replay it on later renders. It is **off by default**; turn it on under Settings > Ace Redis Cache > Caching > Block Cache.

- **What it caches:** WooCommerce product blocks (Product Collection, Handpicked, Newest, On Sale, Best Sellers, Top Rated, Products by Category and A.C.E. Product Variations; the WooCommerce options only show when WooCommerce is active), Query Loop blocks, Latest Posts, and any block you add with the `ace_rc_block_cache_custom_blocks` filter. Each group has its own toggle.
- **What it never caches:** blocks that read the page or URL (Query Loops that inherit the page query, related/upsells/cross-sells, filterable collections, the product catalogue, anything containing a mini-cart, filters, sorting or comment form), and any render that put a nonce in its HTML or state.
- **Who gets cached HTML:** anonymous visitors with an empty cart on plain GET requests. Visitors with anything in the cart (cart cookies or a WooCommerce session) and signed-in users get a live render. "Cache for logged-in users" (off by default) caches per role, and only for blocks whose output can only vary by role: WooCommerce blocks are treated as per-user when role, B2B, wholesale or membership pricing plugins are active, or tax is based on the customer's address.
- **Replay:** on a hit the plugin replays what the render added outside its own HTML (Interactivity API state and config, styles, scripts, script modules, wcSettings data, block-supports CSS, inline CSS such as block style variations, id counters and analytics product trackers), and loads the visitor's cart state fresh, so add to cart, quantities, variations and the mini-cart keep working.
- **Invalidation:** every post or product read during a render is recorded in a reverse index (one Redis set per post). Saving, restocking or repricing it deletes just the blocks that used it. Changes that can alter which posts a block shows (publish/unpublish, trash, terms, title/date/order, featured, visibility, sale dates, settings) retire that block group. Entries also expire after the TTL (12 hours by default). The pages that showed the affected blocks are purged through the page cache, which also bans them in Varnish when it is detected.
- **Savings:** hits, misses, average render time on a miss and database queries on a miss are counted per block in one Redis hash per site per day (written once per request). The settings panel shows today and the last 7 days, including the estimated render time and queries saved. Also at `GET /wp-json/ace-redis-cache/v1/block-cache/stats` and `wp ace block-cache stats [--days=7] [--format=json]`.
- **Flush:** the "Flush block cache" button, `POST /wp-json/ace-redis-cache/v1/block-cache/flush` or `wp ace block-cache flush`. A full cache clear also retires every block entry. Since 0.8.4, saving the settings retires block entries only when a `block_cache_*` setting changed, and a save that only changes `block_cache_*` settings no longer clears the page cache (other settings still clear everything).
- **Off switches:** `define('ACE_RC_BLOCK_CACHE', false)` (or `true`) overrides the setting; filters `ace_rc_block_cache_enabled`, `ace_rc_block_cache_cacheable` (per block), `ace_rc_block_cache_woo_blocks`, `ace_rc_block_cache_profiles`, `ace_rc_block_cache_context_blocks`, `ace_rc_block_cache_vary`, `ace_rc_block_cache_woo_role_safe`.
- **Debugging:** the `X-Ace-Block-Cache` response header lists `hit`, `miss`, `skip` or `live-<reason>` for each cached block type on the page; send `X-Ace-Block-Cache-Debug: 1` to include the block names.
- **Class numbers:** stored copies rename the per-request sequential classes (`wp-elements-N`, `wp-container-N`, `is-style-<variation>--N`) to `...-bc<hash>-N` in the HTML and its CSS, so a cached block can never share a number (and pick up the colours) of another block on the page.
- **Site profiles (0.8.2):** a site can add its own profile with `ace_rc_block_cache_profiles`. Besides `label`, `setting`, `blocks`, `dep_types`, `role_safe` and `bump_on_update`, a profile can set `ttl` (seconds, capped at the global TTL) and `vary_page` => true for blocks that read the page (key adds the queried object, the post in the loop, the page number and whether the post body is rendering), or `vary` (a callable given the parsed block). Scripts and styles a block both registers and enqueues inside its render are now re-registered on a hit.
- **TTL-only profiles (0.8.4):** by default publish, unpublish, trash, term and order changes retire every post profile. Set `retire_on_publish` => false and the profile relies on its `ttl`, the per-post reverse index (editing a post it showed still refreshes it) and any `BlockCache::instance()->bump('<profile>')` calls of your own. Use it for listings on busy sites where a few minutes' lag is fine, for example a news hub publishing ten stories an hour:

```php
add_filter('ace_rc_block_cache_profiles', function ($profiles) {
    $profiles['hub'] = [
        'label' => 'News hub',
        'setting' => 'block_cache_custom',
        'blocks' => ['talkfuse/hub'],
        'dep_types' => null,
        'role_safe' => true,
        'vary_page' => true,
        'ttl' => 90,                   // fresh within 90 s of a publish
        'retire_on_publish' => false,  // not retired by every new story
    ];
    return $profiles;
});
```

- If the old `ace-block-html-cache.php` mu-plugin is loaded, the module stands aside so blocks are never cached twice.

## Installation & Requirements
1. Install plugin into `wp-content/plugins/ace-redis-cache`
2. Copy `assets/dropins/object-cache.php` to `wp-content/object-cache.php`
3. Copy `assets/dropins/advanced-cache.php` to `wp-content/advanced-cache.php`
4. Ensure `define('WP_CACHE', true);` is set in `wp-config.php`
5. Configure Redis host/port (TLS params if needed)
6. Enable desired caching layers & tune TTLs
7. (Optional) Enable dynamic micro-cache

Requires: PHP 7.4+ (8.x recommended), Redis/Valkey 5+, optional Brotli extension.

---
## Debugging
Enable debug headers in settings to get: `X-Cache`, `X-Cache-Mode`, `X-Cache-Dynamic`, `Age`, `X-Cache-Compression`, `X-Redis-Retry`.

---
## Performance Snapshot (Typical)
- Page load: 3–8x faster
- TTFB reduction: 70–95%
- DB queries: up to 95–98% reduction on cached hits
- HTML size reduction: 15–35% via minification
- Hit rate: 85–95% with tuned exclusions

---
## FAQ (Selected)
**Does it require Redis?** Yes, Redis/Valkey 5+. Circuit breaker ensures site uptime if unavailable.

**What about TLS?** Enable TLS + SNI for ElastiCache/Valkey; uses system CAs.

**Unix sockets?** Supported; reduce latency ~20–30% locally.

**Will it conflict with other caching plugins?** Disable competing full-page caches; object-level coexistence generally safe.

**WooCommerce/e-commerce?** Dynamic cart/checkout bypass; static product/archive pages cached.

---
## 📞 Support

For support, feature requests, or bug reports, please visit our [GitHub repository](https://github.com/acemedia/Ace-Redis-Cache) or contact us through our website.

## 📄 License

This project is licensed under the GPLv2 or later - see the [LICENSE](LICENSE) file for details.

---

**Made with ❤️ by [AceMedia](https://acemedia.ninja)**

