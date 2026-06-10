<?php
/**
 * CSRF guard for standalone system_updater process scripts.
 *
 * These scripts bypass the normal index.php → controller → CSRF flow
 * (allowed through .htaccess) so they must validate CSRF tokens themselves.
 *
 * Uses the plugin's own token system (csrf_token.php) — independent of
 * the core's internal storage format. Works with any core version.
 *
 * For SSE endpoints (GET), the token is passed as a query parameter.
 * For AJAX endpoints (GET/POST), the token is passed as a query parameter,
 * in the request body, or in the X-SU-CSRF-Token header.
 *
 * Usage in a process script:
 *   require_once __DIR__ . '/lib/csrf_guard.php';
 *   ensure_request_csrf(); // exits with error if token is invalid
 */

require_once __DIR__ . '/csrf_token.php';

/**
 * Send an error response in the appropriate format and exit.
 *
 * SSE process scripts get a text/event-stream error event.
 * All other endpoints get a JSON 403 response.
 * CLI context writes to STDERR.
 */
function system_updater_csrf_failure_response(string $message): void
{
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $isSse = strpos($uri, 'process_core_update.php') !== false
        || strpos($uri, 'process_restore.php') !== false
        || strpos($uri, 'process_backup.php') !== false;

    if ($isSse) {
        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache, no-transform');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            header('Content-Encoding: identity');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
        }

        echo "event: error\n";
        echo 'data: ' . json_encode([
            'message' => $message,
            'percent' => 0,
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        @flush();
        exit;
    }

    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Read the plugin CSRF token from the current request.
 *
 * Checks (in order):
 * 1. $_GET['su_csrf_token']
 * 2. $_POST['su_csrf_token']
 * 3. X-SU-CSRF-Token header
 */
function system_updater_csrf_read_from_request(): string
{
    $token = $_GET['su_csrf_token'] ?? $_POST['su_csrf_token'] ?? '';

    if ($token === '' && isset($_SERVER['HTTP_X_SU_CSRF_TOKEN'])) {
        $token = $_SERVER['HTTP_X_SU_CSRF_TOKEN'];
    }

    return (string) $token;
}

/**
 * Validate the CSRF token for the current request.
 *
 * On success: closes the session and returns normally.
 * On failure: logs the issue and exits via system_updater_csrf_failure_response().
 */
function ensure_request_csrf(): void
{
    $token = system_updater_csrf_read_from_request();

    if ($token === '') {
        system_updater_csrf_failure_response(
            'Error: Token CSRF ausente. Recarga la página e inténtalo de nuevo.'
        );
        return;
    }

    if (system_updater_csrf_validate($token)) {
        // Valid — close session immediately so SSE can stream without blocking
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        return;
    }

    // Validation failed — log diagnostics
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? 'unknown');
    $sessionStatus = session_status() === PHP_SESSION_ACTIVE ? 'active' : 'inactive';

    error_log(sprintf(
        '[system_updater] CSRF validation failed. uri=%s, session=%s, token_len=%d',
        strtok($uri, '?'),
        $sessionStatus,
        strlen($token)
    ));

    system_updater_csrf_failure_response(
        'Error: Token CSRF inválido. Recarga la página e inténtalo de nuevo.'
    );
}
