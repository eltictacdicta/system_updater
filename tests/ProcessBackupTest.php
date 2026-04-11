<?php

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

class ProcessBackupTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('SYSTEM_UPDATER_PROCESS_BACKUP_BOOTSTRAP_ONLY')) {
            define('SYSTEM_UPDATER_PROCESS_BACKUP_BOOTSTRAP_ONLY', true);
        }

        require_once FS_FOLDER . '/plugins/system_updater/process_backup.php';
    }

    public function testShouldAttemptQueueRecoveryForStalledQueuedJob(): void
    {
        $data = [
            'job_id' => 'backup_job_1',
            'status' => 'queued',
            'timestamp' => time() - (FS_BACKUP_QUEUE_RECOVERY_SECONDS + 2),
            'recovery_attempts' => 0,
        ];

        $this->assertTrue(should_attempt_queue_recovery($data));
    }

    public function testShouldNotAttemptQueueRecoveryBeforeGracePeriod(): void
    {
        $data = [
            'job_id' => 'backup_job_2',
            'status' => 'queued',
            'timestamp' => time() - (FS_BACKUP_QUEUE_RECOVERY_SECONDS - 1),
            'recovery_attempts' => 0,
        ];

        $this->assertFalse(should_attempt_queue_recovery($data));
    }

    public function testShouldNotAttemptQueueRecoveryAfterMaxAttempts(): void
    {
        $data = [
            'job_id' => 'backup_job_3',
            'status' => 'queued',
            'timestamp' => time() - (FS_BACKUP_QUEUE_RECOVERY_SECONDS + 5),
            'recovery_attempts' => FS_BACKUP_MAX_RECOVERY_ATTEMPTS,
        ];

        $this->assertFalse(should_attempt_queue_recovery($data));
    }
}