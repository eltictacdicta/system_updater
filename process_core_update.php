<?php
/**
 * Procesador de actualización del núcleo con progreso en tiempo real.
 *
 * Usa Server-Sent Events (SSE) para evitar timeouts en peticiones largas.
 */

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

define('FS_FOLDER', dirname(dirname(__DIR__)));

if (file_exists(FS_FOLDER . '/config.php')) {
    require_once FS_FOLDER . '/config.php';
} else {
    send_sse('error', ['message' => 'Error: No se encuentra el archivo config.php.', 'percent' => 0]);
    exit;
}

if (defined('FS_SESSION_NAME')) {
    session_name(FS_SESSION_NAME);
}
session_start();

require_once __DIR__ . '/lib/core_updater.php';

$is_logged = false;
if (isset($_SESSION['user_id']) || isset($_SESSION['user_nick']) || isset($_SESSION['_sf2_attributes']['user_nick'])) {
    $is_logged = true;
}

if (!$is_logged) {
    send_sse('error', ['message' => 'Error: Sesión no válida. Por favor, inicie sesión nuevamente.', 'percent' => 0]);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$createBackup = isset($_GET['create_backup']) && $_GET['create_backup'] === '0' ? false : true;

$progressFile = sys_get_temp_dir() . '/fs_core_update_' . session_id() . '.json';

$progressCallback = function ($step, $message, $percent) {
    $data = save_progress($step, $message, $percent);
    send_sse('progress', $data);
    usleep(10000);
};

switch ($action) {
    case 'start':
        send_sse('start', ['message' => 'Iniciando actualización del núcleo...', 'percent' => 0]);
        save_progress('init', 'Inicializando...', 0);

        try {
            $updater = new core_updater(FS_FOLDER);
            send_sse('init', ['message' => 'Verificando entorno de actualización...', 'percent' => 2]);

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
        } catch (Exception $e) {
            $errorMsg = 'Excepción: ' . $e->getMessage();
            save_progress('error', $errorMsg, 0, $errorMsg);
            send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
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
}

exit;