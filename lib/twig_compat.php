<?php
/**
 * Backwards compatibility layer for Twig functions used by system_updater views.
 *
 * On older installations that don't register helpers like csp_nonce_attr(),
 * this file provides safe fallbacks so templates render without fatal errors.
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

    $file = (defined('FS_FOLDER') ? FS_FOLDER : dirname(dirname(__DIR__))) . '/base/fs_session_manager.php';
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
 * Registers Twig functions required by system_updater templates when the core
 * has not registered them yet.
 */
function system_updater_register_twig_compat(\Twig\Environment $twig): void
{
    // El núcleo moderno registra csrf_* y csp_nonce_* en Html::registerCsrfFunctions()
    // después del evento TwigInitEvent. Solo aportamos fallbacks en instalaciones legacy.
    if (class_exists(\FSFramework\Security\CsrfManager::class)) {
        return;
    }

    $fallbacks = [
        'csp_nonce_attr' => static function (): string {
            return '';
        },
        'csrf_meta' => static function (): string {
            return system_updater_csrf_meta();
        },
        'csrf_field' => static function (): string {
            return system_updater_csrf_field();
        },
        'csrf_available' => static function (): bool {
            return system_updater_csrf_available();
        },
    ];

    foreach ($fallbacks as $name => $callback) {
        try {
            $twig->addFunction(new \Twig\TwigFunction($name, $callback, ['is_safe' => ['html']]));
        } catch (\LogicException $e) {
            // Function already registered by the core or another plugin.
        }
    }
}
