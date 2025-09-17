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
        return $this->redis_connection->retry_operation(function($redis) {
            $total_keys = 0;
            $cache_keys = 0;
            $minified_cache_keys = 0;
            $total_size = 0;
            
            // Count total keys
            $all_keys = $this->scan_keys('*');
            $total_keys = count($all_keys);
            
            // Count regular cache keys
            $cache_pattern_keys = $this->scan_keys($this->cache_prefix . '*');
            $cache_keys = count($cache_pattern_keys);
            
            // Count minified cache keys
            $minified_cache_pattern_keys = $this->scan_keys($this->minified_cache_prefix . '*');
            $minified_cache_keys = count($minified_cache_pattern_keys);
            
            // Estimate total memory usage (rough calculation)
            $info = $redis->info('memory');
            $used_memory = isset($info['used_memory']) ? $info['used_memory'] : 0;
            
            return [
                'total_keys' => $total_keys,
                'cache_keys' => $cache_keys,
                'minified_cache_keys' => $minified_cache_keys,
                'memory_usage' => $used_memory,
                'memory_usage_human' => $this->format_bytes($used_memory)
            ];
        }) ?: [
            'total_keys' => 0,
            'cache_keys' => 0,
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
        $cache_keys = $this->scan_keys($this->cache_prefix . '*');
        $deleted_count = $this->delete_keys_chunked($cache_keys);
        
        return [
            'success' => true,
            'cleared' => $deleted_count,
            'message' => sprintf('Cleared %d cache keys', $deleted_count)
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
        $diagnostics['cache_mode'] = $this->settings['mode'];
        $diagnostics['cache_ttl'] = $this->settings['ttl'];
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
        
        $ttl = $ttl ?: $this->settings['ttl'];
        
        return $this->redis_connection->retry_operation(function($redis) use ($key, $value, $ttl) {
            $serialized_value = serialize($value);
            return $redis->setex($key, $ttl, $serialized_value);
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
        
        return unserialize($result);
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
                $redis->setex($minified_key, $this->get_cache_expiry(), $minified_content);
                
                // Store original version (as fallback)
                $original_key = $this->cache_prefix . $key;
                $redis->setex($original_key, $this->get_cache_expiry(), $content);
                
                return true;
            } catch (\Exception $e) {
                // If minification fails, store original
                return $this->set($key, $content);
            }
        } else {
            // Store original version only
            return $this->set($key, $content);
        }
    }
    
    /**
     * Get content from cache with minification handling
     *
     * @param string $key Cache key
     * @return string|null Cached content or null if not found
     */
    public function get_with_minification($key) {
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
                return $content;
            }
        }
        
        // Fall back to original version
        return $this->get($key);
    }
    
    /**
     * Get cache expiry time in seconds
     *
     * @return int Expiry time in seconds
     */
    private function get_cache_expiry() {
        return $this->settings['ttl'] ?? 3600; // Default 1 hour
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
