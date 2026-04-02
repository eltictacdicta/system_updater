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
}