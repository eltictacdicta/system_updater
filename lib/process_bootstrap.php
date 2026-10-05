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
    define('FS_FOLDER', dirname(dirname(dirname(__DIR__))));
}

require_once __DIR__ . '/session_auth.php';
system_updater_prime_fs_path_from_request();

function system_updater_shutdown_on_missing_config(string $mode = 'sse'): void
{
    if (headers_sent()) {
        return;
    }

    $isSse = ($mode === 'sse') || (defined('SYSTEM_UPDATER_SSE_MODE') && SYSTEM_UPDATER_SSE_MODE);
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
 *   Supported modes:
 *   - 'sse' (default): Server-Sent Events. Emits text/event-stream headers,
 *     requires csrf_guard on 'start', and is meant for long-lived progress
 *     streaming.
 *   - 'plain': No SSE headers, no deflate, no buffering.  Used by
 *     `download_backup.php` which needs to send a binary file body without
 *     any framing that would interfere with `readfile()`.
 * @return array{session_id: string, action: string, progress_file: string}
 */
function system_updater_process_init(array $options = []): array
{
    $mode = (string) ($options['mode'] ?? 'sse');

    if (!file_exists(FS_FOLDER . '/config.php')) {
        system_updater_shutdown_on_missing_config($mode);
    }

    require_once FS_FOLDER . '/config.php';

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
        @ini_set('implicit_flush', 'On');

        // Disable Apache mod_deflate for this request (defensive; also done in .htaccess)
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        // Clean all output buffers — Plesk/FastCGI may auto-start one
        while (ob_get_level()) {
            @ob_end_clean();
        }

        // SSE headers — order matters for reverse proxies and buffering intermediaries
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        // Signal mod_deflate / gzip proxies not to re-encode the stream
        header('Content-Encoding: identity');
        // Prevent browsers from sniffing the content type
        header('X-Content-Type-Options: nosniff');
    } elseif ($mode === 'plain') {
        require_once __DIR__ . '/csrf_guard.php';

        // Clean any output buffers left over from config.php — required
        // because we are about to call readfile() and any buffered output
        // would corrupt the binary body.
        while (ob_get_level()) {
            @ob_end_clean();
        }
        // Do NOT set Content-Type, Cache-Control, or X-Accel-Buffering here:
        // each endpoint sets its own headers (the download endpoint needs
        // attachment + Content-Length, not the SSE defaults).
    } else {
        // Unknown mode — fail loud instead of silently falling back to SSE.
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => 'system_updater_process_init: unknown mode "' . $mode . '"',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $action = (string) ($_GET['action'] ?? '');

    // 'begin' y 'chunk' autentican con la sesión del plugin + su CSRF, igual que
    // 'start'. NO pueden depender de la sesión de la aplicación: cada chunk es un
    // request nuevo que vuelve a leer la app del disco, y la restauración de
    // archivos reescribe base/ y src/ a mitad del proceso. Validar contra el
    // código/sesión recién restaurado dejaba el siguiente chunk con "Sesión no
    // válida" aunque el token del plugin fuera correcto.
    if ($action === 'start' || $action === 'begin' || $action === 'chunk') {
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

    // Release session lock — SSE scripts are long-lived and must not block
    // other requests from the same user.  CSRF validation already read and
    // closed the session on the 'start' action; for 'progress'/'status'
    // the session is still open after system_updater_require_authenticated_session().
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

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
    if (system_updater_response_finished()) {
        return;
    }

    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

    // Flush any output buffer layer, then push to the web server
    while (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

/**
 * Envía un comentario SSE keepalive (:: prefix = comment, browsers ignore it).
 * Keeps the connection alive through proxies, load balancers, and FastCGI timeouts.
 */
function system_updater_send_sse_keepalive(): void
{
    if (system_updater_response_finished()) {
        return;
    }

    // SSE spec: lines starting with ':' are ignored by the browser
    echo ": keepalive " . time() . "\n\n";

    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    @flush();
}

/**
 * Ends the HTTP response while letting the PHP script keep running.
 *
 * Long blocking steps with no output — e.g. ZipArchive::close() compressing
 * thousands of files — would otherwise exceed a proxy / FastCGI idle timeout
 * and kill the connection mid-flight. Finishing the response first lets the
 * client switch to polling an action=status endpoint while the work continues
 * in the background (ignore_user_abort is already enabled), so the result no
 * longer depends on proxy timeouts. After this call, SSE output is discarded.
 */
function system_updater_finish_response(): void
{
    // Only the FastCGI SAPI can end the response while the script keeps
    // running. On other SAPIs (mod_php, CGI) there is no way to detach, so the
    // response stays open and the SSE delivers the final 'complete' event; the
    // UI polling fallback covers a proxy cut instead.
    if (!function_exists('fastcgi_finish_request')) {
        return;
    }

    $GLOBALS['system_updater_response_finished'] = true;
    @fastcgi_finish_request();
}

function system_updater_response_finished(): bool
{
    return !empty($GLOBALS['system_updater_response_finished']);
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
    ?string $error = null,
    array $extra = []
): array {
    $data = array_merge([
        'step' => $step,
        'message' => $message,
        'percent' => $percent,
        'timestamp' => time(),
        'error' => $error,
    ], $extra);

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
