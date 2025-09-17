<?php
/**
 * Admin AJAX Class
 * 
 * Handles AJAX requests from the admin interface for status checks,
 * cache operations, and diagnostics.
 *
 * @package AceMedia\RedisCache
 * @since 0.5.0
 */

namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) exit;

class AdminAjax {
    
    private $redis_connection;
    private $cache_manager;
    private $diagnostics;
    private $settings;
    
    /**
     * Constructor
     *
     * @param RedisConnection $redis_connection Redis connection instance
     * @param CacheManager $cache_manager Cache manager instance
     * @param Diagnostics $diagnostics Diagnostics instance
     * @param array $settings Plugin settings
     */
    public function __construct($redis_connection, $cache_manager, $diagnostics, $settings) {
        $this->redis_connection = $redis_connection;
        $this->cache_manager = $cache_manager;
        $this->diagnostics = $diagnostics;
        $this->settings = $settings;
    }
    
    /**
     * Setup AJAX hooks
     */
    public function setup_hooks() {
        add_action('wp_ajax_ace_redis_cache_status', [$this, 'handle_status_request']);
        add_action('wp_ajax_ace_redis_cache_flush', [$this, 'handle_flush_request']);
        add_action('wp_ajax_ace_redis_cache_flush_blocks', [$this, 'handle_flush_blocks_request']);
        add_action('wp_ajax_ace_redis_cache_test_write', [$this, 'handle_test_write_request']);
        add_action('wp_ajax_ace_redis_cache_diagnostics', [$this, 'handle_diagnostics_request']);
        add_action('wp_ajax_ace_redis_cache_metrics', [$this, 'handle_metrics_request']);
        add_action('wp_ajax_ace_redis_cache_save_settings', [$this, 'handle_save_settings']);
        add_action('wp_ajax_ace_redis_dismiss_notice', [$this, 'handle_dismiss_notice']);
    }
    
    /**
     * Send JSON response with error handling
     *
     * @param mixed $data Response data
     * @param bool $success Success status
     */
    private function send_response($data, $success = true) {
        if ($success) {
            wp_send_json_success($data);
        } else {
            wp_send_json_error($data);
        }
    }
    
    /**
     * Handle Redis status request
     */
    public function handle_status_request() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ace_redis_admin_nonce') || !current_user_can('manage_options')) {
            $this->send_response('Unauthorized', false);
            return;
        }
        
        try {
            $status = $this->redis_connection->get_status();
            $cache_stats = $this->cache_manager->get_cache_stats();
            
            $response = [
                'status' => $status['connected'] ? 'Connected successfully' : $status['error'],
                'connected' => $status['connected'],
                'size' => $cache_stats['total_keys'],
                'size_kb' => round($cache_stats['memory_usage'] / 1024, 2),
                'debug_info' => $status['connected'] ? 
                    sprintf('Redis v%s, %s clients, %s uptime', 
                        $status['version'] ?? 'Unknown',
                        $status['connected_clients'] ?? 'N/A',
                        $this->format_uptime($status['uptime'] ?? 0)
                    ) : null
            ];
            
            $this->send_response($response);
            
        } catch (\Exception $e) {
            $this->send_response([
                'status' => 'Connection error: ' . $e->getMessage(),
                'connected' => false,
                'size' => 0,
                'size_kb' => 0
            ], false);
        }
    }
    
    /**
     * Handle cache flush request
     */
    public function handle_flush_request() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ace_redis_admin_nonce') || !current_user_can('manage_options')) {
            $this->send_response('Unauthorized', false);
            return;
        }
        
        try {
            $result = $this->cache_manager->clear_all_cache();
            $this->send_response($result);
            
        } catch (\Exception $e) {
            $this->send_response([
                'message' => 'Failed to clear cache: ' . $e->getMessage(),
                'cleared' => 0
            ], false);
        }
    }
    
    /**
     * Handle block cache flush request
     */
    public function handle_flush_blocks_request() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ace_redis_admin_nonce') || !current_user_can('manage_options')) {
            $this->send_response('Unauthorized', false);
            return;
        }
        
        try {
            $result = $this->cache_manager->clear_block_cache();
            $this->send_response($result);
            
        } catch (\Exception $e) {
            $this->send_response([
                'message' => 'Failed to clear block cache: ' . $e->getMessage(),
                'cleared' => 0
            ], false);
        }
    }
    
    /**
     * Handle Redis write test request
     */
    public function handle_test_write_request() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ace_redis_admin_nonce') || !current_user_can('manage_options')) {
            $this->send_response('Unauthorized', false);
            return;
        }
        
        try {
            $result = $this->redis_connection->test_operations();
            
            if ($result['success']) {
                $this->send_response($result);
            } else {
                $this->send_response($result['error'], false);
            }
            
        } catch (\Exception $e) {
            $this->send_response('Test failed: ' . $e->getMessage(), false);
        }
    }
    
    /**
     * Handle diagnostics request
     */
    public function handle_diagnostics_request() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ace_redis_admin_nonce') || !current_user_can('manage_options')) {
            $this->send_response('Unauthorized', false);
            return;
        }
        
        try {
            $diagnostics = $this->diagnostics->get_full_diagnostics();
            $this->send_response($diagnostics);
            
        } catch (\Exception $e) {
            $this->send_response('Diagnostics failed: ' . $e->getMessage(), false);
        }
    }
    
    /**
     * Handle save settings request
     */
    public function handle_save_settings() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ace_redis_admin_nonce') || !current_user_can('manage_options')) {
            $this->send_response('Unauthorized', false);
            return;
        }

        try {
            // Get current settings for comparison
            $old_settings = get_option('ace_redis_cache_settings', []);
            
            // Parse form data
            parse_str($_POST['form_data'] ?? '', $form_data);
            $new_settings = $form_data['ace_redis_cache_settings'] ?? [];
            
            // Validate and sanitize settings
            $validated_settings = $this->validate_settings($new_settings);
            
            if ($validated_settings === false) {
                $this->send_response('Invalid settings provided', false);
                return;
            }
            
            // Save the settings
            $saved = update_option('ace_redis_cache_settings', $validated_settings);
            
            if (!$saved && $old_settings === $validated_settings) {
                // Settings are the same, still success
                $this->send_response([
                    'message' => 'Settings saved successfully!',
                    'settings_changed' => false
                ]);
                return;
            }
            
            // Clear cache if settings changed significantly
            $cache_cleared = false;
            if ($this->should_clear_cache($old_settings, $validated_settings)) {
                $redis_connection = new RedisConnection($validated_settings);
                $cache_manager = new \AceMedia\RedisCache\CacheManager($redis_connection, $validated_settings);
                $cache_manager->clear_all_cache();
                $cache_cleared = true;
            }
            
            $message = $cache_cleared ? 
                'Settings saved and cache cleared successfully!' : 
                'Settings saved successfully!';
                
            $this->send_response([
                'message' => $message,
                'settings_changed' => true,
                'cache_cleared' => $cache_cleared
            ]);
            
        } catch (Exception $e) {
            $this->send_response('Failed to save settings: ' . $e->getMessage(), false);
        }
    }
    
    /**
     * Validate and sanitize settings
     *
     * @param array $settings Raw settings from form
     * @return array|false Validated settings or false on error
     */
    private function validate_settings($settings) {
        $validated = [];
        
        // Redis connection settings
        $validated['host'] = sanitize_text_field($settings['host'] ?? '127.0.0.1');
        $validated['port'] = absint($settings['port'] ?? 6379);
        $validated['password'] = sanitize_text_field($settings['password'] ?? '');
        $validated['ttl'] = absint($settings['ttl'] ?? 3600);
        
        // Boolean settings - match form field names
        $validated['enabled'] = !empty($settings['enabled']);
        $validated['enable_compression'] = !empty($settings['enable_compression']);
        $validated['enable_tls'] = !empty($settings['enable_tls']);
        $validated['enable_block_caching'] = !empty($settings['enable_block_caching']);
        $validated['enable_minification'] = !empty($settings['enable_minification']);
        $validated['debug_mode'] = !empty($settings['debug_mode']);
        
        // Cache mode - match form field name
        $cache_mode = sanitize_text_field($settings['mode'] ?? 'full');
        $validated['mode'] = in_array($cache_mode, ['full', 'object']) ? $cache_mode : 'full';
        
        // Exclusions
        $validated['exclude_pages'] = sanitize_textarea_field($settings['exclude_pages'] ?? '');
        $validated['exclude_posts'] = sanitize_textarea_field($settings['exclude_posts'] ?? '');
        $validated['exclude_urls'] = sanitize_textarea_field($settings['exclude_urls'] ?? '');
        $validated['exclude_user_agents'] = sanitize_textarea_field($settings['exclude_user_agents'] ?? '');
        
        return $validated;
    }
    
    /**
     * Check if cache should be cleared based on setting changes
     *
     * @param array $old_settings Old settings
     * @param array $new_settings New settings  
     * @return bool True if cache should be cleared
     */
    private function should_clear_cache($old_settings, $new_settings) {
        $cache_affecting_keys = [
            'host', 'port', 'password', 'enabled', 'enable_compression',
            'mode', 'enable_block_caching', 'exclude_pages', 'exclude_posts',
            'exclude_urls', 'exclude_user_agents'
        ];
        
        foreach ($cache_affecting_keys as $key) {
            if (($old_settings[$key] ?? '') !== ($new_settings[$key] ?? '')) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Handle metrics request
     */
    public function handle_metrics_request() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ace_redis_admin_nonce') || !current_user_can('manage_options')) {
            $this->send_response('Unauthorized', false);
            return;
        }

        try {
            $redis_connection = new RedisConnection($this->settings);
            $redis = $redis_connection->get_connection();
            
            if (!$redis) {
                $this->send_response([
                    'cache_hit_rate' => '--',
                    'memory_usage' => '--',
                    'total_keys' => '--',
                    'connection_time' => '--'
                ]);
                return;
            }

            $start_time = microtime(true);
            $info = $redis->info();
            $connection_time = round((microtime(true) - $start_time) * 1000, 2);
            
            // Calculate hit rate from stats
            $hits = isset($info['keyspace_hits']) ? (int)$info['keyspace_hits'] : 0;
            $misses = isset($info['keyspace_misses']) ? (int)$info['keyspace_misses'] : 0;
            $total_requests = $hits + $misses;
            $hit_rate = $total_requests > 0 ? round(($hits / $total_requests) * 100, 1) : 0;
            
            // Get memory usage
            $memory_usage = isset($info['used_memory_human']) ? $info['used_memory_human'] : '0B';
            
            // Count keys in our namespace
            $keys = $redis->keys('ace_redis_cache:*');
            $total_keys = is_array($keys) ? count($keys) : 0;

            $this->send_response([
                'cache_hit_rate' => $hit_rate . '%',
                'memory_usage' => $memory_usage,
                'total_keys' => number_format($total_keys),
                'connection_time' => $connection_time . 'ms'
            ]);

        } catch (Exception $e) {
            $this->send_response([
                'cache_hit_rate' => '--',
                'memory_usage' => '--', 
                'total_keys' => '--',
                'connection_time' => '--',
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle notice dismissal
     */
    public function handle_dismiss_notice() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ace_redis_dismiss') || !current_user_can('manage_options')) {
            $this->send_response('Unauthorized', false);
            return;
        }
        
        $version = sanitize_text_field($_POST['version'] ?? '');
        if ($version) {
            update_option('ace_redis_cache_dismissed_version', $version);
            $this->send_response(['dismissed' => true]);
        } else {
            $this->send_response('Invalid version', false);
        }
    }
    
    /**
     * Format uptime in human-readable format
     *
     * @param int $seconds Uptime in seconds
     * @return string Formatted uptime
     */
    private function format_uptime($seconds) {
        if ($seconds < 60) {
            return $seconds . 's';
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        } elseif ($seconds < 86400) {
            return round($seconds / 3600) . 'h';
        } else {
            return round($seconds / 86400) . 'd';
        }
    }
}
