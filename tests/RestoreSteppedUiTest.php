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
        $budgetAt = strpos($catchBlock, 'restoreConsecutiveFailures <= 5');
        $this->assertNotFalse($budgetAt, 'a bounded retry budget must exist');

        // Everything before the budget's terminal helper is the transient path:
        // it must retry and return, leaving the modal on its current progress
        // step. The classification of a rejected request sits before the budget
        // is charged, so the region is anchored on the budget itself.
        $terminalAt = strpos($catchBlock, 'finishRestoreAsError', (int) $budgetAt);
        $this->assertNotFalse($terminalAt, 'premise: the exhausted budget terminates');

        $transient = substr($catchBlock, 0, (int) $terminalAt);
        $this->assertStringContainsString(
            'restoreConsecutiveFailures <= 5',
            $transient,
            'the retry budget must be decided before the terminal error'
        );
        $this->assertStringContainsString(
            'runRestoreStep(retryAction)',
            $transient,
            'the retry re-issues the action that failed, not a fixed one'
        );
        $this->assertStringContainsString('return;', $transient, 'the transient branch must not fall through');
    }

    /**
     * The retry sends the action that was in flight. A `begin` that never
     * produced progress left no restore state on disk, so retrying it as
     * `chunk` asks the server to advance something that does not exist.
     */
    public function testTheRetryReissuesTheActionInFlightUntilProgressExists(): void
    {
        $source = $this->viewSource();
        $catchBlock = $this->restoreCatchBlock();

        $this->assertStringContainsString(
            'restoreArmed',
            $source,
            'the drive has to remember whether begin already produced progress'
        );
        $this->assertStringContainsString(
            'var retryAction = restoreArmed ? ',
            $catchBlock,
            'the action to retry is derived from that state, not hardcoded'
        );
        $this->assertStringContainsString(
            'runRestoreStep(retryAction)',
            $catchBlock,
            'and the transient branch issues it'
        );
    }

    /**
     * A 4xx is the session or CSRF guard refusing the request. It is terminal:
     * retrying it spends the budget on an answer that is already decided, and
     * reporting it as a cut sends the operator to look at connectivity instead
     * of at the session that has to be re-established.
     */
    public function testASessionRejectionIsTerminalAndNamesTheRealCause(): void
    {
        $catchBlock = $this->restoreCatchBlock();

        $denialAt = strpos($catchBlock, 'status >= 400 && status < 500');
        $budgetAt = strpos($catchBlock, 'restoreConsecutiveFailures++');

        $this->assertNotFalse($denialAt, 'premise: a 4xx is classified');
        $this->assertNotFalse($budgetAt, 'premise: there is a retry budget');
        $this->assertLessThan(
            (int) $budgetAt,
            (int) $denialAt,
            'the rejection must be decided before the retry budget is charged'
        );

        $denial = substr($catchBlock, (int) $denialAt, (int) $budgetAt - (int) $denialAt);

        $this->assertStringContainsString('sesión', $denial, 'the message names the real cause');
        $this->assertStringContainsString('Vuelve a iniciar sesión', $denial);
        $this->assertStringContainsString('No se puede confirmar', $denial);
        $this->assertStringNotContainsString('red', $denial, 'a rejection is not a network failure');
        $this->assertStringNotContainsString('Corte', $denial);
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
