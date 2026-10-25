<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * The console's own session bootstrap and its authorization marker
 * (RACC-05, RACC-08).
 *
 * Why this file exists instead of reusing `lib/session_auth.php`
 * --------------------------------------------------------------
 * The console must boot when the core does not (`RACC-01`): no Composer
 * autoloader, no class under `base/`, `src/`, `controller/` or `model/`, and no
 * kernel. `session_auth.php` is written for the process scripts, which run
 * *after* the framework is healthy, and it reaches into core classes with the
 * autoloader on — `SessionManager`, `SessionPolicy`, `SecureRequestDetector`.
 * The console cannot afford that: a single `class_exists()` with autoload
 * enabled is a load of core code the moment the class exists, which is exactly
 * the dependency A1 removes.
 *
 * So this file reimplements the *policy* while consuming the *same state*: the
 * same session name the core derives, the same save path, the same cookie
 * parameters, the same per-installation secret as a seed. Never a parallel
 * bucket — the operator's panel session and the console's session are the same
 * session, which is what keeps single sign-on and REC-08's re-check working.
 *
 * The marker (RACC-05)
 * --------------------
 * The gate is a query parameter. Without a marker, every reload and the login
 * POST itself would need the secret re-supplied, which multiplies the places it
 * leaks into (history, `Referer`, access logs) and is impractical besides.
 *
 * The marker is therefore written into the session the console opened, under a
 * namespace of its own (`recovery_console`), and only after a successful
 * constant-time comparison. It is:
 *
 * - not an identity key: it names nobody, and the process scripts' guard never
 *   reads it (it looks for `user_nick` / `user_id` / `_sf2_attributes`);
 * - not a credential: `download_backup.php` and every `process_*` script deny a
 *   marker-only session on their own guard, before their token is read;
 * - discarded with the session, and rotated by `session_regenerate_id(true)`
 *   together with the login rotation (`REC-06`, wired in a later slice).
 *
 * It also names the credential that issued it. `authorized => true` on its own
 * would outlive that credential: `--rotate-secret` is RACC-06's way to retire a
 * leaked secret, but it only changes what the gate accepts, so a session that
 * already carried the bare marker kept opening the console for the rest of the
 * session lifetime — whoever obtained the old secret kept their way in. The
 * marker therefore stores a fingerprint of the granting value and is verified
 * against the credential the gate would accept *now*, so a rotation retires
 * every marker it did not issue.
 *
 * The operator pin is the same rule, not an exception. The fingerprint is always
 * over the value the gate answered against — the persisted value when one
 * exists, the pin only in the state where nothing is persisted — so a
 * pin-granted marker survives a rotation only while the pin is still the current
 * credential, and dies the moment a value is persisted (which is what the first
 * `--rotate-secret` does) or the pin stops being defined. Rotating the persisted
 * value when a pin is in force changes nothing about what grants access, so
 * refusing that marker would be theatre; the pin itself has to be retired.
 *
 * Reading it must not be a write. The marker is read with headers suspended —
 * no `Set-Cookie`, no cache directives — and only for a session id that already
 * has a file on disk, so a request that carries a session cookie it invented
 * cannot make the console create a session. That is also what keeps the
 * refusal path free of session state, and what keeps a marker refused for a
 * stale fingerprint from being rewritten into a valid one.
 */

/**
 * The per-installation secret this file reasons about.
 *
 * `require_once` because the marker is meaningless without it — the fingerprint
 * is compared against the value the gate answers against — and because the
 * console's own graph loads the secret library first anyway, so this is a
 * no-op there. Both files are plugin-local and core-free (RACC-01); nothing
 * under `base/`, `src/`, `controller/`, `model/` or `vendor/` is touched.
 */
require_once __DIR__ . '/recovery_secret.php';

/**
 * The marker's namespace inside the console's session.
 *
 * Named as a constant so the confinement assertion has one spelling to look
 * for; the value is part of the contract because no other script of this plugin
 * may adopt it as an identity.
 */
const RECOVERY_CONSOLE_NAMESPACE = 'recovery_console';

/** Prefix of the application session cookie name, as the core derives it. */
const RECOVERY_SESSION_APP_PREFIX = 'FSSESS_';

/**
 * Minimum length the core accepts for a configured session seed. Mirrors
 * `base/fs_session_identity.php` (`FS_SESSION_IDENTITY_SECRET_MIN_LENGTH`).
 */
const RECOVERY_SESSION_SEED_MIN_LENGTH = 32;

/**
 * The installation root this library reasons about.
 *
 * `FS_FOLDER` when the console defined it, otherwise the tree this file sits in
 * — verified the same way the secret library verifies it, so a misplaced copy
 * yields the webroot the caller asked for and not a guess.
 */
function recovery_session_root(?string $installRoot = null): string
{
    if ($installRoot !== null && $installRoot !== '') {
        return rtrim($installRoot, '/\\');
    }

    if (defined('FS_FOLDER')) {
        $folder = rtrim((string) FS_FOLDER, '/\\');
        if ($folder !== '') {
            return $folder;
        }
    }

    return rtrim(dirname(__DIR__, 3), '/\\');
}

/**
 * Normalize a seed so two hosts that spell the same path differently agree,
 * exactly like the core's normalizer.
 */
function recovery_session_normalize_seed(string $value): string
{
    return str_replace('\\', '/', trim($value));
}

/**
 * The per-install secret the core uses as the session seed, or `null`.
 *
 * Precedence mirrors `SecretManager::resolveConfiguredSecret()` /
 * `fs_session_identity_secret()`: constant, then environment variable (both
 * rejected below the core's minimum length), then the secret file. This never
 * generates or persists anything — the console is a reader here.
 */
function recovery_session_seed_secret(string $installRoot): ?string
{
    if (defined('FS_SECRET_KEY')) {
        $constant = trim((string) constant('FS_SECRET_KEY'));
        if ($constant !== '' && strlen($constant) >= RECOVERY_SESSION_SEED_MIN_LENGTH) {
            return $constant;
        }
    }

    $fromEnvironment = getenv('FS_SECRET_KEY');
    if (is_string($fromEnvironment) && strlen($fromEnvironment) >= RECOVERY_SESSION_SEED_MIN_LENGTH) {
        return $fromEnvironment;
    }

    $file = $installRoot . '/.fs_secret_key';
    if (!is_file($file) || !is_readable($file)) {
        return null;
    }

    $contents = (string) @file_get_contents($file);
    $secret = null;

    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        $candidate = trim($line);
        if ($candidate === '' || str_starts_with($candidate, '#') || str_starts_with($candidate, '//')) {
            continue;
        }

        $secret = $candidate;
    }

    if ($secret === null || strlen($secret) < RECOVERY_SESSION_SEED_MIN_LENGTH) {
        return null;
    }

    return $secret;
}

/**
 * RACC-08's third step: derive the name locally from the same seed the core
 * consumes.
 *
 * Falling back to the install root is what the core itself does when no secret
 * is configured, and it is deliberately the last resort here too: it is the one
 * seed an attacker can guess, so it is only used when the operator has not
 * configured anything.
 */
function recovery_session_derived_name(string $installRoot): string
{
    $seed = recovery_session_seed_secret($installRoot) ?? $installRoot;

    return RECOVERY_SESSION_APP_PREFIX
        . substr(sha1(recovery_session_normalize_seed($seed)), 0, 12);
}

/**
 * Read `FS_SESSION_NAME` out of a `config.php` without letting that file paint
 * the response.
 *
 * Two properties make this safe on the gate's failure path:
 *
 * - the include is output-buffered, error-suppressed and `Throwable`-caught, so
 *   a parse error or a runtime error in a damaged `config.php` yields "no name"
 *   instead of a fatal page, and never echoes the error;
 * - a file that already ran in this request is not executed again. That is not
 *   hypothetical: the lost-secret branch of the gate includes `config.php` to
 *   read the operator pin, and a second include would redeclare whatever the
 *   file declares.
 */
function recovery_session_read_config_constants(string $configFile): void
{
    $target = realpath($configFile);
    if ($target === false) {
        return;
    }

    foreach (get_included_files() as $included) {
        $resolved = realpath($included);
        if ($resolved !== false && $resolved === $target) {
            return;
        }
    }

    $level = ob_get_level();
    ob_start();

    try {
        (static function (string $file): void {
            include $file;
        })($target);
    } catch (\Throwable $error) {
        // A damaged config.php must yield "no name", never a fatal page.
    } finally {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
    }
}

/**
 * RACC-08: the application session name, without requiring a core class.
 *
 * The order is the requirement's order:
 *
 *   1. the core `SessionManager`, when it happens to be loadable — the second
 *      argument to `class_exists()` is `false` on purpose, so the class is only
 *      consulted if something already loaded it. Autoloading here would pull
 *      core code, which is the dependency A1 removes;
 *   2. `FS_SESSION_NAME`, from `config.php` — already defined on the normal
 *      path, read defensively when the gate needs the name before the file was
 *      loaded;
 *   3. a plugin-local derivation over the same seed the core uses.
 *
 * A resolved name that does not match the browser's cookie is *not* an
 * authorization failure: it only means the operator is not single-signed-on
 * from the panel and will be asked for credentials.
 */
function recovery_session_name(?string $installRoot = null, ?string $configFile = null): string
{
    if (class_exists('FSFramework\\Security\\SessionManager', false)) {
        return (string) \FSFramework\Security\SessionManager::resolveSessionName();
    }

    if (!defined('FS_SESSION_NAME') && $configFile !== null) {
        recovery_session_read_config_constants($configFile);
    }

    if (defined('FS_SESSION_NAME') && trim((string) FS_SESSION_NAME) !== '') {
        return trim((string) FS_SESSION_NAME);
    }

    return recovery_session_derived_name(recovery_session_root($installRoot));
}

/**
 * Normalize an ini `session.save_path` value to the directory the `files`
 * handler actually writes into.
 *
 * The ini value accepts the `N;/path` and `N;MODE;/path` forms (depth, optional
 * mode), and it can be empty — in which case PHP's `files` handler falls back to
 * the system temp directory. Everything that reasons about *which file* a
 * session id maps to has to go through this: a raw `N;/path` compared against a
 * plain path is a comparison that can never match.
 */
function recovery_session_normalize_save_path(string $configured): string
{
    $configured = trim($configured);

    if (str_contains($configured, ';')) {
        $parts = explode(';', $configured);
        $configured = trim((string) end($parts));
    }

    if ($configured === '') {
        return rtrim(sys_get_temp_dir(), '/');
    }

    return rtrim($configured, '/');
}

/**
 * The directory PHP stores session files in.
 *
 * `FS_SESSION_SAVE_PATH` wins because `config.php` is where the installation
 * states it, and the ini value can be empty (system default) or in the
 * `N;/path` / `N;MODE;/path` forms the session extension accepts.
 */
function recovery_session_save_path(): string
{
    if (defined('FS_SESSION_SAVE_PATH') && trim((string) FS_SESSION_SAVE_PATH) !== '') {
        return rtrim(trim((string) FS_SESSION_SAVE_PATH), '/');
    }

    return recovery_session_normalize_save_path((string) session_save_path());
}

/**
 * Apply the save path, and **verify** the session module took it.
 *
 * This exists because inspecting one path and starting the session in another
 * is a silent, security-relevant divergence: `is_file()` would find the marker
 * where the installation says sessions live, while `session_start()` would look
 * for the same id in the ini path and the `files` handler would create an empty
 * file there — the marker read answers "no", and the refusal leaves a stray
 * file behind.
 *
 * So the value is read back from the session module instead of trusting the
 * setter (PHP can refuse it: `open_basedir`, a locked ini, an active session)
 * and compared against the value the caller is about to inspect. Only a `true`
 * makes the two the same path; a caller that inspects a session file must
 * refuse when this is `false` rather than guess.
 */
function recovery_session_apply_save_path(): bool
{
    $desired = recovery_session_save_path();

    if ($desired !== '' && is_dir($desired)) {
        @session_save_path($desired);
    }

    return recovery_session_normalize_save_path((string) session_save_path()) === $desired;
}

/**
 * The cookie path the panel itself uses, derived from the request instead of
 * from `SCRIPT_NAME` (which would scope the cookie to `.../plugins/system_updater/`
 * and hide it from `index.php`).
 */
function recovery_session_cookie_path(): string
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($requestUri, PHP_URL_PATH);

    if (is_string($path)
        && preg_match('#^(.*)/plugins/system_updater/recovery\.php$#', $path, $matches) === 1
    ) {
        return $matches[1] === '' ? '/' : rtrim($matches[1], '/') . '/';
    }

    return '/';
}

/**
 * Stop `session_start()` from writing session headers, remembering the state.
 *
 * Used by the read-only marker check, and worth its own function because both
 * halves are load-bearing: `session.use_cookies` writes `Set-Cookie`, and
 * `session.cache_limiter` writes `Expires`/`Pragma`/`Cache-Control`. A refusal
 * that wrote either would be distinguishable from a missing file.
 *
 * @return array{use_cookies: string, cache_limiter: string}
 */
function recovery_session_suspend_headers(): array
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
 * @param array{use_cookies?: string, cache_limiter?: string} $previous
 */
function recovery_session_restore_headers(array $previous): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_cookies', (string) ($previous['use_cookies'] ?? '1'));
    ini_set('session.cache_limiter', (string) ($previous['cache_limiter'] ?? 'nocache'));
}

/**
 * Apply the installation's session policy to the session module.
 *
 * The values are the ones the panel uses — idle lifetime, `/`-scoped or
 * subdirectory-scoped cookie, `HttpOnly`, `SameSite=Lax`, `secure` only when the
 * request really is HTTPS (directly or behind a proxy). No core class is
 * consulted: `SecureRequestDetector` and `SessionPolicy` are exactly the
 * dependencies the console cannot have.
 */
function recovery_session_configure(string $sessionName): void
{
    // Applied through the same helper the marker read uses, so the path the
    // console writes to and the path it later inspects cannot diverge. The
    // result is not fatal here: the console has to open a session either way,
    // and a declared path PHP refuses leaves the ini path in force.
    recovery_session_apply_save_path();

    $lifetime = defined('FS_SESSION_LIFETIME') ? (int) FS_SESSION_LIFETIME : 7200;

    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || $forwardedProto === 'https';

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => recovery_session_cookie_path(),
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name($sessionName);
}

/**
 * The marker's binding to the credential that granted it.
 *
 * An HMAC over a fixed context string, keyed by the granting value. The marker
 * never stores the secret, nor a plain digest of it: a bare SHA-256 of a
 * 64-hex secret is a password-equivalent for anyone who can read the session
 * file, while an HMAC under a context string is only useful for this one
 * comparison. `hash_equals()` keeps that comparison constant-time.
 */
function recovery_console_fingerprint(string $grantedValue): string
{
    return hash_hmac('sha256', RECOVERY_CONSOLE_NAMESPACE, $grantedValue);
}

/**
 * Whether a session array carries a marker this request's credential still
 * issues.
 *
 * The shape is required (`authorized === true` and the array), **and** the
 * fingerprint has to match the value the gate would accept now. A marker
 * without a fingerprint, or with one bound to a value that has since been
 * rotated away, is not authorization: it is the normal refusal path.
 *
 * `$grantedValue` is `null` when this request can name no credential at all —
 * nothing is persisted and no pin is visible — in which case nothing can be
 * verified and the answer is `false`.
 *
 * @param array<string, mixed> $session
 */
function recovery_console_has_marker(array $session, ?string $grantedValue): bool
{
    $marker = $session[RECOVERY_CONSOLE_NAMESPACE] ?? null;

    if (!is_array($marker) || ($marker['authorized'] ?? false) !== true) {
        return false;
    }

    $fingerprint = $marker['fingerprint'] ?? null;
    if (!is_string($fingerprint) || $grantedValue === null || $grantedValue === '') {
        return false;
    }

    return hash_equals(recovery_console_fingerprint($grantedValue), $fingerprint);
}

/**
 * Write the marker. Called **only** after the secret comparison succeeded.
 *
 * The `granted_at` timestamp exists so an operator reading the session can tell
 * when the console was opened; nothing in this slice expires on it, because the
 * session's own lifetime is the documented lifetime of the marker.
 *
 * `$grantedValue` is the value the gate answered against. Without one there is
 * nothing to bind the marker to and nothing is written: an unbound marker would
 * be exactly the state a rotation cannot retire.
 */
function recovery_console_mark_authorized(?string $grantedValue): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    if ($grantedValue === null || $grantedValue === '') {
        return;
    }

    $_SESSION[RECOVERY_CONSOLE_NAMESPACE] = [
        'authorized' => true,
        'granted_at' => time(),
        'fingerprint' => recovery_console_fingerprint($grantedValue),
    ];
}

/**
 * Read the marker out of an existing session file without writing anything.
 *
 * A session id is only accepted when it is shaped like one PHP would issue and
 * a file for it already exists: `session_start()` creates the file when the id
 * is unknown, which would turn a forged cookie into session state on the very
 * path that promises to leave none. It also keeps a crafted cookie from naming
 * a path (`sess_../../etc/passwd`).
 *
 * The save path is applied *before* it is inspected and the application is
 * verified, so `is_file()` below and the file `session_start()` opens are the
 * same file on every branch — including an installation that declares
 * `FS_SESSION_SAVE_PATH`, where the ini value points elsewhere. When the
 * declared path cannot be made the active one there is nothing trustworthy to
 * read, so the answer is "no" rather than a guess at the ini directory.
 */
function recovery_console_read_marker(string $sessionName, string $sessionId, ?string $grantedValue): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return false;
    }

    if (preg_match('/^[A-Za-z0-9,-]{22,256}$/', $sessionId) !== 1) {
        return false;
    }

    if (!recovery_session_apply_save_path()) {
        return false;
    }

    if (!is_file(recovery_session_save_path() . '/sess_' . $sessionId)) {
        return false;
    }

    $suspended = recovery_session_suspend_headers();

    try {
        session_name($sessionName);
        session_id($sessionId);

        if (!@session_start(['read_and_close' => true])) {
            return false;
        }

        return recovery_console_has_marker(
            is_array($_SESSION ?? null) ? $_SESSION : [],
            $grantedValue
        );
    } finally {
        $_SESSION = [];
        recovery_session_restore_headers($suspended);
    }
}

/**
 * RACC-05's whole check, as the console calls it: is the request's own session
 * already authorized?
 *
 * Returns `false` without opening anything, and without executing anything,
 * when the request carries no cookie at all — the common case on a first
 * request, and the one that must leave no session file, no `Set-Cookie` and no
 * `config.php` load behind (RACC-04).
 *
 * When a cookie *is* present the session name has to be known before the marker
 * can be looked for, and the name lives in `config.php` (`FS_SESSION_NAME`, or
 * the `FS_SECRET_KEY` the name is derived from). That read is the second and
 * last place a request without an accepted secret executes `config.php`, and it
 * is silent by construction: buffered, error-suppressed, `Throwable`-caught, no
 * body, no header, no state. It is recorded next to the first one (the gate's
 * lost-secret branch) in `tasks.md`'s "Delta conflict resolved at apply time".
 */
function recovery_console_authorized_by_marker(?string $installRoot = null, ?string $configFile = null): bool
{
    if (!is_array($_COOKIE ?? null) || $_COOKIE === []) {
        return false;
    }

    $sessionName = recovery_session_name($installRoot, $configFile);
    if ($sessionName === '') {
        return false;
    }

    $sessionId = isset($_COOKIE[$sessionName]) ? trim((string) $_COOKIE[$sessionName]) : '';
    if ($sessionId === '') {
        return false;
    }

    // Resolved *after* the name, and that order matters: resolving the name is
    // what loads `config.php` on this path, so an operator pin defined there is
    // visible here. What comes back is the value the gate would answer against
    // now — the same function the gate itself consumes — which is what makes a
    // rotated secret retire the marker it granted, and a pin-granted marker
    // survive only while the pin is still the current credential.
    $grant = recovery_secret_gate_value($installRoot);

    return recovery_console_read_marker(
        $sessionName,
        $sessionId,
        $grant === null ? null : $grant['value']
    );
}
