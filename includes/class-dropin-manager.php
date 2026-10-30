<?php
namespace AceMedia\RedisCache;
if (!defined('ABSPATH')) exit;

/** Only replace regular files carrying our signature, using an atomic rename. */
class DropinManager {
    public static function refresh_existing($plugin_path, $content_dir) {
        $ok = true;
        foreach (['object-cache.php' => 'Ace Redis Cache Drop-In', 'advanced-cache.php' => 'Ace Redis Cache advanced-cache drop-in'] as $name => $signature) {
            $target = $content_dir . '/' . $name;
            if (!file_exists($target) && !is_link($target)) continue;
            // An update does not claim another cache plugin's file or activate a missing one.
            if (is_link($target)) continue;
            $contents = is_file($target) ? @file_get_contents($target) : false;
            if (!is_string($contents) || strpos($contents, $signature) === false) continue;
            $ok = self::manage($plugin_path . '/assets/dropins/' . $name, $target, $signature, true) && $ok;
        }
        return $ok;
    }

    public static function manage($source, $target, $signature, $enabled) {
        clearstatcache(true, $target);
        if (is_link($target) || (file_exists($target) && !is_file($target))) return false;
        $existing = file_exists($target) ? @file_get_contents($target) : null;
        if ($existing !== null && (!is_string($existing) || strpos($existing, $signature) === false)) return false;
        if (!$enabled) return $existing === null || @unlink($target); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete only the validated owned local drop-in or our temporary file and inspect the result.
        $contents = is_file($source) ? @file_get_contents($source) : false;
        if (!is_string($contents) || strpos($contents, $signature) === false) return false;
        if ($contents === $existing) return true;
        $temporary = @tempnam(dirname($target), '.ace-rc-');
        if (!$temporary) return false;
        $ok = @file_put_contents($temporary, $contents) === strlen($contents);
        if ($ok) $ok = @chmod($temporary, 0644); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set permissions on our local temporary file before the atomic rename.
        // Refuse a target changed while preparing the replacement.
        clearstatcache(true, $target);
        $current = is_file($target) ? @file_get_contents($target) : null;
        if (is_link($target) || $current !== $existing) $ok = false;
        if ($ok) $ok = @rename($temporary, $target); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory rename is atomic; FTP/copy-based filesystem transports cannot preserve this guarantee.
        if (is_file($temporary)) @unlink($temporary); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete only the validated owned local drop-in or our temporary file and inspect the result.
        return $ok;
    }
}
