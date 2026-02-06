<?php
/**
 * Manejador de subida de backups por chunks
 * 
 * Este script maneja la subida de archivos ZIP de backup utilizando
 * Resumable.js para permitir la subida de archivos grandes divididos
 * en fragmentos.
 * 
 * Utiliza la librería segura del core fs_secure_chunked_upload que
 * proporciona:
 * - Verificación de origen (solo desde plugins)
 * - Autenticación obligatoria (Symfony SessionManager)
 * - Validación CSRF
 * - Rate limiting
 * - Validación de firmas de archivo
 * - Sanitización de archivos
 * 
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 * @version 2.0.0
 */

// Cargar el bootstrap del framework para tener acceso al Kernel y Request de Symfony
define('FS_FOLDER', dirname(dirname(__DIR__)));

// Cargar autoloader y configuración
require_once FS_FOLDER . '/vendor/autoload.php';
require_once FS_FOLDER . '/config.php';

// Cambiar el directorio de trabajo a la raíz del proyecto para que los includes relativos funcionen
chdir(FS_FOLDER);

// Iniciar sesión para mantener estado (auth check)
if (session_status() == PHP_SESSION_NONE) {
    session_name('fsSess');
    @session_start();
}

// Inicializar el Kernel de Symfony si no está inicializado
try {
    \FSFramework\Core\Kernel::getInstance();
} catch (\RuntimeException $e) {
    \FSFramework\Core\Kernel::boot();
}

// Cargar modelos legacy necesarios para la autenticación (SessionManager -> syncFromLegacyCookies)
// fs_model.php requiere fs_core_log.php usando un require relativo, por lo que incluimos fs_core_log primero
if (file_exists(FS_FOLDER . '/base/fs_core_log.php')) {
    require_once FS_FOLDER . '/base/fs_core_log.php';
}
require_once FS_FOLDER . '/base/fs_model.php';
if (file_exists(FS_FOLDER . '/model/fs_user.php')) {
    require_once FS_FOLDER . '/model/fs_user.php';
}

// Cargar la librería segura del core
require_once FS_FOLDER . '/base/fs_secure_chunked_upload.php';

// Cargar backup manager
require_once __DIR__ . '/lib/backup_manager.php';

// Instanciar backup manager para obtener la ruta de backups
$backupManager = new backup_manager();
$targetDir = $backupManager->get_backup_path() . '/';

// Asegurar que el directorio existe
if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0755, true);
}

// Procesar la petición
try {
    // Crear el uploader seguro
    $uploader = new fs_secure_chunked_upload(
        'system_updater',      // Nombre del plugin (debe coincidir con el directorio)
        $targetDir,            // Directorio destino
        ['zip', 'gz'],         // Extensiones permitidas
        2048                   // 2GB máximo para backups
    );

    // Desactivar CSRF para compatibilidad con Resumable.js
    // (Resumable.js envía múltiples peticiones y no maneja tokens CSRF nativamente)
    $uploader->disable_csrf();

    // Callback cuando se completa la subida
    $uploader->on_complete(function ($final_path, $filename, $filesize, $params, $uploaded_by) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $backupType = 'unknown';

        // Si es un ZIP, validar su contenido
        if ($ext === 'zip') {
            $zip = new ZipArchive();
            if ($zip->open($final_path) !== TRUE) {
                @unlink($final_path);
                throw new Exception('El archivo no es un ZIP válido');
            }

            // Verificar contenido del backup
            $hasBackupMetadata = $zip->locateName('backup_metadata.json') !== false;
            $hasDatabase = false;
            $hasFiles = false;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (strpos($name, 'database/') === 0) {
                    $hasDatabase = true;
                }
                if (strpos($name, 'files/') === 0) {
                    $hasFiles = true;
                }
            }

            $zip->close();

            // Determinar el tipo de backup
            if ($hasBackupMetadata && $hasDatabase && $hasFiles) {
                $backupType = 'complete';
            } elseif (strpos($filename, '_db') !== false || $hasDatabase) {
                $backupType = 'database';
            } elseif (strpos($filename, '_files') !== false || $hasFiles) {
                $backupType = 'files';
            } else {
                $backupType = 'zip';
            }
        } elseif ($ext === 'gz') {
            $backupType = 'database';
        }

        // Log de la subida
        error_log("Backup subido: {$filename} ({$filesize} bytes) por {$uploaded_by} - Tipo: {$backupType}");

        return [
            'backup_type' => $backupType,
            'filename' => $filename,
            'size' => $filesize
        ];
    });

    // Manejar la subida del chunk
    $result = $uploader->handle_chunk();

    // Enviar respuesta JSON
    fs_secure_chunked_upload::send_json_response($result);

} catch (Exception $e) {
    $code = $e->getCode();
    $httpCode = ($code >= 400 && $code < 600) ? $code : 500;

    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
