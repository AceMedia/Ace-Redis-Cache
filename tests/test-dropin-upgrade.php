<?php
/** Version-only migration with William-shaped legacy settings; no live WordPress. */
define('ABSPATH', __DIR__ . '/');
define('ACE_REDIS_CACHE_VERSION', '0.8.16');
$dir = sys_get_temp_dir() . '/arc-upgrade-' . bin2hex(random_bytes(8));
mkdir($dir);
define('WP_CONTENT_DIR', $dir);
$GLOBALS['arc_upgrade_options'] = ['ace_redis_cache_settings' => ['enabled' => 1, 'enable_object_cache' => 0]];
$GLOBALS['arc_upgrade_allowed'] = true;
function is_multisite() { return false; }
function get_option($key, $default = false) { return $GLOBALS['arc_upgrade_options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['arc_upgrade_options'][$key] = $value; return true; }
function current_user_can($capability) { return $GLOBALS['arc_upgrade_allowed']; }
require dirname(__DIR__) . '/includes/class-settings-store.php';
require dirname(__DIR__) . '/includes/class-dropin-manager.php';
require dirname(__DIR__) . '/includes/class-ace-redis-cache.php';
$target = $dir . '/object-cache.php';
$original = '<?php /* Ace Redis Cache Drop-In */ // old version';
file_put_contents($target, $original);
try {
    $class = new ReflectionClass(\AceMedia\RedisCache\AceRedisCache::class);
    $plugin = $class->newInstanceWithoutConstructor();
    foreach (['settings' => $GLOBALS['arc_upgrade_options']['ace_redis_cache_settings'], 'plugin_path' => dirname(__DIR__)] as $name => $value) {
        $property = $class->getProperty($name); $property->setAccessible(true); $property->setValue($plugin, $value);
    }
    $GLOBALS['arc_upgrade_allowed'] = false;
    $plugin->upgrade_managed_dropins();
    if (file_get_contents($target) !== $original) throw new RuntimeException('Unauthorised migration');
    $GLOBALS['arc_upgrade_allowed'] = true;
    $before = $GLOBALS['arc_upgrade_options']['ace_redis_cache_settings'];
    $plugin->upgrade_managed_dropins();
    if (file_get_contents($target) !== file_get_contents(dirname(__DIR__) . '/assets/dropins/object-cache.php')) throw new RuntimeException('Legacy installed drop-in not refreshed');
    if (file_exists($dir . '/advanced-cache.php')) throw new RuntimeException('Missing page drop-in activated');
    if ($GLOBALS['arc_upgrade_options']['ace_redis_cache_settings'] !== $before) throw new RuntimeException('Settings changed');
    if (get_option('ace_rc_dropin_version') !== ACE_REDIS_CACHE_VERSION) throw new RuntimeException('Migration not recorded');
    $plugin->upgrade_managed_dropins();
    echo "PASS William-shaped legacy flags preserved; installed object cache refreshed; absent page cache not installed; permission and repeat checks pass\n";
} finally {
    unlink($target); rmdir($dir);
}
