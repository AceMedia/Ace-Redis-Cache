<?php
/**
 * Standalone check that WP-Cron runs persist object-cache writes, while real update operations
 * stay runtime-only. Each case is its own PHP process because DOING_CRON is a constant.
 * Run: php -n tests/test-cron-persists.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (($argv[1] ?? '') === '--case') {
    [$uri, $method, $cron, $post_action] = json_decode(base64_decode($argv[2]), true);
    define('ABSPATH', __DIR__ . '/');
    if ($cron) {
        define('DOING_CRON', true);
    }
    function apply_filters($name, $value) { return $value; }
    function add_action() {}
    function is_multisite() { return false; }
    if (!class_exists('Redis', false)) {
        eval('class Redis { public function connect() { return false; } public function pconnect() { return false; } }');
    }
    $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'example.test'];
    $_GET = [];
    $_POST = $post_action !== '' ? ['action' => $post_action] : [];
    $_COOKIE = [];
    require dirname(__DIR__) . '/assets/dropins/object-cache.php';
    $cache = new WP_Object_Cache();
    $r = new ReflectionClass($cache);
    $out = [];
    foreach (['runtime_only_mode', 'bypass'] as $p) {
        $prop = $r->getProperty($p);
        if (PHP_VERSION_ID < 80100) { $prop->setAccessible(true); }
        $out[$p] = (bool) $prop->getValue($cache);
    }
    echo json_encode($out);
    exit;
}

// [ label, uri, method, DOING_CRON, POST action, expect runtime-only ]
$cases = [
    ['front-end page',             '/',                                  'GET',  false, '',              false],
    ['wp-cron.php',                '/wp-cron.php?doing_wp_cron=1.23',    'GET',  true,  '',              false],
    ['wp cron event run (CLI)',    '',                                   'GET',  true,  '',              false],
    ['update-core.php',            '/wp-admin/update-core.php',          'GET',  false, '',              true],
    ['update.php plugin upgrade',  '/wp-admin/update.php?action=upgrade-plugin', 'GET', false, '',       true],
    ['POST update action',         '/wp-admin/admin-ajax.php',           'POST', false, 'update-plugin', true],
];

$passed = 0;
foreach ($cases as [$label, $uri, $method, $cron, $post_action, $expect]) {
    $arg = base64_encode(json_encode([$uri, $method, $cron, $post_action]));
    $raw = shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg(__FILE__) . ' --case ' . escapeshellarg($arg) . ' 2>&1');
    $got = json_decode((string) $raw, true);
    if (!is_array($got)) {
        throw new RuntimeException("$label: case did not run: " . trim((string) $raw));
    }
    if ($got['runtime_only_mode'] !== $expect) {
        throw new RuntimeException(sprintf('%s: runtime_only_mode=%s, expected %s', $label, var_export($got['runtime_only_mode'], true), var_export($expect, true)));
    }
    $passed++;
}

echo "$passed cron and update-operation cases passed: cron persists, updates stay runtime-only\n";
