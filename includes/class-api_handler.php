<?php
/**
 * REST API Handler for Ace Redis Cache
 *
 * Provides REST API endpoints for plugin operations
 * as an alternative to admin-ajax.php
 *
 * @package AceMedia\RedisCache
 * @since 0.5.0
 */

namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) exit;

/**
 * REST API Handler Class
 */
class API_Handler {
    
    private $namespace = 'ace-redis-cache/v1';
    private $cache_manager;
    private $redis_connection;
    private $settings;
    
    /**
     * Constructor
     *
     * @param CacheManager $cache_manager Cache manager instance
     * @param RedisConnection $redis_connection Redis connection instance
     * @param array $settings Plugin settings
     */
    public function __construct($cache_manager, $redis_connection = null, $settings = null) {
        $this->cache_manager = $cache_manager;
        $this->redis_connection = $redis_connection;
        $this->settings = $settings;
        $this->init();
    }
    
    /**
     * Initialize REST API endpoints
     */
    private function init() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Save settings endpoint
        register_rest_route($this->namespace, '/settings', [
            'methods' => 'POST',
            'callback' => [$this, 'save_settings'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'settings' => [
                    'required' => true,
                    'type' => 'object',
                    'description' => 'Plugin settings to save'
                ],
                'nonce' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Security nonce'
                ]
            ]
        ]);
        
        // Test connection endpoint
        register_rest_route($this->namespace, '/test-connection', [
            'methods' => 'POST',
            'callback' => [$this, 'test_connection'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'nonce' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Security nonce'
                ]
            ]
        ]);
        
        // Test write/read endpoint
        register_rest_route($this->namespace, '/test-write-read', [
            'methods' => 'POST',
            'callback' => [$this, 'test_write_read'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'nonce' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Security nonce'
                ]
            ]
        ]);
        
        // Flush cache endpoint
        register_rest_route($this->namespace, '/flush-cache', [
            'methods' => 'POST',
            'callback' => [$this, 'flush_cache'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'type' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'all',
                    'enum' => ['all', 'blocks'],
                    'description' => 'Type of cache to flush'
                ],
                'nonce' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Security nonce'
                ]
            ]
        ]);
        
        // Get cache stats endpoint
        register_rest_route($this->namespace, '/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_cache_stats'],
            'permission_callback' => [$this, 'check_permissions']
        ]);
        
        // Run diagnostics endpoint
        register_rest_route($this->namespace, '/diagnostics', [
            'methods' => 'POST',
            'callback' => [$this, 'run_diagnostics'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'nonce' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Security nonce'
                ]
            ]
        ]);
        
        // Simple flush route (as requested in requirements)
        register_rest_route($this->namespace, '/flush', [
            'methods' => 'POST',
            'callback' => [$this, 'simple_flush_cache'],
            'permission_callback' => [$this, 'check_simple_permissions']
        ]);
        
        // Simple status route (as requested in requirements)
        register_rest_route($this->namespace, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_simple_status'],
            'permission_callback' => '__return_true'  // Temporary: make public for testing
        ]);
        
        // Simple metrics route for admin dashboard
        register_rest_route($this->namespace, '/metrics', [
            'methods' => 'GET',
            'callback' => [$this, 'get_simple_metrics'],
            'permission_callback' => [$this, 'check_simple_permissions']
        ]);
    }
    
    /**
     * Check user permissions for API access
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function check_permissions($request) {
        // Check if user can manage options
        if (!current_user_can('manage_options')) {
            return false;
        }
        
        // For POST requests, verify nonce
        if ($request->get_method() === 'POST') {
            $nonce = $request->get_param('nonce');
            if (!wp_verify_nonce($nonce, 'ace_redis_admin_nonce')) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Simple permission check without nonce requirement
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function check_simple_permissions($request) {
        // Only check if user can manage options (no nonce required)
        return current_user_can('manage_options');
    }
    
    /**
     * Save plugin settings
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function save_settings($request) {
        try {
            $settings = $request->get_param('settings');
            
            // Sanitize settings (use built-in sanitization logic)
            $sanitized_settings = $this->sanitize_settings($settings);
            
            // Save settings
            $result = update_option('ace_redis_cache_settings', $sanitized_settings);
            
            if ($result) {
                return new \WP_REST_Response([
                    'success' => true,
                    'message' => 'Settings saved successfully.',
                    'data' => $sanitized_settings
                ], 200);
            } else {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'Failed to save settings. No changes detected or database error.',
                    'error' => 'SAVE_FAILED'
                ], 400);
            }
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Error saving settings: ' . $e->getMessage(),
                'error' => 'EXCEPTION'
            ], 500);
        }
    }
    
    /**
     * Test Redis connection
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function test_connection($request) {
        try {
            $start_time = microtime(true);
            $connection = $this->cache_manager->get_redis_connection();
            $result = $connection->get_status();
            $response_time = round((microtime(true) - $start_time) * 1000, 2);
            
            // Add response time to the result
            if (is_array($result)) {
                $result['response_time'] = $response_time . 'ms';
            } else {
                $result = [
                    'original_result' => $result,
                    'response_time' => $response_time . 'ms'
                ];
            }
            
            return new \WP_REST_Response([
                'success' => true,
                'data' => $result
            ], 200);
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
                'error' => 'CONNECTION_FAILED'
            ], 500);
        }
    }
    
    /**
     * Test Redis write/read operations
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function test_write_read($request) {
        try {
            $connection = $this->cache_manager->get_redis_connection();
            $result = $connection->test_operations();
            
            return new \WP_REST_Response([
                'success' => true,
                'data' => $result
            ], 200);
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Write/read test failed: ' . $e->getMessage(),
                'error' => 'WRITE_READ_FAILED'
            ], 500);
        }
    }
    
    /**
     * Flush cache
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function flush_cache($request) {
        try {
            $type = $request->get_param('type');
            
            if ($type === 'blocks') {
                $result = $this->cache_manager->clear_block_cache();
                $message = 'Block cache flushed successfully.';
            } else {
                $result = $this->cache_manager->clear_all_cache();
                $message = 'All cache flushed successfully.';
            }
            
            if ($result) {
                // Get updated stats
                $stats = $this->cache_manager->get_cache_stats();
                
                return new \WP_REST_Response([
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'cache_size' => $stats['memory_usage_human'] ?? 'Unknown',
                        'key_count' => $stats['cache_keys'] ?? 0
                    ]
                ], 200);
            } else {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'Failed to flush cache.',
                    'error' => 'FLUSH_FAILED'
                ], 500);
            }
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Cache flush failed: ' . $e->getMessage(),
                'error' => 'EXCEPTION'
            ], 500);
        }
    }
    
    /**
     * Get cache statistics
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_cache_stats($request) {
        try {
            $stats = $this->cache_manager->get_cache_stats();
            
            return new \WP_REST_Response([
                'success' => true,
                'data' => $stats
            ], 200);
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Failed to get cache stats: ' . $e->getMessage(),
                'error' => 'STATS_FAILED'
            ], 500);
        }
    }
    
    /**
     * Run system diagnostics
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function run_diagnostics($request) {
        try {
            $diagnostics = new Diagnostics($this->redis_connection, $this->cache_manager, $this->settings);
            $result = $diagnostics->get_full_diagnostics();
            
            return new \WP_REST_Response([
                'success' => true,
                'data' => $result
            ], 200);
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Diagnostics failed: ' . $e->getMessage(),
                'error' => 'DIAGNOSTICS_FAILED'
            ], 500);
        }
    }
    
    /**
     * Simple flush cache endpoint handler (as requested in requirements)
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function simple_flush_cache($request) {
        try {
            // Check if cache is enabled first
            $settings = get_option('ace_redis_cache_settings', []);
            if (empty($settings['enabled'])) {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'Cache is disabled, nothing to flush',
                    'timestamp' => current_time('mysql')
                ], 400);
            }
            
            // Clear all cache using the cache manager
            $result = $this->cache_manager->clear_all_cache();
            
            return new \WP_REST_Response([
                'success' => true,
                'message' => 'Cache flushed successfully',
                'timestamp' => current_time('mysql')
            ], 200);
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Failed to flush cache: ' . $e->getMessage(),
                'error' => 'CACHE_FLUSH_FAILED'
            ], 500);
        }
    }
    
    /**
     * Simple status endpoint handler (as requested in requirements)
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_simple_status($request) {
        try {
            // Check if cache is enabled first
            $settings = get_option('ace_redis_cache_settings', []);
            if (empty($settings['enabled'])) {
                return new \WP_REST_Response([
                    'success' => true,
                    'redis_connected' => false,
                    'cache_entries' => 0,
                    'message' => 'Cache is disabled',
                    'timestamp' => current_time('mysql')
                ], 200);
            }
            
            // Get Redis connection status
            $redis_connection = $this->cache_manager->get_redis_connection();
            $connection_status = $redis_connection->get_status();
            
            // Get cache statistics
            $cache_stats = $this->cache_manager->get_cache_stats();
            
            return new \WP_REST_Response([
                'success' => true,
                'redis_connected' => $connection_status['connected'],
                'cache_entries' => $cache_stats['total_keys'] ?? 0,
                'timestamp' => current_time('mysql')
            ], 200);
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Failed to get status: ' . $e->getMessage(),
                'error' => 'STATUS_CHECK_FAILED'
            ], 500);
        }
    }
    
    /**
     * Simple metrics endpoint handler for admin dashboard
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_simple_metrics($request) {
        try {
            // Get basic metrics without heavy operations
            $metrics = [
                'cache_hit_rate' => '--',
                'total_keys' => 0,
                'memory_usage' => '--',
                'uptime' => '--',
                'connected_clients' => '--',
                'ops_per_sec' => '--',
                'response_time' => '--'
            ];
            
            // Check if cache is enabled before attempting any connections
            $settings = get_option('ace_redis_cache_settings', []);
            if (empty($settings['enabled'])) {
                return new \WP_REST_Response([
                    'success' => true,
                    'data' => $metrics,
                    'message' => 'Cache is disabled',
                    'timestamp' => current_time('mysql')
                ], 200);
            }
            
            // Try to get metrics if cache manager is available and cache is enabled
            if ($this->cache_manager) {
                try {
                    $connection = $this->cache_manager->get_redis_connection();
                    
                    if ($connection) {
                        // Get the actual Redis instance, not the wrapper
                        $redis_instance = $connection->get_connection();
                        
                        if ($redis_instance) {
                            $start_time = microtime(true);
                            $info = $redis_instance->info();
                            $keyspace_info = $redis_instance->info('keyspace');
                            $response_time = round((microtime(true) - $start_time) * 1000, 2);
                            
                            if ($info) {
                                // Look for database keys in keyspace info
                                $total_keys = 0;
                                if ($keyspace_info) {
                                    foreach ($keyspace_info as $key => $value) {
                                        if (strpos($key, 'db') === 0) {
                                            // Parse "keys=60,expires=60,avg_ttl=8791544" format
                                            if (preg_match('/keys=(\d+)/', $value, $matches)) {
                                                $total_keys += intval($matches[1]);
                                            }
                                        }
                                    }
                                }
                                
                                // Extract key metrics
                                $metrics = [
                                    'cache_hit_rate' => isset($info['keyspace_hits'], $info['keyspace_misses']) 
                                        ? round(($info['keyspace_hits'] / max(1, $info['keyspace_hits'] + $info['keyspace_misses'])) * 100, 1) . '%'
                                        : '--',
                                    'total_keys' => $total_keys,
                                    'memory_usage' => isset($info['used_memory_human']) ? $info['used_memory_human'] : '--',
                                    'uptime' => isset($info['uptime_in_seconds']) ? gmdate("H:i:s", $info['uptime_in_seconds']) : '--',
                                    'connected_clients' => $info['connected_clients'] ?? '--',
                                    'ops_per_sec' => $info['instantaneous_ops_per_sec'] ?? '--',
                                    'response_time' => $response_time . 'ms'
                                ];
                            }
                        }
                    } else {
                        // Connection wrapper is null
                    }
                } catch (\Exception $e) {
                    // Log error but continue with default metrics
                }
            } else {
                // Try to create a temporary Redis connection directly only if cache is enabled
                if (!empty($settings['enabled'])) {
                    try {
                    
                    if (!empty($settings['host']) && !empty($settings['port'])) {
                        $redis = new \Redis();
                        $connected = $redis->connect($settings['host'], $settings['port'], 2); // 2 second timeout
                        
                        if ($connected && !empty($settings['password'])) {
                            $auth_result = $redis->auth($settings['password']);
                        }
                        
                        if ($connected) {
                            $start_time = microtime(true);
                            $info = $redis->info();
                            $keyspace_info = $redis->info('keyspace');
                            $response_time = round((microtime(true) - $start_time) * 1000, 2);
                            
                            if ($info) {
                                // Look for database keys in keyspace info
                                $total_keys = 0;
                                if ($keyspace_info) {
                                    foreach ($keyspace_info as $key => $value) {
                                        if (strpos($key, 'db') === 0) {
                                            // Parse "keys=60,expires=60,avg_ttl=8791544" format
                                            if (preg_match('/keys=(\d+)/', $value, $matches)) {
                                                $total_keys += intval($matches[1]);
                                            }
                                        }
                                    }
                                }
                                
                                $metrics = [
                                    'cache_hit_rate' => isset($info['keyspace_hits'], $info['keyspace_misses']) 
                                        ? round(($info['keyspace_hits'] / max(1, $info['keyspace_hits'] + $info['keyspace_misses'])) * 100, 1) . '%'
                                        : '--',
                                    'total_keys' => $total_keys,
                                    'memory_usage' => isset($info['used_memory_human']) ? $info['used_memory_human'] : '--',
                                    'uptime' => isset($info['uptime_in_seconds']) ? gmdate("H:i:s", $info['uptime_in_seconds']) : '--',
                                    'connected_clients' => $info['connected_clients'] ?? '--',
                                    'ops_per_sec' => $info['instantaneous_ops_per_sec'] ?? '--',
                                    'response_time' => $response_time . 'ms'
                                ];
                            }
                            $redis->close();
                        } else {
                            // Direct Redis connection failed
                        }
                    } else {
                        // Missing Redis host or port in settings
                    }
                    } catch (\Exception $e) {
                        // Log error but continue with default metrics
                    }
                } else {
                    // Cache is disabled, not attempting direct Redis connection
                }
            }
            
            return new \WP_REST_Response([
                'success' => true,
                'data' => $metrics,
                'timestamp' => current_time('mysql')
            ], 200);
            
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Failed to get metrics: ' . $e->getMessage(),
                'error' => 'METRICS_FAILED',
                'data' => [
                    'cache_hit_rate' => '--',
                    'total_keys' => 0,
                    'memory_usage' => '--',
                    'uptime' => '--',
                    'connected_clients' => '--',
                    'ops_per_sec' => '--',
                    'response_time' => '--'
                ]
            ], 500);
        }
    }
    
    /**
     * Sanitize settings array
     *
     * @param array $input Raw input data
     * @return array Sanitized settings
     */
    private function sanitize_settings($input) {
        $sanitized = [];
        
        $sanitized['enabled'] = !empty($input['enabled']) ? 1 : 0;
        $sanitized['mode'] = in_array($input['mode'] ?? 'full', ['full', 'object']) ? ($input['mode'] ?? 'full') : 'full';
        $sanitized['host'] = sanitize_text_field($input['host']);
        $sanitized['port'] = intval($input['port']);
        $sanitized['password'] = sanitize_text_field($input['password']);
        $sanitized['ttl'] = max(60, intval($input['ttl']));
        $sanitized['enable_tls'] = !empty($input['enable_tls']) ? 1 : 0;
        $sanitized['enable_block_caching'] = !empty($input['enable_block_caching']) ? 1 : 0;
        $sanitized['enable_minification'] = !empty($input['enable_minification']) ? 1 : 0;
        
        // Sanitize exclusion patterns
        $sanitized['custom_cache_exclusions'] = sanitize_textarea_field($input['custom_cache_exclusions'] ?? '');
        $sanitized['custom_transient_exclusions'] = sanitize_textarea_field($input['custom_transient_exclusions'] ?? '');
        $sanitized['custom_content_exclusions'] = sanitize_textarea_field($input['custom_content_exclusions'] ?? '');
        $sanitized['excluded_blocks'] = sanitize_textarea_field($input['excluded_blocks'] ?? '');
        
        return $sanitized;
    }
}
