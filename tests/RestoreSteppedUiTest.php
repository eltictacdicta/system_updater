<?php

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

/**
 * La UI de restauración debe usar el flujo por pasos (begin + chunk) y no un
 * EventSource único, que no sobrevive a un kill del host.
 */
class RestoreSteppedUiTest extends TestCase
{
    private function viewSource(): string
    {
        return (string) file_get_contents(FS_FOLDER . '/plugins/system_updater/view/admin_updater.html.twig');
    }

    public function testRestoreFlowStartsWithBeginAndContinuesWithChunk(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString("runRestoreStep('begin')", $source);
        $this->assertStringContainsString("runRestoreStep('chunk')", $source);
    }

    public function testRestoreNoLongerUsesEventSource(): void
    {
        $this->assertStringNotContainsString(
            'restoreEventSource',
            $this->viewSource(),
            'el flujo de restauración ya no debe abrir un EventSource'
        );
    }

    public function testBackupAndCoreUpdateKeepTheirOwnStreams(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString('backupEventSource', $source);
        $this->assertStringContainsString('coreUpdateEventSource', $source);
    }

    public function testParsesSseFramingFromFetchBody(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString('function parseRestoreSseEvents(body)', $source);
        $this->assertStringContainsString("indexOf('event:')", $source);
        $this->assertStringContainsString("indexOf('data:')", $source);
    }

    public function testRetriesOnTransportFailureToResumeFromCheckpoint(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString('restoreConsecutiveFailures', $source);
        $this->assertStringContainsString('último punto guardado', $source);
    }

    public function testFinishesWithSuccessOrErrorHelpers(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString('function finishRestoreAsSuccess()', $source);
        $this->assertStringContainsString('function finishRestoreAsError(', $source);
        $this->assertStringContainsString("\$('#restoreCompleteBtn').show()", $source);
        $this->assertStringContainsString("\$('#restoreErrorBtn').show()", $source);
    }
}
