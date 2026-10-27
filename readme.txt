=== Ace Redis Cache ===
Contributors: shanerounce
Tags: cache, redis, performance, object cache, page cache
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.8.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Smart Redis-powered caching with WordPress Block API support and configurable exclusions for any plugins.

== Description ==

High-performance full-page, block-aware and object caching layer for WordPress with dynamic placeholder expansion, micro-caching, intelligent minification, and Brotli/Gzip compression. Designed for edge-grade throughput while preserving dynamic user-facing fragments safely.

Includes a Redis object cache drop-in, an advanced-cache page cache drop-in, a block HTML cache with per-profile TTLs, Varnish ban integration, circuit-breaker reliability and AWS ElastiCache/Valkey TLS+SNI support.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New.
2. Activate it through the Plugins screen.
3. Configure it from the plugin's settings screen where one exists.

== Frequently Asked Questions ==

= Where do I report a bug or ask for a feature? =

Open an issue on the plugin's GitHub repository.

== Changelog ==

= 0.8.12 =
* The object cache drop-in keys every value under a per-site generation number (`<namespace>v<gen>:`), held in Redis at `<namespace>gen`. A flush, including the one on publish, is now a single INCR instead of a SCAN of the whole Redis database: old keys are unreachable at once and expire by their TTL. Keys written with no expiry get the 7 day backstop, and `wp_cache_incr()`/`wp_cache_decr()` now set it too. A daily `ace_rc_purge_stale_generations` cron UNLINKs superseded keys early. If the generation key cannot be read the drop-in falls back to the old key format and the SCAN flush. The key format changes, so the first request after the update starts from an empty object cache.

= 0.8.11 =
* The stale-while-revalidate check on a cached page hit reads the stored time, the freshness marker and the soft-purge epoch in one MGET (it falls back to separate reads if a cluster endpoint refuses MGET across slots).
* New `page_cache_ttl_jitter` setting (percent, 0 to 50, off by default) and `ace_rc_page_ttl_jitter` filter: each stored page's fresh lifetime is cut by a random amount up to that percentage, so pages warmed together do not all refresh at once.
* The object cache drop-in fingerprints values for change detection with xxh128 where PHP has it, md5 otherwise.

= 0.8.10 =
* Page invalidation builds each path's keys once (the legacy unversioned keys were listed twice) and sends them as single-key UNLINKs in one pipelined round trip. The object cache flush and group flush UNLINK what SCAN finds in pipelined batches of 500. Servers older than Redis 4 get DEL instead. Every command still names one key, so cluster endpoints never see CROSSSLOT.

= 0.8.9 =
* Recognise a basket page as a WooCommerce cart route in the page and object cache guards.

= 0.8.8 =
* Render normally when a Redis page-cache read fails, and avoid further page-cache reads or writes during that request.

= 0.8.6 =
* Crawler bypass: the `ace_rc_crawler_bypass` filter names search crawler user agents and the path prefixes where they are served live rather than from the page cache, in WordPress and in the pre-boot drop-in alike. Empty by default.

= 0.8.5 =
* Initial public release.
