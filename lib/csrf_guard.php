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
        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, "Error: CSRF token inválido o ausente.\n");
            exit(1);
        }

        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => 'Error: Token CSRF inválido o ausente. Recarga la página e inténtalo de nuevo.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
