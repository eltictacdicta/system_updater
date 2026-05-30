<?php
/**
 * Procesador de Backup con progreso en tiempo real.
 *
 * Usa Server-Sent Events (SSE) para evitar timeouts en peticiones largas.
 *
 * Auth: CSRF token (session-bound, solo emitido a admins) es la prueba de
 * autenticación para el action=start.
 */

if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', dirname(dirname(__DIR__)));
}

require_once __DIR__ . '/lib/process_bootstrap.php';
$ctx = system_updater_process_init(['mode' => 'sse', 'progress_prefix' => 'fs_backup']);
$sessionId = $ctx['session_id'];
$action = $ctx['action'];
$progressFile = $ctx['progress_file'];

if (!file_exists(__DIR__ . '/lib/backup_manager.php')) {
    system_updater_send_sse('error', ['message' => 'Error: No se encuentra el plugin system_updater.', 'percent' => 0]);
    exit;
}
require_once __DIR__ . '/lib/backup_manager.php';

$lastEventTime = time();

$progressCallback = function ($step, $message, $percent) use ($progressFile, &$lastEventTime) {
    if (time() - $lastEventTime > 10) {
        echo ":keepalive\n\n";
        @flush();
    }
    $data = system_updater_save_progress($progressFile, $step, $message, $percent);
    system_updater_send_sse('progress', $data);
    $lastEventTime = time();
    usleep(10000);
};

switch ($action) {
    case 'start':
        system_updater_send_sse('start', ['message' => 'Iniciando copia de seguridad...', 'percent' => 0]);
        system_updater_save_progress($progressFile, 'init', 'Preparando copia de seguridad...', 0);

        try {
            $backupManager = new backup_manager(FS_FOLDER);
            system_updater_send_sse('init', ['message' => 'Verificando entorno...', 'percent' => 2]);

            $result = $backupManager->create_backup_with_progress('', true, $progressCallback);

            if (isset($result['complete']) && !empty($result['complete']['success'])) {
                system_updater_save_progress($progressFile, 'complete', '¡Copia de seguridad creada con éxito!', 100);
                system_updater_send_sse('complete', [
                    'message' => '¡Copia de seguridad creada con éxito!',
                    'percent' => 100,
                    'backup_name' => $result['complete']['backup_name'] ?? '',
                    'redirect' => 'index.php?page=admin_updater&success=backup',
                ]);
            } else {
                $errors = $backupManager->get_errors();
                $errorMsg = !empty($errors) ? implode('; ', $errors) : 'Error desconocido durante el backup';
                system_updater_save_progress($progressFile, 'error', $errorMsg, 0, $errorMsg);
                system_updater_send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
            }
        } catch (\Throwable $e) {
            $errorMsg = 'Excepción: ' . $e->getMessage();
            system_updater_save_progress($progressFile, 'error', $errorMsg, 0, $errorMsg);
            system_updater_send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
        }

        @unlink($progressFile);
        break;

    default:
        system_updater_send_sse('error', ['message' => 'Acción no válida: ' . $action, 'percent' => 0]);
        break;
}
exit;
