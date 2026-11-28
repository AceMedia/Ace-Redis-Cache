<?php
namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) { exit; }

/**
 * Centralized option storage for single-site and multisite network mode.
 */
class SettingsStore {
    const SETTINGS_OPTION = \ACEREDIS_SETTINGS_OPTION;
    private static $file_write_error = null;

    public static function file_write_error() { return self::$file_write_error; }
    public static function clear_file_write_error() { self::$file_write_error = null; }
    public static function report_file_write_error($message) { self::$file_write_error = $message; }

    /**
     * Resolve plugin basename safely for activation checks.
     *
     * @return string
     */
    public static function plugin_basename() {
        if (defined('ACEREDIS_CACHE_PLUGIN_FILE')) {
            return plugin_basename(ACEREDIS_CACHE_PLUGIN_FILE);
        }
        return 'ace-redis-cache/ace-redis-cache.php';
    }

    /**
     * True when plugin is network-activated in multisite.
     *
     * @return bool
     */
    public static function is_network_mode() {
        if (!is_multisite()) {
            return false;
        }

        $plugin_file = self::plugin_basename();

        if (function_exists('is_plugin_active_for_network')) {
            return (bool) is_plugin_active_for_network($plugin_file);
        }

        $active_sitewide = get_site_option('active_sitewide_plugins', []);
        return is_array($active_sitewide) && isset($active_sitewide[$plugin_file]);
    }

    /**
     * Read plugin settings from the correct option scope.
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get_settings($default = []) {
        if (self::is_network_mode()) {
            return get_site_option(self::SETTINGS_OPTION, $default);
        }
        return get_option(self::SETTINGS_OPTION, $default);
    }

    /**
     * Write plugin settings to the correct option scope.
     *
     * @param mixed $value
     * @return bool
     */
    public static function update_settings($value) {
        self::clear_file_write_error();
        $saved = self::is_network_mode()
            ? update_site_option(self::SETTINGS_OPTION, $value)
            : update_option(self::SETTINGS_OPTION, $value);
        // A retry with unchanged settings still needs to repair a previously failed rules write.
        if (!$saved && self::get_settings(null) === $value && function_exists('do_action')) {
            do_action('aceredis_reconcile_static_cache_rules');
        }
        return $saved;
    }

    /**
     * Create settings option if missing.
     *
     * @param mixed $default
     * @return void
     */
    public static function ensure_settings_exists($default) {
        if (self::is_network_mode()) {
            $existing = get_site_option(self::SETTINGS_OPTION, null);
            if ($existing === null) {
                add_site_option(self::SETTINGS_OPTION, $default);
            }
            return;
        }

        $existing = get_option(self::SETTINGS_OPTION, null);
        if ($existing === null) {
            add_option(self::SETTINGS_OPTION, $default, '', 'no');
        }
    }

    /**
     * Generic option getter in network-aware scope.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public static function get($name, $default = false) {
        if (self::is_network_mode()) {
            return get_site_option($name, $default);
        }
        return get_option($name, $default);
    }

    /**
     * Generic option updater in network-aware scope.
     *
     * @param string $name
     * @param mixed $value
     * @return bool
     */
    public static function update($name, $value) {
        if (self::is_network_mode()) {
            return update_site_option($name, $value);
        }
        return update_option($name, $value);
    }

    /**
     * Generic option deleter in network-aware scope.
     *
     * @param string $name
     * @return bool
     */
    public static function delete($name) {
        if (self::is_network_mode()) {
            return delete_site_option($name);
        }
        return delete_option($name);
    }

    /**
     * One-way migration: copy legacy main-site setting into network scope.
     *
     * @return void
     */
    public static function maybe_migrate_legacy_settings_to_network() {
        if (!self::is_network_mode()) {
            return;
        }

        $network_value = get_site_option(self::SETTINGS_OPTION, null);
        if ($network_value !== null) {
            return;
        }

        $main_blog_id = function_exists('get_main_site_id') ? (int) get_main_site_id() : 1;
        $legacy = null;
        if (function_exists('get_blog_option')) {
            $legacy = get_blog_option($main_blog_id, self::SETTINGS_OPTION, null);
        }

        if ($legacy === null) {
            return;
        }

        add_site_option(self::SETTINGS_OPTION, $legacy);
    }
}
