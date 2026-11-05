<?php
/** A regex failing on a large page must never empty or damage it. Run: php8.4 tests/test-minify-regex-failure.php */
define('ABSPATH', __DIR__);
require __DIR__ . '/../includes/class-minification.php';
$m = new AceMedia\RedisCache\Minification(['custom_content_exclusions' => '']);
$fail = 0;
$check = function ($ok, $label) use (&$fail) { echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };

$page = "<!doctype html><html><head><style>a { }\n\n/* c */\nb { }</style><script>var a = 1;\n// keep\nvar b = 2;</script></head><body>  <p>kept</p>\n\n</body></html>";
$normal = $m->minify_output($page);
$check(strpos($normal, 'kept') !== false && strpos($normal, '/* c */') === false, 'normal limits still minify');

ini_set('pcre.jit', '0');
ini_set('pcre.backtrack_limit', '5');
$out = $m->minify_output($page);
$check(is_string($out) && strpos($out, 'kept') !== false, 'the page survives every regex failing');
$check(strpos($out, "// keep\nvar b = 2;") !== false, 'a script is not collapsed onto its comment');
$check(strpos($out, 'a { }') !== false, 'inline CSS is not lost');
exit($fail ? 1 : 0);
