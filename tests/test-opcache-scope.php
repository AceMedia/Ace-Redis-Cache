<?php
/** Isolated OPcache boundary test; never calls the process-wide PHP cache. */
namespace AceMedia\RedisCache {
    function opcache_get_status($scripts = true) { return ['scripts' => $GLOBALS['arc_test_scripts']]; }
    function opcache_invalidate($path, $force) { $GLOBALS['arc_test_invalidated'][] = $path; return true; }
}
namespace {
    define('ABSPATH', __DIR__ . '/');
    $dir = sys_get_temp_dir() . '/arc-opcache-' . bin2hex(random_bytes(8));
    mkdir($dir); mkdir($dir . '/content'); mkdir($dir . '/shared');
    define('WP_CONTENT_DIR', $dir . '/content');
    file_put_contents($dir . '/content/private.php', '<?php');
    file_put_contents($dir . '/shared/plugin.php', '<?php');
    symlink($dir . '/shared/plugin.php', $dir . '/content/shared.php');
    $GLOBALS['arc_test_scripts'] = array_fill_keys([$dir . '/content/private.php', $dir . '/shared/plugin.php', $dir . '/content/shared.php'], []);
    $GLOBALS['arc_test_invalidated'] = [];
    require dirname(__DIR__) . '/includes/class-opcache-helper.php';
    try {
        $ok = \AceMedia\RedisCache\OpcacheHelper::invalidate_private_scripts();
        if (!$ok || $GLOBALS['arc_test_invalidated'] !== [$dir . '/content/private.php']) throw new \RuntimeException('OPcache escaped private content boundary');
        echo "PASS only private site scripts invalidated; shared symlink and outside content preserved\n";
    } finally {
        unlink($dir . '/content/private.php'); unlink($dir . '/content/shared.php'); unlink($dir . '/shared/plugin.php');
        rmdir($dir . '/content'); rmdir($dir . '/shared'); rmdir($dir);
    }
}
