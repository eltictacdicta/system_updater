<?php

namespace Tests\SystemUpdater;

use backup_manager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

if (!class_exists(backup_manager::class)) {
    require_once FS_FOLDER . '/plugins/system_updater/lib/backup_manager.php';
}

class BackupManagerRestoreTest extends TestCase
{
    public function testBackupManagerExposesDatabaseSourceResolver(): void
    {
        $file = FS_FOLDER . '/plugins/system_updater/lib/backup_manager.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString('function resolve_database_backup_source', $content);
        $this->assertStringContainsString('function execute_database_restore', $content);
    }

    public function testRestoreDatabaseUsesResolvedSource(): void
    {
        $file = FS_FOLDER . '/plugins/system_updater/lib/backup_manager.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString('$this->resolve_database_backup_source($backupFile)', $content);
        $this->assertStringContainsString('$this->execute_database_restore($backupPath, $reportProgress, $result)', $content);
    }

    public function testGroupedBackupsExposeDatabaseRestoreMetadata(): void
    {
        $file = FS_FOLDER . '/plugins/system_updater/lib/backup_manager.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString("'can_restore_database'", $content);
        $this->assertStringContainsString("'database_restore_file'", $content);
    }

    public function testAdminUpdaterTemplateShowsDatabaseRestoreForCompleteBackups(): void
    {
        $file = FS_FOLDER . '/plugins/system_updater/view/admin_updater.html.twig';
        $content = file_get_contents($file);

        $this->assertStringContainsString('group.can_restore_database', $content);
        $this->assertStringContainsString('group.database_restore_file', $content);
        $this->assertStringContainsString('data-action="restore_database"', $content);
        $this->assertStringContainsString('Restaurar solo base de datos', $content);
    }

    public function testRecoveryTemplateSupportsDatabaseOnlyRestore(): void
    {
        $file = FS_FOLDER . '/plugins/system_updater/recovery.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString("data-type=\"database\"", $content);
        $this->assertStringContainsString('Restaurar BD', $content);
        $this->assertStringContainsString("type === 'database'", $content);
    }

    public function testProcessRestoreUsesBackupManagerPath(): void
    {
        $file = FS_FOLDER . '/plugins/system_updater/process_restore.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString('get_backup_path()', $content);
        $this->assertStringNotContainsString("FS_FOLDER . '/backups/'", $content);
    }

    public function testProcessRestoreSupportsDatabaseType(): void
    {
        $file = FS_FOLDER . '/plugins/system_updater/process_restore.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString("elseif (\$restoreType === 'database')", $content);
        $this->assertStringContainsString('$backupManager->restore_database($file, $progressCallback)', $content);
    }

    public function testRestoreFilesPreservesHostHtaccessButRestoresSubdirs(): void
    {
        $source = sys_get_temp_dir() . '/fs_restore_src_' . uniqid('', true);
        $dest = sys_get_temp_dir() . '/fs_restore_dst_' . uniqid('', true);
        mkdir($source . '/sub', 0755, true);
        mkdir($dest, 0755, true);

        // Host-specific at root: must NOT be overwritten by a restore.
        file_put_contents($source . '/config.php', 'host db creds');
        file_put_contents($source . '/.htaccess', 'host rewrite rules');
        // App content: must be restored, including subdirectory .htaccess.
        file_put_contents($source . '/index.php', 'app');
        file_put_contents($source . '/sub/.htaccess', 'plugin rules');
        file_put_contents($source . '/sub/file.txt', 'content');

        try {
            $manager = new backup_manager($dest);
            $method = new ReflectionMethod(backup_manager::class, 'copy_directory_with_progress');
            $method->setAccessible(true);
            $method->invoke($manager, $source, $dest, array('config.php', '.htaccess'), null, 0, 100);

            // Root host-specific files are preserved (skipped).
            $this->assertFileDoesNotExist($dest . '/config.php');
            $this->assertFileDoesNotExist($dest . '/.htaccess');
            // App content is restored...
            $this->assertFileExists($dest . '/index.php');
            $this->assertFileExists($dest . '/sub/file.txt');
            // ...and a subdirectory .htaccess is NOT treated as host-specific.
            $this->assertFileExists($dest . '/sub/.htaccess');
            $this->assertSame('plugin rules', file_get_contents($dest . '/sub/.htaccess'));
        } finally {
            $this->removeDir($source);
            $this->removeDir($dest);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
