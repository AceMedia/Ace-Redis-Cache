<?php
/**
 * Cache Manager Class
 * 
 * Handles cache operations, exclusion logic, and performance monitoring
 * for both full-page and object caching modes.
 *
 * @package AceMedia\RedisCache
 * @since 0.5.0
 */

namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) exit;

class CacheManager {
    
    private $redis_connection;
    private $settings;
    private $cache_prefix = 'page_cache:';
    private $minified_cache_prefix = 'page_cache_min:';
    private $compression_marker_prefixes = ['br:' => 'brotli', 'gz:' => 'gzip', 'raw:' => 'raw'];

    /**
     * Compression level filters usage examples
     *
     * Example override in theme or mu-plugin:
     *
     * add_filter('ace_rc_brotli_level_page', function() { return 11; });
     * add_filter('ace_rc_brotli_level_object', function() { return 6; });
     * add_filter('ace_rc_gzip_level_page', function() { return 7; });
     * add_filter('ace_rc_min_compress_size', function($size) { return 1024; });
     */
    
    /**
     * Get prefixes that represent keys managed by this plugin for reporting/cleanup.
     * Allows customization via the 'ace_redis_cache_reporting_prefixes' filter.
     *
     * @return array Associative array label => prefix (without wildcard)
     */
    private function get_reporting_prefixes() {
        $prefixes = [
            'page' => $this->cache_prefix,
            'minified' => $this->minified_cache_prefix,
            'blocks' => 'block_cache:',
        ];
        
        // Include transients only if this feature is enabled
        if (!empty($this->settings['enable_transient_cache'])) {
            // Group both single-site and network site transients under one label
            $prefixes['transients'] = ['transient:', 'site_transient:'];
        }
        
        /**
         * Filter the list of prefixes used by the plugin for metrics and cleanup.
         *
         * @param array $prefixes  Associative array of label => prefix
         * @param array $settings  Plugin settings array
         */
        return apply_filters('ace_redis_cache_reporting_prefixes', $prefixes, $this->settings);
    }
    
    /**
     * Constructor
     *
     * @param RedisConnection $redis_connection Redis connection instance
     * @param array $settings Cache settings
     */
    public function __construct($redis_connection, $settings) {
        $this->redis_connection = $redis_connection;
        $this->settings = $settings;
    }
    
    /**
     * Get cache exclusions from settings
     *
     * @return array Array of exclusion patterns
     */
    public function get_cache_exclusions() {
        $exclusions = [];
        
        // Get custom exclusions from settings
        $custom_exclusions = $this->settings['custom_cache_exclusions'] ?? '';
        if (!empty($custom_exclusions)) {
            $lines = explode("\n", $custom_exclusions);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && !str_starts_with($line, '#')) {
                    $exclusions[] = $line;
                }
            }
        }
        
        return $exclusions;
    }
    
    /**
     * Get transient exclusions from settings
     *
     * @return array Array of transient exclusion patterns
     */
    public function get_transient_exclusions() {
        $exclusions = [];
        
        // Get custom transient exclusions from settings
        $custom_exclusions = $this->settings['custom_transient_exclusions'] ?? '';
        if (!empty($custom_exclusions)) {
            $lines = explode("\n", $custom_exclusions);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && !str_starts_with($line, '#')) {
                    $exclusions[] = $line;
                }
            }
        }
        
        return $exclusions;
    }
    
    /**
     * Get content exclusions from settings
     *
     * @return array Array of content exclusion patterns
     */
    public function get_content_exclusions() {
        $exclusions = [];
        
        // Get custom content exclusions from settings
        $custom_exclusions = $this->settings['custom_content_exclusions'] ?? '';
        if (!empty($custom_exclusions)) {
            $lines = explode("\n", $custom_exclusions);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && !str_starts_with($line, '#')) {
                    $exclusions[] = $line;
                }
            }
        }
        
        return $exclusions;
    }
    
    /**
     * Check if a cache key should be excluded
     *
     * @param string $key Cache key to check
     * @return bool True if key should be excluded
     */
    public function should_exclude_cache_key($key) {
        $exclusions = $this->get_cache_exclusions();
        
        foreach ($exclusions as $pattern) {
            if (fnmatch($pattern, $key)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if a transient should be excluded
     *
     * @param string $transient Transient name to check
     * @return bool True if transient should be excluded
     */
    public function should_exclude_transient($transient) {
        $exclusions = $this->get_transient_exclusions();
        
        foreach ($exclusions as $pattern) {
            if (fnmatch($pattern, $transient)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Delete a single cache key
     *
     * @param string $key Cache key to delete
     * @return bool True if key was deleted, false otherwise
     */
    public function delete_key($key) {
        $redis = $this->redis_connection->get_connection();
        if (!$redis) {
            return false;
        }
        
        try {
            return (bool) $redis->del($key);
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Ace Redis Cache: Failed to delete key ' . $key . ': ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Scan for cache keys matching a pattern
     *
     * @param string $pattern Redis key pattern
     * @return array Array of matching keys
     */
    public function scan_keys($pattern) {
        return $this->redis_connection->retry_operation(function($redis) use ($pattern) {
            $keys = [];
            $iterator = null;
            
            // Use SCAN for better performance with large datasets
            while (($scan_keys = $redis->scan($iterator, $pattern, 1000)) !== false) {
                if (!empty($scan_keys)) {
                    $keys = array_merge($keys, $scan_keys);
                }
                
                if ($iterator === 0) {
                    break;
                }
            }
            
            return array_unique($keys);
        }) ?: [];
    }
    
    /**
     * Delete keys in chunks to avoid blocking Redis
     *
     * @param array $keys Keys to delete
     * @param int $chunk_size Number of keys to delete per chunk
     * @return int Number of keys deleted
     */
    public function delete_keys_chunked($keys, $chunk_size = 1000) {
        if (empty($keys)) {
            return 0;
        }
        
        $total_deleted = 0;
        $key_chunks = array_chunk($keys, $chunk_size);
        
        foreach ($key_chunks as $chunk) {
            $deleted = $this->redis_connection->retry_operation(function($redis) use ($chunk) {
                return $redis->del($chunk);
            });
            
            if ($deleted !== null) {
                $total_deleted += $deleted;
            }
        }
        
        return $total_deleted;
    }
    
    /**
     * Get cache statistics
     *
     * @return array Cache statistics
     */
    public function get_cache_stats() {
        // Only report counts and memory for keys this plugin manages
        // using our known prefixes, instead of the entire Redis DB.
        return $this->redis_connection->retry_operation(function($redis) {
            $prefixes = $this->get_reporting_prefixes();
            
            // Count keys per prefix label
            $counts = [];
            $total_keys = 0;
            foreach ($prefixes as $label => $prefix) {
                $countForLabel = 0;
                if (is_array($prefix)) {
                    foreach ($prefix as $pfx) {
                        $keys = $this->scan_keys($pfx . '*');
                        $countForLabel += is_array($keys) ? count($keys) : 0;
                    }
                } else {
                    $keys = $this->scan_keys($prefix . '*');
                    $countForLabel += is_array($keys) ? count($keys) : 0;
                }
                $counts[$label] = $countForLabel;
                $total_keys += $counts[$label];
            }
            
            // Compute precise memory usage for our keys only
            $breakdown = $this->get_memory_usage_breakdown();
            $used_memory = $breakdown['total_bytes'] ?? 0;
            
            return [
                'total_keys' => $total_keys,
                'cache_keys' => $counts['page'] ?? 0,
                'minified_cache_keys' => $counts['minified'] ?? 0,
                'block_cache_keys' => $counts['blocks'] ?? 0,
                'transient_keys' => $counts['transients'] ?? 0,
                'memory_usage' => $used_memory,
                'memory_usage_human' => $this->format_bytes($used_memory)
            ];
        }) ?: [
            'total_keys' => 0,
            'cache_keys' => 0,
            'minified_cache_keys' => 0,
            'block_cache_keys' => 0,
            'transient_keys' => 0,
            'memory_usage' => 0,
            'memory_usage_human' => '0 B'
        ];
    }
    
    /**
     * Clear all cache keys
     *
     * @return array Result with count of cleared keys
     */
    public function clear_all_cache() {
        // Collect all plugin-managed keys across prefixes
        $all_keys = [];
        foreach ($this->get_reporting_prefixes() as $prefix) {
            // Prefix can be a string or an array (e.g. transients)
            if (is_array($prefix)) {
                foreach ($prefix as $pfx) {
                    $all_keys = array_merge($all_keys, $this->scan_keys($pfx . '*'));
                }
            } else {
                $all_keys = array_merge($all_keys, $this->scan_keys($prefix . '*'));
            }
        }

        $all_keys = array_unique($all_keys);
        $deleted_count = $this->delete_keys_chunked($all_keys);

        return [
            'success' => true,
            'cleared' => $deleted_count,
            'message' => sprintf('Cleared %d plugin cache keys', $deleted_count)
        ];
    }
    
    /**
     * Clear block cache keys specifically
     *
     * @return array Result with count of cleared block keys
     */
    public function clear_block_cache() {
        $block_keys = $this->scan_keys('block_cache:*');
        $deleted_count = $this->delete_keys_chunked($block_keys);
        
        return [
            'success' => true,
            'cleared' => $deleted_count,
            'message' => sprintf('Cleared %d block cache keys', $deleted_count)
        ];
    }
    
    /**
     * Get performance diagnostics
     *
     * @return array Performance diagnostic information
     */
    public function get_performance_diagnostics() {
        $diagnostics = [];
        
        // Redis connection status
        $connection_status = $this->redis_connection->get_status();
        $diagnostics['redis_connected'] = $connection_status['connected'];
        $diagnostics['redis_status'] = $connection_status['status'];
        
        // Cache statistics with minification breakdown
        $cache_stats = $this->get_cache_stats();
        $diagnostics['total_keys'] = $cache_stats['total_keys'];
        $diagnostics['cache_keys'] = $cache_stats['cache_keys'];
        $diagnostics['minified_cache_keys'] = $cache_stats['minified_cache_keys'] ?? 0;
        $diagnostics['memory_usage'] = $cache_stats['memory_usage_human'];
        
        // System information
        $diagnostics['php_version'] = PHP_VERSION;
        $diagnostics['wordpress_version'] = get_bloginfo('version');
        $diagnostics['redis_extension'] = extension_loaded('redis') ? 'Available' : 'Not available';
        
    // Plugin settings
    $diagnostics['page_cache_enabled'] = !empty($this->settings['enable_page_cache']);
    $diagnostics['object_cache_enabled'] = !empty($this->settings['enable_object_cache']);
    $diagnostics['ttl_page'] = (int)($this->settings['ttl_page'] ?? ($this->settings['ttl'] ?? 3600));
    $diagnostics['ttl_object'] = (int)($this->settings['ttl_object'] ?? ($this->settings['ttl'] ?? 3600));
        $diagnostics['block_caching'] = !empty($this->settings['enable_block_caching']) ? 'Enabled' : 'Disabled';
        $diagnostics['minification'] = !empty($this->settings['enable_minification']) ? 'Enabled' : 'Disabled';
        
        // Exclusion counts
        $diagnostics['cache_exclusions'] = count($this->get_cache_exclusions());
        $diagnostics['transient_exclusions'] = count($this->get_transient_exclusions());
        $diagnostics['content_exclusions'] = count($this->get_content_exclusions());
        
        return $diagnostics;
    }
    
    /**
     * Format bytes to human readable format
     *
     * @param int $size Size in bytes
     * @param int $precision Number of decimal places
     * @return string Formatted size string
     */
    private function format_bytes($size, $precision = 2) {
        $base = log($size, 1024);
        $suffixes = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        if ($size == 0) {
            return '0 B';
        }
        
        return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
    }

    /**
     * Get memory usage breakdown for plugin-managed keys
     * Sums Redis MEMORY USAGE for keys under our prefixes
     *
     * @return array Breakdown with bytes and human strings
     */
    public function get_memory_usage_breakdown() {
        $prefixes = [
            'page' => $this->cache_prefix,
            'minified' => $this->minified_cache_prefix,
            'blocks' => 'block_cache:',
        ];
        // Only consider transients if this feature is enabled in settings
        if (!empty($this->settings['enable_transient_cache'])) {
            $prefixes['transients'] = ['transient:', 'site_transient:'];
        }

        $result = [
            'page_bytes' => 0,
            'minified_bytes' => 0,
            'blocks_bytes' => 0,
            'transients_bytes' => 0,
            'total_bytes' => 0,
            'estimated' => false,
        ];

        $this->redis_connection->retry_operation(function($redis) use ($prefixes, &$result) {
            foreach ($prefixes as $key => $prefix) {
                $sum = 0;
                $keys = [];
                if (is_array($prefix)) {
                    foreach ($prefix as $pfx) {
                        $keys = array_merge($keys, $this->scan_keys($pfx . '*'));
                    }
                    $keys = array_unique($keys);
                } else {
                    $keys = $this->scan_keys($prefix . '*');
                }
                if (!empty($keys)) {
                    foreach ($keys as $k) {
                        try {
                            // Use rawCommand for broad compatibility
                            $bytes = $redis->rawCommand('MEMORY', 'USAGE', $k);
                            if (is_int($bytes)) {
                                $sum += $bytes;
                            }
                        } catch (\Exception $e) {
                            // Ignore per-key errors and continue
                        }
                    }
                    // Fallback: If provider restricts MEMORY USAGE, estimate with STRLEN on string values
                    if ($sum === 0) {
                        foreach ($keys as $k) {
                            try {
                                $len = $redis->strlen($k);
                                if (is_int($len)) {
                                    $sum += $len;
                                }
                            } catch (\Exception $e) {
                                // Ignore and continue
                            }
                        }
                        if ($sum > 0) {
                            $result['estimated'] = true;
                        }
                    }
                }
                $result[$key . '_bytes'] = $sum;
                $result['total_bytes'] += $sum;
            }
        });

        // Add human readable values
        $result['page_human'] = $this->format_bytes($result['page_bytes']);
        $result['minified_human'] = $this->format_bytes($result['minified_bytes']);
        $result['blocks_human'] = $this->format_bytes($result['blocks_bytes']);
    $result['transients_human'] = $this->format_bytes($result['transients_bytes']);
        $result['total_human'] = $this->format_bytes($result['total_bytes']);

        return $result;
    }
    
    /**
     * Set cache value with TTL and exclusion checking
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds
     * @return bool Success status
     */
    public function set($key, $value, $ttl = null) {
        if ($this->should_exclude_cache_key($key)) {
            return false;
        }
        
        // Default to object TTL when not explicitly provided
        if ($ttl === null) {
            if (isset($this->settings['ttl_object'])) {
                $ttl = (int) $this->settings['ttl_object'];
            } else {
                $ttl = (int) ($this->settings['ttl'] ?? 3600);
            }
        }
        
        return $this->redis_connection->retry_operation(function($redis) use ($key, $value, $ttl) {
            // For non-string values, serialize before compression decision
            $payload = is_string($value) ? $value : serialize($value);
            $store = $this->maybe_compress($payload, 'object');
            return $redis->setex($key, $ttl, $store);
        }) ?: false;
    }
    
    /**
     * Get cache value with exclusion checking
     *
     * @param string $key Cache key
     * @return mixed Cached value or null if not found/excluded
     */
    public function get($key) {
        if ($this->should_exclude_cache_key($key)) {
            return null;
        }
        
        $result = $this->redis_connection->retry_operation(function($redis) use ($key) {
            return $redis->get($key);
        });
        
        if ($result === null || $result === false) {
            return null;
        }
        
        $decoded = $this->maybe_decompress($result);
        // If we stored serialized data originally, attempt to unserialize
        $unser = @unserialize($decoded);
        return ($unser !== false || $decoded === 'b:0;') ? $unser : $decoded;
    }
    
    /**
     * Delete cache value
     *
     * @param string $key Cache key
     * @return bool Success status
     */
    public function delete($key) {
        return $this->redis_connection->retry_operation(function($redis) use ($key) {
            return $redis->del($key) > 0;
        }) ?: false;
    }
    
    /**
     * Check if cache key exists
     *
     * @param string $key Cache key
     * @return bool True if key exists
     */
    public function exists($key) {
        if ($this->should_exclude_cache_key($key)) {
            return false;
        }
        
        return $this->redis_connection->retry_operation(function($redis) use ($key) {
            return $redis->exists($key) > 0;
        }) ?: false;
    }
    
    /**
     * Get Redis connection instance
     *
     * @return RedisConnection Redis connection instance
     */
    public function get_redis_connection() {
        return $this->redis_connection;
    }

    /**
     * Get the raw Redis client instance (Predis or PhpRedis) or null.
     * Convenience for low-level operations like targeted deletes.
     */
    public function get_raw_client() {
        try {
            if (method_exists($this->redis_connection, 'get_connection')) {
                return $this->redis_connection->get_connection();
            }
        } catch (\Throwable $t) {}
        return null;
    }
    
    /**
     * Check if current request should be excluded from minification
     *
     * @param string $content Optional content to check against content exclusions
     * @return bool True if should be excluded from minification
     */
    public function should_exclude_from_minification($content = '') {
        // Get default minification exclusions
        $default_exclusions = [
            '/wp-admin/',
            '/wp-login.php',
            '/wp-cron.php',
            '/xmlrpc.php',
        ];
        
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        
        // Check default exclusions
        foreach ($default_exclusions as $exclusion) {
            if (strpos($request_uri, $exclusion) !== false) {
                return true;
            }
        }
        
        // Check custom cache exclusions (URL-based)
        $custom_exclusions = $this->get_cache_exclusions();
        foreach ($custom_exclusions as $pattern) {
            if (strpos($request_uri, $pattern) !== false) {
                return true;
            }
        }
        
        // Check content-based exclusions if content is provided
        if (!empty($content)) {
            $content_exclusions = $this->get_content_exclusions();
            foreach ($content_exclusions as $pattern) {
                if (strpos($content, $pattern) !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Store content in cache with minification handling
     *
     * @param string $key Cache key
     * @param string $content Content to cache
     * @param object $minifier Optional minification instance
     * @return bool Success status
     */
    public function set_with_minification($key, $content, $minifier = null) {
        if (empty($content)) {
            return false;
        }
        
        $redis = $this->redis_connection->get_connection();
        if (!$redis) {
            return false;
        }
        
        $should_exclude = $this->should_exclude_from_minification($content);
        $minification_enabled = ($this->settings['enable_minification'] ?? 0) && $minifier;
        
        if (!$should_exclude && $minification_enabled) {
            // Store both minified and original versions
            try {
                $minified_content = $minifier->minify_output($content);
                
                // Store minified version with separate key
                $minified_key = $this->minified_cache_prefix . $key;
                $redis->setex($minified_key, $this->get_cache_expiry(), $this->maybe_compress($minified_content, 'page'));
                
                // Store original version (as fallback)
                $original_key = $this->cache_prefix . $key;
                $redis->setex($original_key, $this->get_cache_expiry(), $this->maybe_compress($content, 'page'));
                
                return true;
            } catch (\Exception $e) {
                // If minification fails, store original
                return $this->set($key, $content);
            }
        } else {
            // Store original version only
            // Store under the raw key (compat with get_with_minification fallback), using page context
            try {
                return $redis->setex($key, $this->get_cache_expiry(), $this->maybe_compress($content, 'page'));
            } catch (\Exception $e) {
                return false;
            }
        }
    }
    
    /**
     * Get content from cache with minification handling
     *
     * @param string $key Cache key
     * @return string|null Cached content or null if not found
     */
    public function get_with_minification($key, $serve_compressed = true) {
        $redis = $this->redis_connection->get_connection();
        if (!$redis) {
            return null;
        }
        
        $should_exclude = $this->should_exclude_from_minification();
        $minification_enabled = ($this->settings['enable_minification'] ?? 0);
        
        if (!$should_exclude && $minification_enabled) {
            // Try to get minified version first
            $minified_key = $this->minified_cache_prefix . $key;
            $content = $redis->get($minified_key);
            
            if ($content !== false) {
                // Force plain HTML when caller wants to manipulate (e.g. dynamic placeholders)
                return $this->maybe_decompress($content, $serve_compressed);
            }
        }
        
        // Fall back to original version stored under the page prefix
        $original_key = $this->cache_prefix . $key;
        $content = $redis->get($original_key);
        if ($content !== false) {
            return $this->maybe_decompress($content, $serve_compressed);
        }
        // Back-compat: try legacy unprefixed key
        $legacy = $redis->get($key);
        if ($legacy !== false) {
            return $this->maybe_decompress($legacy, $serve_compressed);
        }
        return null;
    }
    
    /**
     * Get cache expiry time in seconds
     *
     * @return int Expiry time in seconds
     */
    private function get_cache_expiry() {
        // Prefer specific page TTL when available, fallback to legacy ttl
        if (isset($this->settings['ttl_page'])) {
            return (int) $this->settings['ttl_page'];
        }
        return (int) ($this->settings['ttl'] ?? 3600);
    }

    /**
     * Determine best available compression method based on settings and environment
     * @return string 'brotli'|'gzip'|'off'
     */
    private function get_active_compression_codec() {
        if (empty($this->settings['enable_compression'])) return 'off';
        $method = $this->settings['compression_method'] ?? 'brotli';
        if ($method === 'brotli' && function_exists('brotli_compress')) return 'brotli';
        if ($method === 'gzip' && (function_exists('gzencode') || function_exists('gzcompress'))) return 'gzip';
        // fallback: try any available
        if (function_exists('brotli_compress')) return 'brotli';
        if (function_exists('gzencode') || function_exists('gzcompress')) return 'gzip';
        return 'off';
    }

    /**
     * Get compression level by codec and context, allowing filters to override.
     * Context values: 'object' or 'page'
     *
     * Filters:
     * - ace_rc_brotli_level_object (default 5)
     * - ace_rc_brotli_level_page (default 9)
     * - ace_rc_gzip_level_object (default 6)
     * - ace_rc_gzip_level_page (default 6)
     *
     * @param string $codec 'brotli'|'gzip'|'off'
     * @param string $context 'object'|'page'
     * @return int Level 0-11 for brotli, 0-9 for gzip (we clamp to valid ranges)
     */
    private function get_level_for($codec, $context) {
        $context = ($context === 'page') ? 'page' : 'object';
        if ($codec === 'brotli') {
            $default = ($context === 'page') ? 9 : 5;
            $level = (int) apply_filters('ace_rc_brotli_level_' . $context, $default);
            return max(0, min(11, $level));
        }
        if ($codec === 'gzip') {
            $default = 6;
            $level = (int) apply_filters('ace_rc_gzip_level_' . $context, $default);
            return max(0, min(9, $level));
        }
        return 0;
    }

    /**
     * Compress payload, prefixing with marker including codec+level. Threshold is filterable.
     *
     * Marker formats (back-compat aware):
     * - brX: (e.g., br5:, br9:) then compressed bytes
     * - gzX: (e.g., gz6:) then compressed bytes
     * - br: / gz: legacy markers (still accepted on read)
     * - raw: then plain bytes
     *
     * @param string $payload
     * @param string $context 'object'|'page'
     * @return string stored value with marker
     */
    private function maybe_compress($payload, $context = 'object') {
        if (!is_string($payload)) $payload = (string)$payload;
        // Avoid double-compress if already marked with known markers
        if (preg_match('/^(?:br\d{0,2}|gz\d{0,2}|br|gz|raw):/', $payload) === 1) {
            return $payload;
        }
        $codec = $this->get_active_compression_codec();
        $threshold = (int) apply_filters('ace_rc_min_compress_size', $this->settings['min_compress_size'] ?? 512);
        if ($codec === 'off' || strlen($payload) < $threshold) {
            return 'raw:' . $payload;
        }
        if ($codec === 'brotli') {
            $level = $this->get_level_for('brotli', $context);
            if (function_exists('brotli_compress')) {
                $out = @brotli_compress($payload, $level);
                if ($out !== false) return 'br' . $level . ':' . $out;
            }
        }
        if ($codec === 'gzip') {
            $level = $this->get_level_for('gzip', $context);
            if (function_exists('gzencode')) {
                $out = @gzencode($payload, $level);
                if ($out !== false) return 'gz' . $level . ':' . $out;
            } elseif (function_exists('gzcompress')) {
                $out = @gzcompress($payload, $level);
                if ($out !== false) return 'gz' . $level . ':' . $out;
            }
        }
        return 'raw:' . $payload;
    }

    /**
     * Decompress based on marker; when for_html is true, also set headers for Content-Encoding if applicable
     */
    private function maybe_decompress($stored, $for_html = false) {
        if (!is_string($stored)) return $stored;
        static $logged_once = false;
        // Detect marker
        if (preg_match('/^br(\d{0,2}):/', $stored, $m) === 1) {
            // Accept both br: (no level) and brX: with level
            $prefix_len = strlen($m[0]);
            $data = substr($stored, $prefix_len);
            if ($for_html) {
                // Serve compressed bytes directly if client accepts br
                if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'br') !== false) {
                    if (!headers_sent()) {
                        header('Content-Encoding: br');
                        header('Vary: Accept-Encoding');
                        // Remove any existing Content-Length and set correct one for compressed bytes
                        if (function_exists('header_remove')) { header_remove('Content-Length'); }
                        header('Content-Length: ' . strlen($data));
                    }
                    return $data;
                }
            }
            if (function_exists('brotli_uncompress')) {
                $out = @brotli_uncompress($data);
                if ($out !== false) return $out;
            }
            if (!$logged_once && (defined('WP_DEBUG') && WP_DEBUG)) { $logged_once = true; error_log('Ace-Redis-Cache: brotli decompress failed; serving raw.'); }
            return $data;
        }
        if (preg_match('/^gz(\d{0,2}):/', $stored, $m) === 1) {
            $prefix_len = strlen($m[0]);
            $data = substr($stored, $prefix_len);
            if ($for_html) {
                // Only serve as gzip if client accepts it AND bytes look like gzip (1F 8B)
                if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false && substr($data, 0, 2) === "\x1f\x8b") {
                    if (!headers_sent()) {
                        header('Content-Encoding: gzip');
                        header('Vary: Accept-Encoding');
                        if (function_exists('header_remove')) { header_remove('Content-Length'); }
                        header('Content-Length: ' . strlen($data));
                    }
                    return $data;
                }
            }
            $out = @gzdecode($data);
            if ($out !== false) return $out;
            if (function_exists('gzuncompress')) {
                $out2 = @gzuncompress($data);
                if ($out2 !== false) return $out2;
            }
            if (!$logged_once && (defined('WP_DEBUG') && WP_DEBUG)) { $logged_once = true; error_log('Ace-Redis-Cache: gzip decompress failed; serving raw.'); }
            return $data;
        }
        if (str_starts_with($stored, 'raw:')) {
            return substr($stored, 4);
        }
        // Unknown format: return as-is
        return $stored;
    }

    /**
     * Get a brief compression capabilities/levels summary for diagnostics.
     *
     * @return array
     */
    public function get_compression_info() {
        $codec = $this->get_active_compression_codec();
        $obj_br = (int) apply_filters('ace_rc_brotli_level_object', 5);
        $pg_br = (int) apply_filters('ace_rc_brotli_level_page', 9);
        $obj_gz = (int) apply_filters('ace_rc_gzip_level_object', 6);
        $pg_gz = (int) apply_filters('ace_rc_gzip_level_page', 6);
        $min = (int) apply_filters('ace_rc_min_compress_size', $this->settings['min_compress_size'] ?? 512);
        return [
            'enabled' => !empty($this->settings['enable_compression']),
            'codec' => $codec,
            'functions' => [
                'brotli' => function_exists('brotli_compress') && function_exists('brotli_uncompress'),
                'gzip' => function_exists('gzencode') && function_exists('gzdecode'),
            ],
            'levels' => [
                'object' => [ 'brotli' => $obj_br, 'gzip' => $obj_gz ],
                'page'   => [ 'brotli' => $pg_br, 'gzip' => $pg_gz ],
            ],
            'min_size' => $min,
        ];
    }
    
    /**
     * Clear both regular and minified cache for a key
     *
     * @param string $key Cache key
     * @return bool Success status
     */
    public function delete_with_minification($key) {
        $redis = $this->redis_connection->get_connection();
        if (!$redis) {
            return false;
        }
        
        $success = true;
        
        // Delete regular cache
        $original_key = $this->cache_prefix . $key;
        if ($redis->exists($original_key)) {
            $success &= $redis->del($original_key);
        }
        
        // Delete minified cache
        $minified_key = $this->minified_cache_prefix . $key;
        if ($redis->exists($minified_key)) {
            $success &= $redis->del($minified_key);
        }
        
        return $success;
    }
    
    /**
     * Flush both regular and minified cache
     *
     * @return bool Success status
     */
    public function flush_all_cache() {
        $redis = $this->redis_connection->get_connection();
        if (!$redis) {
            return false;
        }
        
        // Get all cache keys (both regular and minified)
        $regular_keys = $redis->keys($this->cache_prefix . '*');
        $minified_keys = $redis->keys($this->minified_cache_prefix . '*');
        
        $all_keys = array_merge($regular_keys ?: [], $minified_keys ?: []);
        
        if (empty($all_keys)) {
            return true;
        }
        
        return $this->delete_keys_chunked($all_keys);
    }
}
