<?php
/**
 * Session bootstrap and authentication for standalone system_updater process scripts.
 *
 * These scripts bypass index.php, so they must resolve the same session name,
 * save path, and cookie settings as FSFramework before validating login.
 */

function system_updater_resolve_session_name(): string
{
    if (defined('FS_SESSION_NAME') && trim((string) FS_SESSION_NAME) !== '') {
        return trim((string) FS_SESSION_NAME);
    }

    $seed = defined('FS_FOLDER') ? (string) FS_FOLDER : dirname(dirname(__DIR__));
    $seed = str_replace('\\', '/', $seed);

    return 'FSSESS_' . substr(sha1($seed), 0, 12);
}

/**
 * @return array<int, string>
 */
function system_updater_resolve_session_names(): array
{
    $names = [system_updater_resolve_session_name()];

    $iniName = trim((string) ini_get('session.name'));
    if ($iniName !== '') {
        $names[] = $iniName;
    }

    $names[] = 'PHPSESSID';

    $normalized = [];
    foreach ($names as $name) {
        $candidate = trim((string) $name);
        if ($candidate !== '' && !in_array($candidate, $normalized, true)) {
            $normalized[] = $candidate;
        }
    }

    return $normalized;
}

function system_updater_ensure_fs_path(): void
{
    if (defined('FS_PATH')) {
        return;
    }

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = is_string(parse_url($requestUri, PHP_URL_PATH)) ? parse_url($requestUri, PHP_URL_PATH) : '';

    if ($path !== '' && preg_match('#^(.*)/plugins/system_updater/process_[^/]+\.php$#', $path, $matches) === 1) {
        $base = (string) $matches[1];
        define('FS_PATH', $base === '' ? '' : rtrim($base, '/') . '/');

        return;
    }

    $config2File = (defined('FS_FOLDER') ? FS_FOLDER : dirname(dirname(__DIR__))) . '/base/config2.php';
    if (file_exists($config2File)) {
        require_once $config2File;
    }
}

function system_updater_bootstrap_framework(): void
{
    if (!defined('FS_FOLDER')) {
        define('FS_FOLDER', dirname(dirname(__DIR__)));
    }

    if (is_dir(FS_FOLDER)) {
        chdir(FS_FOLDER);
    }

    if (file_exists(FS_FOLDER . '/config.php')) {
        require_once FS_FOLDER . '/config.php';
    }

    system_updater_ensure_fs_path();

    $autoloadFile = FS_FOLDER . '/vendor/autoload.php';
    if (file_exists($autoloadFile)) {
        require_once $autoloadFile;
    }
}

function system_updater_session_has_user(array $session): bool
{
    if (!empty($session['user_nick']) || !empty($session['user_id'])) {
        return true;
    }

    $attributes = $session['_sf2_attributes'] ?? [];

    return !empty($attributes['user_nick']) || !empty($attributes['user_logged_in']);
}

/**
 * @return array<string, mixed>
 */
function system_updater_flatten_session(array $session): array
{
    $flat = $session;
    unset($flat['_sf2_attributes']);

    if (isset($session['_sf2_attributes']) && is_array($session['_sf2_attributes'])) {
        foreach ($session['_sf2_attributes'] as $key => $value) {
            if (!array_key_exists($key, $flat)) {
                $flat[$key] = $value;
            }
        }
    }

    return $flat;
}

function system_updater_session_is_valid(array $session): bool
{
    $flat = system_updater_flatten_session($session);
    $nick = trim((string) ($flat['user_nick'] ?? ''));

    if ($nick === '') {
        return false;
    }

    if (!class_exists('FSFramework\\Security\\SessionPolicy')) {
        return true;
    }

    $loginTime = (int) ($flat['login_time'] ?? 0);
    $lastActivity = (int) ($flat['last_activity'] ?? 0);

    if ($loginTime <= 0 && $lastActivity <= 0) {
        return true;
    }

    if ($lastActivity <= 0) {
        $lastActivity = $loginTime;
    }

    return !\FSFramework\Security\SessionPolicy::isExpired($loginTime, $lastActivity);
}

function system_updater_resolve_cookie_path(): string
{
    if (defined('FS_PATH') && trim((string) FS_PATH) === '') {
        return '/';
    }

    $preferredPath = defined('FS_PATH') ? (string) FS_PATH : null;

    return system_updater_normalize_cookie_path($preferredPath, $_SERVER);
}

/**
 * @param array<string, mixed> $server
 */
function system_updater_normalize_cookie_path(?string $preferredPath, array $server): string
{
    $candidate = trim((string) $preferredPath);
    if ($candidate !== '') {
        return system_updater_normalize_cookie_path_value($candidate);
    }

    $scriptName = trim((string) ($server['SCRIPT_NAME'] ?? ''));
    if ($scriptName !== '') {
        return system_updater_normalize_cookie_path_value((string) dirname($scriptName));
    }

    $requestUri = filter_var((string) ($server['REQUEST_URI'] ?? '/'), FILTER_SANITIZE_URL);
    $parsedPath = parse_url($requestUri, PHP_URL_PATH);
    if (is_string($parsedPath) && $parsedPath !== '') {
        if (str_ends_with($parsedPath, '/index.php')) {
            $parsedPath = substr($parsedPath, 0, -10);
        } else {
            $parsedPath = (string) dirname($parsedPath);
        }

        return system_updater_normalize_cookie_path_value($parsedPath);
    }

    return '/';
}

function system_updater_normalize_cookie_path_value(string $path): string
{
    $normalized = trim(str_replace('\\', '/', $path));
    if ($normalized === '' || $normalized === '.' || $normalized === '/') {
        return '/';
    }

    $normalized = '/' . ltrim($normalized, '/');

    return str_ends_with($normalized, '/') ? $normalized : $normalized . '/';
}

function system_updater_configure_php_session(string $sessionName): void
{
    if (defined('FS_SESSION_SAVE_PATH') && trim((string) FS_SESSION_SAVE_PATH) !== '') {
        session_save_path(trim((string) FS_SESSION_SAVE_PATH));
    }

    $idleTimeout = class_exists('FSFramework\\Security\\SessionPolicy')
        ? \FSFramework\Security\SessionPolicy::getIdleTimeout()
        : (defined('FS_SESSION_LIFETIME') ? (int) FS_SESSION_LIFETIME : 7200);

    $secure = class_exists('FSFramework\\Security\\SecureRequestDetector')
        ? \FSFramework\Security\SecureRequestDetector::isSecure()
        : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => $idleTimeout,
        'path' => system_updater_resolve_cookie_path(),
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name($sessionName);
}

/**
 * Bind the existing browser session before Symfony SessionManager boots.
 */
function system_updater_native_session_start(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }

    foreach (system_updater_resolve_session_names() as $sessionName) {
        $sessionId = isset($_COOKIE[$sessionName]) ? trim((string) $_COOKIE[$sessionName]) : '';
        if ($sessionId === '') {
            continue;
        }

        system_updater_configure_php_session($sessionName);
        session_id($sessionId);

        if (@session_start()) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string, mixed>
 */
function system_updater_read_session_snapshot(): array
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return is_array($_SESSION ?? null) ? $_SESSION : [];
    }

    foreach (system_updater_resolve_session_names() as $sessionName) {
        $sessionId = isset($_COOKIE[$sessionName]) ? trim((string) $_COOKIE[$sessionName]) : '';
        if ($sessionId === '') {
            continue;
        }

        system_updater_configure_php_session($sessionName);
        session_id($sessionId);

        if (PHP_VERSION_ID >= 70100) {
            @session_start(['read_and_close' => true]);
        } else {
            @session_start();
        }

        $session = is_array($_SESSION ?? null) ? $_SESSION : [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if ($session !== []) {
            return $session;
        }
    }

    return [];
}

function system_updater_request_has_valid_csrf(): bool
{
    $token = trim((string) (
        $_GET['_csrf_token']
        ?? $_POST['_csrf_token']
        ?? $_POST['_token']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')
    ));

    if ($token === '' || !class_exists('FSFramework\\Security\\CsrfManager')) {
        return false;
    }

    return \FSFramework\Security\CsrfManager::isValid($token);
}

function system_updater_is_logged_in(): bool
{
    $sessionsToCheck = [];

    if (session_status() === PHP_SESSION_ACTIVE && is_array($_SESSION ?? null)) {
        $sessionsToCheck[] = $_SESSION;
    }

    $snapshot = system_updater_read_session_snapshot();
    if ($snapshot !== []) {
        $sessionsToCheck[] = $snapshot;
    }

    foreach ($sessionsToCheck as $session) {
        if (system_updater_session_has_user($session) && system_updater_session_is_valid($session)) {
            return true;
        }
    }

    // La página admin_updater solo emite CSRF a usuarios autenticados; si el token cuadra
    // con la misma FSSESS del navegador, la petición SSE es de esa sesión aunque Symfony
    // no haya rehidratado user_nick en este script standalone.
    if (system_updater_request_has_valid_csrf()) {
        return true;
    }

    if (class_exists('fs_session_manager', false)) {
        return fs_session_manager::isLoggedIn();
    }

    return false;
}

function system_updater_start_authenticated_session(): string
{
    system_updater_bootstrap_framework();

    if (!system_updater_native_session_start()) {
        foreach (system_updater_resolve_session_names() as $sessionName) {
            if (!empty($_COOKIE[$sessionName])) {
                system_updater_configure_php_session($sessionName);

                if (session_status() !== PHP_SESSION_ACTIVE) {
                    @session_start();
                }

                break;
            }
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name(system_updater_resolve_session_name());
            @session_start();
        }
    }

    return session_id();
}

function system_updater_require_authenticated_session(): string
{
    system_updater_start_authenticated_session();

    if (!system_updater_is_logged_in()) {
        system_updater_deny_unauthenticated_request();
    }

    return session_id();
}

function system_updater_deny_unauthenticated_request(): void
{
    $message = 'Error: Sesión no válida. Por favor, inicie sesión nuevamente.';

    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $accept = strtolower(trim((string) ($_SERVER['HTTP_ACCEPT'] ?? '')));
    $isSse = strpos($accept, 'text/event-stream') !== false
        || strpos((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''), 'empty') !== false
        && strpos((string) ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''), 'cors') !== false;

    if ($isSse || strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), 'process_core_update.php') !== false
        || strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), 'process_restore.php') !== false) {
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

    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
