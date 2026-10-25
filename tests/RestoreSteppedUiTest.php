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

        $this->assertStringContainsString('function finishRestoreAsSuccess(warningCount)', $source);
        $this->assertStringContainsString('function finishRestoreAsError(', $source);
        $this->assertStringContainsString("\$('#restoreCompleteBtn').show()", $source);
        $this->assertStringContainsString("\$('#restoreErrorBtn').show()", $source);
    }

    public function testDistingueErroresDeExitoAlTerminar(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString('doneData.errors_count', $source);
        $this->assertStringContainsString('doneData.warnings_count', $source);
    }

    public function testEsperaCuandoOtroProcesoTieneElLock(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString("evt.data.step === 'busy'", $source);
        $this->assertStringContainsString("}, 2000);", $source);
    }

    public function testMuestraElDetalleDeErroresYNoSoloElContador(): void
    {
        $source = $this->viewSource();

        $this->assertStringContainsString('function logRestoreDetail(payload)', $source);
        $this->assertStringContainsString('payload.errors', $source);
        $this->assertStringContainsString('payload.warnings', $source);
        $this->assertStringContainsString('logRestoreDetail(doneData);', $source);
        $this->assertStringContainsString('logRestoreDetail(errorData);', $source);
    }

    // ============================================================
    // BA-02 — a transient denial during a restore is not a failure
    // ============================================================

    /**
     * The restore rewrites the database, `fs_users` included, and every
     * `progress` poll re-reads that row to confirm the administrator flag
     * (AUTH-03, fail-closed). A poll served inside that window is therefore
     * *expected* to be denied, and the client has to treat it as retryable:
     * retry with backoff, keep showing progress, and never present it as a
     * failed restore.
     *
     * Because this suite has no JavaScript runner (a recorded limitation of
     * the change), the policy is asserted against the template source — the
     * same seam the rest of this file already uses.
     */
    public function testTransientDenialDuringRestoreIsRetriedNotReportedAsFailure(): void
    {
        $catchBlock = $this->restoreCatchBlock();

        $this->assertNotSame('', $catchBlock, 'premise: the restore drive has a failure branch');
        $this->assertStringContainsString('restoreConsecutiveFailures++', $catchBlock);
        $this->assertStringContainsString(
            'restoreConsecutiveFailures <= 5',
            $catchBlock,
            'a bounded retry budget must exist'
        );

        // Everything before the terminal helper is the transient path: it must
        // retry and return, leaving the modal on its current progress step.
        $terminalAt = strpos($catchBlock, 'finishRestoreAsError');
        $this->assertNotFalse($terminalAt, 'premise: the exhausted budget terminates');

        $transient = substr($catchBlock, 0, (int) $terminalAt);
        $this->assertStringContainsString(
            'restoreConsecutiveFailures <= 5',
            $transient,
            'the retry budget must be decided before the terminal error'
        );
        $this->assertStringContainsString("runRestoreStep('chunk')", $transient, 'the retry resumes the drive');
        $this->assertStringContainsString('return;', $transient, 'the transient branch must not fall through');
    }

    /**
     * The restore keeps running server-side under `ignore_user_abort`, so the
     * client must never send a cancel/abort request: doing so cannot stop the
     * work, it only loses the ability to observe it.
     */
    public function testRestoreDriveIssuesNoAbortRequest(): void
    {
        $source = $this->viewSource();

        $this->assertStringNotContainsString('AbortController', $source);
        $this->assertStringNotContainsString('.abort()', $source);
        $this->assertStringNotContainsString('action=abort', $source);
    }

    /**
     * BA-02 second scenario: after the retry budget is spent the denial is
     * permanent, so the UI has to say the session must be re-established — and
     * must not claim a successful restore it cannot verify.
     */
    public function testExhaustedRetryBudgetAsksForANewSessionWithoutClaimingSuccess(): void
    {
        $catchBlock = $this->restoreCatchBlock();
        $terminalAt = strpos($catchBlock, 'finishRestoreAsError');

        $this->assertNotFalse($terminalAt, 'premise: the exhausted budget terminates');
        $terminal = substr($catchBlock, (int) $terminalAt);

        $this->assertStringContainsString(
            'Vuelve a iniciar sesión',
            $terminal,
            'a permanent denial must tell the operator to re-establish the session'
        );
        $this->assertStringContainsString(
            'No se puede confirmar',
            $terminal,
            'unverified evidence must not be reported as a completed restore'
        );
        $this->assertStringNotContainsString(
            'El avance queda guardado',
            $terminal,
            'the client cannot verify server-side progress, so it must not assert it'
        );
    }

    /**
     * The failure branch of `runRestoreStep()`, from `.catch(` to the next
     * top-level function. The template is plain JS in a Twig file, so the
     * boundary is textual.
     */
    private function restoreCatchBlock(): string
    {
        $source = $this->viewSource();

        $start = strpos($source, '.catch(function(error)');
        if ($start === false) {
            return '';
        }

        $end = strpos($source, 'function parseRestoreSseEvents', $start);

        return substr($source, $start, $end === false ? null : $end - $start);
    }
}
