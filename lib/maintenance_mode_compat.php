<?php
/**
 * Backwards compatibility layer for fs_maintenance_mode.
 *
 * On older FSFramework installations that don't have fs_maintenance_mode.php,
 * this file provides a stub class that returns safe default values so the
 * system_updater plugin works without fatal errors.
 *
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 */

$maintenanceModeFile = (defined('FS_FOLDER') ? FS_FOLDER : dirname(dirname(__DIR__))) . '/base/fs_maintenance_mode.php';
$maintenanceModeAvailable = file_exists($maintenanceModeFile);

if ($maintenanceModeAvailable && !class_exists('fs_maintenance_mode', false)) {
    require_once $maintenanceModeFile;
}

if (!defined('FS_MAINTENANCE_MODE_AVAILABLE')) {
    define('FS_MAINTENANCE_MODE_AVAILABLE', $maintenanceModeAvailable && class_exists('fs_maintenance_mode', false));
}

/**
 * Indica si la instalación actual expone el modo mantenimiento del core.
 */
function system_updater_maintenance_mode_available(): bool
{
    return defined('FS_MAINTENANCE_MODE_AVAILABLE') && FS_MAINTENANCE_MODE_AVAILABLE;
}

/**
 * Comprueba si falta configurar el acceso stealth requerido por el core moderno.
 */
function system_updater_maintenance_stealth_required(): bool
{
    if (!system_updater_maintenance_mode_available()) {
        return false;
    }

    $stealthStatus = fs_maintenance_mode::stealthAccessStatus();

    return empty($stealthStatus['ready']);
}

/**
 * Activa el modo mantenimiento cuando el core lo soporta.
 */
function system_updater_begin_maintenance(array $state = []): bool
{
    if (!system_updater_maintenance_mode_available()) {
        return true;
    }

    return fs_maintenance_mode::writeLock($state);
}

/**
 * Desactiva el modo mantenimiento cuando el core lo soporta.
 */
function system_updater_end_maintenance(): void
{
    if (!system_updater_maintenance_mode_available()) {
        return;
    }

    fs_maintenance_mode::clearLock();
}

/**
 * Mensaje estándar cuando falta el acceso stealth en cores modernos.
 */
function system_updater_maintenance_stealth_required_message(): string
{
    return 'Activa primero el modo stealth desde admin_stealth para mantener una ruta de acceso del administrador durante el mantenimiento.';
}

if (!class_exists('fs_maintenance_mode', false)) {
    /**
     * Stub for installations without the maintenance mode feature.
     * All methods return safe defaults: maintenance is never active,
     * and operations that would require maintenance mode are allowed to proceed.
     */
    final class fs_maintenance_mode
    {
        public static function isActive(): bool
        {
            return false;
        }

        public static function isEnabled(): bool
        {
            return false;
        }

        public static function isForced(): bool
        {
            return false;
        }

        public static function hasLock(): bool
        {
            return false;
        }

        /**
         * @return array|null
         */
        public static function readLockState(): ?array
        {
            return null;
        }

        public static function writeLock(array $state = []): bool
        {
            return true;
        }

        public static function clearLock(): bool
        {
            return true;
        }

        public static function message(): string
        {
            return '';
        }

        public static function retryAfter(): int
        {
            return 300;
        }

        public static function lockFilePath(): string
        {
            return '';
        }

        public static function hasAdminSession(): bool
        {
            return false;
        }

        public static function hasBypass(): bool
        {
            return false;
        }

        public static function bypassQueryParam(): string
        {
            return 'fs_maintenance_bypass';
        }

        public static function stealthAccessStatus(): array
        {
            return [
                'enabled' => false,
                'param_name' => '',
                'param_value' => '',
                'ready' => false,
            ];
        }
    }
}
