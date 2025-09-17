<?php
/**
 * Redis Connection Management Class
 * 
 * Handles Redis connection management, circuit breaker pattern,
 * and connection retry logic with TLS support.
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
     * @return \Redis|null Redis instance or null if connection fails
     */
    public function get_connection($force_reconnect = false) {
        if ($this->is_circuit_breaker_open()) {
            return null;
        }
        
        if (!$this->redis || $force_reconnect) {
            try {
                $this->redis = new \Redis();
                
                // Use persistent connections for better performance
                $connect_method = 'pconnect';
                $connect_params = [
                    $this->settings['host'],
                    (int)$this->settings['port'],
                    2.0, // 2 second timeout
                    $this->persistent_id
                ];
                
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
     * @return mixed Result of function or null on failure
     */
    public function retry_operation(callable $fn) {
        $max_retries = 3;
        $attempt = 0;
        
        while ($attempt < $max_retries) {
            try {
                $redis = $this->get_connection($attempt > 0);
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
                    $this->open_circuit_breaker();
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
            if ($this->is_under_load()) {
                $this->open_circuit_breaker();
            }
            throw $e;
        }
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
        
        // Log to debug.log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Ace Redis Cache: {$issue_type} at " . date('Y-m-d H:i:s'));
        }
    }
    
    /**
     * Get connection status and diagnostics
     *
     * @return array Connection status information
     */
    public function get_status() {
        try {
            $redis = $this->get_connection();
            if (!$redis) {
                return [
                    'connected' => false,
                    'status' => 'Connection failed',
                    'error' => 'Unable to connect to Redis server'
                ];
            }
            
            $info = $redis->info();
            $memory_info = isset($info['used_memory_human']) ? $info['used_memory_human'] : 'N/A';
            
            return [
                'connected' => true,
                'status' => 'Connected successfully',
                'version' => isset($info['redis_version']) ? $info['redis_version'] : 'Unknown',
                'memory_usage' => $memory_info,
                'connected_clients' => isset($info['connected_clients']) ? $info['connected_clients'] : 'N/A',
                'uptime' => isset($info['uptime_in_seconds']) ? $info['uptime_in_seconds'] : 'N/A'
            ];
            
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'status' => 'Connection error',
                'error' => $e->getMessage()
            ];
        }
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
            $redis = $this->get_connection();
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
