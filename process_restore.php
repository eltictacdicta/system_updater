<?php
/**
 * Procesador de Restauración con Progreso - Plugin system_updater
 * 
 * Este script maneja la restauración de backups con reporte de progreso en tiempo real
 * usando Server-Sent Events (SSE).
 * 
 * Endpoints:
 * - action=start: Inicia la restauración completa
 * - action=progress: Obtiene el progreso actual (para polling fallback)
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

// Función para enviar eventos SSE
function send_sse($event, $data) {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data) . "\n\n";
    
    // Forzar envío inmediato
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
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

// Obtener parámetros
$action = isset($_GET['action']) ? $_GET['action'] : '';
$file = isset($_GET['file']) ? $_GET['file'] : '';
$restoreType = isset($_GET['type']) ? $_GET['type'] : 'complete';

if (empty($file)) {
    send_sse('error', ['message' => 'Error: No se especificó archivo de backup.', 'percent' => 0]);
    exit;
}

// Sanitizar nombre de archivo
$file = basename($file);

// Crear archivo de progreso temporal
$progressFile = sys_get_temp_dir() . '/fs_restore_' . session_id() . '.json';

// Función para guardar progreso
function save_progress($step, $message, $percent, $error = null) {
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
$progressCallback = function($step, $message, $percent) {
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
        // Iniciar restauración
        send_sse('start', ['message' => 'Iniciando proceso de restauración...', 'percent' => 0]);
        
        // Inicializar archivo de progreso
        save_progress('init', 'Inicializando...', 0);
        
        // Instanciar Backup Manager
        $backupManager = new backup_manager(FS_FOLDER);
        
        send_sse('init', ['message' => 'Verificando backup...', 'percent' => 2]);
        
        // Verificar que el archivo existe
        $backupPath = FS_FOLDER . '/backups/' . $file;
        if (!file_exists($backupPath)) {
            $error = 'El archivo de backup no existe: ' . $file;
            save_progress('error', $error, 0, $error);
            send_sse('error', ['message' => $error, 'percent' => 0]);
            @unlink($progressFile);
            exit;
        }
        
        send_sse('init', ['message' => 'Backup encontrado. Iniciando restauración...', 'percent' => 3]);
        
        // Ejecutar restauración según el tipo
        $result = null;
        
        try {
            if ($restoreType === 'complete') {
                send_sse('phase', ['phase' => 'complete', 'message' => 'Restauración completa']);
                $result = $backupManager->restore_complete($file, $progressCallback);
            } elseif ($restoreType === 'files') {
                send_sse('phase', ['phase' => 'files', 'message' => 'Solo archivos']);
                $result = $backupManager->restore_files($file, $progressCallback);
            } elseif ($restoreType === 'database') {
                send_sse('phase', ['phase' => 'database', 'message' => 'Solo base de datos']);
                $result = $backupManager->restore_database($file, $progressCallback);
            } else {
                throw new Exception('Tipo de restauración no válido: ' . $restoreType);
            }
            
            // Verificar resultado
            if ($result['success']) {
                save_progress('complete', '¡Restauración completada con éxito!', 100);
                send_sse('complete', [
                    'message' => '¡Restauración completada con éxito!',
                    'percent' => 100,
                    'redirect' => 'index.php?page=admin_updater&success=1'
                ]);
            } else {
                $errors = $backupManager->get_errors();
                $errorMsg = !empty($errors) ? implode('; ', $errors) : 'Error desconocido durante la restauración';
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
