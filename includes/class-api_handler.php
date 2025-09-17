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
            $connection = $this->cache_manager->get_connection();
            $result = $connection->test_connection();
            
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
            $connection = $this->cache_manager->get_connection();
            $result = $connection->test_write_read();
            
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
                $connection = $this->cache_manager->get_connection();
                $stats = $connection->get_cache_info();
                
                return new \WP_REST_Response([
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'cache_size' => $stats['cache_size'] ?? 'Unknown',
                        'key_count' => $stats['key_count'] ?? 0
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
            $connection = $this->cache_manager->get_connection();
            $stats = $connection->get_cache_info();
            
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
