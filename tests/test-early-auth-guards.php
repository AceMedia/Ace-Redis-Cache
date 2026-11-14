<?php
/** Exercise early-cache cookie/method/REST guards without WordPress or a Redis service. */
define('ABSPATH', __DIR__ . '/');
if (class_exists('Redis', false)) throw new RuntimeException('Run with php -n');
eval('class Redis {
    public static $connects = 0;
    function connect(...$args) { self::$connects++; return false; }
}');
$source = file_get_contents(dirname(__DIR__) . '/assets/dropins/advanced-cache.php');
$source = str_replace("if (php_sapi_name() === 'cli')", 'if (false)', $source, $replaced);
if ($replaced !== 1) throw new RuntimeException('CLI guard changed');
$GLOBALS['wp_version'] = '7.0';
$cases = [
    [[], 'GET', '/', [], false],
    [['ace_ref' => 'abc123'], 'GET', '/', [], false],
    [['cookieyes-consent' => 'consent:yes'], 'GET', '/', [], false],
];
foreach (['wordpress_logged_in_test', 'wordpress_sec_test', 'wordpressuser_test', 'wp-postpass_test', 'comment_author_test', 'wp_woocommerce_session_test'] as $cookie) {
    $cases[] = [[$cookie => 'fixture'], 'GET', '/', [], true];
}
$cases[] = [[], 'POST', '/', [], true];
$cases[] = [[], 'GET', '/wp-json/private/v1/me', [], true];
$cases[] = [[], 'GET', '/?rest_route=/private/v1/me', ['rest_route' => '/private/v1/me'], true];
foreach ($cases as [$cookies, $method, $uri, $get, $bypass]) {
    $_COOKIE = $cookies; $_GET = $get;
    $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'example.test', 'HTTPS' => 'on'];
    Redis::$connects = 0;
    eval('?>' . $source);
    if ((Redis::$connects === 0) !== $bypass) throw new RuntimeException('Wrong early-cache classification for ' . $uri . ' ' . implode(',', array_keys($cookies)));
}
echo 'PASS: ' . count($cases) . " early-cache auth, password, comment, session, POST, REST and harmless-cookie guards\n";
