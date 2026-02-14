<?php
/**
 * Actualizador del Sistema - Plugin system_updater
 * 
 * Extiende fs_controller para compatibilidad total con el framework.
 * Usa el sistema de templates del tema actual.
 * 
 * Maneja:
 * - Verificación de actualizaciones del núcleo y plugins
 * - Creación, restauración y eliminación de copias de seguridad
 * - Descarga de copias de seguridad
 * - Subida de copias de seguridad por chunks (Resumable.js)
 * - Actualización del núcleo y plugins
 * 
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 * @version 1.0.0
 */

require_once 'base/fs_controller.php';

class admin_updater extends fs_controller
{
    /**
     * @var backup_manager
     */
    private $backup_manager;

    /**
     * @var updater_manager
     */
    private $updater_mgr;

    /**
     * @var array Lista de backups agrupados
     */
    public $backups;

    /**
     * @var array Información de actualizaciones disponibles
     */
    public $updates;

    /**
     * @var array Información del plugin actualizador
     */
    public $updaterInfo;

    /**
     * @var string Mensaje de éxito
     */
    public $successMessage;

    /**
     * @var string Mensaje de error
     */
    public $errorMessage;

    /**
     * @var fs_plugin_manager
     */
    public $plugin_manager;

    /**
     * Constructor - registra la página en el menú admin
     */
    public function __construct()
    {
        parent::__construct(__CLASS__, 'Actualizador', 'admin', TRUE, TRUE);
    }

    /**
     * Lógica principal del controlador (usuario autenticado)
     */
    protected function private_core()
    {
        // Cargar dependencias
        require_once __DIR__ . '/../lib/backup_manager.php';
        require_once __DIR__ . '/../lib/updater_manager.php';
        require_once 'base/fs_plugin_manager.php';

        $this->backup_manager = new backup_manager();
        $this->updater_mgr = new updater_manager();
        $this->plugin_manager = new fs_plugin_manager();

        $this->successMessage = '';
        $this->errorMessage = '';

        // Verificar mensajes de éxito por parámetro GET
        $success = $this->getQueryParam('success');
        if ($success === 'backup') {
            $this->successMessage = 'Copia de seguridad creada correctamente.';
        } elseif ($success === '1') {
            $this->successMessage = 'Restauración completada correctamente.';
        }

        // Procesar acciones
        $this->processActions();

        // Cargar datos
        $this->loadData();
    }

    /**
     * Procesa las acciones del usuario
     */
    private function processActions()
    {
        $action = $this->getQueryParam('action') ?: $this->getPostParam('action');

        if (empty($action)) {
            // Check for Resumable.js chunk upload
            if ($this->isChunkUpload()) {
                $this->handleChunkUpload();
                return;
            }
            return;
        }

        switch ($action) {
            case 'update_core':
                $this->actionUpdateCore();
                break;

            case 'update_plugin':
                $pluginName = $this->getQueryParam('plugin');
                if ($pluginName) {
                    $this->actionUpdatePlugin($pluginName);
                }
                break;

            case 'create_backup':
                $this->actionCreateBackup();
                break;

            case 'restore_complete':
                $file = $this->getQueryParam('file');
                if ($file) {
                    $this->actionRestore($file, 'complete');
                }
                break;

            case 'restore_database':
                $file = $this->getQueryParam('file');
                if ($file) {
                    $this->actionRestore($file, 'database');
                }
                break;

            case 'restore_files':
                $file = $this->getQueryParam('file');
                if ($file) {
                    $this->actionRestore($file, 'files');
                }
                break;

            case 'download_backup':
                $file = $this->getQueryParam('file');
                if ($file) {
                    $this->actionDownloadBackup($file);
                }
                break;

            case 'delete_backup_group':
                $baseName = $this->getQueryParam('base_name');
                if ($baseName) {
                    $this->actionDeleteBackupGroup($baseName);
                }
                break;

            case 'reinstall_core':
                $this->actionReinstallCore();
                break;
        }
    }

    /**
     * Carga los datos para la vista
     */
    private function loadData()
    {
        // Información del actualizador
        $this->updaterInfo = $this->updater_mgr->get_info();

        // Backups agrupados
        $this->backups = $this->backup_manager->list_backups_grouped();

        // Verificar actualizaciones
        $this->updates = $this->checkUpdates();
    }

    /**
     * Verifica actualizaciones disponibles del core y plugins
     * 
     * @return array
     */
    private function checkUpdates()
    {
        $updates = [
            'core' => false,
            'core_new_version' => '',
            'plugins' => [],
        ];

        // Comprobar actualización del actualizador
        $updaterUpdate = $this->updater_mgr->check_for_updates();
        if ($updaterUpdate && isset($updaterUpdate['available']) && $updaterUpdate['available']) {
            // El actualizador tiene una actualización
        }

        // Comprobar actualizaciones de plugins instalados
        // Comparar versión local con versión remota de los plugins privados
        if ($this->plugin_manager->is_private_plugins_enabled()) {
            $remotePlugins = $this->plugin_manager->private_downloads();
            $installedPlugins = $this->plugin_manager->installed();

            foreach ($remotePlugins as $remote) {
                $pluginName = $remote['nombre'] ?? '';
                if (empty($pluginName) || !$remote['instalado']) {
                    continue;
                }

                // Obtener versión local del plugin instalado
                $localVersion = null;
                foreach ($installedPlugins as $installed) {
                    if ($installed['name'] === $pluginName) {
                        $localVersion = $installed['version'] ?? null;
                        break;
                    }
                }

                $remoteVersion = $remote['version'] ?? null;

                if ($localVersion !== null && $remoteVersion !== null && $this->isRemoteVersionNewer((string) $remoteVersion, (string) $localVersion)) {
                    $updates['plugins'][] = [
                        'name' => $pluginName,
                        'description' => $remote['descripcion'] ?? '',
                        'current_version' => $localVersion,
                        'new_version' => $remoteVersion,
                    ];
                }
            }
        }

        // Comprobar actualización del core
        // El core se compara contra la versión remota del repositorio
        $coreUpdate = $this->checkCoreUpdate();
        if ($coreUpdate) {
            $updates['core'] = true;
            $updates['core_new_version'] = $coreUpdate;
        }

        return $updates;
    }

    /**
     * Comprueba si hay una actualización del core disponible
     * 
     * @return string|false Nueva versión disponible o false
     */
    private function checkCoreUpdate()
    {
        // Intentar obtener la versión remota desde GitHub
        $remoteVersionUrl = 'https://raw.githubusercontent.com/eltictacdicta/fs-framework/master/VERSION';
        $remoteVersion = @file_get_contents($remoteVersionUrl);

        if ($remoteVersion === false) {
            return false;
        }

        $remoteVersion = trim($remoteVersion);
        $localVersion = $this->plugin_manager->version;

        if (!empty($remoteVersion) && $this->isRemoteVersionNewer($remoteVersion, (string) $localVersion)) {
            return $remoteVersion;
        }

        return false;
    }

    /**
     * Determina si la versión remota es más nueva que la local.
     *
     * @param string $remoteVersion
     * @param string $localVersion
     *
     * @return bool
     */
    private function isRemoteVersionNewer($remoteVersion, $localVersion)
    {
        $remote = trim((string) $remoteVersion);
        $local = trim((string) $localVersion);

        if ($remote === '' || $local === '') {
            return false;
        }

        return version_compare($remote, $local, '>');
    }

    /**
     * Acción: Actualizar el núcleo
     */
    private function actionUpdateCore()
    {
        // Crear backup previo
        $backupResult = $this->backup_manager->create_pre_update_backup('core');
        if (!isset($backupResult['complete']['success']) || !$backupResult['complete']['success']) {
            $this->errorMessage = 'Error al crear backup previo: ' . implode(', ', $this->backup_manager->get_errors());
            $this->new_error_msg($this->errorMessage);
            return;
        }

        // Descargar e instalar la actualización del core
        $zipUrl = 'https://github.com/eltictacdicta/fs-framework/archive/master.zip';
        $downloadPath = FS_FOLDER . '/download_core.zip';

        if (!@fs_file_download($zipUrl, $downloadPath)) {
            $this->errorMessage = 'Error al descargar la actualización del núcleo.';
            $this->new_error_msg($this->errorMessage);
            return;
        }

        // Extraer y copiar archivos del core
        require_once 'base/fs_file_manager.php';
        $extractPath = FS_FOLDER . '/tmp/core_update';

        if (!fs_file_manager::extract_zip_safe($downloadPath, $extractPath)) {
            $this->errorMessage = 'Error al extraer la actualización.';
            $this->new_error_msg($this->errorMessage);
            @unlink($downloadPath);
            return;
        }

        // Buscar la carpeta extraída (normalmente fs-framework-master)
        $extractedDirs = @scandir($extractPath);
        $sourceDir = null;
        if ($extractedDirs) {
            foreach ($extractedDirs as $dir) {
                if ($dir !== '.' && $dir !== '..' && is_dir($extractPath . '/' . $dir)) {
                    $sourceDir = $extractPath . '/' . $dir;
                    break;
                }
            }
        }

        if ($sourceDir) {
            // Copiar archivos del core (excluyendo plugins, config, backups)
            $excludeFiles = ['config.php', 'plugins', 'backups', 'tmp', '.git'];
            $this->copyDirectorySelective($sourceDir, FS_FOLDER, $excludeFiles);
            $this->successMessage = 'Núcleo actualizado correctamente.';
            $this->new_message($this->successMessage);
        } else {
            $this->errorMessage = 'No se encontró la carpeta extraída.';
            $this->new_error_msg($this->errorMessage);
        }

        // Limpiar
        @unlink($downloadPath);
        if (is_dir($extractPath)) {
            $this->deleteDirectoryRecursive($extractPath);
        }
    }

    /**
     * Acción: Actualizar un plugin
     * 
     * @param string $pluginName
     */
    private function actionUpdatePlugin($pluginName)
    {
        // Intentar actualizar a través de la tienda de plugins privados
        if ($this->plugin_manager->is_private_plugins_enabled()) {
            $remotePlugins = $this->plugin_manager->private_downloads();
            foreach ($remotePlugins as $remote) {
                if (($remote['nombre'] ?? '') === $pluginName && isset($remote['id'])) {
                    if ($this->plugin_manager->download_private($remote['id'])) {
                        $this->successMessage = "Plugin $pluginName actualizado correctamente.";
                        $this->new_message($this->successMessage);
                    } else {
                        $this->errorMessage = "Error al actualizar el plugin $pluginName.";
                        $this->new_error_msg($this->errorMessage);
                    }
                    return;
                }
            }
        }

        $this->errorMessage = "No se pudo encontrar la actualización para $pluginName.";
        $this->new_error_msg($this->errorMessage);
    }

    /**
     * Acción: Crear copia de seguridad
     */
    private function actionCreateBackup()
    {
        $result = $this->backup_manager->create_backup();

        if (isset($result['complete']) && $result['complete']['success']) {
            $msg = 'Copia de seguridad creada correctamente.';
            if ($this->isAjax()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $msg]);
                exit;
            }
            $this->successMessage = $msg;
            $this->new_message($this->successMessage);
        } else {
            $msg = 'Error al crear la copia de seguridad: ' . implode(', ', $this->backup_manager->get_errors());
            if ($this->isAjax()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $msg]);
                exit;
            }
            $this->errorMessage = $msg;
            $this->new_error_msg($this->errorMessage);
        }
    }

    /**
     * Acción: Restaurar copia de seguridad
     * 
     * @param string $file
     * @param string $type (complete, database, files)
     */
    private function actionRestore($file, $type)
    {
        $result = null;

        switch ($type) {
            case 'complete':
                $result = $this->backup_manager->restore_complete($file);
                break;
            case 'database':
                $result = $this->backup_manager->restore_database($file);
                break;
            case 'files':
                $result = $this->backup_manager->restore_files($file);
                break;
        }

        if ($result && $result['success']) {
            $this->successMessage = 'Restauración completada correctamente.';
            $this->new_message($this->successMessage);
        } else {
            $errors = $this->backup_manager->get_errors();
            $this->errorMessage = 'Error en la restauración: ' . implode(', ', $errors);
            $this->new_error_msg($this->errorMessage);
        }
    }

    /**
     * Acción: Descargar copia de seguridad
     * 
     * @param string $file
     */
    private function actionDownloadBackup($file)
    {
        $filePath = $this->backup_manager->get_backup_path() . DIRECTORY_SEPARATOR . basename($file);

        if (file_exists($filePath)) {
            // Enviar el archivo para descarga
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        } else {
            $this->errorMessage = 'El archivo de backup no se encontró.';
            $this->new_error_msg($this->errorMessage);
        }
    }

    /**
     * Acción: Eliminar grupo de backups
     * 
     * @param string $baseName
     */
    private function actionDeleteBackupGroup($baseName)
    {
        $result = $this->backup_manager->delete_backup_group($baseName);

        if ($result['success']) {
            $msg = 'Grupo de copias de seguridad eliminado correctamente.';
            if ($this->isAjax()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $msg]);
                exit;
            }
            $this->successMessage = $msg;
            $this->new_message($this->successMessage);
        } else {
            $msg = 'Error al eliminar las copias de seguridad: ' . implode(', ', $result['errors'] ?? []);
            if ($this->isAjax()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $msg]);
                exit;
            }
            $this->errorMessage = $msg;
            $this->new_error_msg($this->errorMessage);
        }
    }

    /**
     * Acción: Reinstalar el núcleo (forzar descarga)
     */
    private function actionReinstallCore()
    {
        $this->actionUpdateCore();
    }

    /**
     * Comprueba si la petición es una subida de chunk (Resumable.js)
     * 
     * @return bool
     */
    private function isChunkUpload()
    {
        return (
            isset($_FILES['file']) ||
            (isset($_GET['resumableChunkNumber']) || isset($_POST['resumableChunkNumber']))
        );
    }

    /**
     * Maneja la subida de archivos por chunks (Resumable.js)
     */
    private function handleChunkUpload()
    {
        // Cargar la librería de subida segura por chunks si existe
        if (file_exists(FS_FOLDER . '/base/fs_secure_chunked_upload.php')) {
            require_once FS_FOLDER . '/base/fs_secure_chunked_upload.php';

            $uploader = new fs_secure_chunked_upload([
                'upload_dir' => $this->backup_manager->get_backup_path() . DIRECTORY_SEPARATOR,
                'allowed_extensions' => ['zip'],
                'max_file_size' => 2 * 1024 * 1024 * 1024, // 2GB
            ]);

            $uploader->onComplete(function ($filename, $filepath) {
                // Archivo subido completamente, mover a directorio de backups
                $this->successMessage = 'Archivo de backup subido correctamente: ' . $filename;
                $this->new_message($this->successMessage);
            });

            $uploader->handleRequest();
            exit;
        }

        // Fallback: manejo básico de chunks
        $this->handleChunkUploadBasic();
    }

    /**
     * Manejo básico de subida por chunks sin la librería del core
     */
    private function handleChunkUploadBasic()
    {
        $chunkNumber = isset($_REQUEST['resumableChunkNumber']) ? (int) $_REQUEST['resumableChunkNumber'] : 0;
        $totalChunks = isset($_REQUEST['resumableTotalChunks']) ? (int) $_REQUEST['resumableTotalChunks'] : 0;
        $filename = isset($_REQUEST['resumableFilename']) ? basename($_REQUEST['resumableFilename']) : '';
        $identifier = isset($_REQUEST['resumableIdentifier']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_REQUEST['resumableIdentifier']) : '';

        if (empty($filename) || empty($identifier)) {
            http_response_code(400);
            echo json_encode(['message' => 'Parámetros inválidos']);
            exit;
        }

        // Validar extensión
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            http_response_code(400);
            echo json_encode(['message' => 'Solo se permiten archivos .zip']);
            exit;
        }

        $tempDir = sys_get_temp_dir() . '/resumable_' . $identifier;
        $chunkFile = $tempDir . '/chunk_' . str_pad($chunkNumber, 4, '0', STR_PAD_LEFT);

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            // Test si el chunk ya existe
            if (file_exists($chunkFile)) {
                http_response_code(200);
            } else {
                http_response_code(204);
            }
            exit;
        }

        // POST: recibir chunk
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }

        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            move_uploaded_file($_FILES['file']['tmp_name'], $chunkFile);
        }

        // Comprobar si todos los chunks han llegado
        $allChunksPresent = true;
        for ($i = 1; $i <= $totalChunks; $i++) {
            if (!file_exists($tempDir . '/chunk_' . str_pad($i, 4, '0', STR_PAD_LEFT))) {
                $allChunksPresent = false;
                break;
            }
        }

        if ($allChunksPresent) {
            // Ensamblar el archivo final
            $finalPath = $this->backup_manager->get_backup_path() . DIRECTORY_SEPARATOR . $filename;
            $fp = fopen($finalPath, 'wb');

            if ($fp) {
                for ($i = 1; $i <= $totalChunks; $i++) {
                    $chunkPath = $tempDir . '/chunk_' . str_pad($i, 4, '0', STR_PAD_LEFT);
                    $chunkContent = file_get_contents($chunkPath);
                    fwrite($fp, $chunkContent);
                    @unlink($chunkPath);
                }
                fclose($fp);
                @rmdir($tempDir);

                echo json_encode(['message' => 'Archivo subido correctamente']);
            } else {
                http_response_code(500);
                echo json_encode(['message' => 'Error al ensamblar el archivo']);
            }
        }

        exit;
    }

    /**
     * Elimina un directorio de forma recursiva
     * 
     * @param string $dir
     * @return bool
     */
    private function deleteDirectoryRecursive($dir)
    {
        if (!is_dir($dir)) {
            return true;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectoryRecursive($path) : @unlink($path);
        }

        return @rmdir($dir);
    }

    /**
     * Obtiene la ruta del directorio de backups
     * 
     * @return string
     */
    public function getBackupPath()
    {
        return $this->backup_manager->get_backup_path();
    }

    /**
     * Copia selectiva de directorio excluyendo ciertos archivos/directorios
     * 
     * @param string $source
     * @param string $dest
     * @param array $exclude
     */
    private function copyDirectorySelective($source, $dest, $exclude = [])
    {
        $dir = opendir($source);
        if (!$dir) {
            return;
        }

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (in_array($file, $exclude)) {
                continue;
            }

            $srcPath = $source . '/' . $file;
            $destPath = $dest . '/' . $file;

            if (is_dir($srcPath)) {
                if (!is_dir($destPath)) {
                    @mkdir($destPath, 0755, true);
                }
                $this->copyDirectorySelective($srcPath, $destPath, []);
            } else {
                @copy($srcPath, $destPath);
            }
        }

        closedir($dir);
    }

    /**
     * Obtiene un parámetro GET de forma compatible con versiones anteriores del framework.
     * Usa $this->request (Symfony) si está disponible, sino $_GET.
     * 
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    private function getQueryParam($name, $default = null)
    {
        if (isset($this->request) && $this->request !== null) {
            return $this->request->query->get($name, $default);
        }
        return isset($_GET[$name]) ? $_GET[$name] : $default;
    }

    /**
     * Obtiene un parámetro POST de forma compatible con versiones anteriores del framework.
     * Usa $this->request (Symfony) si está disponible, sino $_POST.
     * 
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    private function getPostParam($name, $default = null)
    {
        if (isset($this->request) && $this->request !== null) {
            return $this->request->request->get($name, $default);
        }
        return isset($_POST[$name]) ? $_POST[$name] : $default;
    }
}
