<?php
namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) { exit; }

/**
 * Manages activation / deactivation of dependent plugins listed in the ace_managed_plugins option.
 */
class Plugin_Manager {
    const OPTION = 'ace_managed_plugins';

    /**
     * Ensure option exists.
     */
    public static function bootstrap() {
        $opt = get_option(self::OPTION);
        if (!is_array($opt)) {
            $opt = [ 'plugins' => [] ];
            add_option(self::OPTION, $opt, '', 'no');
        }
    }

    /**
     * Activate managed plugins that have enabled_on_init true.
     */
    public static function activate_managed() {
        if (!function_exists('activate_plugin')) return;
        $opt = get_option(self::OPTION);
        if (!is_array($opt) || empty($opt['plugins'])) return;
        foreach ($opt['plugins'] as $plugin_file => $meta) {
            $flag = isset($meta['enabled_on_init']) ? (bool)$meta['enabled_on_init'] : false;
            if (!$flag) { continue; }
            if (!self::is_active($plugin_file)) {
                $result = activate_plugin($plugin_file, '', is_multisite());
                if (is_wp_error($result)) {
                    error_log('AceRedisCache PluginManager: Failed to activate ' . $plugin_file . ' - ' . $result->get_error_message());
                }
            }
        }
    }

    /**
     * Deactivate all active managed plugins.
     */
    public static function deactivate_managed() {
        if (!function_exists('deactivate_plugins')) return;
        $opt = get_option(self::OPTION);
        if (!is_array($opt) || empty($opt['plugins'])) return;
        $to_deactivate = [];
        foreach ($opt['plugins'] as $plugin_file => $meta) {
            if (self::is_active($plugin_file)) {
                $to_deactivate[] = $plugin_file;
            }
        }
        if ($to_deactivate) {
            deactivate_plugins($to_deactivate, true, is_multisite());
        }
    }

    private static function is_active($plugin_file) {
        if (!function_exists('is_plugin_active')) return false;
        return is_plugin_active($plugin_file);
    }
}
