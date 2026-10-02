<?php
namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) { exit; }

/**
 * WP-CLI commands for autoload inspection & trimming.
 */
class Admin_CLI {
    public static function register() {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('ace autoload-top', [__CLASS__, 'cmd_top']);
            \WP_CLI::add_command('ace trim-autoload', [__CLASS__, 'cmd_trim']);
            \WP_CLI::add_command('ace block-cache', [__CLASS__, 'cmd_block_cache']);
        }
    }

    /**
     * Show top 20 autoloaded options by size.
     */
    public static function cmd_top() {
        global $wpdb; $rows = $wpdb->get_results("SELECT option_name, LENGTH(option_value) sz FROM {$wpdb->options} WHERE autoload='yes' ORDER BY sz DESC LIMIT 20", ARRAY_A);
        if (!$rows) { \WP_CLI::log('No autoload rows found.'); return; }
        $table = [];
        foreach ($rows as $r) { $table[] = [ 'option' => $r['option_name'], 'bytes' => (int)$r['sz'] ]; }
        \WP_CLI\Utils\format_items('table', $table, ['option','bytes']);
    }

    /**
     * Trim specified autoload options: --names=opt1,opt2
     */
    public static function cmd_trim($args, $assoc) {
        if (empty($assoc['names'])) { \WP_CLI::error('Provide --names=comma,separated,list'); }
        $names = array_filter(array_map('trim', explode(',', $assoc['names'])));
        if (!$names) { \WP_CLI::error('No valid names provided'); }
        $protected = [ 'siteurl','home','rewrite_rules','cron','blog_public','category_base','permalink_structure','stylesheet','template','active_plugins' ];
        global $wpdb; $changed=0; $skipped=[]; $updated=[];
        foreach ($names as $name) {
            if (in_array($name, $protected, true)) { $skipped[] = $name; continue; }
            $res = $wpdb->update($wpdb->options, ['autoload' => 'no'], ['option_name' => $name, 'autoload' => 'yes']);
            if ($res === false) { $skipped[] = $name; continue; }
            if ($res > 0) { $changed++; $updated[] = $name; }
        }
        \WP_CLI::log('Updated autoload=no for: ' . implode(', ', $updated));
        if ($skipped) { \WP_CLI::warning('Skipped: ' . implode(', ', $skipped)); }
        \WP_CLI::success('Done. Rows changed: ' . $changed);
    }

    /**
     * Block cache: `wp ace block-cache stats [--days=7] [--format=table|json]` or `wp ace block-cache flush`.
     */
    public static function cmd_block_cache($args, $assoc) {
        $bc = BlockCache::instance();
        if (!$bc) {
            \WP_CLI::error(BlockCache::legacy_module_active() ? 'The legacy ace-block-html-cache mu-plugin is active; the plugin module is standing aside.' : 'Block cache is not running (Redis unavailable).');
        }
        $sub = $args[0] ?? 'stats';
        if ($sub === 'flush') {
            $ok = $bc->flush(true);
            $bc->on_shutdown();
            $ok ? \WP_CLI::success('Block cache flushed.') : \WP_CLI::error('Flush failed.');
            return;
        }
        $settings = SettingsStore::get_settings([]);
        $stats = $bc->get_stats(max(1, min(8, (int) ($assoc['days'] ?? 7))));
        if (($assoc['format'] ?? 'table') === 'json') {
            \WP_CLI::log(wp_json_encode(['enabled' => BlockCache::is_enabled(is_array($settings) ? $settings : []), 'stats' => $stats]));
            return;
        }
        \WP_CLI::log('Enabled: ' . (BlockCache::is_enabled(is_array($settings) ? $settings : []) ? 'yes' : 'no'));
        $rows = [];
        foreach ($stats['blocks'] as $block => $t) {
            $rows[] = ['block' => $block] + $t;
        }
        $rows ? \WP_CLI\Utils\format_items('table', $rows, ['block', 'hits', 'misses', 'avg_ms', 'saved_ms', 'saved_queries']) : \WP_CLI::log('No block cache activity recorded.');
        $t = $stats['totals'];
        \WP_CLI::log(sprintf('Total: %d hits, %d misses, %.1f s render time saved, %d queries saved.', $t['hits'], $t['misses'], $t['saved_ms'] / 1000, $t['saved_queries']));
    }
}
