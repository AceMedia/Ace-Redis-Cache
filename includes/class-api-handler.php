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
    
    /**
     * Constructor
     *
     * @param Cache_Manager $cache_manager Cache manager instance
     */
    public function __construct($cache_manager) {
        $this->cache_manager = $cache_manager;
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
            
            // Sanitize settings (reuse existing sanitization)
            $admin_interface = new Admin_Interface($this->cache_manager);
            $sanitized_settings = $admin_interface->sanitize_settings($settings);
            
            $old = get_option('ace_redis_cache_settings', []);
            $result = update_option('ace_redis_cache_settings', $sanitized_settings);
            $final = get_option('ace_redis_cache_settings', []);
            $changed = $old != $final;
            $msg = $changed ? 'Settings saved.' : 'No changes to save (or unchanged after write).';
            $response_data = [
                'message' => $msg,
                'settings_changed' => $changed,
                'settings' => $sanitized_settings,
                'update_result' => $result,
            ];
            return new \WP_REST_Response([
                'success' => true,
                'data' => $response_data,
            ], 200);
            
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
            $connection = $this->cache_manager->get_redis_connection();
            $result = $connection->test_operations();
            
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
                $result = $this->cache_manager->flush_block_cache();
                $message = 'Block cache flushed successfully.';
            } else {
                $result = $this->cache_manager->flush_all();
                $message = 'All cache flushed successfully.';
            }
            
            if ($result) {
                // Get updated stats
                $connection = $this->cache_manager->get_redis_connection();
                $stats = $connection->get_status();
                
                return new \WP_REST_Response([
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'cache_size' => $stats['memory_usage'] ?? 'Unknown',
                        'key_count' => $stats['connected_clients'] ?? 0
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
            // Return plugin-scoped stats only
            $stats = $this->cache_manager->get_cache_stats();
            $memory = $this->cache_manager->get_memory_usage_breakdown();
            
            return new \WP_REST_Response([
                'success' => true,
                'data' => [
                    'total_keys' => $stats['total_keys'] ?? 0,
                    'cache_keys' => $stats['cache_keys'] ?? 0,
                    'minified_cache_keys' => $stats['minified_cache_keys'] ?? 0,
                    'block_cache_keys' => $stats['block_cache_keys'] ?? 0,
                    'memory_usage' => $stats['memory_usage'] ?? 0,
                    'memory_usage_human' => $stats['memory_usage_human'] ?? '0 B',
                    'memory_breakdown' => $memory
                ]
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
            $diagnostics = new Diagnostics($this->cache_manager);
            $result = $diagnostics->run_full_diagnostics();
            
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
}
