<?php
/**
 * wp-admin query cache.
 *
 * Results of expensive, repeated wp-admin reads kept for a short TTL and dropped the moment the
 * data under them changes. Stored as transients, which the object-cache drop-in shares even on
 * wp-admin requests (and which fall back to the options table without it), so one editor's page
 * load warms the next one's.
 *
 * Each entry names the tags it depends on ("posts", "attachments", "postmeta", or a plugin's own
 * such as "bet_api_tracking"). A tag has a generation number that is part of the entry's key;
 * bumping the tag moves every entry on it to a key nobody reads, so invalidation is one write and
 * nothing is ever served across a change.
 *
 * Plugins use it through ace_rc_admin_cache_remember() and ace_rc_admin_cache_bump(), which run
 * the callback uncached when the setting is off or this plugin is missing (check function_exists).
 *
 * @package AceMedia\RedisCache
 */

namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) {
    exit;
}

class AdminQueryCache {
    const PREFIX = 'ace_rcaq_';

    /** @var bool */
    private static $enabled = false;
    /** @var int */
    private static $ttl = 300;
    /** @var array<string,string> Request-local generations. */
    private static $gens = [];

    public static function configure(array $settings) {
        self::$enabled = !empty($settings['admin_query_cache']);
        self::$ttl = max(30, min(3600, (int) ($settings['admin_query_cache_ttl'] ?? 300)));
    }

    public static function enabled() {
        return self::$enabled && (bool) apply_filters('ace_rc_admin_query_cache_enabled', true);
    }

    /**
     * The cached result for $key, or $compute()'s result stored for next time.
     *
     * @param string   $key     Unique within the caller (prefix it with your plugin's slug).
     * @param callable $compute Returns the value. A null result is not stored.
     * @param string[] $tags    What the value depends on; bump any of them to drop it.
     * @param int|null $ttl     Seconds, else the setting's TTL.
     */
    public static function remember($key, callable $compute, array $tags = [], $ttl = null) {
        if (!self::enabled()) {
            return $compute();
        }
        $name = self::entry_name((string) $key, $tags);
        $hit = get_transient($name);
        if (is_array($hit) && array_key_exists('v', $hit)) {
            return $hit['v'];
        }
        $value = $compute();
        if ($value !== null) {
            set_transient($name, ['v' => $value], $ttl !== null ? max(1, (int) $ttl) : self::$ttl);
        }
        return $value;
    }

    /** Drop every entry that depends on $tag. Safe to call when the cache is off. */
    public static function bump($tag) {
        $tag = sanitize_key((string) $tag);
        if ($tag === '') {
            return;
        }
        $gen = (string) microtime(true);
        self::$gens[$tag] = $gen;
        set_transient(self::PREFIX . 'g_' . $tag, $gen, 0);
    }

    private static function generation($tag) {
        if (!isset(self::$gens[$tag])) {
            $gen = get_transient(self::PREFIX . 'g_' . $tag);
            self::$gens[$tag] = is_string($gen) && $gen !== '' ? $gen : '0';
        }
        return self::$gens[$tag];
    }

    private static function entry_name($key, array $tags) {
        $parts = [$key];
        $tags = array_values(array_unique(array_filter(array_map('sanitize_key', $tags))));
        sort($tags);
        foreach ($tags as $tag) {
            $parts[] = $tag . '=' . self::generation($tag);
        }
        // Transient names are capped at 172 characters; a hash keeps any key inside that.
        return self::PREFIX . md5(implode('|', $parts));
    }

    /**
     * Core's own repeated wp-admin reads that it does not cache, and what drops them. Hooked on
     * every request so a change made anywhere (front end, REST, cron) invalidates.
     */
    public static function register_core_hooks() {
        foreach (['save_post', 'deleted_post', 'transition_post_status'] as $hook) {
            add_action($hook, [__CLASS__, 'bump_posts'], 10, 0);
        }
        foreach (['add_attachment', 'edit_attachment', 'delete_attachment'] as $hook) {
            add_action($hook, [__CLASS__, 'bump_attachments'], 10, 0);
        }
        foreach (['added_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [__CLASS__, 'bump_postmeta'], 10, 0);
        }
        if (!is_admin()) {
            return;
        }
        // The posts list's month filter: SELECT DISTINCT YEAR/MONTH over every post of the type.
        add_filter('pre_months_dropdown_query', [__CLASS__, 'months_dropdown'], 10, 2);
        // The media modal's month filter, the same scan over attachments.
        add_filter('media_library_months_with_files', [__CLASS__, 'media_months'], 10, 1);
        // The custom fields box: SELECT DISTINCT meta_key over the whole postmeta table.
        add_filter('postmeta_form_keys', [__CLASS__, 'postmeta_keys'], 10, 2);
        // "Is there any audio/video?" probes on every screen that opens the media modal.
        add_filter('media_library_show_audio_playlist', [__CLASS__, 'has_audio'], 10, 1);
        add_filter('media_library_show_video_playlist', [__CLASS__, 'has_video'], 10, 1);
    }

    public static function bump_posts() {
        static $done = false;
        if (!$done) { $done = true; self::bump('posts'); }
    }

    public static function bump_attachments() {
        static $done = false;
        if (!$done) { $done = true; self::bump('attachments'); }
    }

    public static function bump_postmeta() {
        static $done = false;
        if (!$done) { $done = true; self::bump('postmeta'); }
    }

    public static function months_dropdown($months, $post_type) {
        if ($months !== false || !self::enabled()) {
            return $months;
        }
        global $wpdb;
        $post_type = (string) $post_type;
        // The query core runs in WP_List_Table::months_dropdown(), trash view included.
        $extra = "AND post_status != 'auto-draft'";
        if (!isset($_GET['post_status']) || 'trash' !== $_GET['post_status']) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter, as in core.
            $extra .= " AND post_status != 'trash'";
        } else {
            $extra = $wpdb->prepare(' AND post_status = %s', 'trash');
        }
        return self::remember('months:' . $post_type . ':' . md5($extra), function () use ($wpdb, $post_type, $extra) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT YEAR( post_date ) AS year, MONTH( post_date ) AS month FROM $wpdb->posts WHERE post_type = %s $extra ORDER BY post_date DESC",
                $post_type
            ));
        }, ['posts', 'attachments']);
    }

    public static function media_months($months) {
        if ($months !== null || !self::enabled()) {
            return $months;
        }
        global $wpdb;
        return self::remember('media_months', function () use ($wpdb) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT YEAR( post_date ) AS year, MONTH( post_date ) AS month FROM $wpdb->posts WHERE post_type = %s ORDER BY post_date DESC",
                'attachment'
            ));
        }, ['attachments']);
    }

    public static function postmeta_keys($keys, $post) {
        if ($keys !== null || !self::enabled()) {
            return $keys;
        }
        global $wpdb;
        $limit = (int) apply_filters('postmeta_form_limit', 30);
        return self::remember('postmeta_keys:' . $limit, function () use ($wpdb, $limit) {
            // The query core runs in meta_form().
            return $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT meta_key FROM $wpdb->postmeta WHERE meta_key NOT BETWEEN '_' AND '_z' HAVING meta_key NOT LIKE %s ORDER BY meta_key LIMIT %d",
                $wpdb->esc_like('_') . '%',
                $limit
            ));
        }, ['postmeta']);
    }

    public static function has_audio($show) {
        return self::has_mime($show, 'audio');
    }

    public static function has_video($show) {
        return self::has_mime($show, 'video');
    }

    private static function has_mime($show, $type) {
        if ($show !== null || !self::enabled()) {
            return $show;
        }
        global $wpdb;
        // Stored as 1/0: a cached false would read as a miss.
        return (bool) self::remember('has_' . $type, function () use ($wpdb, $type) {
            return (int) (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type LIKE %s LIMIT 1",
                $wpdb->esc_like($type . '/') . '%'
            ));
        }, ['attachments']);
    }
}
