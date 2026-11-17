<?php
/**
 * composites_offering(): which composites a changed product shows up in. Run: php8.4 tests/unit/test-woo-composites.php
 */
define('ABSPATH', __DIR__ . '/');
$src = file_get_contents(dirname(__DIR__, 2) . '/includes/class-ace-redis-cache.php');
preg_match('/public static function composites_offering\(.*?\n    }\n/s', $src, $m);
eval('class T { ' . $m[0] . ' }');
$rows = [
    10 => [1 => ['query_type' => 'product_ids', 'assigned_ids' => [7792], 'default_id' => '7792']],
    11 => [1 => ['query_type' => 'product_ids', 'assigned_ids' => [5], 'default_id' => '5'], 2 => ['query_type' => 'category_ids', 'assigned_category_ids' => [30]]],
    12 => [1 => ['query_type' => 'product_ids', 'assigned_ids' => [8], 'default_id' => '7792']],
    13 => [1 => ['query_type' => 'product_ids', 'assigned_ids' => [9]]],
];
$fail = 0;
foreach ([
    'by id and default' => [T::composites_offering($rows, 7792, []), [10, 12]],
    'by category' => [T::composites_offering($rows, 77, [30, 2]), [11]],
    'no match' => [T::composites_offering($rows, 77, []), []],
] as $label => [$got, $want]) {
    echo ($got === $want ? 'PASS' : 'FAIL') . "  $label\n";
    $fail += $got === $want ? 0 : 1;
}
exit($fail ? 1 : 0);
