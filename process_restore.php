<?php
/**
 * Procesador de Restauración con Progreso - Plugin system_updater
 * 
 * Este script maneja la restauración de backups con reporte de progreso en tiempo real
 * usando Server-Sent Events (SSE).
 * 
 * Endpoints:
 * - action=start: Inicia la restauración completa
 * - action=progress: Obtiene el progreso actual (para polling fallback)
 */

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

if (empty($file)) {
    system_updater_send_sse('error', ['message' => 'Error: No se especificó archivo de backup.', 'percent' => 0]);
    exit;
}

$file = basename($file);

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

        $backupPath = FS_FOLDER . '/backups/' . $file;
        if (!file_exists($backupPath)) {
            $error = 'El archivo de backup no existe: ' . $file;
            system_updater_save_progress($progressFile, 'error', $error, 0, $error);
            system_updater_send_sse('error', ['message' => $error, 'percent' => 0]);
            @unlink($progressFile);
            exit;
        }

        system_updater_send_sse('init', ['message' => 'Backup encontrado. Iniciando restauración...', 'percent' => 3]);

        if (system_updater_maintenance_stealth_required()) {
            $error = system_updater_maintenance_stealth_required_message();
            system_updater_save_progress($progressFile, 'error', $error, 0, $error);
            system_updater_send_sse('error', ['message' => $error, 'percent' => 0]);
            @unlink($progressFile);
            exit;
        }

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
