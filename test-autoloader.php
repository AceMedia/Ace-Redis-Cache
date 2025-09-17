<?php
/**
 * Simple autoloader test script
 * Run this to verify the autoloader is working correctly
 */

// WordPress constants for testing
define('ABSPATH', '/var/www/html/ppnews/');

// Include the main plugin file
require_once __DIR__ . '/ace-redis-cache.php';

echo "Testing autoloader...\n";

// Test the bootstrap
$bootstrap = AceRedisCacheBootstrap::getInstance();
echo "✓ Bootstrap instantiated successfully\n";

// Test class loading
try {
    $redis_connection = new \AceMedia\RedisCache\RedisConnection([
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => '',
        'ttl' => 3600
    ]);
    echo "✓ RedisConnection class loaded successfully\n";
} catch (Exception $e) {
    echo "✗ RedisConnection failed: " . $e->getMessage() . "\n";
}

try {
    if (class_exists('AceMedia\\RedisCache\\AceRedisCache')) {
        echo "✓ AceRedisCache class found\n";
    } else {
        echo "✗ AceRedisCache class not found\n";
    }
} catch (Exception $e) {
    echo "✗ AceRedisCache failed: " . $e->getMessage() . "\n";
}

try {
    if (class_exists('AceMedia\\RedisCache\\CacheManager')) {
        echo "✓ CacheManager class found\n";
    } else {
        echo "✗ CacheManager class not found\n";
    }
} catch (Exception $e) {
    echo "✗ CacheManager failed: " . $e->getMessage() . "\n";
}

echo "Test complete.\n";
