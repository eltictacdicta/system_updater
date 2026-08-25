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
 * Auth-gated backup download endpoint.
 *
 * Replaces the old `index.php?page=admin_updater&action=download_backup&file=...`
 * flow which streamed DB dumps (PII, credentials) without authentication.
 *
 * Security invariants:
 *   1. Only authenticated admins reach this script (401 otherwise).
 *   2. A valid `su_csrf_token` is required (403 otherwise).
 *   3. `?file=` is basename()-sanitised AND realpath()-contained inside the
 *      canonical backup dir (400 + security log on any escape attempt).
 *   4. Every successful download is appended to tmp/system_updater_debug.log
 *      with user, file, size, ip, ts.
 *
 * The handler logic lives in `lib/download_backup_handler.php` so it can be
 * unit-tested without spawning a PHP subprocess.
 */

require_once __DIR__ . '/lib/process_bootstrap.php';
$ctx = system_updater_process_init(['mode' => 'plain', 'progress_prefix' => 'fs_download_backup']);
$sessionId = $ctx['session_id'];

require_once __DIR__ . '/lib/backup_manager.php';
require_once __DIR__ . '/lib/download_backup_handler.php';
require_once __DIR__ . '/lib/debug_log.php';

$requestedFile = isset($_GET['file']) ? (string) $_GET['file'] : '';
$userNick = (string) ($_SESSION['user_nick'] ?? $_SESSION['_sf2_attributes']['user_nick'] ?? 'unknown');
$remoteIp = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
$backupDir = backup_manager::resolve_usable_backup_dir();

// The plain-mode bootstrap already required an authenticated session.
// Now require a valid CSRF token. A REJECTED token is a security event and
// must be audited like any other rejection — never a silent exit.
$csrfToken = system_updater_csrf_read_from_request();
if ($csrfToken === '' || !system_updater_csrf_validate($csrfToken)) {
    system_updater_record_download_audit(
        'SECURITY',
        $userNick,
        $requestedFile,
        $backupDir,
        0,
        $remoteIp,
        'csrf_rejected'
    );

    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => 'Token de seguridad inválido.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
// Valid — close session immediately so streaming cannot block on it.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$resolution = system_updater_resolve_backup_file($requestedFile, $backupDir);
$status = $resolution['status'];

if ($status === 'traversal' || $status === 'invalid') {
    system_updater_record_download_audit(
        'SECURITY',
        $userNick,
        $resolution['name'] ?? '',
        $backupDir,
        0,
        $remoteIp,
        'path_traversal_rejected:' . $status
    );

    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => 'Solicitud de archivo inválida.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($status === 'missing_file') {
    // Spec: missing file MUST NOT be logged (not a security event).
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => 'Archivo de backup no encontrado.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// status === 'ok'
$realPath = (string) $resolution['real_path'];
$safeName = (string) $resolution['name'];

// Open the file BEFORE sending success headers or auditing the download:
// if fopen() fails (file removed between resolution and open, permissions
// changed, etc.) we must respond 500 WITHOUT having claimed a successful
// transfer. Deriving the size from the open descriptor also avoids a
// filesize()/fopen() TOCTOU mismatch in Content-Length.
$fp = @fopen($realPath, 'rb');
if ($fp === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo abrir el archivo para lectura.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stat = @fstat($fp);
$size = is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : 0;

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $safeName . '"');
header('Content-Length: ' . $size);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

system_updater_record_download_audit('DOWNLOAD', $userNick, $safeName, $realPath, $size, $remoteIp, null);

while (!feof($fp)) {
    $chunk = fread($fp, 8192);
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    @ob_flush();
    @flush();
}
fclose($fp);
exit;
