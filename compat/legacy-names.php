<?php
/**
 * Names used before 0.10.0, kept working for sites that deploy the plugin from source.
 *
 * This file is never part of a release archive. It bridges the old hook names, global
 * functions, constants and WP-CLI command onto the aceredis names, and provides
 * `wp aceredis convert-names` to move a site's stored rows across.
 *
 * @package AceMedia\RedisCache
 */

if (!defined('ABSPATH')) {
    exit;
}

final class AceRedisLegacyNames {

    /** Hooks the plugin fires. Callbacks added under the old name still run. */
    const FIRED = array('ace_known_query_transients', 'ace_oc_bypass_groups', 'ace_oc_flush_on_post_change', 'ace_oc_member_read_mode', 'ace_oc_persistent_groups', 'ace_rc_admin_query_cache_enabled', 'ace_rc_all_transients_flushed', 'ace_rc_block_cache_cacheable', 'ace_rc_block_cache_context_blocks', 'ace_rc_block_cache_custom_blocks', 'ace_rc_block_cache_custom_role_safe', 'ace_rc_block_cache_enabled', 'ace_rc_block_cache_profiles', 'ace_rc_block_cache_vary', 'ace_rc_block_cache_woo_blocks', 'ace_rc_block_cache_woo_role_safe', 'ace_rc_broad_page_cache_purge_on_post_update', 'ace_rc_brotli_level_object', 'ace_rc_brotli_level_page', 'ace_rc_browser_max_age', 'ace_rc_cache_cleared', 'ace_rc_cpu_count', 'ace_rc_crawler_bypass', 'ace_rc_critical_transient_patterns', 'ace_rc_defer_post_invalidation', 'ace_rc_dynamic_block_patterns', 'ace_rc_dynamic_placeholder_limit', 'ace_rc_dynamic_stale_ttl', 'ace_rc_early_bypass_paths', 'ace_rc_enable_cache_priming', 'ace_rc_enable_canonical_debug', 'ace_rc_enable_first_pass_skip', 'ace_rc_enable_partial_dynamic', 'ace_rc_force_immediate_invalidation_on_critical_change', 'ace_rc_force_transient_flush', 'ace_rc_global_asset_warm_window', 'ace_rc_gzip_level_object', 'ace_rc_gzip_level_page', 'ace_rc_lazy_exclude_pattern', 'ace_rc_lazy_skip_first', 'ace_rc_logged_in_cache_group', 'ace_rc_logged_in_store_block', 'ace_rc_main_content_marker', 'ace_rc_min_compress_size', 'ace_rc_min_main_text_bytes', 'ace_rc_object_cache_bypass', 'ace_rc_object_cache_write_through_groups', 'ace_rc_opcache_prime_files', 'ace_rc_page_epoch_bumped', 'ace_rc_page_grace', 'ace_rc_page_store_block_reason', 'ace_rc_page_ttl', 'ace_rc_page_ttl_jitter', 'ace_rc_path_first_pass_marker_ttl', 'ace_rc_paths_invalidated', 'ace_rc_post_archive_invalidation_paths', 'ace_rc_post_first_pass_marker_ttl', 'ace_rc_post_invalidation_paths', 'ace_rc_post_transient_patterns', 'ace_rc_post_transients_flushed', 'ace_rc_prime_continue_delay', 'ace_rc_prime_parallel', 'ace_rc_prime_time_budget', 'ace_rc_prime_timeout', 'ace_rc_recache_max_urls', 'ace_rc_recache_on_edit', 'ace_rc_recache_related_urls', 'ace_rc_recache_urls', 'ace_rc_reconcile_static_cache_rules', 'ace_rc_sitemap_prime_batch_size', 'ace_rc_sitemap_prime_delay', 'ace_rc_sitemap_prime_urls', 'ace_rc_sitewide_recache_post_types', 'ace_rc_skip_page_cache_store', 'ace_rc_skip_post_object_cache', 'ace_rc_soft_broad_purge', 'ace_rc_soft_purge_min_interval', 'ace_rc_style_asset_complete', 'ace_rc_transient_global_flush_on_post_change', 'ace_rc_varnish_enabled', 'ace_rc_varnish_hosts', 'ace_rc_woocommerce_warm_urls', 'ace_redis_cache_excluded_urls', 'ace_redis_cache_page_cache_key_parts', 'ace_redis_cache_reporting_prefixes', 'ace_redis_cache_transient_exclusions', 'ace_redis_cache_ttl');

    /** Hooks the plugin listens to. Firing the old name still reaches it. */
    const LISTENED = array('ace_known_query_transients', 'ace_rc_broad_page_cache_purge_on_post_update', 'ace_rc_brotli_level_object', 'ace_rc_brotli_level_page', 'ace_rc_cache_cleared', 'ace_rc_gzip_level_page', 'ace_rc_min_compress_size', 'ace_rc_page_epoch_bumped', 'ace_rc_paths_invalidated', 'ace_rc_reconcile_static_cache_rules', 'ace_rc_start_page_cache', 'ace_redis_cache_excluded_urls', 'ace_redis_cache_purge_url', 'ace_redis_cache_transient_exclusions', 'ace_redis_cache_varnish_ban_site');

    /** Scheduled hooks. Events queued under the old name are dropped once; the plugin queues its own again. */
    const CRON = array('ace_rc_deferred_soft_purge', 'ace_rc_prime_post_cache', 'ace_rc_purge_stale_generations', 'ace_rc_run_deferred_invalidation', 'ace_rc_run_sitemap_prime_batch', 'ace_rc_warm_woocommerce', 'ace_redis_cache_process_clear_job');

    /** Option rows that hold state, old name to new. The settings row is handled apart. */
    const STATE_OPTIONS = array(
        'ace_redis_cache_dismissed_version'  => 'aceredis_cache_dismissed_version',
        'ace_redis_cache_shown_version'      => 'aceredis_cache_shown_version',
        'ace_redis_cache_transient_registry' => 'aceredis_cache_transient_registry',
        'ace_rc_dropin_version'              => 'aceredis_dropin_version',
        'ace_managed_plugins'                => 'aceredis_managed_plugins',
    );

    const OLD_SETTINGS = 'ace_redis_cache_settings';
    const NEW_SETTINGS = 'aceredis_cache_settings';

    private static $busy = array();

    public static function rename($name) {
        foreach (array('ace_redis_' => 'aceredis_', 'ace_rc_' => 'aceredis_', 'ace_oc_' => 'aceredis_oc_', 'ace_known_' => 'aceredis_known_') as $old => $new) {
            if (0 === strpos($name, $old)) {
                return $new . substr($name, strlen($old));
            }
        }
        return $name;
    }

    public static function bridge() {
        foreach (array('VERSION', 'PLUGIN_FILE', 'PLUGIN_PATH', 'PLUGIN_URL') as $suffix) {
            if (!defined('ACE_REDIS_CACHE_' . $suffix)) {
                define('ACE_REDIS_CACHE_' . $suffix, constant('ACEREDIS_CACHE_' . $suffix));
            }
        }

        foreach (self::FIRED as $old) {
            $new = self::rename($old);
            add_filter($new, static function (...$args) use ($old) {
                if (!empty(self::$busy[$old]) || !has_filter($old)) {
                    return $args[0] ?? null;
                }
                self::$busy[$old] = true;
                try {
                    return apply_filters_ref_array($old, $args);
                } finally {
                    unset(self::$busy[$old]);
                }
            }, 10, 99);
        }

        foreach (self::LISTENED as $old) {
            $new = self::rename($old);
            add_filter($old, static function (...$args) use ($old, $new) {
                if (!empty(self::$busy[$old])) {
                    return $args[0] ?? null;
                }
                self::$busy[$old] = true;
                try {
                    return apply_filters_ref_array($new, $args);
                } finally {
                    unset(self::$busy[$old]);
                }
            }, 10, 99);
        }

        add_action('init', array(__CLASS__, 'drop_old_cron'), 1);
        add_action('admin_init', array(__CLASS__, 'old_form_fields'), 0);

        if (defined('WP_CLI') && WP_CLI) {
            add_action('plugins_loaded', array(__CLASS__, 'cli'), 20);
        }
    }

    /** The settings form posts under the new field name; options.php expects the row name in use. */
    public static function old_form_fields() {
        if (self::OLD_SETTINGS === ACEREDIS_SETTINGS_OPTION && isset($_POST[self::NEW_SETTINGS]) && !isset($_POST[self::OLD_SETTINGS])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verifies the nonce.
            $_POST[self::OLD_SETTINGS] = $_POST[self::NEW_SETTINGS]; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Sanitised by the registered callback.
        }
    }

    public static function drop_old_cron() {
        if (get_option('aceredis_old_cron_dropped')) {
            return;
        }
        foreach (self::CRON as $hook) {
            wp_unschedule_hook($hook);
        }
        update_option('aceredis_old_cron_dropped', time(), true);
    }

    public static function cli() {
        if (class_exists('AceMedia\\RedisCache\\Admin_CLI')) {
            foreach (array('autoload-top' => 'cmd_top', 'trim-autoload' => 'cmd_trim', 'block-cache' => 'cmd_block_cache') as $name => $method) {
                \WP_CLI::add_command('ace ' . $name, array('AceMedia\\RedisCache\\Admin_CLI', $method));
            }
        }
        \WP_CLI::add_command('aceredis convert-names', array(__CLASS__, 'convert_command'));
    }

    /**
     * Move this site's stored rows to the aceredis names.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what would change without changing it.
     *
     * [--reverse]
     * : Move the rows back to the old names.
     *
     * @when after_wp_load
     */
    public static function convert_command($args, $assoc) {
        $rows = self::convert(!empty($assoc['dry-run']), !empty($assoc['reverse']));
        \WP_CLI\Utils\format_items('table', $rows, array('scope', 'from', 'to', 'action'));
        \WP_CLI::success(!empty($assoc['dry-run']) ? 'Dry run only.' : 'Done. The next request uses the names shown.');
    }

    public static function convert($dry = false, $reverse = false) {
        global $wpdb;
        $rows = array();
        $map  = array(self::OLD_SETTINGS => self::NEW_SETTINGS) + self::STATE_OPTIONS;
        if ($reverse) {
            $map = array_flip($map);
        }

        $tables = array('option' => array($wpdb->options, 'option_name'));
        if (is_multisite()) {
            $tables['network'] = array($wpdb->sitemeta, 'meta_key');
        }

        foreach ($tables as $scope => $table) {
            list($name, $column) = $table;
            foreach ($map as $from => $to) {
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Table and column names come from $wpdb.
                $has_from = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$name} WHERE {$column} = %s", $from));
                $has_to   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$name} WHERE {$column} = %s", $to));
                if (!$has_from) {
                    continue;
                }
                $settings = self::OLD_SETTINGS === $from || self::NEW_SETTINGS === $from;
                if ($has_to && $settings) {
                    $rows[] = array('scope' => $scope, 'from' => $from, 'to' => $to, 'action' => 'BOTH EXIST, left alone');
                    continue;
                }
                $action = $has_to ? 'drop the leftover row' : 'rename';
                if (!$dry) {
                    if ($has_to) {
                        $wpdb->query($wpdb->prepare("DELETE FROM {$name} WHERE {$column} = %s", $from));
                    } else {
                        $wpdb->query($wpdb->prepare("UPDATE {$name} SET {$column} = %s WHERE {$column} = %s", $to, $from));
                    }
                }
                // phpcs:enable
                $rows[] = array('scope' => $scope, 'from' => $from, 'to' => $to, 'action' => $action . ($dry ? ' (dry run)' : ''));
            }
        }

        if (!$dry) {
            foreach (self::CRON as $hook) {
                wp_unschedule_hook($reverse ? self::rename($hook) : $hook);
            }
            foreach (array_merge(array_keys($map), array_values($map)) as $option) {
                wp_cache_delete($option, 'options');
            }
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            if (is_multisite()) {
                wp_cache_flush();
            }
        }

        return $rows;
    }
}

AceRedisLegacyNames::bridge();

// Global functions other code calls under the old names.
if (!function_exists('ace_redis_cache')) {
    function ace_redis_cache() {
        return aceredis_cache();
    }
}
if (!function_exists('ace_rc_soft_purge_page_cache')) {
    function ace_rc_soft_purge_page_cache($force = false) {
        return aceredis_soft_purge_page_cache($force);
    }
}
if (!function_exists('ace_rc_admin_cache_remember')) {
    function ace_rc_admin_cache_remember($key, callable $compute, array $tags = array(), $ttl = null) {
        return aceredis_admin_cache_remember($key, $compute, $tags, $ttl);
    }
}
if (!function_exists('ace_rc_admin_cache_bump')) {
    function ace_rc_admin_cache_bump($tag) {
        return aceredis_admin_cache_bump($tag);
    }
}
// The object cache drop-in defines these; an older installed copy still has the old names.
if (!function_exists('ace_oc_is_public_ajax') && function_exists('aceredis_oc_is_public_ajax')) {
    function ace_oc_is_public_ajax() {
        return aceredis_oc_is_public_ajax();
    }
}
if (!function_exists('ace_oc_is_member_front_ajax') && function_exists('aceredis_oc_is_member_front_ajax')) {
    function ace_oc_is_member_front_ajax() {
        return aceredis_oc_is_member_front_ajax();
    }
}
if (!function_exists('ace_object_cache_is_woocommerce') && function_exists('aceredis_object_cache_is_woocommerce')) {
    function ace_object_cache_is_woocommerce() {
        return aceredis_object_cache_is_woocommerce();
    }
}
