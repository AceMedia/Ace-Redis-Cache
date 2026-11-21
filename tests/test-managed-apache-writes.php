<?php
namespace Arc_Managed_File_Test;
// Real production classes, disposable files, injected filesystem faults. No WordPress bootstrap.
if (PHP_SAPI !== 'cli' || \defined('ABSPATH')) { exit(1); }
\define('ABSPATH', __DIR__ . '/');
\error_reporting(E_ALL);
\set_error_handler(static function ($severity, $message, $file, $line) {
    if (\error_reporting() & $severity) throw new \ErrorException($message, 0, $severity, $file, $line);
    return false;
});
$root = \sys_get_temp_dir() . '/arc-managed-' . \bin2hex(\random_bytes(6));
\mkdir($root, 0700);
$mode = ''; $target = ''; $checks = 0; $outside = [];
function wp_is_writable($path) { return \is_writable($path); }
function file_get_contents($path) { global $mode, $target; return $mode === 'read' && $path === $target ? false : \file_get_contents($path); }
function file_put_contents($path, $bytes, $flags = 0) {
    global $mode, $target;
    if ($mode === 'write') return false;
    if ($mode === 'short') return \file_put_contents($path, \substr($bytes, 0, -1), $flags);
    $result = \file_put_contents($path, $bytes, $flags);
    if ($mode === 'concurrent') \file_put_contents($target, "# unrelated concurrent edit\n");
    if ($mode === 'deleted') \unlink($target);
    if ($mode === 'created') \file_put_contents($target, "# another writer created this\n");
    return $mode === 'close' ? false : $result;
}
function tempnam($dir, $prefix) {
    global $mode, $outside;
    if ($mode === 'temp') return false;
    $path = \tempnam($mode === 'outside' ? \sys_get_temp_dir() : $dir, $prefix);
    if ($mode === 'outside') $outside[] = $path;
    return $path;
}
function chmod($path, $permissions) { global $mode; return $mode === 'chmod' ? false : \chmod($path, $permissions); }
function chown($path, $uid) { global $mode; return $mode === 'owner' ? false : \chown($path, $uid); }
function chgrp($path, $gid) { global $mode; return $mode === 'group' ? false : \chgrp($path, $gid); }
function stat($path) {
    global $mode, $target;
    $row = \stat($path);
    if ($path === $target && $mode === 'owner') ++$row['uid'];
    if ($path === $target && $mode === 'group') ++$row['gid'];
    return $row;
}
function rename($from, $to) { global $mode; return $mode === 'rename' ? false : \rename($from, $to); }
function check($ok, $label) { global $checks; if (!$ok) throw new \RuntimeException($label); ++$checks; echo "PASS $label\n"; }
function clean($path) { if (\is_link($path) || !\is_dir($path)) { if (\file_exists($path) || \is_link($path)) \unlink($path); return; } \chmod($path, 0700); foreach (\scandir($path) as $name) if ($name !== '.' && $name !== '..') clean($path . '/' . $name); \rmdir($path); }
function fresh($name, $contents = "# unrelated rewrite rules\nRewriteEngine On\n") {
    global $root, $target;
    $dir = $root . '/' . $name; \mkdir($dir); $target = $dir . '/.htaccess';
    if ($contents !== null) { \file_put_contents($target, $contents); \chmod($target, 0640); }
    return $target;
}
try {
    foreach (['class-managed-file.php', 'class-managed-cache-rules.php', 'class-ace-redis-cache.php'] as $name) {
        $path = \dirname(__DIR__) . '/includes/' . $name;
        if ($name === 'class-ace-redis-cache.php' && \getenv('ARC_MAIN_SOURCE')) $path = \getenv('ARC_MAIN_SOURCE');
        $code = \str_replace('namespace AceMedia\\RedisCache;', 'namespace Arc_Managed_File_Test;', \file_get_contents($path));
        eval(\preg_replace('/^<\?php\s*/', '', $code, 1));
    }
    $class = new \ReflectionClass(AceRedisCache::class); $instance = $class->newInstanceWithoutConstructor();
    $upsert = $class->getMethod('upsert_managed_static_cache_block'); $remove = $class->getMethod('remove_managed_static_cache_block');
    if (PHP_VERSION_ID < 80100) { $upsert->setAccessible(true); $remove->setAccessible(true); }
    if (\getenv('ARC_FAULT_ONLY')) {
        fresh('baseline-control'); $original = \file_get_contents($target); $mode = \getenv('ARC_FAULT_ONLY');
        $upsert->invoke($instance, $target, 86400); $mode = '';
        check(\file_get_contents($target) === $original, 'failed write keeps the original Apache rules');
        echo "Completed focused baseline control\n";
        return;
    }
    $foreign = "  # unrelated first line\r\nRewriteEngine On\r\n# unrelated trailing spaces  \n";
    fresh('normal', $foreign); $before = \stat($target);
    check($upsert->invoke($instance, $target, 86400) === true, 'create managed block');
    $saved = \file_get_contents($target); $after = \stat($target);
    if (\getenv('ARC_APACHE_FIXTURE')) {
        $export = \getenv('ARC_APACHE_FIXTURE');
        if (!\is_dir($export) || \strpos(\realpath($export), \sys_get_temp_dir() . '/') !== 0) throw new \RuntimeException('Fixture export must be an existing temporary directory');
        \file_put_contents($export . '/.htaccess', $saved);
    }
    check(\strpos($saved, $foreign) === 0, 'unrelated bytes retained exactly');
    check(($before['mode'] & 0777) === ($after['mode'] & 0777) && $before['uid'] === $after['uid'] && $before['gid'] === $after['gid'], 'mode owner and group retained');
    check($upsert->invoke($instance, $target, 86400) === true && \file_get_contents($target) === $saved, 'repeat update is idempotent');
    check($upsert->invoke($instance, $target, 172800) === true && \substr_count(\file_get_contents($target), ManagedCacheRules::BEGIN) === 1, 'replace rather than duplicate managed block');
    check($remove->invoke($instance, $target) === true && \file_get_contents($target) === $foreign, 'remove restores unrelated bytes exactly');
    fresh('absent', null); check($remove->invoke($instance, $target) === true && !\file_exists($target), 'remove absent file does not create it');
    check($upsert->invoke($instance, $target, 86400) === true && (\fileperms($target) & 0777) === 0644, 'create missing file with default permissions');
    foreach (['write','short','close','temp','outside','chmod','rename','read','owner','group'] as $fault) {
        fresh($fault); $original = \file_get_contents($target); $before = \stat($target); $mode = $fault;
        check($upsert->invoke($instance, $target, 86400) === false, $fault . ' reports failure');
        $mode = ''; \clearstatcache(true, $target); $after = \stat($target);
        check(\file_get_contents($target) === $original && $before['uid'] === $after['uid'] && $before['gid'] === $after['gid'] && $before['mode'] === $after['mode'], $fault . ' retains last good file and metadata');
        check(\glob(\dirname($target) . '/.ace-rc-htaccess-*') === [], $fault . ' removes temporary output');
    }
    foreach ($outside as $path) check(!\file_exists($path), 'outside-directory temporary cleaned');
    fresh('malformed', "# untouched\n" . ManagedCacheRules::BEGIN . "\nHeader broken\n"); $original = \file_get_contents($target);
    check($upsert->invoke($instance, $target, 86400) === false && \file_get_contents($target) === $original, 'malformed markers refused without editing');
    fresh('link'); $other = \dirname($target) . '/foreign'; \file_put_contents($other, 'foreign'); \unlink($target); \symlink($other, $target);
    check($upsert->invoke($instance, $target, 86400) === false && \is_link($target) && \file_get_contents($other) === 'foreign', 'target symlink and destination preserved');
    fresh('dangling', null); \symlink(\dirname($target) . '/absent', $target);
    check($remove->invoke($instance, $target) === false && \is_link($target), 'dangling target symlink preserved');
    fresh('lock-link'); $other = \dirname($target) . '/foreign'; \file_put_contents($other, 'foreign'); \symlink($other, \dirname($target) . '/.ace-rc-htaccess.lock'); $original = \file_get_contents($target);
    check($upsert->invoke($instance, $target, 86400) === false && \file_get_contents($target) === $original && \file_get_contents($other) === 'foreign', 'lock symlink refused');
    fresh('busy'); $lock = \fopen(\dirname($target) . '/.ace-rc-htaccess.lock', 'w+'); \flock($lock, LOCK_EX); $original = \file_get_contents($target);
    check($upsert->invoke($instance, $target, 86400) === false && \file_get_contents($target) === $original, 'competing lock does not block or edit'); \flock($lock, LOCK_UN); \fclose($lock);
    fresh('concurrent'); $mode = 'concurrent';
    check($upsert->invoke($instance, $target, 86400) === false && \file_get_contents($target) === "# unrelated concurrent edit\n", 'observed concurrent edit preserved'); $mode = '';
    fresh('deleted'); $mode = 'deleted';
    check($upsert->invoke($instance, $target, 86400) === false && !\file_exists($target), 'observed concurrent deletion preserved'); $mode = '';
    fresh('created', null); $mode = 'created';
    check($upsert->invoke($instance, $target, 86400) === false && \file_get_contents($target) === "# another writer created this\n", 'observed concurrent creation preserved'); $mode = '';
    fresh('readonly'); \file_put_contents(\dirname($target) . '/.ace-rc-htaccess.lock', ''); \chmod(\dirname($target), 0500); $original = \file_get_contents($target);
    if (!\is_writable(\dirname($target))) check($upsert->invoke($instance, $target, 86400) === false && \file_get_contents($target) === $original, 'read-only directory retains last good file');
    else echo "SKIP directory permissions are bypassed by the test user\n";
    \chmod(\dirname($target), 0700);
    if (\function_exists('pcntl_fork')) {
        fresh('concurrent-writers', '0');
        $pids = []; $start = \microtime(true) + 0.15;
        for ($index = 0; $index < 2; ++$index) {
            $pid = \pcntl_fork();
            if ($pid < 0) throw new \RuntimeException('Unable to fork isolated fixture');
            if ($pid === 0) {
                while (\microtime(true) < $start) \usleep(1000);
                $ok = ManagedFile::update($target, static function ($current) { \usleep(100000); return (string) ((int) $current + 1); });
                exit($ok ? 0 : 2);
            }
            $pids[] = $pid;
        }
        $successes = 0;
        foreach ($pids as $pid) {
            \pcntl_waitpid($pid, $status);
            check(\pcntl_wifexited($status) && \in_array(\pcntl_wexitstatus($status), [0, 2], true), 'concurrent writer exits cleanly');
            if (\pcntl_wexitstatus($status) === 0) ++$successes;
        }
        check($successes >= 1 && (int) \file_get_contents($target) === $successes, 'successful concurrent writers do not lose an update');
    } else echo "SKIP pcntl unavailable for concurrent-writer fixture\n";
    echo 'Completed ', $checks, " checks; no hosted database, HTTP, cron or mail used\n";
} finally { clean($root); foreach ($outside as $path) if (\is_file($path)) \unlink($path); }
