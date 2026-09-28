<?php
/**
 * Procesador de Restauración con Progreso - Plugin system_updater
 * 
 * Este script maneja la restauración de backups con reporte de progreso en tiempo real
 * usando Server-Sent Events (SSE).
 * 
 * Endpoints:
 * - action=start: restauración completa en un solo request (legacy)
 * - action=begin: inicia la restauración por pasos (activa mantenimiento, arma el estado)
 * - action=chunk: ejecuta UN tramo acotado de la restauración por pasos
 * - action=progress: obtiene el progreso actual (para polling fallback)
 * - action=status: consulta si hay un proceso vivo
 *
 * La restauración por pasos existe porque el host mata el request a los pocos
 * segundos: `begin` + N x `chunk` mantiene cada request corto y reanudable.
 *
 * Formato de respuesta: SSE. `progress` con `done` (bool) y, en fallo, un
 * evento `error`.
 */

require_once __DIR__ . '/lib/debug_log.php';
system_updater_debug_install_shutdown();

require_once __DIR__ . '/lib/process_bootstrap.php';
$ctx = system_updater_process_init(['mode' => 'sse', 'progress_prefix' => 'fs_restore']);
$sessionId = $ctx['session_id'];
$action = $ctx['action'];
$progressFile = $ctx['progress_file'];

require_once __DIR__ . '/lib/maintenance_mode_compat.php';
if (file_exists(__DIR__ . '/lib/backup_manager.php')) {
    require_once __DIR__ . '/lib/backup_manager.php';
} else {
    system_updater_send_sse('error', ['message' => 'Error: No se encuentra el plugin system_updater.', 'percent' => 0]);
    exit;
}

$file = isset($_GET['file']) ? $_GET['file'] : '';
$restoreType = isset($_GET['type']) ? $_GET['type'] : 'complete';

if (empty($file) && $action !== 'chunk') {
    system_updater_send_sse('error', ['message' => 'Error: No se especificó archivo de backup.', 'percent' => 0]);
    exit;
}

$file = basename($file);

system_updater_debug_log('RESTORE', 'acción recibida', [
    'action' => $action,
    'file' => $file,
    'type' => $restoreType,
]);

/**
 * Presupuesto por paso (segundos). Debe quedar muy por debajo del límite del
 * host, que en producción mata el proceso a los ~14 s.
 */
function system_updater_restore_budget(): float
{
    if (defined('FS_RESTORE_STEP_BUDGET')) {
        $budget = (float) FS_RESTORE_STEP_BUDGET;
        if ($budget > 0) {
            return $budget;
        }
    }

    return 5.0;
}

/**
 * Emite el progreso de un paso por SSE y lo persiste para el polling fallback.
 */
function system_updater_emit_restore_progress(string $progressFile, array $result): void
{
    $progress = is_array($result['progress'] ?? null) ? $result['progress'] : [];

    $data = system_updater_save_progress(
        $progressFile,
        (string) ($progress['step'] ?? 'restore'),
        (string) ($progress['message'] ?? 'Continuando restauración...'),
        (int) ($progress['percent'] ?? 0)
    );
    $data['done'] = !empty($result['done']);

    system_updater_send_sse('progress', $data);
}

$progressCallback = function($step, $message, $percent) use ($progressFile) {
    $data = system_updater_save_progress($progressFile, $step, $message, $percent);
    system_updater_send_sse('progress', $data);
    usleep(10000);
};

switch ($action) {
    case 'start':
        system_updater_send_sse('start', ['message' => 'Iniciando proceso de restauración...', 'percent' => 0]);
        system_updater_save_progress($progressFile, 'init', 'Inicializando...', 0);

        $backupManager = new backup_manager(FS_FOLDER);

        system_updater_send_sse('init', ['message' => 'Verificando backup...', 'percent' => 2]);

        $backupPath = $backupManager->get_backup_path() . DIRECTORY_SEPARATOR . $file;
        if (!is_file($backupPath)) {
            $error = 'El archivo de backup no existe: ' . $file;
            system_updater_save_progress($progressFile, 'error', $error, 0, $error);
            system_updater_send_sse('error', ['message' => $error, 'percent' => 0]);
            @unlink($progressFile);
            exit;
        }

        system_updater_send_sse('init', ['message' => 'Backup encontrado. Iniciando restauración...', 'percent' => 3]);

        if (!system_updater_begin_maintenance([
            'message' => 'Restauración del sistema en curso.',
            'source' => 'system_updater.restore',
            'retry_after' => 300,
        ])) {
            $error = 'No se pudo activar el modo mantenimiento antes de iniciar la restauración.';
            system_updater_save_progress($progressFile, 'error', $error, 0, $error);
            system_updater_send_sse('error', ['message' => $error, 'percent' => 0]);
            @unlink($progressFile);
            exit;
        }

        $result = null;

        try {
            if ($restoreType === 'complete') {
                system_updater_send_sse('phase', ['phase' => 'complete', 'message' => 'Restauración completa']);
                $result = $backupManager->restore_complete($file, $progressCallback);
            } elseif ($restoreType === 'files') {
                system_updater_send_sse('phase', ['phase' => 'files', 'message' => 'Solo archivos']);
                $result = $backupManager->restore_files($file, $progressCallback);
            } elseif ($restoreType === 'database') {
                system_updater_send_sse('phase', ['phase' => 'database', 'message' => 'Solo base de datos']);
                $result = $backupManager->restore_database($file, $progressCallback);
            } else {
                throw new \Exception('Tipo de restauración no válido: ' . $restoreType);
            }

            if ($result['success']) {
                system_updater_save_progress($progressFile, 'complete', '¡Restauración completada con éxito!', 100);
                system_updater_send_sse('complete', [
                    'message' => '¡Restauración completada con éxito!',
                    'percent' => 100,
                    'redirect' => 'index.php?page=admin_updater&success=1'
                ]);
            } else {
                $errors = $backupManager->get_errors();
                $errorMsg = !empty($errors) ? implode('; ', $errors) : 'Error desconocido durante la restauración';
                system_updater_save_progress($progressFile, 'error', $errorMsg, 0, $errorMsg);
                system_updater_send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
            }

        } catch (\Throwable $e) {
            $errorMsg = 'Excepción: ' . $e->getMessage();
            system_updater_save_progress($progressFile, 'error', $errorMsg, 0, $errorMsg);
            system_updater_send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
        } finally {
            system_updater_end_maintenance();
        }

        @unlink($progressFile);
        break;

    case 'begin':
        system_updater_debug_log('RESTORE', 'begin', ['file' => $file, 'type' => $restoreType]);

        // Un lock huérfano de un intento anterior deja el sitio en 503: se
        // libera antes de empezar.
        system_updater_heal_stale_restore_lock();

        if (!system_updater_begin_maintenance([
            'source' => 'system_updater.restore',
            'heartbeat' => time(),
            'message' => 'Restauración del sistema en curso.',
            'retry_after' => 300,
        ])) {
            $error = 'No se pudo activar el modo mantenimiento antes de iniciar la restauración.';
            system_updater_save_progress($progressFile, 'error', $error, 0, $error);
            system_updater_send_sse('error', ['message' => $error, 'percent' => 0]);
            break;
        }

        $backupManager = new backup_manager(FS_FOLDER);
        $result = $backupManager->restore_session_start(
            $file,
            $restoreType,
            $sessionId,
            system_updater_restore_budget()
        );

        if (empty($result['ok'])) {
            system_updater_end_maintenance();
            $error = (string) ($result['error'] ?? 'No se pudo iniciar la restauración.');
            system_updater_save_progress($progressFile, 'error', $error, 0, $error);
            system_updater_send_sse('error', ['message' => $error, 'percent' => 0]);
            break;
        }

        system_updater_emit_restore_progress($progressFile, $result);

        if (!empty($result['done'])) {
            system_updater_end_maintenance();
        }
        break;

    case 'chunk':
        // El bootstrap sólo valida CSRF para 'start' y 'begin'; este endpoint
        // muta estado, así que lo exige explícitamente.
        ensure_request_csrf();

        $backupManager = new backup_manager(FS_FOLDER);
        $state = $backupManager->restore_session_load($sessionId);

        if ($state === null) {
            system_updater_heal_stale_restore_lock();
            $error = 'No hay una restauración activa. Si el proceso anterior murió, el modo '
                . 'mantenimiento ya quedó liberado: reintentá la restauración.';
            system_updater_save_progress($progressFile, 'error', $error, 0, $error);
            system_updater_send_sse('error', ['message' => $error, 'percent' => 0]);
            break;
        }

        system_updater_refresh_restore_heartbeat();

        $result = $backupManager->restore_session_step($state, system_updater_restore_budget());

        system_updater_debug_log('RESTORE', 'chunk ejecutado', [
            'phase' => (string) ($result['state']['phase'] ?? '?'),
            'done' => !empty($result['done']),
            'sql_offset' => (int) ($result['state']['sql_offset'] ?? 0),
            'statements' => (int) ($result['state']['statement_count'] ?? 0),
        ]);

        system_updater_emit_restore_progress($progressFile, $result);

        if (empty($result['ok']) || !empty($result['done'])) {
            system_updater_end_maintenance();
        }

        if (empty($result['ok'])) {
            system_updater_send_sse('error', [
                'message' => (string) ($result['error'] ?? 'Error durante la restauración.'),
                'percent' => (int) ($result['progress']['percent'] ?? 0),
            ]);
        }
        break;

    case 'progress':
        if (file_exists($progressFile)) {
            $data = json_decode((string) file_get_contents($progressFile), true);
            if (!is_array($data)) {
                $data = ['step' => 'waiting', 'message' => 'Esperando datos de progreso...', 'percent' => 0];
            }
            system_updater_send_sse('progress', $data);
        } else {
            system_updater_send_sse('progress', ['step' => 'waiting', 'message' => 'Esperando inicio...', 'percent' => 0]);
        }
        break;

    case 'status':
        // Si el proceso murió, el lock de mantenimiento quedaría puesto: se
        // libera al consultar el estado.
        system_updater_heal_stale_restore_lock();

        if (file_exists($progressFile)) {
            $data = json_decode((string) file_get_contents($progressFile), true);
            $data = is_array($data) ? $data : null;
            $isAlive = (time() - ($data['timestamp'] ?? 0)) < 120;
            system_updater_send_sse('status', [
                'active' => $isAlive,
                'data' => $data
            ]);
        } else {
            system_updater_send_sse('status', ['active' => false, 'data' => null]);
        }
        break;

    default:
        system_updater_send_sse('error', ['message' => 'Acción no válida: ' . $action, 'percent' => 0]);
        break;
}

exit;
