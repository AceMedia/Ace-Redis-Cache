<?php
/*
Plugin Name: Ace Redis Cache
Description: Simple Redis-powered full page and object caching for WordPress.
Version: 0.1.2
Author: Ace Media
*/

if (!defined('ABSPATH')) exit;

class AceRedisCache {
    private $settings;
    private $cache_prefix = 'page_cache:'; // Used for counting and flushing cache keys

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

        // AJAX handlers for status and flushing cache
        add_action('wp_ajax_ace_redis_cache_status', [$this, 'ajax_status']);
        add_action('wp_ajax_ace_redis_cache_flush', [$this, 'ajax_flush']);

        // Admin scripts
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
            'flush_nonce' => wp_create_nonce('ace_redis_cache_flush')
        ]);
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Ace Redis Cache</h1>
            <div id="ace-redis-cache-status" style="margin-bottom:1em;">
                <button id="ace-redis-cache-test-btn" class="button">Test Redis Connection</button>
                <button id="ace-redis-cache-flush-btn" class="button">Flush Cache</button>
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
        <script>
        jQuery(function($) {
            $('#ace-redis-cache-test-btn').on('click', function(e) {
                e.preventDefault();
                $.post(AceRedisCacheAjax.ajax_url, {action:'ace_redis_cache_status', nonce:AceRedisCacheAjax.nonce}, function(res) {
                    if(res.success) {
                        $('#ace-redis-cache-connection').text(res.data.status);
                        $('#ace-redis-cache-size').text(res.data.size + ' keys (' + res.data.size_kb + ' KB)');
                    }
                });
            });
            $('#ace-redis-cache-flush-btn').on('click', function(e) {
                e.preventDefault();
                $.post(AceRedisCacheAjax.ajax_url, {action:'ace_redis_cache_flush', nonce:AceRedisCacheAjax.flush_nonce}, function(res) {
                    alert(res.success ? 'Cache flushed!' : 'Failed to flush cache');
                    $('#ace-redis-cache-test-btn').click();
                });
            });
        });
        </script>
        <?php
    }

    /** AJAX: Connection status **/
    public function ajax_status() {
        check_ajax_referer('ace_redis_cache_status', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('No permission');

        $settings = get_option('ace_redis_cache_settings', $this->settings);
        try {
            $redis = $this->connect_redis();
            $status = $redis ? 'Connected' : 'Not connected';
            $keys = $redis ? $redis->keys($this->cache_prefix . '*') : [];
            $size = is_array($keys) ? count($keys) : 0;

            $totalBytes = 0;
            if ($redis && is_array($keys)) {
                foreach ($keys as $key) {
                    $len = $redis->strlen($key);
                    if ($len !== false) $totalBytes += $len;
                }
            }
            $size_kb = round($totalBytes / 1024, 2);

            wp_send_json_success([
                'status' => $status,
                'size'   => $size,
                'size_kb' => $size_kb,
            ]);
        } catch (Exception $e) {
            wp_send_json_success(['status'=>'Not connected','size'=>0,'size_kb'=>0]);
        }
    }

    /** AJAX: Flush Cache **/
    public function ajax_flush() {
        check_ajax_referer('ace_redis_cache_flush', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('No permission');

        try {
            $redis = $this->connect_redis();
            if ($redis) {
                $keys = $redis->keys($this->cache_prefix . '*');
                if ($keys) $redis->del($keys);
                wp_send_json_success(true);
            }
        } catch (Exception $e) {
            wp_send_json_error(false);
        }
    }

    /** Full Page Cache **/
    private function setup_full_page_cache() {
        add_action('template_redirect', function () {
            if (is_user_logged_in() || $_SERVER['REQUEST_METHOD'] !== 'GET') return;

            $redis = $this->connect_redis();
            if (!$redis) return;

            $cacheKey = $this->cache_prefix . md5($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);

            if ($cached = $redis->get($cacheKey)) {
                header('X-Cache: HIT');
                echo $cached;
                exit;
            }

            // Capture page content
            ob_start();
            header('X-Cache: MISS');

            add_action('shutdown', function () use ($redis, $cacheKey) {
                $buffer = ob_get_clean();
                if ($buffer !== false) {
                    echo $buffer;
                    $redis->setex($cacheKey, intval($this->settings['ttl']), $buffer);
                    $redis->ping();
                }
            }, 0);
        }, 0);
    }

    /** Object Cache Placeholder **/
    private function setup_object_cache() {
        add_action('init', function () {
            header('X-Cache-Mode: Object');
        });
    }

    /** Redis Connection **/
    private function connect_redis() {
    try {
        $redis = new Redis();
        $host = $this->settings['host'];
        $port = intval($this->settings['port']);
        $timeout = 1.5;

        if (stripos($host, 'tls://') === 0) {
            $redis->setOption(Redis::OPT_READ_TIMEOUT, $timeout);
            // Only pass SSL context, no extra zero args
            $redis->connect($host, $port, $timeout, null, 0, [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ]
            ]);
        } else {
            $redis->connect($host, $port, $timeout);
        }

        if (!empty($this->settings['password'])) {
            $redis->auth($this->settings['password']);
        }

        if (!$redis->ping()) {
            return false;
        }

        return $redis;
    } catch (Exception $e) {
        error_log('Redis connect failed: ' . $e->getMessage());
        return false;
    }
}

}

new AceRedisCache();
