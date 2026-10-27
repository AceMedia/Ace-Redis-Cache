<?php
/**
 * Batched key deletion: single-key UNLINKs (or DELs on servers older than Redis 4) sent in
 * pipelines of a few hundred, so a purge costs one round trip per batch instead of one per key.
 * Every command names one key, so a cluster endpoint never sees a CROSSSLOT multi-key call.
 *
 * @package AceMedia\RedisCache
 */

namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) {
    exit;
}

class RedisBatch {

    /** Keys per pipeline. */
    const BATCH_SIZE = 500;

    /** @var bool|null Whether the server knows UNLINK; probed once per process. */
    private static $unlink = null;

    /**
     * Whether the server understands UNLINK (Redis 4.0+, every Valkey). Probed once with a
     * key that never exists, then cached for the rest of the process.
     */
    public static function supports_unlink($redis) {
        if (self::$unlink !== null) {
            return self::$unlink;
        }
        self::$unlink = false;
        if (!is_object($redis) || !method_exists($redis, 'unlink')) {
            return false;
        }
        try {
            $reply = method_exists($redis, 'rawCommand')
                ? $redis->rawCommand('UNLINK', 'ace_rc:unlink_probe')
                : $redis->unlink('ace_rc:unlink_probe');
            self::$unlink = ($reply !== false && $reply !== null);
        } catch (\Throwable $t) {
            self::$unlink = false;
        }
        return self::$unlink;
    }

    /** Test hook: forget the probed capability, or force it. */
    public static function reset_capability($value = null) {
        self::$unlink = $value;
    }

    /**
     * Delete keys in pipelines of BATCH_SIZE single-key commands. Duplicates are dropped first.
     *
     * @param object $redis phpredis client.
     * @param array  $keys  Keys to delete.
     * @param int    $batch Keys per pipeline.
     * @return int Keys the server reported deleted.
     */
    public static function delete_keys($redis, array $keys, $batch = self::BATCH_SIZE) {
        $keys = array_values(array_unique(array_map('strval', $keys)));
        if (!$keys || !is_object($redis)) {
            return 0;
        }
        $command = self::supports_unlink($redis) ? 'unlink' : 'del';
        $deleted = 0;
        foreach (array_chunk($keys, max(1, (int) $batch)) as $chunk) {
            $deleted += self::delete_chunk($redis, $chunk, $command);
        }
        return $deleted;
    }

    private static function delete_chunk($redis, array $chunk, $command) {
        if (method_exists($redis, 'pipeline')) {
            try {
                $pipe = $redis->pipeline();
                foreach ($chunk as $key) {
                    $pipe->$command($key);
                }
                $replies = $pipe->exec();
                if (is_array($replies)) {
                    return array_sum(array_map('intval', $replies));
                }
            } catch (\Throwable $t) {
                // Fall through to one command per key.
                try { if (method_exists($redis, 'discard')) { $redis->discard(); } } catch (\Throwable $t2) {}
            }
        }
        $deleted = 0;
        foreach ($chunk as $key) {
            try {
                $deleted += (int) $redis->$command($key);
            } catch (\Throwable $t) {
            }
        }
        return $deleted;
    }
}
