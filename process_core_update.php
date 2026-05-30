<?php
/**
 * Procesador de actualización del núcleo con progreso en tiempo real.
 *
 * Usa Server-Sent Events (SSE) para evitar timeouts en peticiones largas.
 *
 * Auth: CSRF token (session-bound, solo emitido a admins) es la prueba de
 * autenticación para el action=start. El check de user_nick en sesión es un
 * fallback para actions sin CSRF (progress/status).
 */

require_once __DIR__ . '/lib/process_bootstrap.php';
$ctx = system_updater_process_init(['mode' => 'sse', 'progress_prefix' => 'fs_core_update']);
$sessionId = $ctx['session_id'];
$action = $ctx['action'];
$progressFile = $ctx['progress_file'];

require_once __DIR__ . '/lib/maintenance_mode_compat.php';
require_once __DIR__ . '/lib/core_updater.php';

$createBackup = isset($_GET['create_backup']) && $_GET['create_backup'] === '0' ? false : true;
$mode = isset($_GET['mode']) && $_GET['mode'] === 'reinstall' ? 'reinstall' : 'update';
$operationLabel = $mode === 'reinstall' ? 'reinstalación' : 'actualización';

$progressCallback = function ($step, $message, $percent) use ($progressFile) {
    $data = system_updater_save_progress($progressFile, $step, $message, $percent);
    system_updater_send_sse('progress', $data);
    usleep(10000);
};

switch ($action) {
    case 'start':
        system_updater_send_sse('start', ['message' => 'Iniciando ' . $operationLabel . ' del núcleo...', 'percent' => 0]);
        system_updater_save_progress($progressFile, 'init', 'Inicializando...', 0);

        if (system_updater_maintenance_stealth_required()) {
            $errorMsg = system_updater_maintenance_stealth_required_message();
            system_updater_save_progress($progressFile, 'error', $errorMsg, 0, $errorMsg);
            system_updater_send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
            exit;
        }

        if (!system_updater_begin_maintenance([
            'message' => 'Actualización del núcleo en curso.',
            'source' => 'system_updater.core_update',
            'retry_after' => 300,
        ])) {
            $errorMsg = 'No se pudo activar el modo mantenimiento antes de iniciar la actualización del núcleo.';
            system_updater_save_progress($progressFile, 'error', $errorMsg, 0, $errorMsg);
            system_updater_send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
            exit;
        }

        try {
            $updater = new core_updater(FS_FOLDER);
            system_updater_send_sse('init', ['message' => 'Verificando entorno de ' . $operationLabel . '...', 'percent' => 2]);

            $result = $updater->update_core($createBackup, $progressCallback);

            if (!empty($result['success'])) {
                system_updater_save_progress($progressFile, 'complete', $result['message'], 100);
                system_updater_send_sse('complete', [
                    'message' => $result['message'],
                    'percent' => 100,
                    'installed_version' => $result['installed_version'] ?? '',
                    'redirect' => 'index.php?page=admin_updater&success=core-updated',
                ]);
            } else {
                $errors = $result['errors'] ?? $updater->get_errors();
                $errorMsg = !empty($errors) ? implode('; ', $errors) : 'Error desconocido durante la actualización del núcleo';
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

    case 'progress':
        if (file_exists($progressFile)) {
            $data = json_decode((string) file_get_contents($progressFile), true);
            system_updater_send_sse('progress', $data);
        } else {
            system_updater_send_sse('progress', ['step' => 'waiting', 'message' => 'Esperando inicio...', 'percent' => 0]);
        }
        break;

    case 'status':
        if (file_exists($progressFile)) {
            $data = json_decode((string) file_get_contents($progressFile), true);
            $isAlive = (time() - ($data['timestamp'] ?? 0)) < 120;
            system_updater_send_sse('status', [
                'active' => $isAlive,
                'data' => $data,
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
