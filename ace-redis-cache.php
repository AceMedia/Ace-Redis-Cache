<?php
/*
Plugin Name: Ace Redis Cache
Description: Simple Redis-powered full page and object caching for WordPress.
Version: 0.1.1
Author: Ace Media
*/

if (!defined('ABSPATH')) exit;

class AceRedisCache {
    private $settings;
    private $cache_prefix = 'page_cache:'; // Used for counting cache keys

    public function __construct() {
        $this->settings = get_option('ace_redis_cache_settings', [
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'ttl' => 60,
            'mode' => 'full', // 'full' or 'object'
            'enabled' => 1,
        ]);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // AJAX handlers for connection test and cache size
        add_action('wp_ajax_ace_redis_cache_status', [$this, 'ajax_status']);

        // Enqueue admin JS
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue']);

        if (!is_admin() && $this->settings['enabled']) {
            if ($this->settings['mode'] === 'full') {
                $this->setup_full_page_cache();
            } else {
                $this->setup_object_cache();
            }
        }
    }

    /** Admin Settings Page **/
    public function admin_menu() {
        add_options_page('Ace Redis Cache', 'Ace Redis Cache', 'manage_options', 'ace-redis-cache', [$this, 'settings_page']);
    }

    public function register_settings() {
        register_setting('ace_redis_cache_group', 'ace_redis_cache_settings');
    }

    public function admin_enqueue($hook) {
        if ($hook !== 'settings_page_ace-redis-cache') return;
        wp_enqueue_script('ace-redis-cache-admin', plugins_url('admin.js', __FILE__), ['jquery'], null, true);
        wp_localize_script('ace-redis-cache-admin', 'AceRedisCacheAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ace_redis_cache_status'),
        ]);
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Ace Redis Cache</h1>
            <div id="ace-redis-cache-status" style="margin-bottom:1em;">
                <button id="ace-redis-cache-test-btn" class="button">Test Redis Connection</button>
                <span id="ace-redis-cache-connection"></span>
                <br>
                <strong>Cache Size:</strong> <span id="ace-redis-cache-size">-</span>
            </div>
            <form method="post" action="options.php">
                <?php settings_fields('ace_redis_cache_group'); ?>
                <?php $opts = get_option('ace_redis_cache_settings', $this->settings); ?>
                <table class="form-table">
                    <tr><th>Enable Cache</th><td><input type="checkbox" name="ace_redis_cache_settings[enabled]" value="1" <?php checked($opts['enabled'], 1); ?>></td></tr>
                    <tr><th>Redis Host</th><td><input type="text" name="ace_redis_cache_settings[host]" value="<?php echo esc_attr($opts['host']); ?>"></td></tr>
                    <tr><th>Redis Port</th><td><input type="number" name="ace_redis_cache_settings[port]" value="<?php echo esc_attr($opts['port']); ?>"></td></tr>
                    <tr><th>Redis Password</th><td><input type="password" name="ace_redis_cache_settings[password]" value="<?php echo esc_attr($opts['password']); ?>"></td></tr>
                    <tr><th>Cache TTL (seconds)</th><td><input type="number" name="ace_redis_cache_settings[ttl]" value="<?php echo esc_attr($opts['ttl']); ?>"></td></tr>
                    <tr><th>Cache Mode</th>
                        <td>
                            <select name="ace_redis_cache_settings[mode]">
                                <option value="full" <?php selected($opts['mode'], 'full'); ?>>Full Page Cache</option>
                                <option value="object" <?php selected($opts['mode'], 'object'); ?>>Object Cache Only</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /** AJAX handler for connection status and cache size **/
    public function ajax_status() {
        check_ajax_referer('ace_redis_cache_status', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('No permission');

        // Use latest settings from DB
        $settings = get_option('ace_redis_cache_settings', $this->settings);
        try {
            $redis = new Redis();
            $redis->connect($settings['host'], intval($settings['port']), 2);
            if (!empty($settings['password'])) {
                $redis->auth($settings['password']);
            }
            $status = $redis->ping() ? 'Connected' : 'Not connected';

            // Get cache size (number of keys with prefix)
            $keys = $redis->keys($this->cache_prefix . '*');
            $size = is_array($keys) ? count($keys) : 0;

            // Calculate total size in bytes
            $totalBytes = 0;
            if (is_array($keys)) {
                foreach ($keys as $key) {
                    $len = $redis->strlen($key);
                    if ($len !== false) {
                        $totalBytes += $len;
                    }
                }
            }
            $size_kb = round($totalBytes / 1024, 2);

            wp_send_json_success([
                'status' => $status,
                'size'   => $size,
                'size_kb' => $size_kb,
            ]);
        } catch (Exception $e) {
            wp_send_json_success([
                'status' => 'Not connected',
                'size'   => 0,
                'size_kb' => 0,
            ]);
        }
    }

    /** Full Page Caching **/
    private function setup_full_page_cache() {
        add_action('init', function () {
            if (is_user_logged_in() || $_SERVER['REQUEST_METHOD'] !== 'GET') {
                return;
            }

            $redis = $this->connect_redis();
            if (!$redis) return;

            $cacheKey = $this->cache_prefix . md5($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);

            if ($cached = $redis->get($cacheKey)) {
                header('X-Cache: HIT');
                echo $cached;
                exit;
            }

            ob_start(function ($buffer) use ($redis, $cacheKey) {
                $redis->setex($cacheKey, intval($this->settings['ttl']), $buffer);
                header('X-Cache: MISS');
                return $buffer;
            });
        });
    }

    /** Object Cache Placeholder **/
    private function setup_object_cache() {
        add_action('init', function () {
            header('X-Cache-Mode: Object'); // Placeholder until integrated with wp_cache_*()
        });
    }

    /** Redis Connection **/
    private function connect_redis() {
        try {
            $redis = new Redis();
            $redis->connect($this->settings['host'], intval($this->settings['port']));
            if (!empty($this->settings['password'])) {
                $redis->auth($this->settings['password']);
            }
            return $redis;
        } catch (Exception $e) {
            return false;
        }
    }
}

new AceRedisCache();
