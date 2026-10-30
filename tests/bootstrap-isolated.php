<?php
// Isolated unit suite: load classes without bootstrapping a live site.
define('ABSPATH', dirname(__DIR__) . '/');
define('ACE_REDIS_CACHE_PLUGIN_FILE', dirname(__DIR__) . '/ace-redis-cache.php');
spl_autoload_register(function ($class) {
    $prefix = 'AceMedia\\RedisCache\\';
    if (strpos($class, $prefix) !== 0) return;
    $name = substr($class, strlen($prefix));
    $name = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $name));
    foreach (['includes', 'admin'] as $directory) {
        foreach ([$name, str_replace('_', '-', $name)] as $variant) {
            $file = dirname(__DIR__) . '/' . $directory . '/class-' . $variant . '.php';
            if (is_file($file)) { require_once $file; return; }
        }
    }
});
function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }
define('ACE_OC_FLUSHDB_ON_SAVE', true);

define('ACE_REDIS_CACHE_VERSION', '0.8.15');
