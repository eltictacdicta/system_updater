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

// Asegurar que las funciones de sesión estén disponibles
require_once __DIR__ . '/session_auth.php';

/**
 * Ensure the plugin's dedicated session is active.
 * 
 * This function ensures that the plugin uses its own session (SU_SESS_*)
 * instead of the framework session, so tokens are stored in a session
 * that both the controller and SSE scripts can access.
 * 
 * IMPORTANT: If a different session is active (e.g., framework session),
 * this function will close it and start the plugin session. This is intentional
 * because the plugin needs its own session namespace that persists across
 * controller requests and standalone SSE scripts. The framework session data
 * is preserved (session_write_close() saves it), and the framework can
 * continue using its session after this function returns.
 */
function system_updater_csrf_ensure_session(): void
{
    // If session is already active and it's our plugin session, we're good
    if (session_status() === PHP_SESSION_ACTIVE) {
        $currentName = session_name();
        $expectedName = system_updater_resolve_session_name();
        if ($currentName === $expectedName) {
            return;
        }
        // Session is active but it's not our plugin session
        // We need to close it and start our own
        // This is safe: session_write_close() saves the session data
        session_write_close();
    }

    // Start our plugin session
    $sessionName = system_updater_resolve_session_name();
    session_name($sessionName);
    
    // Try to resume existing session from cookie
    if (isset($_COOKIE[$sessionName])) {
        session_id($_COOKIE[$sessionName]);
    }
    
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
    
    if (!isset($_SESSION['system_updater']['csrf_tokens'][$tokenId]['value'])) {
        return null;
    }

    return (string) $_SESSION['system_updater']['csrf_tokens'][$tokenId]['value'];
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
