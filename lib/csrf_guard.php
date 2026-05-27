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

    if ($token === '') {
        system_updater_csrf_failure_response(
            'Error: Token CSRF ausente. Recarga la página e inténtalo de nuevo.'
        );
        return;
    }

    // Primero intentar validación via CsrfManager (Symfony session)
    $valid = false;
    $diagInfo = '';

    try {
        $valid = \FSFramework\Security\CsrfManager::isValid((string) $token);
    } catch (\Throwable $e) {
        $diagInfo = $e->getMessage();
    }

    if ($valid) {
        return;
    }

    // Fallback: validar leyendo el token directamente de $_SESSION (sin Symfony)
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION)) {
        $stored = system_updater_csrf_read_stored_token();
        if ($stored !== '' && system_updater_csrf_verify_token($token, $stored)) {
            return;
        }
    }

    $sessionStatus = session_status() === PHP_SESSION_ACTIVE ? 'active' : 'inactive';
    $sessionId = session_id() ?: 'none';
    $cookieNames = array_keys(array_filter($_COOKIE, fn($k) => str_starts_with($k, 'FSSESS'), ARRAY_FILTER_USE_KEY));
    $hasSf2 = isset($_SESSION['_sf2_attributes']) ? 'yes' : 'no';
    $hasToken = system_updater_csrf_read_stored_token() !== '' ? 'yes' : 'no';

    error_log(sprintf(
        '[system_updater] CSRF validation failed. session=%s, sid=%s, cookies=[%s], sf2_attrs=%s, stored_token=%s, token_len=%d, diag=%s',
        $sessionStatus,
        substr($sessionId, 0, 8) . '...',
        implode(',', $cookieNames),
        $hasSf2,
        $hasToken,
        strlen($token),
        $diagInfo
    ));

    system_updater_csrf_failure_response(
        'Error: Token CSRF inválido. Recarga la página e inténtalo de nuevo. [session=' . $sessionStatus . ', sf2=' . $hasSf2 . ', stored=' . $hasToken . ']'
    );
}

/**
 * Lee el token CSRF almacenado directamente de $_SESSION sin pasar por Symfony.
 * Symfony SessionTokenStorage guarda en $_SESSION['_sf2_attributes']['_csrf/TOKEN_ID'].
 */
function system_updater_csrf_read_stored_token(): string
{
    $tokenId = 'fs_form';
    $sessionKey = '_csrf/' . $tokenId;

    $attrs = $_SESSION['_sf2_attributes'] ?? [];
    if (isset($attrs[$sessionKey]) && is_string($attrs[$sessionKey])) {
        return $attrs[$sessionKey];
    }

    if (isset($_SESSION[$sessionKey]) && is_string($_SESSION[$sessionKey])) {
        return $_SESSION[$sessionKey];
    }

    return '';
}

/**
 * Valida un token CSRF contra el valor almacenado, manejando la randomización de Symfony 7.
 *
 * Formato randomizado: "checksum.base64url(key).base64url(xor(value, key))"
 * Para validar: decodificar key y xored, XOR → debe coincidir con stored.
 */
function system_updater_csrf_verify_token(string $submittedToken, string $storedToken): bool
{
    if ($submittedToken === $storedToken) {
        return true;
    }

    $parts = explode('.', $submittedToken);
    if (count($parts) !== 3) {
        return hash_equals($storedToken, $submittedToken);
    }

    // parts[0] = checksum (ignorar), parts[1] = key, parts[2] = xored value
    $key = base64_decode(strtr($parts[1], '-_', '+/'), true);
    $xored = base64_decode(strtr($parts[2], '-_', '+/'), true);

    if ($key === false || $key === '' || $xored === false) {
        return hash_equals($storedToken, $submittedToken);
    }

    // XOR para recuperar el valor original (misma lógica que CsrfTokenManager::xor)
    if (strlen($xored) > strlen($key)) {
        $key = str_repeat($key, (int) ceil(strlen($xored) / strlen($key)));
    }
    $candidate = $xored ^ $key;

    return hash_equals($storedToken, $candidate);
}
