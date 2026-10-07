<?php
/**
 * Tests básicos para CoreUpdater - limpieza de archivos del núcleo.
 */

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

class CoreUpdaterTest extends TestCase
{
    private string $tempDir;
    private \core_updater $updater;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/fs_test_' . uniqid();

        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }

        file_put_contents($this->tempDir . '/base_test.php', '<?php echo "test";');
        file_put_contents($this->tempDir . '/controller_test.php', '<?php echo "test";');

        require_once FS_FOLDER . '/plugins/system_updater/lib/core_updater.php';

        $this->updater = new \core_updater($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->deleteDirectoryRecursive($this->tempDir);
        }
    }

    private function deleteDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectoryRecursive($path) : unlink($path);
        }

        rmdir($dir);
    }

    public function testCoreUpdaterCanBeInstantiated(): void
    {
        $this->assertInstanceOf(\core_updater::class, $this->updater);
    }

    public function testCleanupRootDirectoriesMethodExists(): void
    {
        $this->assertTrue(method_exists($this->updater, 'cleanupRootDirectories'));
    }

    public function testSyncBundledPluginsMethodExists(): void
    {
        $this->assertTrue(method_exists($this->updater, 'syncBundledPlugins'));
    }

    public function testGetInstalledCoreVersionMethodExists(): void
    {
        $this->assertTrue(method_exists($this->updater, 'getInstalledCoreVersion'));
        $version = $this->updater->getInstalledCoreVersion();
        $this->assertIsString($version);
    }

    /**
     * Regression: a core update must not overwrite the operator's .htaccess.
     * The distributed .htaccess can carry 'Options' directives that some hosts
     * (e.g. Plesk) reject with HTTP 500, so it must stay host-specific and be
     * excluded from the copy, like config.php.
     */
    public function testHostSpecificHtaccessIsExcludedFromCoreCopy(): void
    {
        $method = new \ReflectionMethod($this->updater, 'coreRootCopyExcludes');
        $method->setAccessible(true);

        $excludes = $method->invoke($this->updater);

        $this->assertContains('config.php', $excludes);
        $this->assertContains('.htaccess', $excludes);
    }

    public function testCopyDoesNotOverwriteExistingHtaccess(): void
    {
        $source = $this->tempDir . '/source';
        $dest = $this->tempDir . '/dest';
        mkdir($source);
        mkdir($dest);

        file_put_contents($source . '/index.php', '<?php // new core');
        file_put_contents($source . '/.htaccess', "Options +FollowSymLinks\n");
        file_put_contents($dest . '/.htaccess', "# operator htaccess\n");
        file_put_contents($dest . '/index.php', '<?php // old core');

        $excludesMethod = new \ReflectionMethod($this->updater, 'coreRootCopyExcludes');
        $excludesMethod->setAccessible(true);

        $copyMethod = new \ReflectionMethod($this->updater, 'copyDirectorySelective');
        $copyMethod->setAccessible(true);
        $copyMethod->invoke($this->updater, $source, $dest, $excludesMethod->invoke($this->updater));

        $this->assertSame(
            "# operator htaccess\n",
            file_get_contents($dest . '/.htaccess'),
            'Existing .htaccess must be preserved on update'
        );
        $this->assertSame(
            '<?php // new core',
            file_get_contents($dest . '/index.php'),
            'Non-excluded files must still be copied'
        );
    }
}