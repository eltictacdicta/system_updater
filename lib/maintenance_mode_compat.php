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

@require_once __DIR__ . '/debug_log.php';

$maintenanceModeFile = (defined('FS_FOLDER') ? FS_FOLDER : dirname(dirname(dirname(__DIR__)))) . '/base/fs_maintenance_mode.php';
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
 * Comprueba si falta configurar el acceso stealth recomendado por el core moderno.
 *
 * Ya no bloquea operaciones; sirve para emitir advertencias informativas.
 */
function system_updater_maintenance_stealth_required(): bool
{
    if (!system_updater_maintenance_mode_available()) {
        if (function_exists('system_updater_debug_log')) {
            system_updater_debug_log('MM', 'stealth_required: maintenance mode unavailable, returning false');
        }
        return false;
    }

    if (function_exists('system_updater_debug_log')) {
        system_updater_debug_log('MM', 'stealth_required: calling fs_maintenance_mode::stealthAccessStatus');
    }
    $stealthStatus = fs_maintenance_mode::stealthAccessStatus();
    if (function_exists('system_updater_debug_log')) {
        system_updater_debug_log('MM', 'stealth_required: status received', [
            'ready' => !empty($stealthStatus['ready']),
            'enabled' => !empty($stealthStatus['enabled']),
            'has_param_value' => !empty($stealthStatus['param_value']),
        ]);
    }

    return empty($stealthStatus['ready']);
}

/**
 * Usuarios con actividad reciente (según last_login / last_login_time).
 *
 * @return list<array{nick: string, last_login: string, last_ip: string}>
 */
function system_updater_get_recent_active_users(string $excludeNick = '', int $minutesThreshold = 15): array
{
    if (!defined('FS_FOLDER') || $minutesThreshold <= 0) {
        return [];
    }

    $userModelFile = FS_FOLDER . '/model/fs_user.php';
    if (!file_exists($userModelFile)) {
        return [];
    }

    require_once $userModelFile;

    if (!class_exists('fs_user', false)) {
        return [];
    }

    $userModel = new fs_user();
    if (!method_exists($userModel, 'all_enabled')) {
        return [];
    }

    $cutoff = time() - ($minutesThreshold * 60);
    $activeUsers = [];

    foreach ($userModel->all_enabled() as $user) {
        $nick = trim((string) ($user->nick ?? ''));
        if ($nick === '' || ($excludeNick !== '' && $nick === $excludeNick)) {
            continue;
        }

        $lastLogin = trim((string) ($user->last_login ?? ''));
        $lastLoginTime = trim((string) ($user->last_login_time ?? ''));
        if ($lastLogin === '' || $lastLoginTime === '') {
            continue;
        }

        $lastActivity = strtotime($lastLogin . ' ' . $lastLoginTime);
        if ($lastActivity === false || $lastActivity < $cutoff) {
            continue;
        }

        $activeUsers[] = [
            'nick' => $nick,
            'last_login' => method_exists($user, 'show_last_login')
                ? (string) $user->show_last_login()
                : $lastLogin . ' ' . $lastLoginTime,
        ];
    }

    return $activeUsers;
}

/**
 * Advertencias informativas antes de operaciones delicadas (restauración, actualización, etc.).
 *
 * @return list<array{type: string, level: string, message: string, link?: string, link_label?: string, users?: list<array{nick: string, last_login: string}>}>
 */
function system_updater_get_operation_warnings(string $excludeNick = '', int $minutesThreshold = 15): array
{
    $warnings = [];

    if (system_updater_maintenance_stealth_required()) {
        $warnings[] = [
            'type' => 'stealth',
            'level' => 'warning',
            'message' => system_updater_maintenance_stealth_required_message(),
            'link' => 'index.php?page=admin_stealth',
            'link_label' => 'Configurar stealth',
        ];
    }

    $activeUsers = system_updater_get_recent_active_users($excludeNick, $minutesThreshold);
    if ($activeUsers !== []) {
        $nicks = array_map(static fn(array $user): string => $user['nick'], $activeUsers);
        $warnings[] = [
            'type' => 'active_users',
            'level' => 'warning',
            'message' => count($activeUsers) === 1
                ? 'Hay otro usuario con actividad reciente: ' . implode(', ', $nicks) . '.'
                : 'Hay ' . count($activeUsers) . ' usuarios con actividad reciente: ' . implode(', ', $nicks) . '.',
            'users' => $activeUsers,
        ];
    }

    return $warnings;
}

/**
 * Activa el modo mantenimiento cuando el core lo soporta.
 */
function system_updater_begin_maintenance(array $state = []): bool
{
    if (!system_updater_maintenance_mode_available()) {
        if (function_exists('system_updater_debug_log')) {
            system_updater_debug_log('MM', 'begin_maintenance: maintenance mode unavailable, returning true');
        }
        return true;
    }

    if (function_exists('system_updater_debug_log')) {
        system_updater_debug_log('MM', 'begin_maintenance: calling fs_maintenance_mode::writeLock', [
            'lock_path' => method_exists('fs_maintenance_mode', 'lockFilePath')
                ? (string) fs_maintenance_mode::lockFilePath()
                : '(no lockFilePath method)',
        ]);
    }
    $result = fs_maintenance_mode::writeLock($state);
    if (function_exists('system_updater_debug_log')) {
        system_updater_debug_log('MM', 'begin_maintenance: writeLock returned', ['success' => $result]);
    }
    return $result;
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
                'ready' => true,
            ];
        }
    }
}
