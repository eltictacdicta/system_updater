<?php
/**
 * Backwards compatibility helpers for system_updater views and processors.
 *
 * Prefer passing these values from controllers into Twig templates instead of
 * relying on Twig functions that may be unavailable on legacy framework versions.
 *
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 */

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
 * Generates a CSRF meta tag using the modern or legacy token source.
 */
function system_updater_csrf_meta(): string
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
 * Generates a CSRF hidden field using the modern or legacy token source.
 */
function system_updater_csrf_field(): string
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
 * @param object $controller
 */
function system_updater_prepare_view_compat($controller): void
{
    if (!is_object($controller)) {
        return;
    }

    $controller->script_nonce_attr = system_updater_script_nonce_attr();
    $controller->csrf_meta_html = system_updater_csrf_meta();
    $controller->csrf_field_html = system_updater_csrf_field();
}
