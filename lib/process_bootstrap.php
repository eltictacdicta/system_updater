<?php
/**
 * Bootstrap compartido para los process scripts del plugin system_updater.
 *
 * Los scripts process_backup.php, process_core_update.php y process_restore.php
 * comparten lógica de inicialización: definir FS_FOLDER, verificar config.php,
 * cargar sesión, configurar headers SSE/JSON, y funciones de progreso con flock.
 *
 * Uso:
 *   require_once __DIR__ . '/lib/process_bootstrap.php';
 *   $ctx = system_updater_process_init(['mode' => 'sse', 'progress_prefix' => 'fs_core_update']);
 *   // $ctx['session_id'], $ctx['action'], $ctx['progress_file']
 */

if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', dirname(dirname(__DIR__)));
}

require_once __DIR__ . '/session_auth.php';

function system_updater_shutdown_on_missing_config(): void
{
    if (headers_sent()) {
        return;
    }

    $isSse = defined('SYSTEM_UPDATER_SSE_MODE') && SYSTEM_UPDATER_SSE_MODE;
    if ($isSse) {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        echo "event: error\n";
        echo 'data: ' . json_encode([
            'message' => 'Error: No se encuentra el archivo config.php.',
            'percent' => 0,
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
    } else {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => 'Error: No se encuentra el archivo config.php.',
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

/**
 * @param array<string, mixed> $options
 * @return array{session_id: string, action: string, progress_file: string}
 */
function system_updater_process_init(array $options = []): array
{
    if (!file_exists(FS_FOLDER . '/config.php')) {
        system_updater_shutdown_on_missing_config();
    }

    require_once FS_FOLDER . '/config.php';

    $mode = (string) ($options['mode'] ?? 'sse');

    @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    @ini_set('memory_limit', '512M');
    @ignore_user_abort(true);

    if ($mode === 'sse') {
        define('SYSTEM_UPDATER_SSE_MODE', true);

        require_once __DIR__ . '/csrf_guard.php';

        @ini_set('zlib.output_compression', 'Off');
        @ini_set('output_buffering', 'Off');
        @ini_set('output_handler', '');

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
    }

    $action = (string) ($_GET['action'] ?? '');

    if ($action === 'start') {
        system_updater_start_authenticated_session();

        if ($mode === 'sse') {
            ensure_request_csrf();
        }

        $sessionId = session_id();
    } else {
        $sessionId = system_updater_require_authenticated_session();
    }

    $progressPrefix = (string) ($options['progress_prefix'] ?? 'fs_process');
    $progressFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . preg_replace('/[^A-Za-z0-9_.-]/', '', $progressPrefix)
        . '_'
        . $sessionId
        . '.json';

    return [
        'session_id' => $sessionId,
        'action' => $action,
        'progress_file' => $progressFile,
    ];
}

/**
 * Envía un evento SSE al cliente.
 *
 * @param string $event
 * @param array<string, mixed> $data
 */
function system_updater_send_sse(string $event, array $data): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    @flush();
}

/**
 * Guarda el progreso con protección flock() para evitar race conditions.
 *
 * @param string $progressFile
 * @param string $step
 * @param string $message
 * @param int $percent
 * @param string|null $error
 * @return array<string, mixed>
 */
function system_updater_save_progress(
    string $progressFile,
    string $step,
    string $message,
    int $percent,
    ?string $error = null
): array {
    $data = [
        'step' => $step,
        'message' => $message,
        'percent' => $percent,
        'timestamp' => time(),
        'error' => $error,
    ];

    $fp = @fopen($progressFile, 'c');
    if ($fp) {
        if (@flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    return $data;
}
