<?php
/**
 * Plugin Name: Ace Redis Cache
 * Description: Smart Redis-powered caching with WordPress Block API support and configurable exclusions for any plugins.
 * Version: 0.5.0
 * Author: Ace Media
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ace-redis-cache
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ACE_REDIS_CACHE_VERSION', '0.5.0');
define('ACE_REDIS_CACHE_PLUGIN_FILE', __FILE__);
define('ACE_REDIS_CACHE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('ACE_REDIS_CACHE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Polyfill for PHP < 8.0
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

/**
 * Main plugin bootstrap class
 */
class AceRedisCacheBootstrap {
    
    private static $instance = null;
    private $plugin = null;
    
    /**
     * Get singleton instance
     *
     * @return AceRedisCacheBootstrap
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Initialize the plugin
     */
    private function init() {
        // Check requirements
        if (!$this->check_requirements()) {
            return;
        }
        
        // Load autoloader
        $this->load_autoloader();
        
        // Initialize plugin
        add_action('plugins_loaded', [$this, 'load_plugin']);
        
        // Plugin lifecycle hooks
        register_activation_hook(__FILE__, [$this, 'on_activation']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivation']);
        register_uninstall_hook(__FILE__, [__CLASS__, 'on_uninstall']);
    }
    
    /**
     * Check plugin requirements
     *
     * @return bool True if requirements are met
     */
    private function check_requirements() {
        $requirements_met = true;
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>Ace Redis Cache:</strong> This plugin requires PHP 7.4 or higher. ';
                echo 'You are running PHP ' . PHP_VERSION . '.';
                echo '</p></div>';
            });
            $requirements_met = false;
        }
        
        // Check WordPress version
        global $wp_version;
        if (version_compare($wp_version, '5.0', '<')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>Ace Redis Cache:</strong> This plugin requires WordPress 5.0 or higher. ';
                echo 'You are running WordPress ' . $GLOBALS['wp_version'] . '.';
                echo '</p></div>';
            });
            $requirements_met = false;
        }
        
        // Check if Redis extension is available
        if (!extension_loaded('redis')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning"><p>';
                echo '<strong>Ace Redis Cache:</strong> The PHP Redis extension is not installed. ';
                echo 'Please install php-redis to use this plugin.';
                echo '</p></div>';
            });
            // Don't prevent loading, but show warning
        }
        
        return $requirements_met;
    }
    
    /**
     * Load the autoloader
     */
    private function load_autoloader() {
        // Simple autoloader for our namespace
        spl_autoload_register(function($class) {
            // Check if this is our namespace
            if (strpos($class, 'AceMedia\\RedisCache\\') !== 0) {
                return;
            }
            
            // Remove namespace prefix
            $class = str_replace('AceMedia\\RedisCache\\', '', $class);
            
            // Convert class name to file name
            $class_file = 'class-' . strtolower(str_replace('_', '-', $class)) . '.php';
            
            // Try includes directory first
            $includes_file = ACE_REDIS_CACHE_PLUGIN_PATH . 'includes/' . $class_file;
            if (file_exists($includes_file)) {
                require_once $includes_file;
                return;
            }
            
            // Try admin directory
            $admin_file = ACE_REDIS_CACHE_PLUGIN_PATH . 'admin/' . $class_file;
            if (file_exists($admin_file)) {
                require_once $admin_file;
                return;
            }
        });
    }
    
    /**
     * Load and initialize the main plugin
     */
    public function load_plugin() {
        if (class_exists('AceMedia\\RedisCache\\AceRedisCache')) {
            $this->plugin = new \AceMedia\RedisCache\AceRedisCache();
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>Ace Redis Cache:</strong> Failed to load plugin classes. ';
                echo 'Please check file permissions and plugin integrity.';
                echo '</p></div>';
            });
        }
    }
    
    /**
     * Plugin activation
     */
    public function on_activation() {
        // Check requirements again on activation
        if (!$this->check_requirements()) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                'Ace Redis Cache activation failed: System requirements not met.',
                'Plugin Activation Error',
                ['back_link' => true]
            );
        }
        
        // Load plugin if not already loaded
        if (!$this->plugin) {
            $this->load_plugin();
        }
        
        // Call plugin activation if available
        if ($this->plugin && method_exists($this->plugin, 'on_activation')) {
            $this->plugin->on_activation();
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function on_deactivation() {
        // Call plugin deactivation if available
        if ($this->plugin && method_exists($this->plugin, 'on_deactivation')) {
            $this->plugin->on_deactivation();
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin uninstallation
     */
    public static function on_uninstall() {
        // Clean up options
        delete_option('ace_redis_cache_settings');
        delete_option('ace_redis_cache_dismissed_version');
        
        // Clean up transients
        delete_transient('ace_redis_circuit_breaker');
        delete_transient('ace_redis_recent_issues');
        
        // Note: We don't clear Redis cache data as it might be used by other applications
    }
    
    /**
     * Get plugin instance
     *
     * @return \AceMedia\RedisCache\AceRedisCache|null
     */
    public function get_plugin() {
        return $this->plugin;
    }
}

// Initialize the plugin
AceRedisCacheBootstrap::getInstance();

/**
 * Helper function to get plugin instance
 *
 * @return \AceMedia\RedisCache\AceRedisCache|null
 */
function ace_redis_cache() {
    return AceRedisCacheBootstrap::getInstance()->get_plugin();
}
