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

if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', dirname(dirname(__DIR__)));
}

if (!file_exists(FS_FOLDER . '/config.php')) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    echo "event: error\n";
    echo 'data: ' . json_encode([
        'message' => 'Error: No se encuentra el archivo config.php.',
        'percent' => 0,
    ], JSON_UNESCAPED_UNICODE) . "\n\n";
    exit;
}

require_once __DIR__ . '/lib/session_auth.php';
require_once __DIR__ . '/lib/csrf_guard.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// Para action=start, el CSRF token vinculado a sesión es suficiente autenticación.
// Para progress/status sin CSRF, verificamos sesión de usuario.
if ($action === 'start') {
    system_updater_start_authenticated_session();
    ensure_request_csrf();
    $sessionId = session_id();
} else {
    $sessionId = system_updater_require_authenticated_session();
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

@set_time_limit(0);
@ini_set('max_execution_time', 0);
@ini_set('memory_limit', '512M');
@ini_set('zlib.output_compression', 'Off');
@ini_set('output_buffering', 'Off');
@ini_set('output_handler', '');

while (ob_get_level()) {
    ob_end_clean();
}

function send_sse($event, $data)
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data) . "\n\n";
    @flush();
}

function save_progress($step, $message, $percent, $error = null)
{
    global $progressFile;

    $data = [
        'step' => $step,
        'message' => $message,
        'percent' => $percent,
        'timestamp' => time(),
        'error' => $error,
    ];

    @file_put_contents($progressFile, json_encode($data));
    return $data;
}

require_once __DIR__ . '/lib/maintenance_mode_compat.php';
require_once __DIR__ . '/lib/core_updater.php';

$createBackup = isset($_GET['create_backup']) && $_GET['create_backup'] === '0' ? false : true;
$mode = isset($_GET['mode']) && $_GET['mode'] === 'reinstall' ? 'reinstall' : 'update';
$operationLabel = $mode === 'reinstall' ? 'reinstalación' : 'actualización';

$progressFile = sys_get_temp_dir() . '/fs_core_update_' . $sessionId . '.json';

$progressCallback = function ($step, $message, $percent) {
    $data = save_progress($step, $message, $percent);
    send_sse('progress', $data);
    usleep(10000);
};

switch ($action) {
    case 'start':
        send_sse('start', ['message' => 'Iniciando ' . $operationLabel . ' del núcleo...', 'percent' => 0]);
        save_progress('init', 'Inicializando...', 0);

        if (system_updater_maintenance_stealth_required()) {
            $errorMsg = system_updater_maintenance_stealth_required_message();
            save_progress('error', $errorMsg, 0, $errorMsg);
            send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
            exit;
        }

        if (!system_updater_begin_maintenance([
            'message' => 'Actualización del núcleo en curso.',
            'source' => 'system_updater.core_update',
            'retry_after' => 300,
        ])) {
            $errorMsg = 'No se pudo activar el modo mantenimiento antes de iniciar la actualización del núcleo.';
            save_progress('error', $errorMsg, 0, $errorMsg);
            send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
            exit;
        }

        try {
            $updater = new core_updater(FS_FOLDER);
            send_sse('init', ['message' => 'Verificando entorno de ' . $operationLabel . '...', 'percent' => 2]);

            $result = $updater->update_core($createBackup, $progressCallback);

            if (!empty($result['success'])) {
                save_progress('complete', $result['message'], 100);
                send_sse('complete', [
                    'message' => $result['message'],
                    'percent' => 100,
                    'installed_version' => $result['installed_version'] ?? '',
                    'redirect' => 'index.php?page=admin_updater&success=core-updated',
                ]);
            } else {
                $errors = $result['errors'] ?? $updater->get_errors();
                $errorMsg = !empty($errors) ? implode('; ', $errors) : 'Error desconocido durante la actualización del núcleo';
                save_progress('error', $errorMsg, 0, $errorMsg);
                send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
            }
        } catch (\Throwable $e) {
            $errorMsg = 'Excepción: ' . $e->getMessage();
            save_progress('error', $errorMsg, 0, $errorMsg);
            send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
        } finally {
            system_updater_end_maintenance();
        }

        @unlink($progressFile);
        break;

    case 'progress':
        if (file_exists($progressFile)) {
            $data = json_decode((string) file_get_contents($progressFile), true);
            send_sse('progress', $data);
        } else {
            send_sse('progress', ['step' => 'waiting', 'message' => 'Esperando inicio...', 'percent' => 0]);
        }
        break;

    case 'status':
        if (file_exists($progressFile)) {
            $data = json_decode((string) file_get_contents($progressFile), true);
            $isAlive = (time() - ($data['timestamp'] ?? 0)) < 120;
            send_sse('status', [
                'active' => $isAlive,
                'data' => $data,
            ]);
        } else {
            send_sse('status', ['active' => false, 'data' => null]);
        }
        break;

    default:
        send_sse('error', ['message' => 'Acción no válida: ' . $action, 'percent' => 0]);
        break;
}

exit;