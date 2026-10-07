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
$finalizingEmitted = false;

$progressCallback = function ($step, $message, $percent) use ($progressFile, &$lastEventTime, &$finalizingEmitted) {
    if (time() - $lastEventTime > 10) {
        echo ":keepalive\n\n";
        @flush();
    }
    $data = system_updater_save_progress($progressFile, $step, $message, $percent);
    system_updater_send_sse('progress', $data);
    $lastEventTime = time();
    usleep(10000);

    // The next step (ZipArchive::close()) compresses every file and emits
    // nothing for minutes, so a proxy/FastCGI idle timeout would cut the SSE
    // stream. Emit a 'finalizing' event, then end the HTTP response: the backup
    // finishes in background (ignore_user_abort) and the UI polls action=status.
    if ($step === 'files_close') {
        $finalizingEmitted = true;
        system_updater_send_sse('finalizing', $data);
        system_updater_finish_response();
    }
};

/**
 * Best-effort liveness check for the background worker that owns the progress
 * snapshot. Returns true unless it is reasonably certain the worker is gone,
 * so the status endpoint never reports a false "stalled" for a live backup.
 */
function system_updater_worker_is_alive(array $data): bool
{
    $pid = (int) ($data['pid'] ?? 0);
    if ($pid <= 0) {
        return true; // Snapshot without a PID (older run): cannot prove death.
    }

    if (function_exists('posix_kill')) {
        if (@posix_kill($pid, 0)) {
            return true;
        }
        // EPERM (1) means the process exists but belongs to another user.
        if (function_exists('posix_get_last_error') && posix_get_last_error() === 1) {
            return true;
        }
    }

    if (is_dir('/proc')) {
        return file_exists('/proc/' . $pid);
    }

    return true;
}

switch ($action) {
    case 'start':
        // Clear any previous run's progress so a concurrent action=status poll
        // can never read a stale 'complete' from an earlier backup.
        @unlink($progressFile);
        system_updater_send_sse('start', ['message' => 'Iniciando copia de seguridad...', 'percent' => 0]);
        system_updater_save_progress($progressFile, 'init', 'Preparando copia de seguridad...', 0);

        try {
            $backupManager = new backup_manager(FS_FOLDER);
            system_updater_send_sse('init', ['message' => 'Verificando entorno...', 'percent' => 2]);

            $result = $backupManager->create_backup_with_progress('', true, $progressCallback);

            if (isset($result['complete']) && !empty($result['complete']['success'])) {
                $backupName = $result['complete']['backup_name'] ?? '';
                system_updater_save_progress($progressFile, 'complete', '¡Copia de seguridad creada con éxito!', 100, null, ['backup_name' => $backupName]);
                system_updater_send_sse('complete', [
                    'message' => '¡Copia de seguridad creada con éxito!',
                    'percent' => 100,
                    'backup_name' => $backupName,
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

        // Keep the terminal progress snapshot whenever the UI was handed off to
        // polling ('finalizing' emitted), on every SAPI. On hosts without
        // fastcgi_finish_request the response is not actually finished, but the
        // client still polls action=status and must find the terminal state.
        // The snapshot is cleared at the start of the next backup.
        if (!$finalizingEmitted) {
            @unlink($progressFile);
        }
        break;

    case 'progress':
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        if (file_exists($progressFile)) {
            $raw = (string) file_get_contents($progressFile);
            echo $raw !== '' ? $raw : json_encode(['step' => 'waiting', 'message' => 'Esperando inicio...', 'percent' => 0], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['step' => 'waiting', 'message' => 'Esperando inicio...', 'percent' => 0], JSON_UNESCAPED_UNICODE);
        }
        break;

    case 'status':
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');

        // Read under a shared lock so a poll can never observe a half-written
        // JSON snapshot (which would decode to null and look like a dead job).
        $data = null;
        $fp = @fopen($progressFile, 'rb');
        if ($fp) {
            $raw = '';
            if (@flock($fp, LOCK_SH)) {
                $raw = (string) stream_get_contents($fp);
                @flock($fp, LOCK_UN);
            }
            fclose($fp);
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        if (is_array($data)) {
            $isTerminal = in_array((string) ($data['step'] ?? ''), ['complete', 'error'], true);
            if (!$isTerminal && !system_updater_worker_is_alive($data)) {
                // The background worker is gone but never wrote a terminal
                // state: a PHP timeout / request_terminate_timeout killed it.
                $data['step'] = 'stalled';
                $data['message'] = 'El proceso de copia se interrumpio antes de finalizar. Verifica la lista de copias de seguridad.';
            }
        }

        $isAlive = is_array($data) && (time() - (int) ($data['timestamp'] ?? 0)) < 120;
        echo json_encode(['active' => $isAlive, 'data' => $data], JSON_UNESCAPED_UNICODE);
        break;

    default:
        system_updater_send_sse('error', ['message' => 'Acción no válida: ' . $action, 'percent' => 0]);
        break;
}
exit;
