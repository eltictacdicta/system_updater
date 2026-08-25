<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

/**
 * Pure helper functions for `download_backup.php`.
 *
 * Extracted so the path-containment, basename-safety, and audit-log
 * decisions can be unit-tested without spawning a PHP subprocess.
 */

require_once __DIR__ . '/debug_log.php';

if (!defined('SYSTEM_UPDATER_DOWNLOAD_AUDIT_TAG')) {
    define('SYSTEM_UPDATER_DOWNLOAD_AUDIT_TAG', 'BACKUP_DOWNLOAD');
}

if (!function_exists('system_updater_resolve_backup_file')) {
    /**
     * Resolve `?file=` against the canonical backup dir with three layers
     * of defence against path traversal:
     *   1. `basename()` strips any directory components (`../` included).
     *   2. `realpath()` resolves symlinks; if it returns `false` the file
     *      does not exist and we return `missing_file`.
     *   3. The resolved real path MUST start with the canonical backup
     *      dir + DIRECTORY_SEPARATOR (the trailing separator blocks the
     *      `/backups-evil/` bypass).
     *
     * @param string $requestedFile Raw value of `?file=`.
     * @param string $backupDir     Canonical backup dir (from resolve_backup_dir()).
     * @return array{status: string, name: string, real_path: string|false}
     *   `status` is one of: 'ok', 'traversal', 'invalid', 'missing_file'.
     */
    function system_updater_resolve_backup_file(string $requestedFile, string $backupDir): array
    {
        $result = [
            'status' => 'invalid',
            'name' => '',
            'real_path' => false,
        ];

        if ($requestedFile === '') {
            return $result;
        }

        // Layer 1: basename() strips directory components and any `..`.
        $name = basename($requestedFile);
        if ($name === '' || $name === '.' || $name === '..') {
            $result['name'] = $name;
            $result['status'] = 'traversal';
            return $result;
        }

        // Layer 2: realpath() — collapses symlinks. If the file does not
        // exist, realpath() returns false and we report missing_file (not
        // traversal — it is a legitimate, just-non-existent name).
        $candidate = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $name;
        $real = realpath($candidate);
        if ($real === false) {
            $result['name'] = $name;
            $result['status'] = 'missing_file';
            return $result;
        }

        // Layer 3: containment check. The trailing DIRECTORY_SEPARATOR is
        // critical — without it, '/backups' would also be a prefix of
        // '/backups-evil/...'.
        $prefix = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR;
        if (!str_starts_with($real, $prefix)) {
            $result['name'] = $name;
            $result['status'] = 'traversal';
            return $result;
        }

        if (!is_file($real)) {
            $result['name'] = $name;
            $result['status'] = 'missing_file';
            return $result;
        }

        $result['status'] = 'ok';
        $result['name'] = $name;
        $result['real_path'] = $real;
        return $result;
    }
}

if (!function_exists('system_updater_record_download_audit')) {
    /**
     * Append a structured audit line to the plugin debug log.
     *
     * The exact same destination (`tmp/system_updater_debug.log`) and
     * channel (`system_updater_debug_log`) used elsewhere in the plugin,
     * so operators can grep the file for `BACKUP_DOWNLOAD` to review
     * every download attempt.
     *
     * @param string $kind    Either 'DOWNLOAD' (success) or 'SECURITY' (rejected probe).
     * @param string $user    Nick of the authenticated user, or 'unknown'.
     * @param string $file    The basename requested (or the rejected name).
     * @param string $realPath The realpath of the file (or the backup dir on rejection).
     * @param int    $size    File size in bytes (0 on rejection).
     * @param string $ip      Client IP.
     * @param string|null $note Free-form reason (e.g. "path_traversal_rejected:..").
     */
    function system_updater_record_download_audit(
        string $kind,
        string $user,
        string $file,
        string $realPath,
        int $size,
        string $ip,
        ?string $note
    ): void {
        if (!function_exists('system_updater_debug_log')) {
            return;
        }

        $payload = [
            'kind' => $kind,
            'user' => $user,
            'file' => $file,
            'real_path' => $realPath,
            'size' => $size,
            'ip' => $ip,
            'ts' => time(),
        ];
        if ($note !== null) {
            $payload['note'] = $note;
        }

        system_updater_debug_log(
            SYSTEM_UPDATER_DOWNLOAD_AUDIT_TAG,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
