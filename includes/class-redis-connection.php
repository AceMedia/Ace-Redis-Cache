<?php
/**
 * Redis Connection Management Class
 * 
 * Handles Redis connection management, circuit breaker pattern,
 * and connection retry logic with TLS support.
 *
 * Circuit Breaker Bypass Logic:
 * - Admin requests: Always bypass circuit breaker
 * - Local IP connections (127.0.0.1, localhost, private IPs): Always bypass
 * - Guest frontend requests: Respect circuit breaker to protect user experience
 *
 * @package AceMedia\RedisCache
 * @since 0.5.0
 */

namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) exit;

class RedisConnection {
    
    private $redis;
    private $settings;
    private $persistent_id = 'ace_redis_cache';
    private $circuit_breaker_key = 'ace_redis_circuit_breaker';
    private $circuit_breaker_window = 60; // Seconds to keep circuit open
    
    /**
     * Constructor
     *
     * @param array $settings Redis connection settings
     */
    public function __construct($settings) {
        $this->settings = $settings;
    }
    
    /**
     * Get Redis connection with circuit breaker pattern
     *
     * @param bool $force_reconnect Force a new connection
     * @param bool $bypass_circuit_breaker Bypass circuit breaker for admin/local requests
     * @return \Redis|null Redis instance or null if connection fails
     */
    public function get_connection($force_reconnect = false, $bypass_circuit_breaker = false) {
        // Check if circuit breaker should be bypassed
        if (!$bypass_circuit_breaker && !$this->should_bypass_circuit_breaker()) {
            if ($this->is_circuit_breaker_open()) {
                return null;
            }
        }
        
        if (!$this->redis || $force_reconnect) {
            try {
                $this->redis = new \Redis();
                
                // Prepare connection parameters
                $host = $this->settings['host'];
                $port = (int)$this->settings['port'];
                $timeout = 2.0; // 2 second timeout
                $reserved = null;
                $retry_interval = 0;
                $read_timeout = 0;
                
                // Configure TLS context options if TLS is enabled
                $context_options = [];
                if (!empty($this->settings['enable_tls'])) {
                    $context_options = [
                        'stream' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                        ]
                    ];
                }
                
                // Use persistent connections for better performance
                $connect_method = 'pconnect';
                $connect_params = [
                    $host,
                    $port,
                    $timeout,
                    $this->persistent_id,
                    $retry_interval,
                    $read_timeout
                ];
                
                // Add context options for TLS if enabled
                if (!empty($context_options)) {
                    $connect_params[] = $context_options;
                }
                
                if (!call_user_func_array([$this->redis, $connect_method], $connect_params)) {
                    $this->record_redis_issue();
                    return null;
                }
                
                // Authenticate if password is provided
                if (!empty($this->settings['password'])) {
                    if (!$this->redis->auth($this->settings['password'])) {
                        $this->record_redis_issue();
                        return null;
                    }
                }
                
                // Test the connection
                if (!$this->redis->ping()) {
                    $this->record_redis_issue();
                    return null;
                }
                
            } catch (\Exception $e) {
                $this->record_redis_issue();
                return null;
            }
        }
        
        return $this->redis;
    }
    
    /**
     * Close Redis connection
     */
    public function close_connection() {
        if ($this->redis) {
            try {
                $this->redis->close();
            } catch (\Exception $e) {
                // Connection might already be closed
            }
            $this->redis = null;
        }
    }
    
    /**
     * Execute Redis operation with retry logic
     *
     * @param callable $fn Function to execute
     * @param bool $bypass_circuit_breaker Bypass circuit breaker for admin/local requests
     * @return mixed Result of function or null on failure
     */
    public function retry_operation(callable $fn, $bypass_circuit_breaker = false) {
        // Auto-detect if circuit breaker should be bypassed if not explicitly set
        if (!$bypass_circuit_breaker) {
            $bypass_circuit_breaker = $this->should_bypass_circuit_breaker();
        }
        
        $max_retries = 3;
        $attempt = 0;
        
        while ($attempt < $max_retries) {
            try {
                $redis = $this->get_connection($attempt > 0, $bypass_circuit_breaker);
                if (!$redis) {
                    return null;
                }
                
                $result = $this->execute_with_timeout($fn, $redis, 1.0);
                
                // If we get here, operation succeeded
                return $result;
                
            } catch (\RedisException $e) {
                $attempt++;
                $this->record_redis_issue();
                
                if ($attempt >= $max_retries) {
                    // Only open circuit breaker for non-admin, non-local requests
                    if (!$bypass_circuit_breaker) {
                        $this->open_circuit_breaker();
                    }
                    return null;
                }
                
                // Wait before retry (exponential backoff)
                usleep(pow(2, $attempt) * 10000); // 20ms, 40ms, 80ms
            }
        }
        
        return null;
    }
    
    /**
     * Execute function with timeout protection
     *
     * @param callable $fn Function to execute
     * @param \Redis $redis Redis connection
     * @param float $max_time Maximum execution time
     * @return mixed Result of function
     * @throws \RedisException on timeout or failure
     */
    private function execute_with_timeout(callable $fn, $redis, $max_time) {
        $start_time = microtime(true);
        
        // Set Redis timeout for this operation
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, $max_time);
        
        try {
            $result = $fn($redis);
            
            $execution_time = microtime(true) - $start_time;
            if ($execution_time > $max_time) {
                throw new \RedisException("Operation timeout: {$execution_time}s");
            }
            
            return $result;
            
        } catch (\RedisException $e) {
            // Check if we're under load and should be more conservative
            // But only open circuit breaker for non-admin, non-local requests
            if ($this->is_under_load() && !$this->should_bypass_circuit_breaker()) {
                $this->open_circuit_breaker();
            }
            throw $e;
        }
    }
    
    /**
     * Check if circuit breaker should be bypassed
     *
     * @return bool True if circuit breaker should be bypassed
     */
    private function should_bypass_circuit_breaker() {
        // Always bypass for admin users and admin requests
        if ($this->is_admin_request()) {
            return true;
        }
        
        // Bypass for local IP connections
        if ($this->is_local_ip_connection()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if current request is from admin or admin user
     *
     * @return bool True if admin request
     */
    private function is_admin_request() {
        // Check if we're in admin area
        if (is_admin()) {
            return true;
        }
        
        // Check if current user can manage options (admin capability)
        if (function_exists('current_user_can') && current_user_can('manage_options')) {
            return true;
        }
        
        // Check for admin AJAX requests
        if (defined('DOING_AJAX') && DOING_AJAX) {
            // Check if it's an admin AJAX request
            if (isset($_POST['action']) && strpos($_POST['action'], 'ace_redis_') === 0) {
                return true;
            }
        }
        
        // Check for REST API admin endpoints
        if (defined('REST_REQUEST') && REST_REQUEST) {
            if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/ace-redis/') !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if connection is to a local IP
     *
     * @return bool True if connecting to local IP
     */
    private function is_local_ip_connection() {
        $host = $this->settings['host'] ?? '127.0.0.1';
        
        // Check for localhost variations
        if (in_array($host, ['localhost', '127.0.0.1', '::1'])) {
            return true;
        }
        
        // Check for private IP ranges
        if ($this->is_private_ip($host)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if an IP address is in private ranges
     *
     * @param string $ip IP address to check
     * @return bool True if IP is in private range
     */
    private function is_private_ip($ip) {
        // Convert to long format for easier comparison
        $ip_long = ip2long($ip);
        if ($ip_long === false) {
            return false; // Invalid IP
        }
        
        // Private IP ranges:
        // 10.0.0.0 - 10.255.255.255 (10.0.0.0/8)
        // 172.16.0.0 - 172.31.255.255 (172.16.0.0/12)  
        // 192.168.0.0 - 192.168.255.255 (192.168.0.0/16)
        
        return (
            ($ip_long >= ip2long('10.0.0.0') && $ip_long <= ip2long('10.255.255.255')) ||
            ($ip_long >= ip2long('172.16.0.0') && $ip_long <= ip2long('172.31.255.255')) ||
            ($ip_long >= ip2long('192.168.0.0') && $ip_long <= ip2long('192.168.255.255'))
        );
    }

    /**
     * Check if circuit breaker is open
     *
     * @return bool True if circuit breaker is open
     */
    private function is_circuit_breaker_open() {
        $breaker_time = get_transient($this->circuit_breaker_key);
        return $breaker_time && (time() - $breaker_time) < $this->circuit_breaker_window;
    }
    
    /**
     * Open circuit breaker to prevent further Redis attempts
     */
    private function open_circuit_breaker() {
        set_transient($this->circuit_breaker_key, time(), $this->circuit_breaker_window);
    }
    
    /**
     * Check if system is under load
     *
     * @return bool True if system is under high load
     */
    private function is_under_load() {
        // Check system load average (Linux only)
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load && is_array($load) && $load[0] > 2.0) {
                return true;
            }
        }
        
        // Check memory usage
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit && $memory_limit !== '-1') {
            $memory_limit_bytes = $this->parse_memory_limit($memory_limit);
            $memory_usage = memory_get_usage(true);
            
            if ($memory_usage / $memory_limit_bytes > 0.85) { // 85% memory usage
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Parse memory limit string to bytes
     *
     * @param string $memory_limit Memory limit string (e.g., "256M")
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
    
    /**
     * Check if there have been recent Redis issues
     *
     * @return bool True if there have been recent issues
     */
    private function has_recent_redis_issues() {
        $recent_issues = get_transient('ace_redis_recent_issues');
        if (!$recent_issues) {
            return false;
        }
        
        // Check if we have more than 3 issues in the last 5 minutes
        $recent_count = 0;
        $five_minutes_ago = time() - 300;
        
        foreach ($recent_issues as $issue_time) {
            if ($issue_time > $five_minutes_ago) {
                $recent_count++;
            }
        }
        
        return $recent_count > 3;
    }
    
    /**
     * Record a Redis issue for monitoring
     *
     * @param string $issue_type Type of issue encountered
     */
    private function record_redis_issue($issue_type = 'connection_failure') {
        $recent_issues = get_transient('ace_redis_recent_issues') ?: [];
        $recent_issues[] = time();
        
        // Keep only last 10 issues
        $recent_issues = array_slice($recent_issues, -10);
        
        set_transient('ace_redis_recent_issues', $recent_issues, 600); // 10 minutes
    }
    
    /**
     * Get connection status and diagnostics
     *
     * @return array Connection status information
     */
    public function get_status() {
        try {
            // Always bypass circuit breaker for status checks (admin operation)
            $redis = $this->get_connection(false, true);
            if (!$redis) {
                return [
                    'connected' => false,
                    'status' => 'Connection failed',
                    'error' => 'Unable to connect to Redis server',
                    'size' => 0,
                    'size_kb' => 0
                ];
            }
            
            $info = $redis->info();
            $memory_info = isset($info['used_memory_human']) ? $info['used_memory_human'] : 'N/A';
            $memory_bytes = isset($info['used_memory']) ? (int)$info['used_memory'] : 0;
            $memory_kb = round($memory_bytes / 1024, 2);
            
            // Get database size (number of keys)
            $db_size = 0;
            try {
                $db_size = $redis->dbSize();
            } catch (\Exception $e) {
                // Some Redis configurations might not allow DBSIZE
                $db_size = 'N/A';
            }
            
            // Detect server type and provide suggestions
            $server_suggestions = $this->get_server_suggestions($info);
            
            return [
                'connected' => true,
                'status' => 'Connected successfully',
                'version' => isset($info['redis_version']) ? $info['redis_version'] : 'Unknown',
                'memory_usage' => $memory_info,
                'memory_usage_bytes' => $memory_bytes,
                'size' => $db_size, // Number of keys (for JS)
                'size_kb' => $memory_kb, // Memory in KB (for JS)
                'connected_clients' => isset($info['connected_clients']) ? $info['connected_clients'] : 'N/A',
                'uptime' => isset($info['uptime_in_seconds']) ? $info['uptime_in_seconds'] : 'N/A',
                'server_type' => $server_suggestions['type'],
                'suggestions' => $server_suggestions['suggestions']
            ];
            
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'status' => 'Connection error',
                'error' => $e->getMessage(),
                'size' => 0,
                'size_kb' => 0
            ];
        }
    }
    
    /**
     * Detect Redis server type and provide suggestions
     *
     * @param array $info Redis INFO output
     * @return array Server type and suggestions
     */
    private function get_server_suggestions($info) {
        $suggestions = [];
        $type = 'Unknown';
        
        // Detect server type based on info
        if (isset($info['redis_mode'])) {
            $type = ucfirst($info['redis_mode']);
        } elseif (isset($info['server_type'])) {
            $type = $info['server_type'];
        } elseif (isset($info['redis_version'])) {
            $type = 'Redis ' . $info['redis_version'];
        }
        
        // Check for common cloud providers
        if (isset($info['os']) && $info['os']) {
            if (strpos($info['os'], 'Amazon') !== false) {
                $type = 'AWS ElastiCache';
                $suggestions[] = 'Consider enabling cluster mode for better scalability';
            } elseif (strpos($info['os'], 'Azure') !== false) {
                $type = 'Azure Cache for Redis';
                $suggestions[] = 'Monitor premium tier features for better performance';
            } elseif (strpos($info['os'], 'Google') !== false) {
                $type = 'Google Cloud Memorystore';
                $suggestions[] = 'Enable high availability for production workloads';
            }
        }
        
        // Memory usage suggestions
        if (isset($info['used_memory']) && isset($info['maxmemory'])) {
            $used = (int)$info['used_memory'];
            $max = (int)$info['maxmemory'];
            
            if ($max > 0) {
                $usage_percent = ($used / $max) * 100;
                
                if ($usage_percent > 90) {
                    $suggestions[] = '⚠️ Memory usage is high (' . round($usage_percent) . '%) - consider increasing memory limit';
                } elseif ($usage_percent > 75) {
                    $suggestions[] = '📊 Memory usage is moderate (' . round($usage_percent) . '%) - monitor closely';
                }
            }
        }
        
        // Performance suggestions based on connected clients
        if (isset($info['connected_clients'])) {
            $clients = (int)$info['connected_clients'];
            if ($clients > 100) {
                $suggestions[] = '🔗 High client connections (' . $clients . ') - consider connection pooling';
            }
        }
        
        // Persistence suggestions
        if (isset($info['rdb_last_save_time']) && isset($info['aof_enabled'])) {
            if ($info['aof_enabled'] == 0 && $info['rdb_last_save_time'] == 0) {
                $suggestions[] = '💾 No persistence configured - enable RDB or AOF for data safety';
            }
        }
        
        // TLS suggestions based on connection settings
        $host = $this->settings['host'] ?? '127.0.0.1';
        if (!$this->is_local_ip_connection() && empty($this->settings['enable_tls'])) {
            $suggestions[] = '🔒 Consider enabling TLS for remote connections';
        }
        
        // Local development suggestions
        if ($this->is_local_ip_connection()) {
            $suggestions[] = '🏠 Local development detected - TLS not required';
            if (empty($this->settings['password'])) {
                $suggestions[] = '🔓 No password set - okay for local development';
            }
        }
        
        // Default suggestions if none found
        if (empty($suggestions)) {
            $suggestions[] = '✅ Configuration looks good';
        }
        
        return [
            'type' => $type,
            'suggestions' => $suggestions
        ];
    }

    /**
     * Test write and read operations
     *
     * @return array Test results
     */
    public function test_operations() {
        $test_key = 'ace_redis_test_' . uniqid();
        $test_value = 'test_value_' . time();
        
        try {
            // Always bypass circuit breaker for test operations (admin operation)
            $redis = $this->get_connection(false, true);
            if (!$redis) {
                return [
                    'success' => false,
                    'error' => 'No Redis connection available'
                ];
            }
            
            // Test write
            $write_result = $redis->setex($test_key, 30, $test_value);
            if (!$write_result) {
                return [
                    'success' => false,
                    'error' => 'Failed to write test value'
                ];
            }
            
            // Test read
            $read_value = $redis->get($test_key);
            if ($read_value !== $test_value) {
                return [
                    'success' => false,
                    'error' => 'Read value does not match written value'
                ];
            }
            
            // Clean up
            $redis->del($test_key);
            
            return [
                'success' => true,
                'write' => 'SUCCESS',
                'read' => 'SUCCESS',
                'value' => $test_value
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
