<?php
/**
 * Backwards compatibility helpers for system_updater views and processors.
 *
 * Prefer passing these values from controllers into Twig templates instead of
 * relying on Twig functions that may be unavailable on legacy framework versions.
 *
 * CSRF strategy:
 * - Core csrf_field()/csrf_meta() → for controller POST forms (via index.php).
 * - Plugin system_updater_csrf_field()/system_updater_csrf_meta() → for SSE
 *   process scripts that bypass index.php (independent token system).
 *
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 */

require_once __DIR__ . '/csrf_token.php';

/**
 * Ensures the legacy session manager is available for CSRF fallbacks.
 */
function system_updater_ensure_legacy_session_manager(): void
{
    if (class_exists('fs_session_manager', false)) {
        return;
    }

    $file = (defined('FS_FOLDER') ? FS_FOLDER : dirname(dirname(dirname(__DIR__)))) . '/base/fs_session_manager.php';
    if (file_exists($file)) {
        require_once $file;
    }
}

/**
 * Returns true when a CSRF token source is available on this installation.
 */
function system_updater_csrf_available(): bool
{
    if (class_exists(\FSFramework\Security\CsrfManager::class)) {
        return true;
    }

    system_updater_ensure_legacy_session_manager();

    return class_exists('fs_session_manager', false);
}

/**
 * Generates a core CSRF meta tag using the modern or legacy token source.
 * Used for controller POST forms that go through index.php.
 */
function system_updater_core_csrf_meta(): string
{
    if (class_exists(\FSFramework\Security\CsrfManager::class)) {
        return \FSFramework\Security\CsrfManager::metaTag();
    }

    system_updater_ensure_legacy_session_manager();
    if (class_exists('fs_session_manager', false)) {
        return fs_session_manager::csrfMeta();
    }

    return '';
}

/**
 * Generates a core CSRF hidden field using the modern or legacy token source.
 * Used for controller POST forms that go through index.php.
 */
function system_updater_core_csrf_field(): string
{
    if (class_exists(\FSFramework\Security\CsrfManager::class)) {
        return \FSFramework\Security\CsrfManager::field();
    }

    system_updater_ensure_legacy_session_manager();
    if (class_exists('fs_session_manager', false)) {
        return fs_session_manager::csrfField();
    }

    return '';
}

/**
 * Returns the CSP nonce HTML attribute for inline/external script tags, or empty.
 */
function system_updater_script_nonce_attr(): string
{
    if (!class_exists(\FSFramework\Security\SecurityHeaders::class, false)
        || !method_exists(\FSFramework\Security\SecurityHeaders::class, 'nonceAttribute')) {
        return '';
    }

    $attribute = (string) \FSFramework\Security\SecurityHeaders::nonceAttribute();

    return $attribute !== '' ? ' ' . $attribute : '';
}

/**
 * Populates public controller properties used by plugin Twig templates.
 *
 * Provides both core CSRF tokens (for controller POST forms) and plugin
 * CSRF tokens (for SSE process scripts).
 *
 * @param object $controller
 */
function system_updater_prepare_view_compat($controller): void
{
    if (!is_object($controller)) {
        return;
    }

    $controller->script_nonce_attr = system_updater_script_nonce_attr();
    $controller->csrf_meta_html = system_updater_core_csrf_meta();
    $controller->csrf_field_html = system_updater_core_csrf_field();

    // Plugin-specific CSRF token for SSE process scripts
    // Reuse existing token if present, only generate if missing
    $storedToken = system_updater_csrf_get_stored();
    if ($storedToken === null) {
        $storedToken = system_updater_csrf_generate();
    }
    $controller->su_csrf_token = $storedToken;
    $controller->su_csrf_meta_html = system_updater_csrf_meta();
}
