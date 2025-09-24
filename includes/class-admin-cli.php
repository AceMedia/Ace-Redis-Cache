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
}
