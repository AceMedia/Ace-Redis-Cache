=== Ace Redis Cache ===
Contributors: shanerounce
Tags: cache, redis, performance, object cache, page cache
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.8.5
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

= 0.8.5 =
* Initial public release.
