<?php
/**
 * Diagnostics Class
 * 
 * Provides comprehensive system diagnostics, performance monitoring,
 * and troubleshooting information for the Redis cache system.
 *
 * @package AceMedia\RedisCache
 * @since 0.5.0
 */

namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) exit;

class Diagnostics {
    
    private $redis_connection;
    private $cache_manager;
    private $settings;
    
    /**
     * Constructor
     *
     * @param RedisConnection $redis_connection Redis connection instance
     * @param CacheManager $cache_manager Cache manager instance
     * @param array $settings Plugin settings
     */
    public function __construct($redis_connection, $cache_manager, $settings) {
        $this->redis_connection = $redis_connection;
        $this->cache_manager = $cache_manager;
        $this->settings = $settings;
    }
    
    /**
     * Get comprehensive system diagnostics
     *
     * @return array Diagnostic information
     */
    public function get_full_diagnostics() {
        $diagnostics = [];
        
        // System information
        $diagnostics[] = "=== Ace Redis Cache Diagnostics ===";
        $diagnostics[] = "Plugin Version: " . $this->get_plugin_version();
        $diagnostics[] = "WordPress Version: " . get_bloginfo('version');
        $diagnostics[] = "PHP Version: " . PHP_VERSION;
        $diagnostics[] = "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown');
        $diagnostics[] = "";
        
        // Redis information
        $diagnostics[] = "=== Redis Configuration ===";
        $diagnostics[] = "Redis Class Available: " . (class_exists('Redis') ? 'YES' : 'NO');
        $diagnostics[] = "Host: " . $this->settings['host'];
        $diagnostics[] = "Port: " . $this->settings['port'];
        $diagnostics[] = "Password: " . (empty($this->settings['password']) ? 'No' : 'Yes (hidden)');
        $diagnostics[] = "TLS Enabled: " . ($this->settings['enable_tls'] ? 'YES' : 'NO');
        $diagnostics[] = "";
        
        // Connection status
        $connection_status = $this->redis_connection->get_status();
        $diagnostics[] = "=== Connection Status ===";
        $diagnostics[] = "Connected: " . ($connection_status['connected'] ? 'YES' : 'NO');
        $diagnostics[] = "Status: " . $connection_status['status'];
        
        if ($connection_status['connected']) {
            $diagnostics[] = "Redis Version: " . ($connection_status['version'] ?? 'Unknown');
            $diagnostics[] = "Memory Usage: " . ($connection_status['memory_usage'] ?? 'N/A');
            $diagnostics[] = "Connected Clients: " . ($connection_status['connected_clients'] ?? 'N/A');
            $diagnostics[] = "Uptime: " . $this->format_uptime($connection_status['uptime'] ?? 0);
        } else {
            $diagnostics[] = "Error: " . ($connection_status['error'] ?? 'Unknown connection error');
        }
        $diagnostics[] = "";
        
        // Plugin settings
        $diagnostics[] = "=== Plugin Settings ===";
        $diagnostics[] = "Cache Enabled: " . ($this->settings['enabled'] ? 'YES' : 'NO');
        $diagnostics[] = "Cache Mode: " . strtoupper($this->settings['mode']);
        $diagnostics[] = "Cache TTL: " . $this->settings['ttl'] . " seconds";
        $diagnostics[] = "Block Caching: " . ($this->settings['enable_block_caching'] ? 'YES' : 'NO');
        $diagnostics[] = "Minification: " . ($this->settings['enable_minification'] ? 'YES' : 'NO');
        $diagnostics[] = "";
        
        // Cache statistics
        if ($connection_status['connected']) {
            $cache_stats = $this->cache_manager->get_cache_stats();
            $diagnostics[] = "=== Cache Statistics ===";
            $diagnostics[] = "Total Keys: " . $cache_stats['total_keys'];
            $diagnostics[] = "Cache Keys: " . $cache_stats['cache_keys'];
            $diagnostics[] = "Memory Usage: " . $cache_stats['memory_usage_human'];
        }
        $diagnostics[] = "";

        // Compression info
        $diagnostics[] = "=== Compression Levels ===";
        $comp = method_exists($this->cache_manager, 'get_compression_info') ? $this->cache_manager->get_compression_info() : null;
        if ($comp) {
            $diagnostics[] = "Enabled: " . (!empty($comp['enabled']) ? 'YES' : 'NO');
            $diagnostics[] = "Active Codec: " . strtoupper($comp['codec']);
            $diagnostics[] = sprintf(
                'Object Levels — br:%s, gz:%s',
                $comp['levels']['object']['brotli'] ?? '-',
                $comp['levels']['object']['gzip'] ?? '-'
            );
            $diagnostics[] = sprintf(
                'Page Levels — br:%s, gz:%s',
                $comp['levels']['page']['brotli'] ?? '-',
                $comp['levels']['page']['gzip'] ?? '-'
            );
            $diagnostics[] = 'Min Size: ' . ($comp['min_size'] ?? 512) . ' bytes';
            $diagnostics[] = sprintf(
                'Functions — brotli:%s, gzip:%s',
                !empty($comp['functions']['brotli']) ? 'YES' : 'NO',
                !empty($comp['functions']['gzip']) ? 'YES' : 'NO'
            );
        } else {
            $diagnostics[] = 'Compression info not available';
        }
        $diagnostics[] = "";
        
        // Exclusion rules
        $diagnostics[] = "=== Exclusion Rules ===";
        $cache_exclusions = $this->cache_manager->get_cache_exclusions();
        $diagnostics[] = "Cache Exclusions: " . count($cache_exclusions) . " patterns";
        foreach ($cache_exclusions as $pattern) {
            $diagnostics[] = "  - " . $pattern;
        }
        
        $transient_exclusions = $this->cache_manager->get_transient_exclusions();
        $diagnostics[] = "Transient Exclusions: " . count($transient_exclusions) . " patterns";
        foreach ($transient_exclusions as $pattern) {
            $diagnostics[] = "  - " . $pattern;
        }
        
        $content_exclusions = $this->cache_manager->get_content_exclusions();
        $diagnostics[] = "Content Exclusions: " . count($content_exclusions) . " patterns";
        foreach ($content_exclusions as $pattern) {
            $diagnostics[] = "  - " . $pattern;
        }
        $diagnostics[] = "";
        
        // Performance diagnostics
        $diagnostics[] = "=== Performance Information ===";
        $diagnostics[] = "PHP Memory Limit: " . ini_get('memory_limit');
        $diagnostics[] = "PHP Memory Usage: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB";
        $diagnostics[] = "PHP Max Execution Time: " . ini_get('max_execution_time') . "s";
        
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load) {
                $diagnostics[] = "System Load: " . implode(', ', array_slice($load, 0, 3));
            }
        }
        $diagnostics[] = "";
        
        // WordPress information
        $diagnostics[] = "=== WordPress Configuration ===";
        $diagnostics[] = "WP_DEBUG: " . (defined('WP_DEBUG') && WP_DEBUG ? 'YES' : 'NO');
        $diagnostics[] = "WP_CACHE: " . (defined('WP_CACHE') && WP_CACHE ? 'YES' : 'NO');
        $diagnostics[] = "DOING_AJAX: " . (wp_doing_ajax() ? 'YES' : 'NO');
        $diagnostics[] = "Is Admin: " . (is_admin() ? 'YES' : 'NO');
        $diagnostics[] = "Active Plugins: " . count(get_option('active_plugins', []));
        $diagnostics[] = "";
        
        // Recent issues
        $recent_issues = get_transient('ace_redis_recent_issues');
        if ($recent_issues && is_array($recent_issues)) {
            $diagnostics[] = "=== Recent Issues ===";
            $diagnostics[] = "Issue Count (last 10 minutes): " . count($recent_issues);
            foreach (array_slice($recent_issues, -5) as $issue_time) {
                $diagnostics[] = "  - Issue at " . date('Y-m-d H:i:s', $issue_time);
            }
            $diagnostics[] = "";
        }
        
        // Test operations
        $diagnostics[] = "=== Connection Test ===";
        $test_result = $this->redis_connection->test_operations();
        if ($test_result['success']) {
            $diagnostics[] = "Write Test: " . $test_result['write'];
            $diagnostics[] = "Read Test: " . $test_result['read'];
            $diagnostics[] = "Test Value: " . $test_result['value'];
        } else {
            $diagnostics[] = "Test Failed: " . $test_result['error'];
        }
        
        return $diagnostics;
    }
    
    /**
     * Get plugin version
     *
     * @return string Plugin version
     */
    private function get_plugin_version() {
        if (!function_exists('get_plugin_data')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        $plugin_file = dirname(__DIR__) . '/ace-redis-cache.php';
        if (file_exists($plugin_file)) {
            $plugin_data = get_plugin_data($plugin_file);
            return $plugin_data['Version'] ?? 'Unknown';
        }
        
        return 'Unknown';
    }
    
    /**
     * Format uptime in human-readable format
     *
     * @param int $seconds Uptime in seconds
     * @return string Formatted uptime
     */
    private function format_uptime($seconds) {
        if ($seconds < 60) {
            return $seconds . ' seconds';
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . ' minutes';
        } elseif ($seconds < 86400) {
            return round($seconds / 3600) . ' hours';
        } else {
            $days = floor($seconds / 86400);
            $hours = floor(($seconds % 86400) / 3600);
            return $days . ' days, ' . $hours . ' hours';
        }
    }
    
    /**
     * Get performance benchmarks
     *
     * @return array Performance benchmark results
     */
    public function get_performance_benchmarks() {
        $benchmarks = [];
        
        // Redis connection benchmark
        $start_time = microtime(true);
        $connection_status = $this->redis_connection->get_status();
        $connection_time = microtime(true) - $start_time;
        
        $benchmarks['connection_time'] = round($connection_time * 1000, 2); // ms
        $benchmarks['connection_success'] = $connection_status['connected'];
        
        if ($connection_status['connected']) {
            // Write benchmark
            $start_time = microtime(true);
            $write_result = $this->redis_connection->test_operations();
            $write_time = microtime(true) - $start_time;
            
            $benchmarks['write_read_time'] = round($write_time * 1000, 2); // ms
            $benchmarks['write_read_success'] = $write_result['success'];
            
            // Cache stats retrieval benchmark
            $start_time = microtime(true);
            $this->cache_manager->get_cache_stats();
            $stats_time = microtime(true) - $start_time;
            
            $benchmarks['stats_retrieval_time'] = round($stats_time * 1000, 2); // ms
        }
        
        return $benchmarks;
    }
    
    /**
     * Check system health
     *
     * @return array Health check results
     */
    public function get_health_check() {
        $health = [
            'overall' => 'good',
            'issues' => []
        ];
        
        // Check Redis connection
        $connection_status = $this->redis_connection->get_status();
        if (!$connection_status['connected']) {
            $health['issues'][] = 'Redis connection failed: ' . ($connection_status['error'] ?? 'Unknown error');
            $health['overall'] = 'critical';
        }
        
        // Check PHP Redis extension
        if (!class_exists('Redis')) {
            $health['issues'][] = 'PHP Redis extension is not installed';
            $health['overall'] = 'critical';
        }
        
        // Check memory usage
        $memory_usage = memory_get_usage(true);
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit && $memory_limit !== '-1') {
            $memory_limit_bytes = $this->parse_memory_limit($memory_limit);
            if ($memory_usage / $memory_limit_bytes > 0.9) {
                $health['issues'][] = 'PHP memory usage is very high (' . round($memory_usage / $memory_limit_bytes * 100) . '%)';
                $health['overall'] = $health['overall'] === 'critical' ? 'critical' : 'warning';
            }
        }
        
        // Check for recent Redis issues
        $recent_issues = get_transient('ace_redis_recent_issues');
        if ($recent_issues && count($recent_issues) > 5) {
            $health['issues'][] = 'Multiple Redis connection issues detected recently (' . count($recent_issues) . ' issues)';
            $health['overall'] = $health['overall'] === 'critical' ? 'critical' : 'warning';
        }
        
        // Check system load if available
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load && $load[0] > 5.0) {
                $health['issues'][] = 'System load is high (' . round($load[0], 2) . ')';
                $health['overall'] = $health['overall'] === 'critical' ? 'critical' : 'warning';
            }
        }
        
        return $health;
    }
    
    /**
     * Parse memory limit string to bytes
     *
     * @param string $memory_limit Memory limit string
     * @return int Memory limit in bytes
     */
    private function parse_memory_limit($memory_limit) {
        $memory_limit = trim($memory_limit);
        $last = strtolower($memory_limit[strlen($memory_limit) - 1]);
        $memory_limit = (int)$memory_limit;
        
        switch ($last) {
            case 'g': $memory_limit *= 1024;
            case 'm': $memory_limit *= 1024;
            case 'k': $memory_limit *= 1024;
        }
        
        return $memory_limit;
    }
}
