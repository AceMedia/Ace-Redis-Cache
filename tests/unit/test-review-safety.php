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
    public function testStringHelperMatchesNativeEmptyPrefixBehaviour() {
        $this->assertTrue(StringHelper::starts_with('abc', ''));
        $this->assertTrue(StringHelper::starts_with('abc', 'ab'));
        $this->assertFalse(StringHelper::starts_with('abc', 'bc'));
    }
}
