<?php

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

class ProcessBackupTest extends TestCase
{
    public function testProcessBackupFileExists(): void
    {
        $file = FS_FOLDER . '/plugins/system_updater/process_backup.php';
        $this->assertFileExists($file);
    }

    public function testProcessBackupHasNoSyntaxErrors(): void
    {
        $file = FS_FOLDER . '/plugins/system_updater/process_backup.php';
        $output = [];
        $status = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, 'process_backup.php has syntax errors: ' . implode("\n", $output));
    }

    public function testProcessBackupUsesSseBootstrap(): void
    {
        $file = FS_FOLDER . '/plugins/system_updater/process_backup.php';
        $content = file_get_contents($file);
        $this->assertStringContainsString("system_updater_process_init(['mode' => 'sse'", $content);
    }

    public function testProcessBackupDoesNotContainWorkerMachinery(): void
    {
        $file = FS_FOLDER . '/plugins/system_updater/process_backup.php';
        $content = file_get_contents($file);
        $this->assertStringNotContainsString('launch_cli_worker', $content);
        $this->assertStringNotContainsString('recover_queued_job', $content);
        $this->assertStringNotContainsString('should_attempt_queue_recovery', $content);
        $this->assertStringNotContainsString('has_active_job', $content);
        $this->assertStringNotContainsString('FS_BACKUP_STALE_SECONDS', $content);
        $this->assertStringNotContainsString('FS_BACKUP_QUEUE_RECOVERY_SECONDS', $content);
        $this->assertStringNotContainsString('FS_BACKUP_MAX_RECOVERY_ATTEMPTS', $content);
    }
}
