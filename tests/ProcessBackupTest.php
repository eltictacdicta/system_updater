<?php

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/system_updater/lib/process_bootstrap.php';
require_once FS_FOLDER . '/plugins/system_updater/lib/backup_manager.php';

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

    public function testProcessBackupFinishesResponseBeforeZipAndSupportsStatusPolling(): void
    {
        $file = FS_FOLDER . '/plugins/system_updater/process_backup.php';
        $content = file_get_contents($file);
        $this->assertStringContainsString('system_updater_finish_response', $content);
        $this->assertStringContainsString("case 'status':", $content);
        $this->assertStringContainsString("case 'progress':", $content);
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

    public function testWorkerLivenessTrustsDefinitivePidProbe(): void
    {
        $old = ['pid' => 4242, 'timestamp' => 0];
        $this->assertTrue(system_updater_worker_is_alive($old, fn () => true));
        $this->assertFalse(system_updater_worker_is_alive(['pid' => 4242, 'timestamp' => time()], fn () => false));
    }

    public function testWorkerLivenessFallsBackToSnapshotAgeWhenPidCannotBeProbed(): void
    {
        // open_basedir hides /proc and posix is disabled: must not answer "alive" forever.
        $unknown = fn () => null;
        $hiddenProc = '/nonexistent-proc';
        $now = 1_000_000;
        $this->assertTrue(system_updater_worker_is_alive(['pid' => 4242, 'timestamp' => $now - 60], $unknown, $hiddenProc, $now));
        $this->assertFalse(system_updater_worker_is_alive(['pid' => 4242, 'timestamp' => $now - 601], $unknown, $hiddenProc, $now));
        $this->assertFalse(system_updater_worker_is_alive(['timestamp' => $now - 601], null, $hiddenProc, $now));
    }

    public function testWorkerLivenessUsesProcWhenReadable(): void
    {
        $proc = sys_get_temp_dir() . '/su_proc_' . getmypid();
        @mkdir($proc . '/4242', 0755, true);
        try {
            $this->assertTrue(system_updater_worker_is_alive(['pid' => 4242, 'timestamp' => 0], fn () => null, $proc));
            $this->assertFalse(system_updater_worker_is_alive(['pid' => 4243, 'timestamp' => time()], fn () => null, $proc));
        } finally {
            @rmdir($proc . '/4242');
            @rmdir($proc);
        }
    }

    public function testListBackupsIgnoresUnfinishedLibzipTempFiles(): void
    {
        $dir = sys_get_temp_dir() . '/su_backups_' . getmypid();
        @mkdir($dir, 0755, true);
        $names = ['h_2026_db.sql.gz', 'h_2026_files.zip', 'h_2026_complete.zip', 'h_2026_files.zip.nyg1iu.part', 'h_2026_files.zip.Ab12Cd'];
        foreach ($names as $n) {
            file_put_contents($dir . '/' . $n, 'x');
        }
        try {
            $manager = (new \ReflectionClass(\backup_manager::class))->newInstanceWithoutConstructor();
            $prop = new \ReflectionProperty(\backup_manager::class, 'backupPath');
            $prop->setAccessible(true);
            $prop->setValue($manager, $dir);

            $listed = array_column($manager->list_backups(), 'name');
            sort($listed);
            $this->assertSame(['h_2026_complete.zip', 'h_2026_db.sql.gz', 'h_2026_files.zip'], $listed);
        } finally {
            foreach ($names as $n) {
                @unlink($dir . '/' . $n);
            }
            @rmdir($dir);
        }
    }

    public function testHeartbeatStepsAreDeclaredDeadAfterTwoMinutes(): void
    {
        $now = 1_000_000;
        $unknown = fn () => null;
        $this->assertFalse(system_updater_worker_is_alive(['step' => 'files_closing', 'pid' => 1, 'timestamp' => $now - 121], $unknown, '/nonexistent-proc', $now));
        $this->assertTrue(system_updater_worker_is_alive(['step' => 'db_export', 'pid' => 1, 'timestamp' => $now - 121], $unknown, '/nonexistent-proc', $now));
    }

    public function testZipCloseReportsHeartbeatProgress(): void
    {
        $dir = sys_get_temp_dir() . '/su_hb_' . getmypid();
        @mkdir($dir, 0755, true);
        $zip = new \ZipArchive();
        $zip->open($dir . '/x.zip', \ZipArchive::CREATE);
        $zip->addFromString('a', random_bytes(200000));
        $calls = [];
        $manager = (new \ReflectionClass(\backup_manager::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(\backup_manager::class, 'register_close_heartbeat');
        $m->setAccessible(true);
        $m->invoke($manager, $zip, function ($step, $msg, $pct) use (&$calls) { $calls[] = $step; }, 'files_closing', 'Finalizando', 88);
        $zip->close();
        @unlink($dir . '/x.zip');
        @rmdir($dir);
        $this->assertNotEmpty($calls);
        $this->assertSame(['files_closing'], array_values(array_unique($calls)));
    }

    public function testRemovesOnlyStaleLibzipTempFiles(): void
    {
        $dir = sys_get_temp_dir() . '/su_tmpclean_' . getmypid();
        @mkdir($dir, 0755, true);
        $old = ['h_files.zip.nyg1iu.part', 'h_complete.zip.Ab12Cd'];
        $keep = ['h_files.zip', 'h_db.sql.gz', 'h_files.zip.fresh1.part'];
        foreach (array_merge($old, $keep) as $n) {
            file_put_contents($dir . '/' . $n, 'x');
        }
        foreach (array_merge($old, ['h_files.zip', 'h_db.sql.gz']) as $n) {
            touch($dir . '/' . $n, time() - 7200);
        }
        try {
            $manager = (new \ReflectionClass(\backup_manager::class))->newInstanceWithoutConstructor();
            $prop = new \ReflectionProperty(\backup_manager::class, 'backupPath');
            $prop->setAccessible(true);
            $prop->setValue($manager, $dir);

            $this->assertSame(2, $manager->remove_stale_zip_temp_files());
            $left = array_values(array_diff(scandir($dir), ['.', '..']));
            sort($left);
            sort($keep);
            $this->assertSame($keep, $left);
        } finally {
            array_map('unlink', glob($dir . '/*'));
            @rmdir($dir);
        }
    }
}
