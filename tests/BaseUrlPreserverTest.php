<?php

namespace Tests\SystemUpdater;

use base_url_preserver;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/system_updater/lib/base_url_preserver.php';

/**
 * Restoring a backup taken on another host must not import that host's base
 * URL: the target server's value (or its absence) is put back afterwards.
 */
class BaseUrlPreserverTest extends TestCase
{
    /** @var list<?string> */
    private array $writes = [];

    public function testTargetValueIsWrittenBackOverTheBackupOne(): void
    {
        $p = $this->preserver(['ok' => true, 'value' => 'https://staging.example']);

        $result = $p->reapply($p->capture());

        $this->assertSame(['https://staging.example'], $this->writes);
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('https://staging.example', $result['message']);
    }

    public function testTargetWithoutValueDropsTheBackupOne(): void
    {
        $p = $this->preserver(['ok' => true, 'value' => null]);

        $p->reapply($p->capture());

        $this->assertSame([null], $this->writes);
    }

    public function testEmptyStoredValueCountsAsAbsent(): void
    {
        $p = $this->preserver(['ok' => true, 'value' => '']);

        $p->reapply($p->capture());

        $this->assertSame([null], $this->writes);
    }

    public function testUnknownStateKeepsTheBackupValue(): void
    {
        $p = $this->preserver(['ok' => false, 'value' => null]);

        $result = $p->reapply($p->capture());

        $this->assertSame([], $this->writes);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('se mantiene la de la copia', $result['message']);
    }

    public function testStateNeverCapturedKeepsTheBackupValue(): void
    {
        $p = $this->preserver(['ok' => true, 'value' => 'https://a.example']);

        $this->assertFalse($p->reapply(null)['ok']);
        $this->assertSame([], $this->writes);
    }

    public function testReadThatThrowsIsUnknownNotFatal(): void
    {
        $p = new base_url_preserver(
            static function (): array {
                throw new \RuntimeException('db down');
            },
            function (?string $v): bool {
                $this->writes[] = $v;
                return true;
            }
        );

        $state = $p->capture();
        $p->reapply($state);

        $this->assertFalse($state['known']);
        $this->assertSame([], $this->writes);
    }

    public function testFailedWriteIsReportedNotFatal(): void
    {
        $p = new base_url_preserver(
            static fn (): array => ['ok' => true, 'value' => 'https://a.example'],
            static function (?string $v): bool {
                throw new \RuntimeException('write failed');
            }
        );

        $result = $p->reapply($p->capture());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No se pudo conservar', $result['message']);
    }

    public function testRestoreDatabaseWrapsTheRestoreWithThePreserver(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/plugins/system_updater/lib/backup_manager.php');
        $capture = strpos($source, '$baseUrlState = $baseUrl->capture();');
        $restore = strpos($source, '$result = $this->execute_database_restore($backupPath, $reportProgress, $result);');
        $reapply = strpos($source, '$baseUrl->reapply($baseUrlState)');

        $this->assertNotFalse($capture);
        $this->assertNotFalse($restore);
        $this->assertNotFalse($reapply);
        $this->assertTrue($capture < $restore && $restore < $reapply, 'capture before the restore, reapply after it');
    }

    public function testSteppedRestoreCapturesInPrepareAndReappliesInCleanup(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/plugins/system_updater/lib/backup_manager.php');
        $prepare = strpos($source, 'private function restore_step_prepare(');
        $capture = strpos($source, "\$state['base_url'] = \$this->base_url_preserver()->capture();");
        $decompress = strpos($source, 'private function restore_step_decompress(');
        $cleanup = strpos($source, 'private function restore_step_cleanup(');
        $reapply = strpos($source, "\$this->base_url_preserver()->reapply(\$state['base_url'])");

        $this->assertNotFalse($capture);
        $this->assertNotFalse($reapply);
        $this->assertTrue($prepare < $capture && $capture < $decompress, 'captured in prepare, before any database phase');
        $this->assertTrue($cleanup < $reapply, 'reapplied in cleanup');
    }

    private function preserver(array $state): base_url_preserver
    {
        return new base_url_preserver(
            static fn (): array => $state,
            function (?string $v): bool {
                $this->writes[] = $v;
                return true;
            }
        );
    }
}
