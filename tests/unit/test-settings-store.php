<?php
/**
 * SettingsStore tests
 *
 * @package AceMedia\RedisCache
 */

use PHPUnit\Framework\TestCase;
use AceMedia\RedisCache\SettingsStore;

if (!function_exists('is_multisite')) {
    function is_multisite() {
        return !empty($GLOBALS['ace_test_is_multisite']);
    }
}

if (!function_exists('is_plugin_active_for_network')) {
    function is_plugin_active_for_network($plugin_file) {
        return !empty($GLOBALS['ace_test_network_active_plugins'][$plugin_file]);
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['ace_test_options']) ? $GLOBALS['ace_test_options'][$name] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($name, $value) {
        $GLOBALS['ace_test_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('add_option')) {
    function add_option($name, $value, $deprecated = '', $autoload = 'yes') {
        if (!array_key_exists($name, $GLOBALS['ace_test_options'])) {
            $GLOBALS['ace_test_options'][$name] = $value;
        }
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($name) {
        unset($GLOBALS['ace_test_options'][$name]);
        return true;
    }
}

if (!function_exists('get_site_option')) {
    function get_site_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['ace_test_site_options']) ? $GLOBALS['ace_test_site_options'][$name] : $default;
    }
}

if (!function_exists('update_site_option')) {
    function update_site_option($name, $value) {
        $GLOBALS['ace_test_site_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('add_site_option')) {
    function add_site_option($name, $value) {
        if (!array_key_exists($name, $GLOBALS['ace_test_site_options'])) {
            $GLOBALS['ace_test_site_options'][$name] = $value;
        }
        return true;
    }
}

if (!function_exists('delete_site_option')) {
    function delete_site_option($name) {
        unset($GLOBALS['ace_test_site_options'][$name]);
        return true;
    }
}

if (!function_exists('get_main_site_id')) {
    function get_main_site_id() {
        return 1;
    }
}

if (!function_exists('get_blog_option')) {
    function get_blog_option($blog_id, $name, $default = false) {
        $key = $blog_id . ':' . $name;
        return array_key_exists($key, $GLOBALS['ace_test_blog_options']) ? $GLOBALS['ace_test_blog_options'][$key] : $default;
    }
}

class SettingsStoreTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['ace_test_is_multisite'] = false;
        $GLOBALS['ace_test_network_active_plugins'] = [];
        $GLOBALS['ace_test_options'] = [];
        $GLOBALS['ace_test_site_options'] = [];
        $GLOBALS['ace_test_blog_options'] = [];
    }

    public function testSingleSiteUsesRegularOptions() {
        SettingsStore::update_settings(['enabled' => 1]);

        $this->assertSame(['enabled' => 1], SettingsStore::get_settings([]));
        $this->assertSame(['enabled' => 1], $GLOBALS['ace_test_options'][SettingsStore::SETTINGS_OPTION]);
        $this->assertArrayNotHasKey(SettingsStore::SETTINGS_OPTION, $GLOBALS['ace_test_site_options']);
    }

    public function testNetworkModeUsesSiteOptions() {
        $GLOBALS['ace_test_is_multisite'] = true;
        $GLOBALS['ace_test_network_active_plugins'][SettingsStore::plugin_basename()] = 1;

        SettingsStore::update_settings(['enabled' => 1, 'host' => '127.0.0.1']);

        $this->assertSame(['enabled' => 1, 'host' => '127.0.0.1'], SettingsStore::get_settings([]));
        $this->assertSame(['enabled' => 1, 'host' => '127.0.0.1'], $GLOBALS['ace_test_site_options'][SettingsStore::SETTINGS_OPTION]);
        $this->assertArrayNotHasKey(SettingsStore::SETTINGS_OPTION, $GLOBALS['ace_test_options']);
    }

    public function testMigrationCopiesMainSiteLegacyOption() {
        $GLOBALS['ace_test_is_multisite'] = true;
        $GLOBALS['ace_test_network_active_plugins'][SettingsStore::plugin_basename()] = 1;
        $GLOBALS['ace_test_blog_options']['1:' . SettingsStore::SETTINGS_OPTION] = ['enabled' => 1, 'port' => 6379];

        SettingsStore::maybe_migrate_legacy_settings_to_network();

        $this->assertSame(['enabled' => 1, 'port' => 6379], $GLOBALS['ace_test_site_options'][SettingsStore::SETTINGS_OPTION]);
    }
}
