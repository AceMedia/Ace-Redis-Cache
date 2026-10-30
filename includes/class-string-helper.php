<?php
namespace AceMedia\RedisCache;
if (!defined('ABSPATH')) exit;
class StringHelper {
    public static function starts_with($haystack, $needle) {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
