<?php
/**
 * Redis Cache Performance Test
 * Run this to test cache performance improvements
 */

// Load WordPress
define('WP_USE_THEMES', false);
require_once(__DIR__ . '/../../../core/wp-load.php');

echo "=== Ace Redis Cache Performance Test ===" . PHP_EOL;

// Test 1: Basic cache operations
echo "Testing basic cache operations..." . PHP_EOL;
$start = microtime(true);

for ($i = 0; $i < 100; $i++) {
    wp_cache_set("test_key_$i", "test_value_$i", 'default', 300);
}

$set_time = microtime(true) - $start;
echo "100 SET operations: " . round($set_time * 1000, 2) . "ms" . PHP_EOL;

$start = microtime(true);
$hits = 0;
for ($i = 0; $i < 100; $i++) {
    $value = wp_cache_get("test_key_$i");
    if ($value === "test_value_$i") {
        $hits++;
    }
}

$get_time = microtime(true) - $start;
echo "100 GET operations: " . round($get_time * 1000, 2) . "ms (hits: $hits/100)" . PHP_EOL;

// Test 2: Taxonomy queries (the slow operations from your logs)
echo "\nTesting taxonomy operations..." . PHP_EOL;

$start = microtime(true);
$categories = get_categories(['number' => 10]);
$cat_time = microtime(true) - $start;
echo "get_categories(): " . round($cat_time * 1000, 2) . "ms (found: " . count($categories) . ")" . PHP_EOL;

$start = microtime(true);
$terms = get_terms(['taxonomy' => 'category', 'number' => 10]);
$terms_time = microtime(true) - $start;
echo "get_terms(): " . round($terms_time * 1000, 2) . "ms (found: " . count($terms) . ")" . PHP_EOL;

// Test 3: Post queries
echo "\nTesting post operations..." . PHP_EOL;
$start = microtime(true);
$posts = get_posts(['numberposts' => 10]);
$posts_time = microtime(true) - $start;
echo "get_posts(): " . round($posts_time * 1000, 2) . "ms (found: " . count($posts) . ")" . PHP_EOL;

// Test 4: Cache status
echo "\nCache Status:" . PHP_EOL;
global $wp_object_cache;

if (method_exists($wp_object_cache, 'connection_details')) {
    $details = $wp_object_cache->connection_details();
    echo "Connected: " . ($details['connected'] ? 'YES' : 'NO') . PHP_EOL;
    echo "Active: " . ($details['active'] ? 'YES' : 'NO') . PHP_EOL;
    echo "Via: " . ($details['via'] ?? 'unknown') . PHP_EOL;
    echo "Bypassed: " . ($details['bypassed'] ? 'YES' : 'NO') . PHP_EOL;
    if ($details['error']) {
        echo "Error: " . $details['error'] . PHP_EOL;
    }
}

// Clean up test data
for ($i = 0; $i < 100; $i++) {
    wp_cache_delete("test_key_$i");
}

echo "\n=== Test Complete ===" . PHP_EOL;
echo "Total memory usage: " . round(memory_get_usage() / 1024 / 1024, 2) . "MB" . PHP_EOL;
echo "Peak memory usage: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . "MB" . PHP_EOL;