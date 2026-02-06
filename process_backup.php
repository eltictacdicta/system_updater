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

// Iniciar sesión para mantener estado
session_name('fsSess');
session_start();

define('FS_FOLDER', dirname(dirname(__DIR__)));

// Cargar configuración
if (file_exists(FS_FOLDER . '/config.php')) {
    require_once FS_FOLDER . '/config.php';
} else {
    send_sse('error', ['message' => 'Error: No se encuentra el archivo config.php.', 'percent' => 0]);
    exit;
}

// Cargar Backup Manager
if (file_exists(__DIR__ . '/lib/backup_manager.php')) {
    require_once __DIR__ . '/lib/backup_manager.php';
} else {
    send_sse('error', ['message' => 'Error: No se encuentra el plugin system_updater.', 'percent' => 0]);
    exit;
}

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    send_sse('error', ['message' => 'Error: Sesión no válida. Por favor, inicie sesión nuevamente.', 'percent' => 0]);
    exit;
}

// Obtener parámetros
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Crear archivo de progreso temporal
$progressFile = sys_get_temp_dir() . '/fs_backup_' . session_id() . '.json';

// Función para guardar progreso
function save_progress($step, $message, $percent, $error = null)
{
    global $progressFile;
    $data = [
        'step' => $step,
        'message' => $message,
        'percent' => $percent,
        'timestamp' => time(),
        'error' => $error
    ];
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
        'timestamp' => time()
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
                save_progress('complete', $data['message'], 100);
                send_sse('complete', $data);
            } else {
                $errors = $backupManager->get_errors();
                $errorMsg = !empty($errors) ? implode('; ', $errors) : 'Error desconocido durante el backup';
                save_progress('error', $errorMsg, 0, $errorMsg);
                send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
            }

        } catch (Exception $e) {
            $errorMsg = 'Excepción: ' . $e->getMessage();
            save_progress('error', $errorMsg, 0, $errorMsg);
            send_sse('error', ['message' => $errorMsg, 'percent' => 0]);
        }

        // Limpiar archivo de progreso
        @unlink($progressFile);
        break;

    case 'progress':
        // Solo devolver el progreso actual (para polling)
        if (file_exists($progressFile)) {
            $data = json_decode(file_get_contents($progressFile), true);
            send_sse('progress', $data);
        } else {
            send_sse('progress', ['step' => 'waiting', 'message' => 'Esperando inicio...', 'percent' => 0]);
        }
        break;

    case 'status':
        // Verificar si hay un proceso en ejecución
        if (file_exists($progressFile)) {
            $data = json_decode(file_get_contents($progressFile), true);
            // Verificar si el proceso está "vivo" (menos de 2 minutos sin actualizar)
            $isAlive = (time() - $data['timestamp']) < 120;
            send_sse('status', [
                'active' => $isAlive,
                'data' => $data
            ]);
        } else {
            send_sse('status', ['active' => false, 'data' => null]);
        }
        break;

    default:
        send_sse('error', ['message' => 'Acción no válida: ' . $action, 'percent' => 0]);
}

exit;
