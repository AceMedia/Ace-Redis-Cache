<?php
namespace AceMedia\RedisCache;
if (!defined('ABSPATH')) { exit; }

/** Replace only our complete marker block, retaining all unrelated rule bytes. */
class ManagedCacheRules {
    const BEGIN = '# BEGIN Ace Redis Cache Static Assets';
    const END = '# END Ace Redis Cache Static Assets';
    const PATTERN = '/^# BEGIN Ace Redis Cache Static Assets\r?\n.*?^# END Ace Redis Cache Static Assets(?:\r?\n|$)/ms';

    public static function update($target, $block) {
        if ($block === null && !file_exists($target) && !is_link($target)) { return true; }
        if ($block !== null && (!is_string($block) ||
            substr($block, 0, strlen(self::BEGIN)) !== self::BEGIN ||
            substr($block, -strlen(self::END)) !== self::END ||
            substr_count($block, self::BEGIN) !== 1 || substr_count($block, self::END) !== 1)) { return false; }
        return ManagedFile::update($target, static function ($current) use ($block) {
            if ($current === null && $block === null) { return null; }
            $text = $current === null ? '' : $current;
            $count = 0;
            $stripped = preg_replace(self::PATTERN, '', $text, -1, $count);
            if ($stripped === null || substr_count($text, self::BEGIN) !== $count ||
                substr_count($text, self::END) !== $count) { return false; }
            if ($block === null) { return $stripped; }
            $separator = $stripped === '' || substr($stripped, -1) === "\n" ? '' : "\n";
            return $stripped . $separator . $block . "\n";
        });
    }
}
