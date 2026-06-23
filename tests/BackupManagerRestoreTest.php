<?php

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

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

    public function testProcessRestoreSupportsDatabaseType(): void
    {
        $file = FS_FOLDER . '/plugins/system_updater/process_restore.php';
        $content = file_get_contents($file);

        $this->assertStringContainsString("elseif (\$restoreType === 'database')", $content);
        $this->assertStringContainsString('$backupManager->restore_database($file, $progressCallback)', $content);
    }
}
