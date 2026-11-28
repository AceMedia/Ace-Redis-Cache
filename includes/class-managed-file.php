<?php
namespace AceMedia\RedisCache;
if (!defined('ABSPATH')) { exit; }

// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem cannot replace a file atomically under a lock while keeping its owner and mode, which is what protects .htaccess here.
/** Checked local replacement; the persistent advisory lock serialises our writers. */
class ManagedFile {
    public static function update($target, $transform) {
        $directory = realpath(dirname($target));
        if ($directory === false || !is_dir($directory)) { return false; }
        $target = $directory . '/' . basename($target);
        $lock_path = $directory . '/.ace-rc-htaccess.lock';
        if (is_link($lock_path) || (file_exists($lock_path) && !is_file($lock_path))) { return false; }
        $lock = @fopen($lock_path, 'x+b');
        if ($lock) {
            if (!@chmod($lock_path, 0644)) { fclose($lock); return false; }
        } else {
            $lock = @fopen($lock_path, 'rb');
        }
        if (!$lock) { return false; }
        $temporary = null;
        try {
            clearstatcache(true, $lock_path);
            $opened = fstat($lock);
            $named = @lstat($lock_path);
            if (!$opened || !$named || ($opened['mode'] & 0170000) !== 0100000 ||
                $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino'] ||
                is_link($lock_path) || !@flock($lock, LOCK_EX | LOCK_NB)) { return false; }

            clearstatcache(true, $target);
            if (is_link($target) || (file_exists($target) && !is_file($target))) { return false; }
            $before = file_exists($target) ? @stat($target) : null;
            if ($before !== null && (!$before || ($before['mode'] & 07000))) { return false; }
            $current = $before === null ? null : @file_get_contents($target);
            if ($before !== null && !is_string($current)) { return false; }
            $contents = $transform($current);
            if ($contents === $current) { return true; }
            if (!is_string($contents)) { return false; }

            $temporary = @tempnam($directory, '.ace-rc-htaccess-');
            if (!$temporary || dirname($temporary) !== $directory) { return false; }
            if (@file_put_contents($temporary, $contents) !== strlen($contents)) { return false; }
            if ($before !== null) {
                $created = @stat($temporary);
                if (!$created) { return false; }
                if ($created['uid'] !== $before['uid'] && !@chown($temporary, $before['uid'])) { return false; }
                if ($created['gid'] !== $before['gid'] && !@chgrp($temporary, $before['gid'])) { return false; }
            }
            $mode = $before === null ? (defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644) : ($before['mode'] & 0777);
            if (!@chmod($temporary, $mode)) { return false; }
            clearstatcache(true, $temporary);
            $created = @stat($temporary);
            if (!$created || ($created['mode'] & 0777) !== $mode ||
                ($before !== null && ($created['uid'] !== $before['uid'] || $created['gid'] !== $before['gid']))) { return false; }

            // Other software may ignore our lock. Refuse any change observed before publication.
            clearstatcache(true, $target);
            if (is_link($target) || (file_exists($target) && !is_file($target))) { return false; }
            $after = file_exists($target) ? @stat($target) : null;
            if (($before === null) !== ($after === null)) { return false; }
            if ($before !== null) {
                foreach (['dev', 'ino', 'uid', 'gid', 'mode', 'size', 'mtime', 'ctime'] as $key) {
                    if (!$after || $before[$key] !== $after[$key]) { return false; }
                }
                if (@file_get_contents($target) !== $current) { return false; }
            }
            return @rename($temporary, $target);
        } finally {
            if ($temporary && (is_file($temporary) || is_link($temporary))) { @unlink($temporary); }
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
