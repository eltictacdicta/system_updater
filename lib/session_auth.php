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
        define('FS_PATH', $base === '' ? '/' : rtrim($base, '/') . '/');

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
function system_updater_read_session_snapshot(): array
{
    foreach (system_updater_resolve_session_names() as $sessionName) {
        $sessionId = isset($_COOKIE[$sessionName]) ? trim((string) $_COOKIE[$sessionName]) : '';
        if ($sessionId === '') {
            continue;
        }

        if (defined('FS_SESSION_SAVE_PATH') && trim((string) FS_SESSION_SAVE_PATH) !== '') {
            session_save_path(trim((string) FS_SESSION_SAVE_PATH));
        }

        session_name($sessionName);
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

function system_updater_is_logged_in(): bool
{
    if (class_exists('fs_session_manager', false)) {
        return fs_session_manager::isLoggedIn();
    }

    return system_updater_session_has_user(system_updater_read_session_snapshot());
}

function system_updater_start_authenticated_session(): string
{
    system_updater_bootstrap_framework();

    $sessionManagerFile = FS_FOLDER . '/base/fs_session_manager.php';
    if (file_exists($sessionManagerFile)) {
        require_once $sessionManagerFile;
        fs_session_manager::initialize();
    } else {
        foreach (system_updater_resolve_session_names() as $sessionName) {
            if (!empty($_COOKIE[$sessionName])) {
                if (defined('FS_SESSION_SAVE_PATH') && trim((string) FS_SESSION_SAVE_PATH) !== '') {
                    session_save_path(trim((string) FS_SESSION_SAVE_PATH));
                }

                session_name($sessionName);

                if (session_status() !== PHP_SESSION_ACTIVE) {
                    session_start();
                }

                break;
            }
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name(system_updater_resolve_session_name());
            session_start();
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
