<?php
/**
 * Procesador de Backup con Progreso - Plugin system_updater
 * 
 * Este script maneja la creación de backups con reporte de progreso en tiempo real
 * usando Server-Sent Events (SSE).
 * 
 * Se ejecuta de forma asíncrona para evitar timeouts en servidores con límites de tiempo.
 * 
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 */

// Configurar headers para SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Desactivar buffering de nginx

// Aumentar límites de ejecución
@set_time_limit(0);
@ini_set('max_execution_time', 0);
@ini_set('memory_limit', '512M');
@ignore_user_abort(true);

// Desactivar compresión de salida
@ini_set('zlib.output_compression', 'Off');
@ini_set('output_buffering', 'Off');
@ini_set('output_handler', '');

// Limpiar cualquier buffer existente
while (ob_get_level()) {
    ob_end_clean();
}

// Función para enviar eventos SSE
function send_sse($event, $data)
{
    echo "event: {$event}\n";
    echo "data: " . json_encode($data) . "\n\n";

    // Forzar envío inmediato
    @flush();
}

define('FS_FOLDER', dirname(dirname(__DIR__)));

// Cargar configuración
if (file_exists(FS_FOLDER . '/config.php')) {
    require_once FS_FOLDER . '/config.php';
} else {
    send_sse('error', ['message' => 'Error: No se encuentra el archivo config.php.', 'percent' => 0]);
    exit;
}

// Iniciar sesión para mantener estado
// Usar el nombre de sesión configurado o el por defecto de PHP (PHPSESSID)
if (defined('FS_SESSION_NAME')) {
    session_name(FS_SESSION_NAME);
}
session_start();

// Cargar Backup Manager
if (file_exists(__DIR__ . '/lib/backup_manager.php')) {
    require_once __DIR__ . '/lib/backup_manager.php';
} else {
    send_sse('error', ['message' => 'Error: No se encuentra el plugin system_updater.', 'percent' => 0]);
    exit;
}

// Verificar autenticación (compatibilidad con Symfony Session y Legacy)
$is_logged = false;
if (isset($_SESSION['user_id'])) {
    $is_logged = true;
} elseif (isset($_SESSION['user_nick'])) {
    $is_logged = true;
} elseif (isset($_SESSION['_sf2_attributes']['user_nick'])) {
    $is_logged = true;
}

if (!$is_logged) {
    send_sse('error', ['message' => 'Error: Sesión no válida. Por favor, inicie sesión nuevamente.', 'percent' => 0]);
    exit;
}

// Liberar el bloqueo de sesión para permitir peticiones paralelas de estado/progreso.
session_write_close();

// Obtener parámetros
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Crear archivo de progreso temporal
$progressFile = sys_get_temp_dir() . '/fs_backup_' . session_id() . '.json';

// Mantener un pequeño histórico del estado final para permitir recuperación
// si el canal SSE se corta antes de que el navegador procese el último evento.
function load_progress()
{
    global $progressFile;

    if (!file_exists($progressFile)) {
        return null;
    }

    $content = @file_get_contents($progressFile);
    if ($content === false || $content === '') {
        return null;
    }

    $data = json_decode($content, true);
    return is_array($data) ? $data : null;
}

// Función para guardar progreso
function save_progress($step, $message, $percent, $error = null, array $extra = [])
{
    global $progressFile;
    $data = [
        'step' => $step,
        'message' => $message,
        'percent' => $percent,
        'timestamp' => time(),
        'error' => $error,
        'status' => $error ? 'error' : (($step === 'complete') ? 'complete' : 'running')
    ];

    if (!empty($extra)) {
        $data = array_merge($data, $extra);
    }

    @file_put_contents($progressFile, json_encode($data));
    return $data;
}

// Callback de progreso para backup_manager
$progressCallback = function ($step, $message, $percent) {
    global $progressFile;

    $data = [
        'step' => $step,
        'message' => $message,
        'percent' => $percent,
        'timestamp' => time(),
        'status' => 'running'
    ];

    // Guardar en archivo
    @file_put_contents($progressFile, json_encode($data));

    // Enviar vía SSE
    send_sse('progress', $data);

    // Pequeña pausa para permitir que el navegador procese
    usleep(10000); // 10ms
};

// Procesar según la acción
switch ($action) {
    case 'start':
        $existingProgress = load_progress();
        if (is_array($existingProgress) && !empty($existingProgress['timestamp']) && (time() - (int) $existingProgress['timestamp']) > 86400) {
            @unlink($progressFile);
        }

        // Iniciar backup
        send_sse('start', ['message' => 'Iniciando proceso de backup...', 'percent' => 0]);

        // Inicializar archivo de progreso
        save_progress('init', 'Inicializando...', 0);

        // Instanciar Backup Manager
        $backupManager = new backup_manager(FS_FOLDER);

        send_sse('init', ['message' => 'Preparando copia de seguridad...', 'percent' => 2]);

        try {
            // Crear backup con progreso
            send_sse('phase', ['phase' => 'backup', 'message' => 'Creando copia de seguridad completa']);

            $result = $backupManager->create_backup_with_progress('', true, $progressCallback);

            // Verificar resultado
            if (isset($result['complete']) && $result['complete']['success']) {
                $data = [
                    'message' => '¡Copia de seguridad creada con éxito!',
                    'percent' => 100,
                    'backup_name' => $result['complete']['backup_name'] ?? '',
                    'files_size' => $result['files']['size_formatted'] ?? '',
                    'database_size' => $result['database']['size_formatted'] ?? '',
                    'redirect' => 'index.php?page=admin_updater&success=backup'
                ];
                save_progress('complete', $data['message'], 100, null, [
                    'finished_at' => time(),
                    'result' => $data,
                ]);
                send_sse('complete', $data);
            } else {
                $errors = $backupManager->get_errors();
                $errorMsg = !empty($errors) ? implode('; ', $errors) : 'Error desconocido durante el backup';
                save_progress('error', $errorMsg, 0, $errorMsg, [
                    'finished_at' => time(),
                ]);
                send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
            }

        } catch (Exception $e) {
            $errorMsg = 'Excepción: ' . $e->getMessage();
            save_progress('error', $errorMsg, 0, $errorMsg, [
                'finished_at' => time(),
            ]);
            send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
        }
        break;

    case 'progress':
        // Solo devolver el progreso actual (para polling)
        $data = load_progress();
        if (is_array($data)) {
            if (isset($_GET['format']) && $_GET['format'] === 'json') {
                header('Content-Type: application/json');
                echo json_encode($data);
            } else {
                send_sse('progress', $data);
            }
        } else {
            $payload = ['step' => 'waiting', 'message' => 'Esperando inicio...', 'percent' => 0, 'status' => 'idle'];
            if (isset($_GET['format']) && $_GET['format'] === 'json') {
                header('Content-Type: application/json');
                echo json_encode($payload);
            } else {
                send_sse('progress', $payload);
            }
        }
        break;

    case 'status':
        // Verificar si hay un proceso en ejecución
        $data = load_progress();
        if (is_array($data)) {
            // Verificar si el proceso está "vivo" (menos de 2 minutos sin actualizar)
            $status = $data['status'] ?? 'running';
            $isAlive = $status === 'running' && (time() - $data['timestamp']) < 120;
            $payload = [
                'active' => $isAlive,
                'data' => $data
            ];

            if (isset($_GET['format']) && $_GET['format'] === 'json') {
                header('Content-Type: application/json');
                echo json_encode($payload);
            } else {
                send_sse('status', $payload);
            }
        } else {
            $payload = ['active' => false, 'data' => null];
            if (isset($_GET['format']) && $_GET['format'] === 'json') {
                header('Content-Type: application/json');
                echo json_encode($payload);
            } else {
                send_sse('status', $payload);
            }
        }
        break;

    case 'cleanup':
        @unlink($progressFile);
        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } else {
            send_sse('complete', ['success' => true]);
        }
        break;

    default:
        send_sse('error', ['message' => 'Acción no válida: ' . $action, 'percent' => 0]);
}

exit;
