=== Ace Redis Cache ===
Contributors: shanerounce
Tags: cache, redis, performance, object cache, page cache
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.8.15
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

= 0.8.15 =
* Preserve foreign cache drop-ins and symlinks; atomically update ARC-owned files and retry failed upgrades.
* Keep object-cache flushes and OPcache invalidation inside the site's own data and private code.
* Use WordPress-managed admin assets and the WordPress 6.9 template enhancement buffer.
* Validate admin requests, restore valid AJAX capability checks and restrict operational REST status to administrators.
* Start new installations with caching disabled until configured, and allow activation without Redis.
* Document external connections and compatibility; exclude standalone diagnostics from release packages.

= 0.8.14 =
* A maximum TTL now caps every object cache write, explicit expiries included: set, add, set_multiple, incr/decr and the shared transient path all store for at most the cap. The default is 86400 seconds, so a plugin's week-long transient lives for a day and cold keys stop crowding the working set out of a shared Redis. Set it per site with the Object Cache Max TTL setting (`object_cache_max_ttl`, 0 turns it off, otherwise 60 to 2592000) or `ACE_OC_MAX_TTL` in wp-config, which wins. The generation counter key is exempt and keeps no TTL.
* A matching Page Cache Max TTL setting (`page_cache_max_ttl`) caps a page's Redis lifetime, fresh TTL plus stale-while-revalidate grace together. It is off (0) by default, since page TTLs are already short.

= 0.8.13 =
* Object cache keys written without an expiry now get a one day backstop TTL instead of seven days, so cold entries leave Redis within a day and the shared instance holds a smaller working set under its lower maxmemory.

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

== Compatibility and external services ==

Full-page caching and standalone HTML minification require WordPress 6.9 or later. ARC uses WordPress's template enhancement buffer and never leaves its own response buffer open. On WordPress 5.0 to 6.8 pages render live; object and transient caches remain available. Block HTML caching requires the WordPress 6.5 Interactivity API; earlier versions render blocks live. Existing page-cache settings are retained for a later WordPress upgrade. Response compression is left to the web server for freshly rendered pages so other HTML filters receive HTML rather than compressed bytes.

Redis or Valkey is an administrator-configured cache service. ARC sends cache keys and the data being cached (which may include site content and WordPress object data) to the host and database you configure while caching is enabled. Install the PHP Redis extension before enabling a connection. No hosted Redis account is supplied or selected by this plugin. If using a hosted service, consult your chosen provider's terms and privacy policy before enabling it.

Optional Varnish integration sends purge requests containing site hosts and URL patterns when cached content changes. By default it detects a local proxy at 127.0.0.1:6081; an administrator can override that address with ACE_RC_VARNISH_HOST or disable integration with ACE_RC_VARNISH=false. No remote proxy is selected automatically. Same-site warming requests public URLs on this site's configured address when enabled; it does not send content to an ARC-operated service. ARC has no telemetry and unconfigured activation does not contact an external service.

JavaScript and CSS sources are included under assets/src. The source repository includes package.json and webpack.config.js for rebuilding the distributed assets with npm ci and npm run build.
