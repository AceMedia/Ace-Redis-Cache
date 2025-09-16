=== Ace Redis Cache ===
Contributors: AceMedia
Donate link: https://acemedia.ninja/donate/
Tags: cache, redis, performance, speed, optimization, object cache, page cache, full page cache
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Smart Redis-powered caching plugin for WordPress with intelligent exclusions to prevent conflicts with external plugins.

## Description

**Ace Redis Cache** is a lightweight, high-performance caching plugin that uses Redis to dramatically improve your WordPress site's speed. Unlike other caching plugins, it features smart exclusions that prevent conflicts with specialized plugins like betting APIs, e-commerce systems, and other dynamic content providers.

### 🚀 Why Choose Ace Redis Cache?

* **Smart Exclusions** - Automatically detects and excludes external plugin data
* **Conflict-Free** - Works seamlessly alongside bet-api, W3TC, and other plugins  
* **Lightning Fast** - Redis-powered caching for maximum performance
* **Easy Setup** - Simple configuration with sensible defaults
* **Developer Friendly** - Clean code with hooks and filters for customization

### 🎯 Perfect For

* **News & Publishing Sites** - Fast loading for high-traffic content
* **E-commerce Stores** - Cache static content while preserving dynamic data
* **Betting & Gaming Sites** - Exclude real-time data while caching pages
* **Multi-plugin Sites** - Intelligent conflict prevention
* **High-Traffic Websites** - Redis performance for demanding loads

## Features

### Core Caching Features
* **Full Page Caching** - Complete HTML page caching for maximum speed
* **Object Cache Support** - Optional object-only caching mode
* **Redis Integration** - Robust Redis connection with SSL/TLS support
* **Smart TTL Management** - Configurable time-to-live settings
* **Cache Statistics** - Real-time cache size and hit/miss monitoring
* **One-Click Flush** - Easy cache clearing from admin panel

### Smart Exclusion System
* **External Plugin Protection** - Automatically excludes bet-api, W3TC, LiteSpeed, WP Rocket
* **Transient Management** - Intelligent WordPress transient exclusion
* **Dynamic Content Detection** - Excludes pages with real-time data blocks
* **API Endpoint Protection** - Skips caching for AJAX and API calls
* **Content-Based Exclusions** - Scans page content for dynamic elements

### Performance & Reliability
* **Connection Resilience** - Graceful fallback when Redis is unavailable
* **Memory Efficient** - Minimal resource usage and smart key management
* **Background Processing** - Non-blocking cache operations
* **Error Logging** - Detailed logging for troubleshooting
* **Multi-Environment Support** - Works in shared, VPS, and dedicated hosting

### Developer Features
* **WordPress Hooks** - Extensive filter and action hooks
* **Custom Exclusions** - Easily add your own exclusion patterns
* **Debug Mode** - Detailed cache headers and logging
* **Clean Code** - Well-documented, PSR-compliant PHP code
* **Uninstall Clean** - Complete removal of all plugin data

### Security & Compatibility
* **Password Protection** - Redis AUTH support for secure connections
* **SSL/TLS Support** - Encrypted Redis connections
* **Multisite Ready** - Full WordPress multisite compatibility
* **Plugin Compatibility** - Tested with major WordPress plugins
* **Theme Agnostic** - Works with any WordPress theme

### What Gets Cached vs Excluded

#### ✅ Cached by Redis
* WordPress pages, posts, and custom post types
* Archive pages, category pages, and search results
* Static content and media files
* Theme assets and styling
* WordPress core data (when appropriate)
* Standard plugin data and widgets
* RSS feeds and sitemaps

#### ❌ Excluded from Redis  
* **Bet-API Plugin Data** - All `betapi_*` prefixed cache keys
* **Real-Time Betting Data** - Racing post data, live odds, NAPs tables
* **Dynamic Betting Content** - Pages with bet-api blocks or shortcodes
* **External Plugin Caches** - W3 Total Cache, LiteSpeed, WP Rocket data
* **API Endpoints** - Bet-API proxy calls and AJAX requests
* **User-Specific Content** - Logged-in user pages and personalized data
* **E-commerce Carts** - WooCommerce cart and checkout pages
* **Admin Pages** - WordPress admin and dashboard pages

## Installation

### Automatic Installation
1. Log in to your WordPress admin panel
2. Navigate to **Plugins > Add New**
3. Search for "Ace Redis Cache"
4. Click **Install Now** and then **Activate**
5. Go to **Settings > Ace Redis Cache** to configure

### Manual Installation
1. Download the plugin ZIP file
2. Upload to `/wp-content/plugins/ace-redis-cache/` 
3. Activate the plugin through the **Plugins** menu in WordPress
4. Configure settings at **Settings > Ace Redis Cache**

### Server Requirements
* Redis server running (local or remote)
* PHP Redis extension installed
* WordPress 5.0 or higher
* PHP 7.4 or higher

## Configuration

### Basic Settings
1. **Enable Cache** - Master switch to enable/disable all caching
2. **Redis Host** - Redis server address (default: 127.0.0.1)
3. **Redis Port** - Redis server port (default: 6379)
4. **Redis Password** - Optional password for Redis AUTH
5. **Cache TTL** - Time-to-live for cached content in seconds (default: 60)
6. **Cache Mode** - Choose between Full Page Cache or Object Cache Only

### Advanced Settings
* **Exclude Transients** - Skip WordPress transients (recommended: enabled)
* **Exclude External Plugin Data** - Skip bet-api and other plugin data (recommended: enabled)

### Recommended Configuration
For most sites, these settings provide optimal performance:
```
✅ Enable Cache: ON
✅ Exclude Transients: ON  
✅ Exclude External Plugin Data: ON
📝 Cache Mode: Full Page Cache
📝 TTL: 60 seconds
```

## Performance Benefits

### Before & After Results
Typical performance improvements with Ace Redis Cache:
* **Page Load Time**: 2-5x faster
* **Time to First Byte (TTFB)**: 50-80% reduction
* **Server Response Time**: 70-90% improvement
* **Database Queries**: Up to 90% reduction
* **Memory Usage**: 30-50% lower server memory usage

### Benchmark Comparisons
* **vs No Cache**: 300-500% faster page loads
* **vs Database Cache**: 50-100% faster than database-only caching  
* **vs File Cache**: 20-40% faster than file-based caching
* **vs Other Redis Plugins**: Comparable speed with better compatibility

## Why Smart Exclusions Matter

### The Problem
Traditional caching plugins cache everything, which can cause conflicts:
* Real-time data gets cached when it shouldn't be
* External plugins can't manage their own caching
* Dynamic content becomes stale
* E-commerce and betting data becomes outdated

### Our Solution  
Ace Redis Cache intelligently identifies and excludes:
* **Time-Sensitive Data** - Live betting odds, stock prices, real-time feeds
* **User-Specific Content** - Personalized data, shopping carts, user dashboards  
* **External Plugin Data** - Let specialized plugins handle their own caching
* **API Endpoints** - Dynamic data sources that need real-time updates

### Real-World Example: Bet-API Integration
The bet-api plugin has sophisticated caching for real-time betting data:
* **Short cache durations** (60 seconds) for live odds
* **Fallback mechanisms** between persistent cache and transients  
* **API-specific cache warming** and management
* **Time-sensitive data** requiring precise cache control

By excluding bet-api data, we ensure:
✅ No conflicts between caching systems  
✅ Real-time betting data stays fresh
✅ Bet-api's cache management works properly
✅ Redis focuses on basic WordPress content for speed

## Frequently Asked Questions

### Is Redis required on my server?
Yes, you need a Redis server running. Most hosting providers offer Redis, or you can install it yourself. The plugin will gracefully degrade if Redis is unavailable.

### Will this conflict with my existing caching plugin?
Ace Redis Cache is designed to work alongside other caching plugins by using smart exclusions. However, for best performance, use only one caching solution.

### Can I customize what gets excluded?
Yes! The plugin includes developer hooks and filters to add custom exclusion patterns for your specific needs.

### Does this work with WooCommerce?
Absolutely! The plugin automatically excludes cart pages, checkout, and other dynamic e-commerce content while caching product pages and static content.

### What happens if Redis goes down?
The plugin includes graceful fallback - if Redis is unavailable, WordPress continues to function normally using standard caching methods.

### Is this compatible with multisite?
Yes, Ace Redis Cache fully supports WordPress multisite installations with per-site configuration options.

### How do I know it's working?
Check the plugin's status dashboard for connection status, cache statistics, and hit/miss ratios. You can also look for `X-Cache: HIT` headers in your browser's developer tools.

### Installation & Usage

1. Ensure Redis is running on your server
2. Configure connection settings in **Settings → Ace Redis Cache**
3. **Keep exclusion settings enabled** (default and recommended)
4. Test the connection and monitor cache performance

### Technical Details

- **Plugin Priorities**: Bet-api cache operations bypass Redis filters
- **Transient Patterns**: Excludes `betapi_%`, `racing_post_%`, `bet_banner_%`, etc.
- **Cache Key Prefixes**: Excludes `betapi_`, `bet_api_`, `w3tc_`, etc.
- **Content Detection**: Scans for bet-api blocks and shortcodes in page content
- **Fallback Safe**: If exclusions fail, original WordPress caching takes over

### Compatibility

- WordPress 5.0+
- PHP 7.4+
- Redis 5.0+
- Compatible with bet-api plugin v1.0+
- Compatible with Ace SEO and other Ace Media plugins

---

**Version History:**
- **0.2.0**: Added smart exclusions for bet-api and external plugins
- **0.1.2**: Basic Redis caching with full page and object cache modes
