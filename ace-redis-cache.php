<?php
/**
 * Plugin Name: Ace Redis Cache
 * Description: Smart Redis-powered caching with WordPress Block API support and configurable exclusions for any plugins.
 * Version: 0.10.0
 * Author: Ace Media
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Network: true
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ace-redis-cache
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// aceredis-source-only:start
// Sites set these in wp-config.php under the names used before 0.10.0.
foreach (array('ACE_FORCE_TRANSIENT_FLUSH', 'ACE_OC_ADMIN_CACHE_SUSPENDED', 'ACE_OC_ADMIN_READ', 'ACE_OC_BYPASS', 'ACE_OC_FLUSHDB_ON_SAVE', 'ACE_OC_FLUSH_ON_POST_CHANGE', 'ACE_OC_MAX_TTL', 'ACE_OC_MEMBER_READ', 'ACE_OC_NO_PUBLIC_AJAX', 'ACE_OC_PROF', 'ACE_OC_RUNTIME_ONLY', 'ACE_OC_SHARED_TRANSIENTS', 'ACE_OC_SLOW_LOG', 'ACE_OC_SLOW_LOG_MS', 'ACE_OC_WC_READ', 'ACE_RC_BLOCK_CACHE', 'ACE_RC_EXIT_ON_HIT', 'ACE_RC_VARNISH', 'ACE_RC_VARNISH_HOST', 'ACE_REDIS_DB', 'ACE_REDIS_EARLY_REFRESH_ADDR', 'ACE_REDIS_EARLY_SERVE', 'ACE_REDIS_EARLY_STALE', 'ACE_REDIS_EARLY_STALE_BLOCKS', 'ACE_REDIS_HOST', 'ACE_REDIS_MAX_RETRIES', 'ACE_REDIS_OP_TIMEOUT', 'ACE_REDIS_PAGE_DB', 'ACE_REDIS_PAGE_HOST', 'ACE_REDIS_PAGE_PASSWORD', 'ACE_REDIS_PAGE_PORT', 'ACE_REDIS_PASSWORD', 'ACE_REDIS_PORT', 'ACE_REDIS_READ_TIMEOUT', 'ACE_REDIS_SOCKET', 'ACE_REDIS_TIMEOUT', 'ACE_REDIS_USE_TLS', 'ACE_REDIS_VERIFY_TLS') as $aceredis_old) {
    $aceredis_new = 'ACEREDIS_' . substr($aceredis_old, 0 === strpos($aceredis_old, 'ACE_REDIS_') ? 10 : (0 === strpos($aceredis_old, 'ACE_OC_') ? 4 : (0 === strpos($aceredis_old, 'ACE_RC_') ? 7 : 4)));
    if (defined($aceredis_old) && !defined($aceredis_new)) { define($aceredis_new, constant($aceredis_old)); }
}
unset($aceredis_old, $aceredis_new);
// aceredis-source-only:end

// Define plugin constants
define('ACEREDIS_CACHE_VERSION', '0.10.0');
define('ACEREDIS_CACHE_PLUGIN_FILE', __FILE__);
define('ACEREDIS_CACHE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('ACEREDIS_CACHE_PLUGIN_URL', plugin_dir_url(__FILE__));

// The option rows that hold the settings and the managed plugin list.
$aceredis_settings_option = 'aceredis_cache_settings';
$aceredis_managed_option  = 'aceredis_managed_plugins';
// aceredis-source-only:start
// A site that still has its settings under the old row name keeps using it until
// `wp aceredis convert-names` moves the row.
if (null === get_option('aceredis_cache_settings', null) && null === get_site_option('aceredis_cache_settings', null)
    && (null !== get_option('ace_redis_cache_settings', null) || null !== get_site_option('ace_redis_cache_settings', null))) {
    $aceredis_settings_option = 'ace_redis_cache_settings';
    $aceredis_managed_option  = 'ace_managed_plugins';
}
// aceredis-source-only:end
define('ACEREDIS_SETTINGS_OPTION', $aceredis_settings_option);
define('ACEREDIS_MANAGED_PLUGINS_OPTION', $aceredis_managed_option);
unset($aceredis_settings_option, $aceredis_managed_option);

// aceredis-source-only:start
if (is_readable(__DIR__ . '/compat/legacy-names.php')) {
    require_once __DIR__ . '/compat/legacy-names.php';
}
// aceredis-source-only:end

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
                echo 'You are running WordPress ' . esc_html($GLOBALS['wp_version']) . '.';
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
            
            // Convert class name to file name (handle CamelCase)
            $converted = preg_replace('/([a-z])([A-Z])/', '$1-$2', $class);
            // Primary variant (original convention): lowercase, dashes from camelCase
            $class_file = 'class-' . strtolower($converted) . '.php';
            // Accept underscores by also trying a hyphenated variant (Plugin_Manager -> class-plugin-manager.php)
            $alt_file = 'class-' . strtolower(str_replace('_', '-', $converted)) . '.php';
            
            // Try includes directory first
            foreach ([$class_file, $alt_file] as $candidate) {
                $includes_file = ACEREDIS_CACHE_PLUGIN_PATH . 'includes/' . $candidate;
                if (file_exists($includes_file)) {
                    require_once $includes_file;
                    return;
                }
                $admin_file = ACEREDIS_CACHE_PLUGIN_PATH . 'admin/' . $candidate;
                if (file_exists($admin_file)) {
                    require_once $admin_file;
                    return;
                }
            }
            // If we reach here and WP_DEBUG enabled, log miss once.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                static $logged = [];
                if (!isset($logged[$class])) {
                    error_log('[Ace-Redis-Cache] Autoloader miss for class ' . $class . ' tried [' . $class_file . ', ' . $alt_file . ']');
                    $logged[$class] = true;
                }
            }
        });
    }
    
    /**
     * Load and initialize the main plugin
     */
    public function load_plugin() {
        if (class_exists('AceMedia\\RedisCache\\SettingsStore')) {
            \AceMedia\RedisCache\SettingsStore::maybe_migrate_legacy_settings_to_network();
        }

        if (class_exists('AceMedia\\RedisCache\\AceRedisCache')) {
            $this->plugin = new \AceMedia\RedisCache\AceRedisCache();
            // Register CLI commands if available
            if (class_exists('AceMedia\\RedisCache\\Admin_CLI')) { \AceMedia\RedisCache\Admin_CLI::register(); }
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
    public function on_activation($network_wide = false) {
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
            $this->plugin->on_activation((bool) $network_wide);
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
        // Clean up options in the active storage scope.
        if (class_exists('AceMedia\\RedisCache\\SettingsStore')) {
            \AceMedia\RedisCache\SettingsStore::delete(ACEREDIS_SETTINGS_OPTION);
            \AceMedia\RedisCache\SettingsStore::delete('aceredis_cache_dismissed_version');
        } else {
            delete_option(ACEREDIS_SETTINGS_OPTION);
            delete_site_option(ACEREDIS_SETTINGS_OPTION);
            delete_option('aceredis_cache_dismissed_version');
            delete_site_option('aceredis_cache_dismissed_version');
        }
        
        // Clean up transients
        delete_transient('aceredis_circuit_breaker');
        delete_transient('aceredis_recent_issues');
        
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
function aceredis_cache() {
    return AceRedisCacheBootstrap::getInstance()->get_plugin();
}

/**
 * Mark every cached page stale without deleting it (see AceRedisCache::soft_purge_page_cache()).
 * For deploy hooks and imports. Calls within aceredis_soft_purge_min_interval of the last soft
 * purge are coalesced into one trailing purge; pass $force = true (deploys) to purge at once.
 *
 * @param bool $force Skip the coalescing window.
 * @return bool False when soft purging is not possible here; the caller should purge instead.
 */
function aceredis_soft_purge_page_cache($force = false) {
    $plugin = aceredis_cache();
    return $plugin && method_exists($plugin, 'soft_purge_page_cache') && $plugin->soft_purge_page_cache((bool) $force);
}

/**
 * A wp-admin read kept in the admin query cache (see AdminQueryCache). Runs $compute uncached
 * when the "wp-admin query cache" setting is off, so callers need no setting check of their own.
 *
 * @param string   $key     Unique within your plugin; prefix it with your slug.
 * @param callable $compute Returns the value; null is not stored.
 * @param string[] $tags    What the value depends on: bump any of them to drop it.
 * @param int|null $ttl     Seconds, else the setting's TTL.
 * @return mixed
 */
function aceredis_admin_cache_remember($key, callable $compute, array $tags = [], $ttl = null) {
    if (!class_exists('AceMedia\\RedisCache\\AdminQueryCache')) {
        return $compute();
    }
    return \AceMedia\RedisCache\AdminQueryCache::remember($key, $compute, $tags, $ttl);
}

/**
 * Drop every admin query cache entry that depends on $tag. Call it wherever that data is written.
 *
 * @param string $tag
 */
function aceredis_admin_cache_bump($tag) {
    if (class_exists('AceMedia\\RedisCache\\AdminQueryCache')) {
        \AceMedia\RedisCache\AdminQueryCache::bump($tag);
    }
}
