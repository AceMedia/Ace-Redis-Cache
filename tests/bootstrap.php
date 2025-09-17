<?php
/**
 * PHPUnit Test Bootstrap
 *
 * @package AceMedia\RedisCache
 */

// Composer autoloader
$vendor_dir = dirname(__DIR__) . '/vendor';
if (file_exists($vendor_dir . '/autoload.php')) {
    require_once $vendor_dir . '/autoload.php';
}

// WordPress test environment
$wp_tests_dir = getenv('WP_TESTS_DIR');
if (!$wp_tests_dir) {
    $wp_tests_dir = '/tmp/wordpress-tests-lib';
}

// WordPress functions
if (file_exists($wp_tests_dir . '/includes/functions.php')) {
    require_once $wp_tests_dir . '/includes/functions.php';
} else {
    // Mock basic WordPress functions for isolated testing
    if (!function_exists('get_option')) {
        function get_option($option, $default = false) { return $default; }
    }
    
    if (!function_exists('update_option')) {
        function update_option($option, $value) { return true; }
    }
    
    if (!function_exists('delete_option')) {
        function delete_option($option) { return true; }
    }
    
    if (!function_exists('wp_die')) {
        function wp_die($message) { die($message); }
    }
    
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(__DIR__) . '/');
    }
}

// Load plugin
require_once dirname(__DIR__) . '/ace-redis-cache.php';
