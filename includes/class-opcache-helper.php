<?php
namespace AceMedia\RedisCache;
if (!defined('ABSPATH')) exit;

class OpcacheHelper {
    public static function invalidate_private_scripts() {
        if (!defined('WP_CONTENT_DIR') || !function_exists('opcache_get_status') || !function_exists('opcache_invalidate')) return false;
        $root = realpath(WP_CONTENT_DIR);
        if (!$root) return false;
        $status = opcache_get_status(true);
        if (!is_array($status)) return false;
        $ok = true;
        foreach (array_keys($status['scripts'] ?? []) as $file) {
            $path = realpath($file);
            // Resolved paths exclude symlinked shared plugins and WordPress core.
            if ($path && strpos($path, $root . DIRECTORY_SEPARATOR) === 0) {
                $ok = opcache_invalidate($path, true) && $ok;
            }
        }
        return $ok;
    }
}
