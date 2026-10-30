<?php
use PHPUnit\Framework\TestCase;
use AceMedia\RedisCache\DropinManager;
use AceMedia\RedisCache\StringHelper;

class ReviewSafetyTest extends TestCase {
    private $dir;
    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/arc-owned-' . bin2hex(random_bytes(8));
        mkdir($this->dir);
    }
    protected function tearDown(): void {
        foreach (glob($this->dir . '/*') as $file) unlink($file);
        rmdir($this->dir);
    }
    public function testOnlyOwnedDropinsCanBeInstalledUpdatedAndRemoved() {
        $source = $this->dir . '/source.php';
        $target = $this->dir . '/object-cache.php';
        file_put_contents($source, '<?php /* Ace Redis Cache Drop-In */ // version one');
        $this->assertTrue(DropinManager::manage($source, $target, 'Ace Redis Cache Drop-In', true));
        $this->assertSame(file_get_contents($source), file_get_contents($target));
        file_put_contents($source, '<?php /* Ace Redis Cache Drop-In */ // version two');
        $this->assertTrue(DropinManager::manage($source, $target, 'Ace Redis Cache Drop-In', true));
        $this->assertSame(file_get_contents($source), file_get_contents($target));
        $this->assertTrue(DropinManager::manage($source, $target, 'Ace Redis Cache Drop-In', false));
        $this->assertFileDoesNotExist($target);
    }
    public function testForeignFilesAndSymlinksArePreserved() {
        $source = $this->dir . '/source.php';
        $target = $this->dir . '/object-cache.php';
        file_put_contents($source, '<?php /* Ace Redis Cache Drop-In */');
        file_put_contents($target, '<?php /* another cache */');
        foreach ([true, false] as $enabled) {
            $this->assertFalse(DropinManager::manage($source, $target, 'Ace Redis Cache Drop-In', $enabled));
            $this->assertSame('<?php /* another cache */', file_get_contents($target));
        }
        unlink($target);
        symlink($source, $target);
        foreach ([true, false] as $enabled) {
            $this->assertFalse(DropinManager::manage($source, $target, 'Ace Redis Cache Drop-In', $enabled));
            $this->assertTrue(is_link($target));
        }
    }
    public function testDanglingSymlinkIsPreserved() {
        $target = $this->dir . '/advanced-cache.php';
        symlink($this->dir . '/missing.php', $target);
        $this->assertFalse(DropinManager::manage('missing-source', $target, 'signature', true));
        $this->assertTrue(is_link($target));
    }
    public function testFailedInstallCanBeRetried() {
        $source = $this->dir . '/source.php';
        $target = $this->dir . '/object-cache.php';
        $this->assertFalse(DropinManager::manage($source, $target, 'Ace Redis Cache Drop-In', true));
        file_put_contents($source, '<?php /* Ace Redis Cache Drop-In */');
        $this->assertTrue(DropinManager::manage($source, $target, 'Ace Redis Cache Drop-In', true));
    }
    public function testAjaxRejectsNestedAndNonArraySettings() {
        $class = new ReflectionClass(\AceMedia\RedisCache\AdminAjax::class);
        $instance = $class->newInstanceWithoutConstructor();
        $method = $class->getMethod('validate_settings');
        $method->setAccessible(true);
        $this->assertFalse($method->invoke($instance, 'not an object'));
        $this->assertFalse($method->invoke($instance, ['host' => ['bad']]));
        $this->assertTrue($class->hasMethod('get_manage_capability'));
    }
    public function testVersionUpgradeRefreshesExistingFilesWithoutInstallingMissingFiles() {
        $plugin = dirname(__DIR__, 2);
        $target = $this->dir . '/object-cache.php';
        file_put_contents($target, '<?php /* Ace Redis Cache Drop-In */ // old');
        $this->assertTrue(DropinManager::refresh_existing($plugin, $this->dir));
        $this->assertSame(file_get_contents($plugin . '/assets/dropins/object-cache.php'), file_get_contents($target));
        $this->assertFileDoesNotExist($this->dir . '/advanced-cache.php');
        file_put_contents($target, '<?php /* other cache */');
        $this->assertTrue(DropinManager::refresh_existing($plugin, $this->dir));
        $this->assertSame('<?php /* other cache */', file_get_contents($target));
    }
    public function testCanonicalAuthorityIncludesPortAndRejectsPoisonedHosts() {
        foreach ([
            ['http://127.0.0.1:9484', '127.0.0.1:9484', true],
            ['https://example.test', 'EXAMPLE.TEST:443', true],
            ['https://example.test', 'example.test:444', false],
            ['https://example.test', 'attacker.test', false],
            ['https://example.test', 'example.test@attacker.test', false],
            ['https://example.test', 'example.test/path', false],
            ['http://[::1]:9484', '[::1]:9484', true],
        ] as $case) {
            $this->assertSame($case[2], \AceMedia\RedisCache\AceRedisCache::request_matches_home_authority($case[0], $case[1]));
        }
    }
    public function testMissingModernBlockApisRenderLive() {
        $this->assertFalse(\AceMedia\RedisCache\BlockCache::runtime_available());
        $reflection = new ReflectionClass(\AceMedia\RedisCache\BlockCache::class);
        $block = $reflection->newInstanceWithoutConstructor();
        $this->assertNull($block->pre_render(null, ['blockName' => 'core/query']));
    }
    public function testStringHelperMatchesNativeEmptyPrefixBehaviour() {
        $this->assertTrue(StringHelper::starts_with('abc', ''));
        $this->assertTrue(StringHelper::starts_with('abc', 'ab'));
        $this->assertFalse(StringHelper::starts_with('abc', 'bc'));
    }
}
