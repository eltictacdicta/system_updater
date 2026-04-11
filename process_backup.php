<?php
/**
 * Procesador de Backup con estado persistente - Plugin system_updater
 *
 * El backup se ejecuta desacoplado de la conexión HTTP para evitar que el
 * progreso dependa de un canal SSE largo o de un navegador conectado.
 *
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 */

const FS_BACKUP_STALE_SECONDS = 1800;
const FS_BACKUP_QUEUE_RECOVERY_SECONDS = 8;
const FS_BACKUP_MAX_RECOVERY_ATTEMPTS = 2;

if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv)) {
    foreach (array_slice($argv, 1) as $argument) {
        if (strpos($argument, '=') === false) {
            continue;
        }

        list($key, $value) = explode('=', $argument, 2);
        $_GET[$key] = $value;
        $_REQUEST[$key] = $value;
    }
}

@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');
@ignore_user_abort(true);

if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', dirname(dirname(__DIR__)));
}

function backup_json_encode(array $payload)
{
    return json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function respond_json(array $payload, $statusCode = 200, $exit = true)
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    echo backup_json_encode($payload);

    if ($exit) {
        exit;
    }
}

function respond_and_continue(array $payload)
{
    $json = backup_json_encode($payload);

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Connection: close');
        header('Content-Length: ' . strlen($json));
    }

    echo $json;

    while (ob_get_level()) {
        ob_end_flush();
    }

    @flush();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

function get_request_param($name, $default = '')
{
    return $_GET[$name] ?? $_POST[$name] ?? $default;
}

function sanitize_token($value)
{
    return preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $value);
}

function get_progress_file($jobId)
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'fs_backup_' . sanitize_token($jobId) . '.json';
}

function get_lock_file($jobId)
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'fs_backup_' . sanitize_token($jobId) . '.lock';
}

function get_session_pointer_file($sessionKey)
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'fs_backup_current_' . sanitize_token($sessionKey) . '.json';
}

function read_json_file($filePath)
{
    if (!is_file($filePath)) {
        return null;
    }

    $content = @file_get_contents($filePath);
    if ($content === false || $content === '') {
        return null;
    }

    $data = json_decode($content, true);
    return is_array($data) ? $data : null;
}

function write_json_file($filePath, array $data)
{
    return @file_put_contents($filePath, backup_json_encode($data), LOCK_EX) !== false;
}

function load_pointer($sessionKey)
{
    return read_json_file(get_session_pointer_file($sessionKey));
}

function load_progress($jobId = '', $sessionKey = '')
{
    $jobId = sanitize_token($jobId);

    if ($jobId === '' && $sessionKey !== '') {
        $pointer = load_pointer($sessionKey);
        $jobId = sanitize_token($pointer['job_id'] ?? '');
    }

    if ($jobId === '') {
        return null;
    }

    return read_json_file(get_progress_file($jobId));
}

function save_progress($jobId, $sessionKey, $step, $message, $percent, $error = null, array $extra = [], $status = null)
{
    $jobId = sanitize_token($jobId);
    $sessionKey = sanitize_token($sessionKey);

    if ($status === null) {
        if ($error !== null) {
            $status = 'error';
        } elseif ($step === 'complete') {
            $status = 'complete';
        } elseif ($step === 'queued') {
            $status = 'queued';
        } else {
            $status = 'running';
        }
    }

    $data = array_merge([
        'job_id' => $jobId,
        'session_key' => $sessionKey,
        'step' => $step,
        'message' => $message,
        'percent' => (int) $percent,
        'timestamp' => time(),
        'error' => $error,
        'status' => $status,
    ], $extra);

    write_json_file(get_progress_file($jobId), $data);
    write_json_file(get_session_pointer_file($sessionKey), [
        'job_id' => $jobId,
        'status' => $status,
        'step' => $step,
        'message' => $message,
        'timestamp' => $data['timestamp'],
    ]);

    return $data;
}

function clear_job_state($jobId, $sessionKey)
{
    $jobId = sanitize_token($jobId);
    $sessionKey = sanitize_token($sessionKey);

    if ($jobId !== '') {
        @unlink(get_progress_file($jobId));
        @unlink(get_lock_file($jobId));
    }

    $pointerFile = get_session_pointer_file($sessionKey);
    $pointer = read_json_file($pointerFile);
    if (is_array($pointer) && ($pointer['job_id'] ?? '') === $jobId) {
        @unlink($pointerFile);
    }
}

function mark_stale_job_if_needed(array $data, $sessionKey)
{
    $status = $data['status'] ?? 'idle';
    $timestamp = (int) ($data['timestamp'] ?? 0);
    $jobId = sanitize_token($data['job_id'] ?? '');

    if ($jobId === '' || !in_array($status, ['queued', 'running'], true)) {
        return $data;
    }

    if ((time() - $timestamp) <= FS_BACKUP_STALE_SECONDS) {
        return $data;
    }

    return save_progress(
        $jobId,
        $sessionKey,
        'error',
        'El proceso de backup dejó de reportar actividad. Revise los logs del servidor.',
        (int) ($data['percent'] ?? 0),
        'Proceso sin actividad',
        [
            'finished_at' => time(),
            'stale' => true,
            'previous_status' => $status,
        ],
        'error'
    );
}

function should_attempt_queue_recovery(array $data)
{
    $status = $data['status'] ?? 'idle';
    $jobId = sanitize_token($data['job_id'] ?? '');
    $timestamp = (int) ($data['timestamp'] ?? 0);
    $recoveryAttempts = (int) ($data['recovery_attempts'] ?? 0);

    if ($status !== 'queued' || $jobId === '' || $timestamp <= 0) {
        return false;
    }

    if ($recoveryAttempts >= FS_BACKUP_MAX_RECOVERY_ATTEMPTS) {
        return false;
    }

    return (time() - $timestamp) >= FS_BACKUP_QUEUE_RECOVERY_SECONDS;
}

function recover_queued_job(array $data, $sessionKey)
{
    $jobId = sanitize_token($data['job_id'] ?? '');
    $sessionKey = sanitize_token($sessionKey);

    if ($jobId === '' || $sessionKey === '' || !should_attempt_queue_recovery($data)) {
        return false;
    }

    $message = 'El worker en segundo plano no respondió. Reintentando el backup desde la petición actual...';

    $updatedData = save_progress(
        $jobId,
        $sessionKey,
        'queued',
        $message,
        max(2, (int) ($data['percent'] ?? 0)),
        null,
        [
            'created_at' => $data['created_at'] ?? time(),
            'launch_mode' => $data['launch_mode'] ?? 'recovery',
            'pid' => $data['pid'] ?? null,
            'recovery_triggered_at' => time(),
            'recovery_mode' => 'status-shutdown',
        ],
        'queued'
    );

    respond_and_continue([
        'active' => true,
        'job_id' => $jobId,
        'data' => $updatedData,
        'recovery_started' => true,
    ]);

    run_backup_job($jobId, $sessionKey);
    exit;
}

function has_active_job($sessionKey, &$data = null)
{
    $data = load_progress('', $sessionKey);
    if (!is_array($data)) {
        return false;
    }

    $data = mark_stale_job_if_needed($data, $sessionKey);
    return in_array($data['status'] ?? 'idle', ['queued', 'running'], true);
}

function shell_functions_available()
{
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    foreach (['exec', 'escapeshellarg'] as $function) {
        if (in_array($function, $disabled, true) || !function_exists($function)) {
            return false;
        }
    }

    return true;
}

function detect_php_binary()
{
    if (defined('PHP_BINARY') && PHP_BINARY && @is_executable(PHP_BINARY)) {
        return PHP_BINARY;
    }

    if (!shell_functions_available()) {
        return '';
    }

    $output = [];
    $status = 0;
    @exec('command -v php 2>/dev/null', $output, $status);

    if ($status === 0 && !empty($output[0])) {
        return trim($output[0]);
    }

    return '';
}

function launch_cli_worker($jobId, $sessionKey)
{
    if (!shell_functions_available()) {
        return [false, null, 'shell-functions-disabled'];
    }

    $phpBinary = detect_php_binary();
    if ($phpBinary === '') {
        return [false, null, 'php-binary-not-found'];
    }

    $command = sprintf(
        'nohup %s %s %s %s %s > /dev/null 2>&1 & echo $!',
        escapeshellarg($phpBinary),
        escapeshellarg(__FILE__),
        escapeshellarg('action=worker'),
        escapeshellarg('job_id=' . $jobId),
        escapeshellarg('session_key=' . $sessionKey)
    );

    $output = [];
    $status = 0;
    @exec($command, $output, $status);

    if ($status !== 0) {
        return [false, null, 'worker-launch-failed'];
    }

    $pid = isset($output[0]) ? trim($output[0]) : null;
    return [true, $pid !== '' ? $pid : null, 'cli'];
}

function create_job_id($sessionKey)
{
    try {
        $suffix = bin2hex(random_bytes(8));
    } catch (Throwable $exception) {
        $suffix = str_replace('.', '', uniqid('', true));
    }

    return sanitize_token($sessionKey . '_' . $suffix);
}

function is_logged_in()
{
    return isset($_SESSION['user_id'])
        || isset($_SESSION['user_nick'])
        || isset($_SESSION['_sf2_attributes']['user_nick']);
}

function ensure_session_ready()
{
    if (PHP_SAPI === 'cli') {
        return '';
    }

    if (defined('FS_SESSION_NAME')) {
        session_name(FS_SESSION_NAME);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!is_logged_in()) {
        respond_json([
            'success' => false,
            'message' => 'Error: Sesión no válida. Por favor, inicie sesión nuevamente.',
        ], 401);
    }

    $sessionKey = session_id();
    session_write_close();

    return $sessionKey;
}

function run_backup_job($jobId, $sessionKey)
{
    $jobId = sanitize_token($jobId);
    $sessionKey = sanitize_token($sessionKey);

    if ($jobId === '' || $sessionKey === '') {
        return false;
    }

    if (!file_exists(__DIR__ . '/lib/backup_manager.php')) {
        save_progress($jobId, $sessionKey, 'error', 'Error: No se encuentra el plugin system_updater.', 0, 'backup_manager.php no encontrado', [
            'finished_at' => time(),
        ], 'error');
        return false;
    }

    require_once __DIR__ . '/lib/backup_manager.php';

    $lockHandle = @fopen(get_lock_file($jobId), 'c');
    if (!$lockHandle) {
        save_progress($jobId, $sessionKey, 'error', 'No se pudo adquirir el bloqueo del proceso de backup.', 0, 'No se pudo abrir el lockfile', [
            'finished_at' => time(),
        ], 'error');
        return false;
    }

    if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        return false;
    }

    $pid = function_exists('getmypid') ? getmypid() : null;
    $progressData = load_progress($jobId, $sessionKey);
    $progressExtra = [];

    if (is_array($progressData) && isset($progressData['recovery_triggered_at'])) {
        $progressExtra['recovery_attempts'] = (int) ($progressData['recovery_attempts'] ?? 0) + 1;
        $progressExtra['recovery_triggered_at'] = $progressData['recovery_triggered_at'];
        $progressExtra['recovery_mode'] = $progressData['recovery_mode'] ?? 'status-shutdown';
    }

    try {
        save_progress($jobId, $sessionKey, 'init', 'Preparando copia de seguridad...', 2, null, [
            'pid' => $pid,
            'started_at' => time(),
        ] + $progressExtra, 'running');

        $backupManager = new backup_manager(FS_FOLDER);

        $progressCallback = function ($step, $message, $percent) use ($jobId, $sessionKey, $pid, $progressExtra) {
            save_progress($jobId, $sessionKey, $step, $message, $percent, null, [
                'pid' => $pid,
            ] + $progressExtra, 'running');
        };

        $result = $backupManager->create_backup_with_progress('', true, $progressCallback);

        if (isset($result['complete']) && !empty($result['complete']['success'])) {
            $payload = [
                'message' => '¡Copia de seguridad creada con éxito!',
                'percent' => 100,
                'backup_name' => $result['complete']['backup_name'] ?? '',
                'files_size' => $result['files']['size_formatted'] ?? '',
                'database_size' => $result['database']['size_formatted'] ?? '',
                'redirect' => 'index.php?page=admin_updater&success=backup',
            ];

            save_progress($jobId, $sessionKey, 'complete', $payload['message'], 100, null, [
                'pid' => $pid,
                'finished_at' => time(),
                'result' => $payload,
            ] + $progressExtra, 'complete');
        } else {
            $errors = $backupManager->get_errors();
            $errorMessage = !empty($errors) ? implode('; ', $errors) : 'Error desconocido durante el backup';

            save_progress($jobId, $sessionKey, 'error', $errorMessage, 0, $errorMessage, [
                'pid' => $pid,
                'finished_at' => time(),
            ] + $progressExtra, 'error');
        }
    } catch (Throwable $exception) {
        $errorMessage = 'Excepción: ' . $exception->getMessage();
        save_progress($jobId, $sessionKey, 'error', $errorMessage, 0, $errorMessage, [
            'pid' => $pid,
            'finished_at' => time(),
        ] + $progressExtra, 'error');
    }

    @flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    return true;
}

if (defined('SYSTEM_UPDATER_PROCESS_BACKUP_BOOTSTRAP_ONLY') && SYSTEM_UPDATER_PROCESS_BACKUP_BOOTSTRAP_ONLY) {
    return;
}

if (!file_exists(FS_FOLDER . '/config.php')) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Error: No se encuentra el archivo config.php.\n");
        exit(1);
    }

    respond_json([
        'success' => false,
        'message' => 'Error: No se encuentra el archivo config.php.',
    ], 500);
}

require_once FS_FOLDER . '/config.php';

$action = get_request_param('action', '');

switch ($action) {
    case 'start':
        $sessionKey = ensure_session_ready();

        $existingData = null;
        if (has_active_job($sessionKey, $existingData)) {
            respond_json([
                'success' => true,
                'message' => 'Ya hay una copia de seguridad en ejecución.',
                'job_id' => $existingData['job_id'] ?? '',
                'already_running' => true,
                'data' => $existingData,
            ]);
        }

        $jobId = create_job_id($sessionKey);
        save_progress($jobId, $sessionKey, 'queued', 'Preparando el proceso de backup...', 1, null, [
            'created_at' => time(),
            'launch_mode' => 'pending',
        ], 'queued');

        list($launched, $pid, $launchMode) = launch_cli_worker($jobId, $sessionKey);

        if ($launched) {
            save_progress($jobId, $sessionKey, 'queued', 'Proceso de backup lanzado en segundo plano.', 2, null, [
                'created_at' => time(),
                'launch_mode' => $launchMode,
                'pid' => $pid,
            ], 'queued');

            respond_json([
                'success' => true,
                'job_id' => $jobId,
                'message' => 'Proceso de backup iniciado en segundo plano.',
                'launch_mode' => $launchMode,
                'pid' => $pid,
            ]);
        }

        save_progress($jobId, $sessionKey, 'queued', 'El servidor no permite lanzar un worker CLI. Se continuará tras cerrar la respuesta HTTP.', 2, null, [
            'created_at' => time(),
            'launch_mode' => 'shutdown',
            'launch_error' => $launchMode,
        ], 'queued');

        respond_and_continue([
            'success' => true,
            'job_id' => $jobId,
            'message' => 'Proceso de backup iniciado. El servidor continuará trabajando aunque la ventana no mantenga una conexión SSE.',
            'launch_mode' => 'shutdown',
        ]);

        run_backup_job($jobId, $sessionKey);
        exit;

    case 'worker':
        if (PHP_SAPI !== 'cli') {
            respond_json([
                'success' => false,
                'message' => 'Acción no válida fuera de CLI.',
            ], 400);
        }

        $jobId = sanitize_token(get_request_param('job_id'));
        $sessionKey = sanitize_token(get_request_param('session_key'));

        if ($jobId === '' || $sessionKey === '') {
            fwrite(STDERR, "Parámetros de worker incompletos.\n");
            exit(1);
        }

        run_backup_job($jobId, $sessionKey);
        exit(0);

    case 'progress':
    case 'status':
        $sessionKey = ensure_session_ready();
        $jobId = sanitize_token(get_request_param('job_id'));
        $data = load_progress($jobId, $sessionKey);

        if (is_array($data)) {
            if (should_attempt_queue_recovery($data)) {
                recover_queued_job($data, $sessionKey);
            }

            $data = mark_stale_job_if_needed($data, $sessionKey);
            respond_json([
                'active' => in_array($data['status'] ?? 'idle', ['queued', 'running'], true),
                'job_id' => $data['job_id'] ?? $jobId,
                'data' => $data,
            ]);
        }

        respond_json([
            'active' => false,
            'job_id' => $jobId,
            'data' => null,
        ]);

    case 'cleanup':
        $sessionKey = ensure_session_ready();
        $jobId = sanitize_token(get_request_param('job_id'));

        if ($jobId === '') {
            $pointer = load_pointer($sessionKey);
            $jobId = sanitize_token($pointer['job_id'] ?? '');
        }

        if ($jobId !== '') {
            clear_job_state($jobId, $sessionKey);
        }

        respond_json(['success' => true, 'job_id' => $jobId]);

    default:
        respond_json([
            'success' => false,
            'message' => 'Acción no válida: ' . $action,
        ], 400);
}
