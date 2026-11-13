<?php
/**
 * Self-contained CSRF token system for system_updater plugin.
 *
 * Uses a dedicated session (SU_SESS_*) independent of the framework session
 * to avoid conflicts between controller and SSE process scripts.
 *
 * Token format: bin2hex(random_bytes(32)) — 64-char hex string.
 * Storage: $_SESSION['system_updater']['csrf_tokens'][$tokenId]
 * Validation: hash_equals() for timing-safe comparison.
 * TTL: 4 hours (configurable via SYSTEM_UPDATER_CSRF_TTL constant).
 *
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 */

if (!defined('SYSTEM_UPDATER_CSRF_TTL')) {
    define('SYSTEM_UPDATER_CSRF_TTL', 4 * 3600); // 4 hours
}

// El nombre de la sesión del plugin, sin arrastrar el bootstrap del framework:
// `session_auth.php` carga `base/config2.php` y el autoloader, y este módulo es
// el que el console de recuperación necesita para acuñar el token (RACC-07) sin
// ninguna dependencia del core (RACC-01). El nombre lo resuelve exactamente la
// misma función que resuelven los process scripts.
require_once __DIR__ . '/session_name.php';

/**
 * Ensure the plugin's dedicated session is active.
 *
 * The plugin keeps its tokens in its own session (SU_SESS_*) so the controller
 * and the standalone SSE scripts share them. When another session is active
 * (the framework's FSSESS_*), it is written, remembered and closed here, and
 * system_updater_csrf_restore_session() resumes it afterwards.
 *
 * The plugin session never inherits the id of the session it replaces: that
 * silently merged both stores, and once the framework id rotated (logout/login)
 * the stale SU_SESS cookie made the framework continue the request with the
 * plugin's id, so every page rendered a framework CSRF token that was already
 * gone ("Token de seguridad inválido").
 */
function system_updater_csrf_ensure_session(): void
{
    $expectedName = system_updater_resolve_session_name();

    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() === $expectedName) {
            return;
        }
        $GLOBALS['system_updater_previous_session'] = [session_name(), session_id()];
        session_write_close();
    }

    $cookieId = (string) ($_COOKIE[$expectedName] ?? '');
    session_name($expectedName);
    session_id(preg_match('/^[A-Za-z0-9,-]{22,256}$/', $cookieId) === 1 ? $cookieId : session_create_id());

    @session_start();
    // Later calls in this request must reopen this same session, not mint another one.
    $_COOKIE[$expectedName] = session_id();
}

/**
 * Resume the session that system_updater_csrf_ensure_session() closed, if any.
 *
 * No-op when the plugin session was opened from scratch (SSE scripts).
 */
function system_updater_csrf_restore_session(): void
{
    $previous = $GLOBALS['system_updater_previous_session'] ?? null;
    if (!is_array($previous)) {
        return;
    }
    unset($GLOBALS['system_updater_previous_session']);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    session_name($previous[0]);
    session_id($previous[1]);
    @session_start();
}

/**
 * Generate a CSRF token and store it in the plugin session.
 *
 * @param string $tokenId Unique identifier for the token slot.
 * @return string The plaintext token value (64-char hex).
 */
function system_updater_csrf_generate(string $tokenId = 'system_updater_csrf'): string
{
    system_updater_csrf_ensure_session();

    $token = bin2hex(random_bytes(32));

    if (!isset($_SESSION['system_updater']) || !is_array($_SESSION['system_updater'])) {
        $_SESSION['system_updater'] = [];
    }
    if (!isset($_SESSION['system_updater']['csrf_tokens']) || !is_array($_SESSION['system_updater']['csrf_tokens'])) {
        $_SESSION['system_updater']['csrf_tokens'] = [];
    }

    $_SESSION['system_updater']['csrf_tokens'][$tokenId] = [
        'value' => $token,
        'created_at' => time(),
    ];

    // CRITICAL: Force session to disk immediately.
    // Without this, the token exists in $_SESSION but the session file
    // may not be written before the request ends, causing the SSE script
    // to fail validation when it tries to read the session.
    // The session will be restarted automatically on the next call.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    system_updater_csrf_restore_session();

    return $token;
}

/**
 * Return the stored token value without generating a new one, or null if missing.
 *
 * @param string $tokenId
 * @return string|null
 */
function system_updater_csrf_get_stored(string $tokenId = 'system_updater_csrf'): ?string
{
    system_updater_csrf_ensure_session();
    $stored = $_SESSION['system_updater']['csrf_tokens'][$tokenId]['value'] ?? null;
    system_updater_csrf_restore_session();

    return $stored === null ? null : (string) $stored;
}

/**
 * Validate a submitted token against the stored value.
 *
 * Checks existence, TTL expiry, and uses hash_equals() for timing-safe comparison.
 * Removes expired entries from session.
 *
 * @param string $token The submitted token value.
 * @param string $tokenId The token slot identifier.
 * @return bool True if valid, false otherwise.
 */
function system_updater_csrf_validate(string $token, string $tokenId = 'system_updater_csrf'): bool
{
    if ($token === '') {
        return false;
    }

    system_updater_csrf_ensure_session();
    try {
        return system_updater_csrf_check_entry($token, $tokenId);
    } finally {
        system_updater_csrf_restore_session();
    }
}

/**
 * Token check against the (already active) plugin session.
 */
function system_updater_csrf_check_entry(string $token, string $tokenId): bool
{
    if (!isset($_SESSION['system_updater']['csrf_tokens'][$tokenId])) {
        return false;
    }

    $entry = $_SESSION['system_updater']['csrf_tokens'][$tokenId];

    if (!is_array($entry) || !isset($entry['value'], $entry['created_at'])) {
        return false;
    }

    // Check TTL
    $age = time() - (int) $entry['created_at'];
    if ($age > SYSTEM_UPDATER_CSRF_TTL) {
        // Expired — clean up
        unset($_SESSION['system_updater']['csrf_tokens'][$tokenId]);
        return false;
    }

    return hash_equals((string) $entry['value'], $token);
}

/**
 * Classify why a token would be rejected, for the failure log only.
 *
 * Must run BEFORE system_updater_csrf_validate(), which drops expired entries.
 *
 * @return string no_cookie|empty_session|no_stored_token|expired|mismatch|valid
 */
function system_updater_csrf_failure_reason(string $token, string $tokenId = 'system_updater_csrf'): string
{
    if (!isset($_COOKIE[system_updater_resolve_session_name()])) {
        return 'no_cookie';
    }

    system_updater_csrf_ensure_session();
    $entry = $_SESSION['system_updater']['csrf_tokens'][$tokenId] ?? null;
    $sessionEmpty = empty($_SESSION);
    system_updater_csrf_restore_session();

    if (!is_array($entry) || !isset($entry['value'], $entry['created_at'])) {
        return $sessionEmpty ? 'empty_session' : 'no_stored_token';
    }
    if (time() - (int) $entry['created_at'] > SYSTEM_UPDATER_CSRF_TTL) {
        return 'expired';
    }

    return hash_equals((string) $entry['value'], $token) ? 'valid' : 'mismatch';
}

/**
 * Emit an HTML hidden input field with the current plugin CSRF token.
 *
 * Auto-generates a token if none exists in the session.
 *
 * @param string $tokenId
 * @return string HTML <input> element.
 */
function system_updater_csrf_field(string $tokenId = 'system_updater_csrf'): string
{
    $stored = system_updater_csrf_get_stored($tokenId);
    if ($stored === null) {
        $stored = system_updater_csrf_generate($tokenId);
    }

    return '<input type="hidden" name="su_csrf_token" value="' . htmlspecialchars($stored, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Emit an HTML meta tag with the current plugin CSRF token.
 *
 * Auto-generates a token if none exists in the session.
 *
 * @param string $tokenId
 * @return string HTML <meta> element.
 */
function system_updater_csrf_meta(string $tokenId = 'system_updater_csrf'): string
{
    $stored = system_updater_csrf_get_stored($tokenId);
    if ($stored === null) {
        $stored = system_updater_csrf_generate($tokenId);
    }

    return '<meta name="su-csrf-token" content="' . htmlspecialchars($stored, ENT_QUOTES, 'UTF-8') . '">';
}
