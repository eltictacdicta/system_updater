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
 * Registers Twig functions required by system_updater templates when the core
 * has not registered them yet.
 */
function system_updater_register_twig_compat(\Twig\Environment $twig): void
{
    $fallbacks = [
        'csp_nonce_attr' => static function (): string {
            return '';
        },
        'csrf_meta' => static function (): string {
            if (class_exists(\FSFramework\Security\CsrfManager::class)) {
                return \FSFramework\Security\CsrfManager::metaTag();
            }

            return '';
        },
        'csrf_field' => static function (): string {
            if (class_exists(\FSFramework\Security\CsrfManager::class)) {
                return \FSFramework\Security\CsrfManager::field();
            }

            return '';
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
