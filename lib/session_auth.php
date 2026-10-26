<?php
/**
 * Session bootstrap and authentication for standalone system_updater process scripts.
 *
 * These scripts bypass index.php, so they must resolve the same session name,
 * save path, and cookie settings as FSFramework before validating login.
 */

// El nombre de la sesión del plugin vive aparte (lib/session_name.php): el
// console de recuperación también lo necesita y no puede cargar este fichero,
// porque aquí vive el bootstrap del framework y ese es justo el grafo que
// RACC-01 prohíbe en el console. La derivación es la misma; sólo cambia de
// sitio, y la comparten los dos (RACC-07).
require_once __DIR__ . '/session_name.php';

/**
 * Framework session cookie name, as the core itself resolves it.
 *
 * Single source of truth: {@see \FSFramework\Security\SessionManager::resolveSessionName()}.
 * Never re-derive it here with the pre-0.28.0 formula (seed = install root):
 * the core moved that seed to the per-install secret, so a local copy only
 * matches while FS_SESSION_NAME is set. Without that override the standalone
 * process scripts would stop finding the framework session altogether.
 *
 * The local branch below is only reached before the plugin bootstrap loads the
 * Composer autoloader, where the framework class is not available yet.
 */
function system_updater_resolve_framework_session_name(): string
{
    if (class_exists('FSFramework\\Security\\SessionManager')) {
        return \FSFramework\Security\SessionManager::resolveSessionName();
    }

    if (defined('FS_SESSION_NAME') && trim((string) FS_SESSION_NAME) !== '') {
        return trim((string) FS_SESSION_NAME);
    }

    $seed = str_replace('\\', '/', defined('FS_FOLDER') ? (string) FS_FOLDER : dirname(dirname(dirname(__DIR__))));

    return 'FSSESS_' . substr(sha1($seed), 0, 12);
}

/**
 * @return array<int, string>
 */
function system_updater_resolve_session_names(): array
{
    // La sesión del plugin tiene prioridad
    $names = [system_updater_resolve_session_name()];

    // Luego las sesiones del framework como fallback
    $fsSessionName = system_updater_resolve_framework_session_name();
    if (!in_array($fsSessionName, $names, true)) {
        $names[] = $fsSessionName;
    }

    $iniName = trim((string) ini_get('session.name'));
    if ($iniName !== '' && !in_array($iniName, $names, true)) {
        $names[] = $iniName;
    }

    if (!in_array('PHPSESSID', $names, true)) {
        $names[] = 'PHPSESSID';
    }

    return $names;
}

/**
 * Resolves the application base path for standalone process/recovery scripts.
 *
 * Must run before config.php/config2.php so FS_PATH matches index.php (cookie path "/")
 * instead of the plugin subdirectory derived from SCRIPT_NAME.
 * 
 * Returns '/' for root installs (not empty string) to avoid ambiguity.
 */
function system_updater_resolve_app_fs_path_from_request(): string
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = is_string(parse_url($requestUri, PHP_URL_PATH)) ? parse_url($requestUri, PHP_URL_PATH) : '';

    if ($path !== '' && preg_match('#^(.*)/plugins/system_updater/(?:process_[^/]+\.php|recovery\.php)$#', $path, $matches) === 1) {
        $base = (string) $matches[1];

        // Return '/' for root installs, not empty string
        return $base === '' ? '/' : rtrim($base, '/') . '/';
    }

    // Return '/' as default, not empty string
    return '/';
}

function system_updater_is_standalone_process_request(): bool
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = is_string(parse_url($requestUri, PHP_URL_PATH)) ? parse_url($requestUri, PHP_URL_PATH) : '';

    return $path !== ''
        && preg_match('#/plugins/system_updater/(?:process_[^/]+\.php|recovery\.php)$#', $path) === 1;
}

/**
 * Define FS_PATH for process scripts before config.php loads config2.php.
 */
function system_updater_prime_fs_path_from_request(): void
{
    if (defined('FS_PATH')) {
        return;
    }

    if (!system_updater_is_standalone_process_request()) {
        return;
    }

    define('FS_PATH', system_updater_resolve_app_fs_path_from_request());
}

function system_updater_ensure_fs_path(): void
{
    if (!defined('FS_PATH')) {
        $primedPath = system_updater_resolve_app_fs_path_from_request();
        if (system_updater_is_standalone_process_request()) {
            define('FS_PATH', $primedPath);

            return;
        }
    } elseif (system_updater_is_standalone_process_request()) {
        // config2.php may have set FS_PATH from SCRIPT_NAME (/plugins/system_updater/).
        // Cookie path must match index.php; callers cannot redefine the constant.
        return;
    } else {
        return;
    }

    $config2File = (defined('FS_FOLDER') ? FS_FOLDER : dirname(dirname(dirname(__DIR__)))) . '/base/config2.php';
    if (file_exists($config2File)) {
        require_once $config2File;
    }
}

function system_updater_bootstrap_framework(): void
{
    if (!defined('FS_FOLDER')) {
        define('FS_FOLDER', dirname(dirname(dirname(__DIR__))));
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

/**
 * Nick written by a completed login, or null.
 *
 * Pure by contract: no session call, no I/O and no class lookup, so it can be
 * asserted over an injected session array (AUTH-02).
 *
 * @param array<string, mixed> $session
 */
function system_updater_resolve_session_nick(array $session): ?string
{
    $flat = system_updater_flatten_session($session);
    $nick = trim((string) ($flat['user_nick'] ?? ''));

    return $nick === '' ? null : $nick;
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
    // Standalone SSE/AJAX scripts must share the same cookie scope as index.php.
    // This ensures the SU_SESS_* cookie is sent to both controller and SSE scripts.
    if (system_updater_is_standalone_process_request()) {
        return system_updater_resolve_app_fs_path_from_request();
    }

    // For controller requests, use FS_PATH if defined
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

/**
 * Apply the framework session configuration to the PHP session module.
 *
 * @param string $sessionName session name to adopt
 * @param bool $withCookieParams whether the browser-facing cookie parameters
 *                               must be configured too. An isolated read turns
 *                               them off: it must not write a cookie, and
 *                               `session_set_cookie_params()` warns loudly when
 *                               cookie emission is already suspended.
 */
function system_updater_configure_php_session(string $sessionName, bool $withCookieParams = true): void
{
    if (defined('FS_SESSION_SAVE_PATH') && trim((string) FS_SESSION_SAVE_PATH) !== '') {
        session_save_path(trim((string) FS_SESSION_SAVE_PATH));
    }

    if (!$withCookieParams) {
        session_name($sessionName);

        return;
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
 * Stop `session_start()` from writing session headers, remembering the state.
 *
 * Must run while no session is active: PHP refuses to change these ini values
 * during an active session. The two settings are the whole header surface of
 * a session start — `session.use_cookies` writes `Set-Cookie`, and
 * `session.cache_limiter` writes `Expires`/`Pragma`/`Cache-Control`.
 *
 * @return array{use_cookies: string, cache_limiter: string}
 */
function system_updater_suspend_session_headers(): array
{
    $previous = [
        'use_cookies' => (string) ini_get('session.use_cookies'),
        'cache_limiter' => (string) ini_get('session.cache_limiter'),
    ];

    ini_set('session.use_cookies', '0');
    ini_set('session.cache_limiter', '');

    return $previous;
}

/**
 * Restore what {@see system_updater_suspend_session_headers()} suspended.
 *
 * Leaves the module alone when a session is somehow still active: PHP would
 * reject the change and emit a warning into the response, which is worse than
 * the caller's own start applying the previous values again.
 *
 * @param array{use_cookies: string, cache_limiter: string} $previous
 */
function system_updater_restore_session_headers(array $previous): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_cookies', (string) ($previous['use_cookies'] ?? '1'));
    ini_set('session.cache_limiter', (string) ($previous['cache_limiter'] ?? 'nocache'));
}

/**
 * Bind the existing browser session before Symfony SessionManager boots.
 */
function system_updater_native_session_start(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }

    // El plugin usa su propia sesión independiente (SU_SESS_*)
    // para evitar conflictos con las sesiones del framework
    $sessionName = system_updater_resolve_session_name();
    $sessionId = isset($_COOKIE[$sessionName]) ? trim((string) $_COOKIE[$sessionName]) : '';
    
    if ($sessionId !== '') {
        // Configurar sesión del plugin
        system_updater_configure_php_session($sessionName);
        session_id($sessionId);

        if (@session_start()) {
            return true;
        }
    }

    // Si no hay cookie de sesión del plugin, intentar con las sesiones del framework
    // como fallback para mantener compatibilidad
    foreach (system_updater_resolve_session_names() as $fallbackName) {
        if ($fallbackName === $sessionName) {
            continue; // Ya lo intentamos
        }
        
        $fallbackId = isset($_COOKIE[$fallbackName]) ? trim((string) $_COOKIE[$fallbackName]) : '';
        if ($fallbackId === '') {
            continue;
        }

        system_updater_configure_php_session($fallbackName);
        session_id($fallbackId);

        if (@session_start()) {
            return true;
        }
    }

    return false;
}

/**
 * Enumerate every candidate session for the request.
 *
 * Pure: the I/O lives in `$readSession`. It evaluates ALL names — it never
 * returns at the first non-empty one, which is the defect AUTH-02 closes —
 * and a candidate that cannot be opened is skipped, not fatal.
 *
 * @param array<int, string> $sessionNames
 * @param array<string, mixed> $cookies
 * @param callable(string, string): array<string, mixed> $readSession
 * @return array<string, array<string, mixed>> sessions keyed by session name
 */
function system_updater_collect_session_candidates(array $sessionNames, array $cookies, callable $readSession): array
{
    $candidates = [];

    foreach ($sessionNames as $sessionName) {
        $name = trim((string) $sessionName);
        if ($name === '' || array_key_exists($name, $candidates)) {
            continue;
        }

        $sessionId = isset($cookies[$name]) ? trim((string) $cookies[$name]) : '';
        if ($sessionId === '') {
            continue;
        }

        try {
            $session = $readSession($name, $sessionId);
        } catch (\Throwable $error) {
            $session = [];
        }

        if (!is_array($session) || $session === []) {
            continue;
        }

        $candidates[$name] = $session;
    }

    return $candidates;
}

/**
 * Read one session by cookie name and leave the caller's session untouched.
 *
 * The read is silent: no `Set-Cookie` for the candidate session, no cache
 * directives from the session cache limiter, and both ini settings restored
 * afterwards so the rest of the request sees the module exactly as before. A
 * `status`/`progress` poll runs this once per candidate and must not rewrite
 * the browser's cookies while it does.
 *
 * Ordering matters and is load-bearing: `session_set_cookie_params()` warns
 * when cookie emission is suspended, so the session is configured first, and
 * the suspension starts only once the caller's session is closed (PHP refuses
 * to change these ini values during an active session). The restore runs before
 * the caller's session is reopened, for the same reason.
 *
 * @return array<string, mixed>
 */
function system_updater_read_session_file(string $sessionName, string $sessionId): array
{
    $wasActive = session_status() === PHP_SESSION_ACTIVE;
    $savedName = $wasActive ? (string) session_name() : '';
    $savedId = $wasActive ? (string) session_id() : '';
    $savedSession = $wasActive && is_array($_SESSION ?? null) ? $_SESSION : [];

    if ($wasActive) {
        session_write_close();
    }

    $session = [];
    $suspendedIni = null;

    try {
        $_SESSION = [];
        system_updater_configure_php_session($sessionName, false);
        $suspendedIni = system_updater_suspend_session_headers();
        session_id($sessionId);
        $started = @session_start(['read_and_close' => true]);
        $session = $started && is_array($_SESSION ?? null) ? $_SESSION : [];
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_SESSION = [];

        if (is_array($suspendedIni)) {
            system_updater_restore_session_headers($suspendedIni);
        }
    }

    if ($wasActive) {
        // Put the caller's session back: the bootstrap returns session_id()
        // and later reads/writes that same session. Its cookie was already
        // written when the bootstrap opened it, so the same name and value are
        // re-sent here at worst — never a cookie for somebody else's session.
        system_updater_configure_php_session($savedName);
        session_id($savedId);
        @session_start();
        $_SESSION = $savedSession;
    }

    return $session;
}

/**
 * All readable candidate sessions for this request, keyed by session name.
 *
 * The plugin session and the framework session are distinct candidates and
 * both are read, including when one of them is already open (AUTH-02).
 *
 * @return array<string, array<string, mixed>>
 */
function system_updater_read_session_candidates(): array
{
    $activeName = '';
    $activeSession = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        $activeName = (string) session_name();
        $activeSession = is_array($_SESSION ?? null) ? $_SESSION : [];
    }

    $candidates = system_updater_collect_session_candidates(
        system_updater_resolve_session_names(),
        is_array($_COOKIE ?? null) ? $_COOKIE : [],
        static function (string $sessionName, string $sessionId) use ($activeName, $activeSession): array {
            if ($activeName !== '' && $sessionName === $activeName) {
                return $activeSession;
            }

            return system_updater_read_session_file($sessionName, $sessionId);
        }
    );

    if ($activeName !== '' && $activeSession !== [] && !array_key_exists($activeName, $candidates)) {
        // An open session may have no cookie entry yet; it is still a candidate.
        $candidates = [$activeName => $activeSession] + $candidates;
    }

    return $candidates;
}

/**
 * Verdict vocabulary of the guard decision.
 *
 * There is exactly one denial reason for every cause — anonymous, expired,
 * unknown nick, non-administrator, failed verdict — because the caller must
 * not be able to tell them apart (AUTH-04).
 */
if (!defined('SYSTEM_UPDATER_GUARD_VERDICT_ACCEPT')) {
    define('SYSTEM_UPDATER_GUARD_VERDICT_ACCEPT', 'accept');
}
if (!defined('SYSTEM_UPDATER_GUARD_VERDICT_DENY')) {
    define('SYSTEM_UPDATER_GUARD_VERDICT_DENY', 'deny');
}
if (!defined('SYSTEM_UPDATER_GUARD_REASON_AUTHENTICATED')) {
    define('SYSTEM_UPDATER_GUARD_REASON_AUTHENTICATED', 'authenticated');
}
if (!defined('SYSTEM_UPDATER_GUARD_REASON_UNAUTHENTICATED')) {
    define('SYSTEM_UPDATER_GUARD_REASON_UNAUTHENTICATED', 'unauthenticated');
}

/**
 * The refusal text, and the only copy of it.
 *
 * AUTH-04: every denial — anonymous, expired, unknown nick, non-administrator,
 * unresolvable verdict — produces the same refusal body, and the transport
 * difference (a JSON 401 here, an SSE `error` event there) must never become a
 * denial-reason difference. Two literals cannot promise that; one constant and
 * two payload builders can.
 *
 * Deliberately not named after a cause: the moment it reads like a diagnosis,
 * somebody will add a second one.
 */
if (!defined('SYSTEM_UPDATER_GUARD_DENIAL')) {
    define('SYSTEM_UPDATER_GUARD_DENIAL', 'Error: Sesión no válida. Por favor, inicie sesión nuevamente.');
}

/**
 * Identity from any candidate session, never from the first non-empty one.
 *
 * An expired candidate contributes no identity. A candidate carrying only
 * plugin token state is "no evidence" and is skipped, never treated as an
 * identity that failed (AUTH-02).
 *
 * @param array<string, array<string, mixed>> $sessions
 */
function system_updater_resolve_session_identity(array $sessions): ?string
{
    foreach ($sessions as $session) {
        if (!is_array($session) || !system_updater_session_is_valid($session)) {
            continue;
        }

        $nick = system_updater_resolve_session_nick($session);
        if ($nick !== null) {
            return $nick;
        }
    }

    return null;
}

/**
 * The uniform denial shape. Every cause returns exactly this value.
 *
 * @return array{verdict: string, nick: null, reason: string}
 */
function system_updater_guard_denial(): array
{
    return [
        'verdict' => SYSTEM_UPDATER_GUARD_VERDICT_DENY,
        'nick' => null,
        'reason' => SYSTEM_UPDATER_GUARD_REASON_UNAUTHENTICATED,
    ];
}

/**
 * Pure guard decision over an injected map of candidate sessions.
 *
 * Identity comes only from a session written by a completed login. Nothing in
 * `$request` can confer it — the parameter exists so the contract is
 * assertable, and it is deliberately never read (AUTH-01). The resolved nick
 * is then confirmed against the authoritative administrator verdict, and
 * anything other than a strict `true` denies (AUTH-03).
 *
 * @param array<string, array<string, mixed>> $sessions candidate sessions keyed by session name
 * @param array<string, mixed> $request incoming request — deliberately unread
 * @param callable(string): mixed $isAdmin authoritative administrator verdict for a nick
 * @return array{verdict: string, nick: ?string, reason: string}
 */
function system_updater_guard_decision(array $sessions, array $request, callable $isAdmin): array
{
    $nick = system_updater_resolve_session_identity($sessions);

    if ($nick === null) {
        return system_updater_guard_denial();
    }

    try {
        $verdict = $isAdmin($nick);
    } catch (\Throwable $error) {
        return system_updater_guard_denial();
    }

    if ($verdict !== true) {
        return system_updater_guard_denial();
    }

    return [
        'verdict' => SYSTEM_UPDATER_GUARD_VERDICT_ACCEPT,
        'nick' => $nick,
        'reason' => SYSTEM_UPDATER_GUARD_REASON_AUTHENTICATED,
    ];
}

/**
 * The only database backend this guard can read `fs_users` through.
 *
 * One client, one backend: `mysqli`. `recovery.php` connects the same way, and
 * `lib/backup_manager.php`'s PostgreSQL branches only shell out to
 * `pg_dump`/`psql` — they never hand PHP a PostgreSQL reader. A PostgreSQL
 * install is therefore a supported target for backups and an unreadable one
 * for this guard, which is why the backend has to be *classified* before a
 * connection is attempted instead of being discovered by a failed connect.
 */
if (!defined('SYSTEM_UPDATER_GUARD_BACKEND_MYSQL')) {
    define('SYSTEM_UPDATER_GUARD_BACKEND_MYSQL', 'MYSQL');
}

/**
 * Diagnostic kind for "this installation's backend is not readable here".
 *
 * Distinct from {@see SYSTEM_UPDATER_GUARD_REASON_UNAUTHENTICATED} on purpose:
 * AUTH-04 keeps the *caller* from telling causes apart, and this token is the
 * *operator's* copy, written to the error log and to the decision's callers.
 */
if (!defined('SYSTEM_UPDATER_GUARD_DIAGNOSTIC_BACKEND_UNSUPPORTED')) {
    define('SYSTEM_UPDATER_GUARD_DIAGNOSTIC_BACKEND_UNSUPPORTED', 'backend_unsupported');
}

/**
 * Diagnostic kind for "the mysqli extension is not loaded in this process".
 *
 * A supported backend with no client is the same operator-facing situation as
 * an unsupported backend — nobody can be verified — but the remedy is completely
 * different (install an extension, not migrate a database), so it gets its own
 * kind rather than being folded into the backend one.
 */
if (!defined('SYSTEM_UPDATER_GUARD_DIAGNOSTIC_MYSQLI_MISSING')) {
    define('SYSTEM_UPDATER_GUARD_DIAGNOSTIC_MYSQLI_MISSING', 'mysqli_missing');
}

/**
 * Diagnostic kind for "the connection constants are not defined".
 *
 * The realistic cause is a damaged or half-restored installation whose
 * `config.php` no longer defines FS_DB_HOST/FS_DB_USER/FS_DB_NAME.
 */
if (!defined('SYSTEM_UPDATER_GUARD_DIAGNOSTIC_DB_CONFIG_MISSING')) {
    define('SYSTEM_UPDATER_GUARD_DIAGNOSTIC_DB_CONFIG_MISSING', 'db_config_missing');
}

/**
 * Canonical name for the configured database backend.
 *
 * `FS_DB_TYPE` is free-form in legacy installs: `mariadb` speaks the MySQL
 * protocol, and `POSTGRES`/`PGSQL` are aliases of `POSTGRESQL`. An empty or
 * absent value keeps the historical MySQL default, which is what
 * `backup_manager`'s `normalize_database_type()` already assumes.
 *
 * @param string|null $dbType backend name; `null` reads `FS_DB_TYPE`
 */
function system_updater_normalize_database_backend(?string $dbType = null): string
{
    $configured = $dbType ?? (defined('FS_DB_TYPE') ? (string) FS_DB_TYPE : '');
    $normalized = strtoupper(trim($configured));

    if ($normalized === '' || $normalized === 'MARIADB') {
        return SYSTEM_UPDATER_GUARD_BACKEND_MYSQL;
    }

    if (in_array($normalized, ['POSTGRES', 'POSTGRESQL', 'PGSQL'], true)) {
        return 'POSTGRESQL';
    }

    return $normalized;
}

/**
 * Whether this installation's backend can be read by the guard.
 *
 * Pure: no connection, no session, and the only constant it reads is
 * `FS_DB_TYPE`, and only when no argument is given. That is what makes the
 * classification assertable in a suite with no database.
 *
 * @return array{backend: string, supported: bool, diagnostic: string}
 */
function system_updater_admin_backend_support(?string $dbType = null): array
{
    $backend = system_updater_normalize_database_backend($dbType);
    $supported = $backend === SYSTEM_UPDATER_GUARD_BACKEND_MYSQL;

    return [
        'backend' => $backend,
        'supported' => $supported,
        'diagnostic' => $supported ? '' : SYSTEM_UPDATER_GUARD_DIAGNOSTIC_BACKEND_UNSUPPORTED,
    ];
}

/**
 * Connection constants this guard cannot work without.
 *
 * `FS_DB_PASS` and `FS_DB_PORT` are read defensively (`defined()` guards) and
 * have documented defaults, so their absence is legitimate. These three do not.
 *
 * @return array<int, string>
 */
function system_updater_admin_read_required_constants(): array
{
    return ['FS_DB_HOST', 'FS_DB_USER', 'FS_DB_NAME'];
}

/**
 * Whether this process can read `fs_users` at all — and, when it cannot, why.
 *
 * The guard is fail-closed either way; what this removes is the *silence*. A
 * missing `mysqli` extension and a `config.php` that lost its connection
 * constants both used to look exactly like a visitor with no session: `null`
 * from the resolver, one anonymous denial, no operator-visible cause.
 *
 * Pure over its two overrides, so a suite with no database can assert both
 * branches without uninstalling an extension or breaking `config.php`.
 *
 * Precedence is deliberate: no client outranks no configuration, because
 * installing the extension is a prerequisite for the constants to matter.
 *
 * @param bool|null $mysqliAvailable override for "mysqli is loaded"
 * @param array<int, string>|null $definedConstants override for "which of the
 *        required constants exist"; `null` asks PHP
 * @return array{available: bool, diagnostic: string, missing: array<int, string>}
 */
function system_updater_admin_read_availability(?bool $mysqliAvailable = null, ?array $definedConstants = null): array
{
    if (($mysqliAvailable ?? class_exists('mysqli')) !== true) {
        return [
            'available' => false,
            'diagnostic' => SYSTEM_UPDATER_GUARD_DIAGNOSTIC_MYSQLI_MISSING,
            'missing' => ['mysqli'],
        ];
    }

    $missing = [];
    foreach (system_updater_admin_read_required_constants() as $name) {
        $isDefined = $definedConstants === null ? defined($name) : in_array($name, $definedConstants, true);
        if (!$isDefined) {
            $missing[] = $name;
        }
    }

    if ($missing !== []) {
        return [
            'available' => false,
            'diagnostic' => SYSTEM_UPDATER_GUARD_DIAGNOSTIC_DB_CONFIG_MISSING,
            'missing' => $missing,
        ];
    }

    return ['available' => true, 'diagnostic' => '', 'missing' => []];
}

/**
 * Operator-facing line for an administrator read that cannot be performed.
 *
 * Deliberately *not* the uniform denial text: that text is what the caller
 * sees. This one is what the operator sees, so it names the backend and what is
 * missing. A bare `null` was the defect — it silently turned "we cannot check"
 * into "you are not logged in".
 *
 * @param array<int, string> $missing what the diagnostic determined is absent
 */
function system_updater_admin_read_diagnostic_message(string $diagnostic, string $backend, array $missing = []): string
{
    if ($diagnostic === SYSTEM_UPDATER_GUARD_DIAGNOSTIC_BACKEND_UNSUPPORTED) {
        return 'system_updater: no se puede verificar la cuenta de administrador. Este guard solo'
            . ' lee fs_users con mysqli y la instalación usa el motor ' . $backend . '. Toda acción'
            . ' protegida (status, progress, descargas) será rechazada hasta que la instalación use'
            . ' MySQL/MariaDB o el guard incorpore un lector para ' . $backend . '.';
    }

    if ($diagnostic === SYSTEM_UPDATER_GUARD_DIAGNOSTIC_MYSQLI_MISSING) {
        return 'system_updater: no se puede verificar la cuenta de administrador. La extensión mysqli de'
            . ' PHP no está cargada en este proceso y es el único cliente con el que este guard lee'
            . ' fs_users. Toda acción protegida (status, progress, descargas) será rechazada hasta que la'
            . ' extensión esté disponible.';
    }

    if ($diagnostic === SYSTEM_UPDATER_GUARD_DIAGNOSTIC_DB_CONFIG_MISSING) {
        return 'system_updater: no se puede verificar la cuenta de administrador. Faltan las constantes'
            . ' de conexión ' . implode(', ', $missing) . ' (config.php), así que este guard no puede'
            . ' abrir la base de datos. Toda acción protegida (status, progress, descargas) será'
            . ' rechazada hasta que estén definidas.';
    }

    return 'system_updater: no se puede verificar la cuenta de administrador (' . $diagnostic
        . '). Toda acción protegida será rechazada.';
}

/**
 * Per-process record of the diagnostics already written, keyed by
 * diagnostic + backend.
 *
 * @return array<string, bool>
 */
function system_updater_admin_read_diagnostics(): array
{
    $reported = $GLOBALS['system_updater_admin_read_diagnostics'] ?? [];

    return is_array($reported) ? $reported : [];
}

/**
 * Record an unavailable administrator read where an operator will see it.
 *
 * Once per process per diagnostic: `status` and `progress` poll every couple of
 * seconds, and flooding the log would bury the first line — the only one an
 * operator reads.
 *
 * @param array<int, string> $missing passed through to the message
 */
function system_updater_report_admin_read_unavailable(string $diagnostic, string $backend, array $missing = []): void
{
    $key = $diagnostic . ':' . $backend;
    $reported = system_updater_admin_read_diagnostics();

    if (($reported[$key] ?? false) === true) {
        return;
    }

    $reported[$key] = true;
    $GLOBALS['system_updater_admin_read_diagnostics'] = $reported;

    error_log(system_updater_admin_read_diagnostic_message($diagnostic, $backend, $missing));
}

/**
 * Clear the per-process diagnostic record. Test seam only.
 */
function system_updater_reset_admin_read_diagnostics(): void
{
    $GLOBALS['system_updater_admin_read_diagnostics'] = [];
}

/**
 * Authoritative administrator verdict for a nick: one fresh row read, no cache.
 *
 * Returns `true` only when `fs_users.admin` is truthy for that nick, `false`
 * when the row is missing or the flag is falsy, and `null` when the database
 * cannot be read at all. Every value other than `true` denies (AUTH-03), so a
 * deleted row, a demotion and an unreachable database are all "no".
 *
 * A backend this guard cannot read is a `null` too — but a *reported* one: the
 * backend is classified before a connection is attempted and the refusal is
 * written to the error log, because an unreachable-by-design backend and an
 * anonymous visitor must not look the same to the operator (see
 * {@see system_updater_admin_backend_support()}).
 *
 * The same holds for the two ways the reader environment can be unusable
 * without being a backend problem: no `mysqli` extension, and no connection
 * constants. Both are diagnosed by
 * {@see system_updater_admin_read_availability()} before a connection is
 * attempted — never discovered by a silent `null`.
 *
 * Reading the row directly is deliberate. The standalone scripts bootstrap
 * only `config.php` plus the Composer autoloader, so the legacy `fs_user`
 * model is not loadable here — measured in this container: with only Composer
 * registered, `class_exists('fs_user')` is false — and the framework adapter
 * answers `null` for that reason alone, which would deny every administrator.
 * A prepared statement against `fs_users` is the same path `recovery.php` and
 * `backup_manager` already use in this standalone runtime.
 *
 * @param string $nick user whose `fs_users.admin` flag is authoritative
 * @param string|null $dbType backend override; `null` reads `FS_DB_TYPE`
 * @param bool|null $mysqliAvailable override for "the mysqli extension is loaded"
 * @param array<int, string>|null $definedConstants override for "which required
 *        constants exist"; `null` asks PHP
 */
function system_updater_resolve_admin_verdict(
    string $nick,
    ?string $dbType = null,
    ?bool $mysqliAvailable = null,
    ?array $definedConstants = null
): ?bool {
    $nick = trim($nick);
    if ($nick === '') {
        return false;
    }

    // Classify the backend before touching a socket. Discovering it through a
    // failed connect would report every administrator as anonymous, which is
    // the silent denial this contract exists to remove.
    $backendSupport = system_updater_admin_backend_support($dbType);
    if (!$backendSupport['supported']) {
        system_updater_report_admin_read_unavailable(
            $backendSupport['diagnostic'],
            $backendSupport['backend']
        );

        return null;
    }

    // Then classify the reader environment for the same reason: a missing
    // extension and a broken config.php are operator problems that used to
    // present as "you are not logged in".
    $availability = system_updater_admin_read_availability($mysqliAvailable, $definedConstants);
    if (!$availability['available']) {
        system_updater_report_admin_read_unavailable(
            $availability['diagnostic'],
            SYSTEM_UPDATER_GUARD_BACKEND_MYSQL,
            $availability['missing']
        );

        return null;
    }

    $link = null;
    $statement = null;

    try {
        $link = @new \mysqli(
            (string) FS_DB_HOST,
            (string) FS_DB_USER,
            defined('FS_DB_PASS') ? (string) FS_DB_PASS : '',
            (string) FS_DB_NAME,
            defined('FS_DB_PORT') ? (int) FS_DB_PORT : 3306
        );

        $statement = $link->prepare('SELECT admin FROM fs_users WHERE nick = ? LIMIT 1');
        if ($statement === false) {
            return null;
        }

        $statement->bind_param('s', $nick);
        if (!$statement->execute()) {
            return null;
        }

        $result = $statement->get_result();
        $row = $result === false ? null : $result->fetch_assoc();

        if (!is_array($row)) {
            return false;
        }

        return !empty($row['admin']);
    } catch (\Throwable $error) {
        return null;
    } finally {
        if ($statement instanceof \mysqli_stmt) {
            @$statement->close();
        }

        if ($link instanceof \mysqli) {
            @$link->close();
        }
    }
}

function system_updater_is_logged_in(): bool
{
    $decision = system_updater_guard_decision(
        system_updater_read_session_candidates(),
        is_array($_REQUEST ?? null) ? $_REQUEST : [],
        'system_updater_resolve_admin_verdict'
    );

    if ($decision['verdict'] === SYSTEM_UPDATER_GUARD_VERDICT_ACCEPT) {
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

/**
 * The JSON refusal body shared by every denial (AUTH-04).
 *
 * @return array{success: false, message: string}
 */
function system_updater_denial_payload(): array
{
    return [
        'success' => false,
        'message' => SYSTEM_UPDATER_GUARD_DENIAL,
    ];
}

/**
 * The SSE refusal payload shared by every denial (AUTH-04).
 *
 * Same `message` as {@see system_updater_denial_payload()}; only the framing
 * differs, because the transport is the one thing that legitimately differs.
 *
 * @return array{message: string, percent: int}
 */
function system_updater_denial_sse_payload(): array
{
    return [
        'message' => SYSTEM_UPDATER_GUARD_DENIAL,
        'percent' => 0,
    ];
}

function system_updater_deny_unauthenticated_request(): void
{
    $message = SYSTEM_UPDATER_GUARD_DENIAL;

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
        echo 'data: ' . json_encode(
            system_updater_denial_sse_payload(),
            JSON_UNESCAPED_UNICODE
        ) . "\n\n";
        @flush();
        exit;
    }

    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(system_updater_denial_payload(), JSON_UNESCAPED_UNICODE);
    exit;
}
