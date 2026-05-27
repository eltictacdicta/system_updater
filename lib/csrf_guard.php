<?php
/**
 * CSRF guard for standalone system_updater process scripts.
 *
 * These scripts bypass the normal index.php → controller → CSRF flow
 * (allowed through .htaccess) so they must validate CSRF tokens themselves.
 *
 * For SSE endpoints (GET), the token is passed as a query parameter.
 * For AJAX endpoints (GET/POST), the token is passed as a query parameter
 * or in the request body / header.
 *
 * Usage in a process script:
 *   require_once __DIR__ . '/lib/csrf_guard.php';
 *   ensure_request_csrf(); // exits with error if token is invalid
 */

function system_updater_csrf_failure_response(string $message): void
{
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $isSse = strpos($uri, 'process_core_update.php') !== false
        || strpos($uri, 'process_restore.php') !== false;

    if ($isSse) {
        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
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

function ensure_request_csrf(): void
{
    $autoloadFile = dirname(dirname(__DIR__)) . '/vendor/autoload.php';
    if (!file_exists($autoloadFile)) {
        return;
    }

    require_once $autoloadFile;

    if (!class_exists(\FSFramework\Security\CsrfManager::class)) {
        return;
    }

    $token = $_GET[\FSFramework\Security\CsrfManager::FIELD_NAME]
        ?? $_POST[\FSFramework\Security\CsrfManager::FIELD_NAME]
        ?? $_POST['_token']
        ?? '';

    if ($token === '' && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
    }

    if ($token === '' || !\FSFramework\Security\CsrfManager::isValid((string) $token)) {
        system_updater_csrf_failure_response(
            'Error: Token CSRF inválido o ausente. Recarga la página e inténtalo de nuevo.'
        );
    }
}
