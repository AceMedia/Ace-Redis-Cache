<?php
namespace {
    if (PHP_SAPI !== 'cli' || defined('ABSPATH')) { exit(1); }
    error_reporting(E_ALL);
    set_error_handler(static function ($severity, $message, $file, $line) {
        if (error_reporting() & $severity) throw new \ErrorException($message, 0, $severity, $file, $line);
        return false;
    });
    $fixture_root = sys_get_temp_dir() . '/arc-report-' . bin2hex(random_bytes(6));
    mkdir($fixture_root, 0700); mkdir($fixture_root . '/site'); mkdir($fixture_root . '/site/core');
    define('ABSPATH', $fixture_root . '/site/core/');
    define('ACEREDIS_CACHE_PLUGIN_FILE', '/fixture/Ace-Redis-Cache/ace-redis-cache.php');
    define('ACEREDIS_SETTINGS_OPTION', 'aceredis_cache_settings'); define('ACEREDIS_MANAGED_PLUGINS_OPTION', 'aceredis_managed_plugins');
    $network = false; $options = []; $site_options = []; $notices = []; $json_response = null; $writes = 0; $retry_actions = 0; $allowed = true; $valid_nonce = true;
    function is_multisite() { return $GLOBALS['network']; }
    function is_plugin_active_for_network($file) { return $GLOBALS['network']; }
    function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }
    function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
    function get_site_option($name, $default = false) { return $GLOBALS['site_options'][$name] ?? $default; }
    function update_option($name, $value) { return fixture_store('options', $name, $value); }
    function update_site_option($name, $value) { return fixture_store('site_options', $name, $value); }
    function fixture_store($scope, $name, $value) {
        if (($GLOBALS[$scope][$name] ?? null) === $value) return false;
        $GLOBALS[$scope][$name] = $value; ++$GLOBALS['writes'];
        $property = new \ReflectionProperty(\AceMedia\RedisCache\AceRedisCache::class, 'settings');
        if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
        $property->setValue($GLOBALS['plugin'], $value);
        // Contain unrelated component initialisation and cache clearing, while using the actual file reconciliation.
        $GLOBALS['plugin']->reconcile_static_cache_rules();
        return true;
    }
    function do_action($name) {
        if ($name !== 'aceredis_reconcile_static_cache_rules') throw new \RuntimeException('Unexpected synthetic hook');
        ++$GLOBALS['retry_actions']; $GLOBALS['plugin']->reconcile_static_cache_rules();
    }
    function add_settings_error($setting, $code, $message, $type) { $GLOBALS['notices'][] = compact('setting', 'code', 'message', 'type'); }
    function wp_is_writable($path) { return is_writable($path); }
    function sanitize_text_field($value) { return trim((string) $value); }
    function sanitize_textarea_field($value) { return (string) $value; }
    function absint($value) { return abs((int) $value); }
    function trailingslashit($value) { return rtrim($value, '/') . '/'; }
    function wp_cache_delete(...$args) { return true; }
    function wp_unslash($value) { return $value; }
    function wp_verify_nonce($value, $name) { return $GLOBALS['valid_nonce']; }
    function current_user_can($capability) { return $GLOBALS['allowed']; }
    function wp_send_json_error($data) { $GLOBALS['json_response'] = ['success' => false, 'data' => $data]; }
    function wp_send_json_success($data) { $GLOBALS['json_response'] = ['success' => true, 'data' => $data]; }
    class WP_Error {
        public $code; public $message; public $data;
        public function __construct($code, $message, $data) { $this->code = $code; $this->message = $message; $this->data = $data; }
    }
    class ArcFixtureRequest {
        private $data;
        public function __construct($data) { $this->data = $data; }
        public function get_param($name) { return $this->data[$name] ?? null; }
    }
}
namespace AceMedia\RedisCache {
    class BlockCache { public static function sanitize_settings($settings) { return []; } }
}
namespace {
    foreach (['class-managed-file.php','class-managed-cache-rules.php','class-settings-store.php','class-ace-redis-cache.php','class-admin-ajax.php','class-api_handler.php'] as $name) require dirname(__DIR__) . '/' . ($name === 'class-admin-ajax.php' ? 'admin' : 'includes') . '/' . $name;
    $plugin = (new \ReflectionClass(\AceMedia\RedisCache\AceRedisCache::class))->newInstanceWithoutConstructor();
    $checks = 0; $path = $fixture_root . '/site/.htaccess'; $lock = $fixture_root . '/site/.ace-rc-htaccess.lock'; $foreign = $fixture_root . '/foreign';
    file_put_contents($path, "# unrelated Apache rules\n"); file_put_contents($foreign, 'foreign');
    $initial = file_get_contents($path);
    $settings = ['enabled' => 0, 'enable_static_asset_cache' => 1, 'manage_static_cache_via_htaccess' => 1, 'prefer_existing_static_cache_headers' => 0, 'static_asset_cache_ttl' => 86400];
    function fixture_check($ok, $label) { if (!$ok) throw new \RuntimeException($label); ++$GLOBALS['checks']; echo "PASS $label\n"; }
    function fixture_clean($path) { if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; } foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') fixture_clean($path . '/' . $name); rmdir($path); }
    try {
        foreach ([false, true] as $network) {
            if (file_exists($lock) || is_link($lock)) unlink($lock);
            symlink($foreign, $lock); file_put_contents($path, $initial);
            $notices = []; $saved = \AceMedia\RedisCache\SettingsStore::update_settings($settings);
            fixture_check($saved === true && \AceMedia\RedisCache\SettingsStore::get_settings() === $settings, 'database settings retained despite file failure');
            fixture_check(\AceMedia\RedisCache\SettingsStore::file_write_error() !== null && file_get_contents($path) === $initial, 'file failure recorded without replacing rules');
            fixture_check(count($notices) === 1 && $notices[0]['code'] === 'aceredis_htaccess_write_failed' && $notices[0]['type'] === 'error', 'standard settings page receives actionable error');
            unlink($lock); $before_retries = $retry_actions;
            fixture_check(\AceMedia\RedisCache\SettingsStore::update_settings($settings) === false && $retry_actions === $before_retries + 1, 'unchanged save retries file reconciliation');
            fixture_check(\AceMedia\RedisCache\SettingsStore::file_write_error() === null && strpos(file_get_contents($path), 'max-age=86400') !== false, 'retry repairs rules and clears failure');
        }
        $network = false;
        if (file_exists($lock)) unlink($lock); symlink($foreign, $lock); file_put_contents($path, $initial);
        $_POST = ['nonce' => 'fixture', 'form_data' => http_build_query(['aceredis_cache_settings' => $settings])];
        $ajax = (new \ReflectionClass(\AceMedia\RedisCache\AdminAjax::class))->newInstanceWithoutConstructor();
        $json_response = null; $ajax->handle_save_settings();
        fixture_check($json_response['success'] === false && is_string($json_response['data']) && strpos($json_response['data'], 'Settings were saved, but') === 0, 'AJAX distinguishes saved settings from failed rules');
        fixture_check(strpos($json_response['data'], 'Apache cache rules could not be updated') !== false && file_get_contents($path) === $initial, 'AJAX reports file failure and retains rules');
        $allowed = false; $before_writes = $writes; $json_response = null; $ajax->handle_save_settings();
        fixture_check($json_response['success'] === false && $json_response['data'] === 'Unauthorized' && $writes === $before_writes, 'AJAX permission guard still rejects before saving');
        $allowed = true; $valid_nonce = false; $json_response = null; $ajax->handle_save_settings();
        fixture_check($json_response['success'] === false && $writes === $before_writes, 'AJAX nonce guard still rejects before saving');
        $valid_nonce = true;
        foreach ([false, true] as $network) {
            $api = (new \ReflectionClass(\AceMedia\RedisCache\API_Handler::class))->newInstanceWithoutConstructor();
            $result = $api->save_settings(new ArcFixtureRequest(['settings' => $settings]));
            fixture_check($result instanceof WP_Error && $result->code === 'aceredis_htaccess_write_failed' && $result->data['status'] === 500 && $result->data['settings_saved'] === true, 'REST reports failed rules separately from saved settings');
            foreach (['saving_settings','last_saved_settings','after_update_option'] as $name) {
                $property = new \ReflectionProperty($api, $name); if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
                fixture_check($property->getValue($api) === ($name === 'last_saved_settings' ? null : false), 'REST clears its in-flight state after file failure');
            }
        }
        fixture_check(file_get_contents($foreign) === 'foreign' && file_get_contents($path) === $initial, 'all failed interfaces retain unrelated files');
        echo 'Completed ', $checks, " reporting checks with synthetic settings and real disposable files; no database, HTTP, cron callback or mail used\n";
    } finally { fixture_clean($fixture_root); }
}
