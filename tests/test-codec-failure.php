<?php
// Synthetic cache entries only. Never connects to Redis or a WordPress database.
define('ABSPATH', __DIR__ . '/');
function add_action(...$args) {}
function get_option($key, $default = false) { return $default; }
require dirname(__DIR__) . '/includes/class-string-helper.php';
require dirname(__DIR__) . '/includes/class-cache-manager.php';
$connection = new class {
    public $payload;
    public function get_connection() { return $this; }
    public function get($key) { return $this->payload; }
    public function retry_operation($operation) { return $operation($this); }
};
$manager = new AceMedia\RedisCache\CacheManager($connection, ['enable_minification' => false]);
$checks = 0;
$check = static function ($ok, $message) use (&$checks) {
    if (!$ok) { throw new RuntimeException($message); }
    ++$checks;
    echo "PASS $message\n";
};
$good = 'Synthetic cached HTML';
$connection->payload = 'raw:' . $good;
$check($manager->get_with_minification('fixture', false) === $good, 'plain HTML retains its content');
$gzip = gzencode($good);
if (function_exists('gzdecode') || function_exists('gzuncompress')) {
    foreach (['gz6:' . $gzip, serialize('gz6:' . $gzip)] as $payload) {
        $connection->payload = $payload;
        $check($manager->get_with_minification('fixture', false) === $good, 'healthy gzip HTML decodes');
    }
    $connection->payload = 'gz6:' . $gzip;
    $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip';
    $check($manager->get_with_minification('fixture', true) === $gzip, 'valid gzip passthrough preserves encoded bytes');
    unset($_SERVER['HTTP_ACCEPT_ENCODING']);
} else {
    $connection->payload = 'gz6:' . $gzip;
    $check($manager->get_with_minification('fixture', false) === null, 'missing gzip decoder is a cache miss');
    $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip';
    $check($manager->get_with_minification('fixture', true) === null, 'missing gzip decoder cannot serve unvalidated bytes');
    unset($_SERVER['HTTP_ACCEPT_ENCODING']);
}
foreach (['gz6:invalid-compressed-data', 'gz6:' . "\x1f\x8b" . 'truncated'] as $payload) {
    $connection->payload = $payload;
    $check($manager->get_with_minification('fixture', false) === null, 'corrupt gzip is a page-cache miss');
    $check($manager->get('fixture') === null, 'corrupt gzip is a generic cache miss');
    $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip';
    $check($manager->get_with_minification('fixture', true) === null, 'gzip clients never receive a corrupt encoded response');
    unset($_SERVER['HTTP_ACCEPT_ENCODING']);
}
if (function_exists('brotli_compress') && function_exists('brotli_uncompress')) {
    $brotli = brotli_compress($good);
    $connection->payload = 'br9:' . $brotli;
    $check($manager->get_with_minification('fixture', false) === $good, 'healthy Brotli HTML decodes');
    $_SERVER['HTTP_ACCEPT_ENCODING'] = 'br';
    $check($manager->get_with_minification('fixture', true) === $brotli, 'valid Brotli passthrough preserves encoded bytes');
    unset($_SERVER['HTTP_ACCEPT_ENCODING']);
} else {
    $connection->payload = 'br9:' . hex2bin('1b0600f8250002a4');
    $check($manager->get_with_minification('fixture', false) === null, 'missing Brotli decoder is a cache miss');
    $check($manager->get('fixture') === null, 'missing decoder never returns raw bytes from generic reads');
    $_SERVER['HTTP_ACCEPT_ENCODING'] = 'br';
    $check($manager->get_with_minification('fixture', true) === null, 'missing decoder cannot serve an unvalidated encoded page');
    unset($_SERVER['HTTP_ACCEPT_ENCODING']);
}
$connection->payload = 'br9:corrupt-brotli';
$check($manager->get_with_minification('fixture', false) === null, 'corrupt Brotli is a cache miss');
$connection->payload = 'raw:b:0;';
$check($manager->get('fixture') === false, 'stored false retains its meaning');
$connection->payload = 'raw:s:0:"";';
$check($manager->get('fixture') === '', 'stored empty string retains its meaning');
$connection->payload = 'raw:' . serialize((object) ['answer' => 42]);
$check($manager->get('fixture') == (object) ['answer' => 42], 'known object values retain their meaning');
$connection->payload = 'raw:O:22:"SyntheticMissingClassX":0:{}';
$check($manager->get('fixture') === null, 'an unavailable root class is a cache miss');
echo "Completed $checks synthetic checks on PHP " . PHP_VERSION . "\n";
