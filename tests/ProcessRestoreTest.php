<?php

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

/**
 * Contrato del endpoint de restauración por pasos.
 */
class ProcessRestoreTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(FS_FOLDER . '/plugins/system_updater/process_restore.php');
    }

    private function bootstrapSource(): string
    {
        return (string) file_get_contents(FS_FOLDER . '/plugins/system_updater/lib/process_bootstrap.php');
    }

    public function testFileExistsAndHasNoSyntaxErrors(): void
    {
        $file = FS_FOLDER . '/plugins/system_updater/process_restore.php';
        $this->assertFileExists($file);

        $output = [];
        $status = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, 'process_restore.php tiene errores de sintaxis: ' . implode("\n", $output));
    }

    public function testInstallsFatalErrorLogger(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("require_once __DIR__ . '/lib/debug_log.php'", $source);
        $this->assertStringContainsString('system_updater_debug_install_shutdown()', $source);
        $this->assertStringContainsString("system_updater_debug_log('RESTORE'", $source);
    }

    public function testUsesSseBootstrapWithRestorePrefix(): void
    {
        $this->assertStringContainsString(
            "system_updater_process_init(['mode' => 'sse', 'progress_prefix' => 'fs_restore'])",
            $this->source()
        );
    }

    public function testDeclaresTheSteppedActions(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("case 'begin':", $source);
        $this->assertStringContainsString("case 'chunk':", $source);
    }

    public function testKeepsTheLegacyActions(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("case 'start':", $source);
        $this->assertStringContainsString("case 'progress':", $source);
        $this->assertStringContainsString("case 'status':", $source);
    }

    public function testChunkEnforcesCsrfExplicitly(): void
    {
        $chunk = substr($this->source(), (int) strpos($this->source(), "case 'chunk':"));

        $this->assertStringContainsString(
            'ensure_request_csrf()',
            $chunk,
            'chunk muta estado: no puede depender de la validación del bootstrap'
        );
    }

    public function testBeginIsAuthenticatedByTheBootstrap(): void
    {
        $this->assertStringContainsString(
            "\$action === 'start' || \$action === 'begin'",
            $this->bootstrapSource(),
            "begin debe recibir sesión autenticada + CSRF como start"
        );
    }

    public function testMaintenanceLockIsHealedAndHeartbeatRefreshed(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('system_updater_heal_stale_restore_lock()', $source);
        $this->assertStringContainsString('system_updater_refresh_restore_heartbeat()', $source);
        $this->assertStringContainsString("'source' => 'system_updater.restore'", $source);
    }

    public function testMaintenanceIsReleasedOnSuccessAndFailure(): void
    {
        $this->assertSame(
            4,
            substr_count($this->source(), 'system_updater_end_maintenance()'),
            'el lock debe liberarse al iniciar mal, al terminar, al fallar un paso y en el finally del path legacy'
        );
    }

    public function testSteppedFlowDelegatesToTheResumableEngine(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('restore_session_start(', $source);
        $this->assertStringContainsString('restore_session_step(', $source);
        $this->assertStringContainsString('restore_session_load(', $source);
        $this->assertStringContainsString('system_updater_restore_budget()', $source);
    }
}
