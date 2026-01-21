<?php
/**
 * Ace Redis Cache Monitor
 * Add this to a cron job or run manually to monitor cache performance
 */

// Load WordPress
define('WP_USE_THEMES', false);
require_once(__DIR__ . '/../../../core/wp-load.php');

$report = [];
$report['timestamp'] = date('Y-m-d H:i:s');

// Check cache status
global $wp_object_cache;
if (method_exists($wp_object_cache, 'connection_details')) {
    $report['cache'] = $wp_object_cache->connection_details();
} else {
    $report['cache'] = ['connected' => false, 'error' => 'Cache not available'];
}

// Test Redis performance
if ($report['cache']['connected']) {
    $start = microtime(true);
    wp_cache_set('monitor_test', 'test_value', 'default', 60);
    $value = wp_cache_get('monitor_test');
    wp_cache_delete('monitor_test');
    $cache_time = round((microtime(true) - $start) * 1000, 2);
    
    $report['cache']['performance_ms'] = $cache_time;
    $report['cache']['performance_status'] = $cache_time < 5 ? 'excellent' : ($cache_time < 20 ? 'good' : 'slow');
}

// Test database queries that were slow
$start = microtime(true);
$categories = get_categories(['number' => 5]);
$report['get_categories_ms'] = round((microtime(true) - $start) * 1000, 2);

$start = microtime(true);
$terms = get_terms(['taxonomy' => 'category', 'number' => 5]);
$report['get_terms_ms'] = round((microtime(true) - $start) * 1000, 2);

$start = microtime(true);
$posts = get_posts(['numberposts' => 5]);
$report['get_posts_ms'] = round((microtime(true) - $start) * 1000, 2);

// Memory usage
$report['memory'] = [
    'current_mb' => round(memory_get_usage() / 1024 / 1024, 2),
    'peak_mb' => round(memory_get_peak_usage() / 1024 / 1024, 2),
    'limit' => ini_get('memory_limit')
];

// Output format
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    echo json_encode($report, JSON_PRETTY_PRINT);
} else {
    echo "=== Ace Redis Cache Monitor Report ===" . PHP_EOL;
    echo "Time: " . $report['timestamp'] . PHP_EOL;
    echo "Cache Connected: " . ($report['cache']['connected'] ? 'YES' : 'NO') . PHP_EOL;
    
    if ($report['cache']['connected']) {
        echo "Cache Performance: " . $report['cache']['performance_ms'] . "ms (" . $report['cache']['performance_status'] . ")" . PHP_EOL;
        echo "Cache Via: " . $report['cache']['via'] . PHP_EOL;
        echo "Cache Active: " . ($report['cache']['active'] ? 'YES' : 'NO') . PHP_EOL;
        echo "Cache Bypassed: " . ($report['cache']['bypassed'] ? 'YES' : 'NO') . PHP_EOL;
    }
    
    if (isset($report['cache']['error']) && $report['cache']['error']) {
        echo "Cache Error: " . $report['cache']['error'] . PHP_EOL;
    }
    
    echo "Query Performance:" . PHP_EOL;
    echo "  get_categories(): " . $report['get_categories_ms'] . "ms" . PHP_EOL;
    echo "  get_terms(): " . $report['get_terms_ms'] . "ms" . PHP_EOL;
    echo "  get_posts(): " . $report['get_posts_ms'] . "ms" . PHP_EOL;
    
    echo "Memory Usage: " . $report['memory']['current_mb'] . "MB (peak: " . $report['memory']['peak_mb'] . "MB)" . PHP_EOL;
    
    // Performance recommendations
    echo PHP_EOL . "Performance Analysis:" . PHP_EOL;
    if (!$report['cache']['connected']) {
        echo "❌ Redis cache not connected - this will cause slow database queries" . PHP_EOL;
    } elseif ($report['cache']['bypassed']) {
        echo "⚠️  Cache is bypassed - performance will be reduced" . PHP_EOL;
    } elseif (isset($report['cache']['performance_ms']) && $report['cache']['performance_ms'] > 20) {
        echo "⚠️  Cache operations are slow (" . $report['cache']['performance_ms'] . "ms)" . PHP_EOL;
    } else {
        echo "✅ Cache is working optimally" . PHP_EOL;
    }
    
    if ($report['get_categories_ms'] > 100 || $report['get_terms_ms'] > 100) {
        echo "⚠️  Taxonomy queries are slow - check database indexes" . PHP_EOL;
    }
    
    if ($report['get_posts_ms'] > 100) {
        echo "⚠️  Post queries are slow - check database performance" . PHP_EOL;
    }
}