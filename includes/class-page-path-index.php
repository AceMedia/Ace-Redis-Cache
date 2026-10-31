<?php
/** Bounded URL variants, shared by page writers and targeted invalidation. */
namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) { exit; }

class PagePathIndex {
    const LIMIT = 256;

    public static function key($home, $uri) {
        $parts = wp_parse_url($home);
        $scope = strtolower($parts['host'] ?? '') . ':' . ($parts['port'] ?? '') . rtrim($parts['path'] ?? '', '/');
        $path = explode('?', $uri, 2)[0];
        // Slash alternatives share one bounded registry, but never another path/site.
        return 'ace_rc:path_variants:' . hash('sha256', $scope . "\n" . (rtrim($path, '/') ?: '/'));
    }

    public static function register($redis, $home, $uri, $ttl, $index_key = null) {
        if (!$redis || !method_exists($redis, 'rawCommand') || strlen($uri) > 8192) return false;
        // A single-key script works on Redis Cluster and makes the bound/TTL atomic.
        $script = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now)
local previous = redis.call('ZSCORE', KEYS[1], ARGV[1])
if redis.call('ZCARD', KEYS[1]) >= tonumber(ARGV[3]) and not previous then return 0 end
local expires = math.max(tonumber(previous) or 0, now + tonumber(ARGV[2]))
redis.call('ZADD', KEYS[1], expires, ARGV[1])
local ttl = math.max(redis.call('TTL', KEYS[1]), expires - now)
return redis.call('EXPIRE', KEYS[1], ttl)
LUA;
        try {
            $result = $redis->rawCommand('EVAL', $script, '1', ($index_key ?: self::key($home, $uri)), $uri, (string) (max(1, (int) $ttl) + 60), (string) self::LIMIT);
            return $result === 1 || $result === true;
        } catch (\Throwable $error) { return false; }
    }

    public static function expand($redis, $home, array $paths) {
        $expanded = [];
        foreach ($paths as $path) {
            $path = AceRedisCache::normalize_request_uri((string) $path);
            $expanded[$path] = true;
            // An exact meaningful query purges only that query. Clean paths purge all variants.
            if (strpos($path, '?') !== false || !$redis || !method_exists($redis, 'rawCommand')) continue;
            try {
                $variants = $redis->rawCommand('ZRANGE', self::key($home, $path), '0', (string) (self::LIMIT - 1));
                if (!is_array($variants)) continue;
                foreach (array_slice($variants, 0, self::LIMIT) as $variant) {
                    if (is_string($variant) && self::key($home, $variant) === self::key($home, $path)) $expanded[$variant] = true;
                }
            } catch (\Throwable $error) {
                // Retain registry for a later retry; never fall back to a global Redis scan.
            }
        }
        return array_keys($expanded);
    }
}
