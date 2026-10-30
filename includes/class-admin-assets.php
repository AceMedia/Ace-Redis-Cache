<?php
namespace AceMedia\RedisCache;
defined('ABSPATH') || exit;

/** WordPress-managed assets for the plugin's admin renderers. */
final class AdminAssets {
    private static $inline_count = 0;

    public static function script_file($name) {
        wp_enqueue_script('ace-redis-cache-' . $name, ACE_REDIS_CACHE_PLUGIN_URL . 'assets/admin/' . $name . '.js', array('jquery'), ACE_REDIS_CACHE_VERSION, true);
    }

    public static function script($code) {
        $handle = 'ace-redis-cache-inline-' . ++self::$inline_count;
        wp_register_script($handle, false, array('jquery'), ACE_REDIS_CACHE_VERSION, true);
        wp_enqueue_script($handle);
        wp_add_inline_script($handle, $code);
    }

    public static function style_file($name) {
        $handle = 'ace-redis-cache-' . $name;
        wp_enqueue_style($handle, ACE_REDIS_CACHE_PLUGIN_URL . 'assets/admin/' . $name . '.css', array(), ACE_REDIS_CACHE_VERSION);
        // Fallback views are selected during rendering, after the usual admin head.
        if (did_action('admin_print_styles')) {
            wp_print_styles(array($handle));
        }
    }
}
