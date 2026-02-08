<?php
/**
 * Controlador del Actualizador - Plugin system_updater
 * 
 * Extiende fs_controller para compatibilidad total con el framework.
 * Usa el sistema de templates del tema actual.
 * 
 * Maneja:
 * - Verificación y actualización del núcleo
 * - Actualización de plugins
 * - Sistema de copias de seguridad (backups)
 * - Restauración de backups
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
    private $backupManager;

    /**
     * @var array Lista de backups agrupados
     */
    public $backups;

    /**
     * @var string Mensaje de éxito
     */
    public $successMessage;

    /**
     * @var string Mensaje de error
     */
    public $errorMessage;

    /**
     * @var array Información del actualizador
     */
    public $updaterInfo;

    /**
     * @var array|false Actualización disponible del actualizador
     */
    public $updaterUpdate;

    /**
     * @var array Actualizaciones del núcleo y plugins
     */
    public $updates;

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
        // Cargar dependencias del plugin
        require_once __DIR__ . '/../lib/backup_manager.php';
        require_once __DIR__ . '/../lib/updater_manager.php';
        require_once 'base/fs_plugin_manager.php';
        
        // Verificar si es una subida chunked (Resumable.js)
        // Solo disponible en versiones del framework que incluyan fs_secure_chunked_upload
        $chunkedUploadFile = FS_FOLDER . '/base/fs_secure_chunked_upload.php';
        if (file_exists($chunkedUploadFile)) {
            require_once $chunkedUploadFile;
            if (class_exists('fs_secure_chunked_upload') && fs_secure_chunked_upload::is_chunk_request()) {
                // Asegurar que backupManager esté disponible para el handler
                $this->backupManager = new backup_manager();
                $this->handleChunkUpload();
                return;
            }
        }

        $this->backupManager = new backup_manager();
        $this->plugin_manager = new fs_plugin_manager();

        $this->successMessage = '';
        $this->errorMessage = '';

        // Procesar acciones
        $this->processActions();

        // Cargar datos para la vista
        $this->loadData();

        // Usar la vista del plugin (el template engine la buscará)
        // Por defecto usa el nombre de la clase como template
    }

    /**
     * Procesa las acciones del usuario
     */
    private function processActions()
    {
        $action = $this->getQueryParam('action');

        switch ($action) {
            case 'download_backup':
                $file = $this->getQueryParam('file');
                $type = $this->getQueryParam('type');
                if ($file) {
                    $this->downloadBackup($file, $type);
                }
                break;

            case 'create_backup':
                $result = $this->backupManager->create_backup();
                if ($result['complete']['success']) {
                    $this->successMessage = 'Copia de seguridad creada: ' . $result['complete']['backup_name'];
                    if ($this->isAjax()) {
                        $this->jsonResponse(['success' => true, 'message' => $this->successMessage, 'data' => $result]);
                    }
                    $this->new_message($this->successMessage);
                } else {
                    $this->errorMessage = 'Error al crear backup: ' . implode(', ', $this->backupManager->get_errors());
                    if ($this->isAjax()) {
                        $this->jsonResponse(['success' => false, 'message' => $this->errorMessage]);
                    }
                    $this->new_error_msg($this->errorMessage);
                }
                break;

            case 'delete_backup_group':
                $baseName = $this->getQueryParam('base_name');
                if ($baseName) {
                    $result = $this->backupManager->delete_backup_group($baseName);
                    if ($result['success']) {
                        $this->successMessage = 'Copias eliminadas: ' . implode(', ', $result['deleted']);
                        if ($this->isAjax()) {
                            $this->jsonResponse(['success' => true, 'message' => $this->successMessage]);
                        }
                        $this->new_message($this->successMessage);
                    } else {
                        $this->errorMessage = implode(', ', $result['errors']);
                        if ($this->isAjax()) {
                            $this->jsonResponse(['success' => false, 'message' => $this->errorMessage]);
                        }
                        $this->new_error_msg($this->errorMessage);
                    }
                }
                break;

            case 'restore_complete':
                $file = $this->getQueryParam('file');
                if ($file) {
                    $result = $this->backupManager->restore_complete($file);
                    if ($result['success']) {
                        $this->successMessage = 'Restauración completa realizada correctamente';
                        if ($this->isAjax()) {
                            $this->jsonResponse(['success' => true, 'message' => $this->successMessage]);
                        }
                        $this->new_message($this->successMessage);
                    } else {
                        $this->errorMessage = 'Error en restauración: ' . implode(', ', $this->backupManager->get_errors());
                        if ($this->isAjax()) {
                            $this->jsonResponse(['success' => false, 'message' => $this->errorMessage]);
                        }
                        $this->new_error_msg($this->errorMessage);
                    }
                }
                break;

            case 'restore_files':
                $file = $this->getQueryParam('file');
                if ($file) {
                    $result = $this->backupManager->restore_files($file);
                    if ($result['success']) {
                        $this->successMessage = 'Archivos restaurados correctamente';
                        if ($this->isAjax()) {
                            $this->jsonResponse(['success' => true, 'message' => $this->successMessage]);
                        }
                        $this->new_message($this->successMessage);
                    } else {
                        $this->errorMessage = 'Error al restaurar archivos: ' . implode(', ', $this->backupManager->get_errors());
                        if ($this->isAjax()) {
                            $this->jsonResponse(['success' => false, 'message' => $this->errorMessage]);
                        }
                        $this->new_error_msg($this->errorMessage);
                    }
                }
                break;

            case 'restore_database':
                $file = $this->getQueryParam('file');
                if ($file) {
                    $result = $this->backupManager->restore_database($file);
                    if ($result['success']) {
                        $this->successMessage = 'Base de datos restaurada correctamente';
                        if ($this->isAjax()) {
                            $this->jsonResponse(['success' => true, 'message' => $this->successMessage]);
                        }
                        $this->new_message($this->successMessage);
                    } else {
                        $this->errorMessage = 'Error al restaurar base de datos: ' . implode(', ', $this->backupManager->get_errors());
                        if ($this->isAjax()) {
                            $this->jsonResponse(['success' => false, 'message' => $this->errorMessage]);
                        }
                        $this->new_error_msg($this->errorMessage);
                    }
                }
                break;

            case 'update_core':
                $this->updateCore();
                break;

            case 'reinstall_core':
                $this->updateCore();
                break;

            case 'update_plugin':
                $pluginName = $this->getQueryParam('plugin');
                if ($pluginName) {
                    $this->updatePlugin($pluginName);
                }
                break;
        }
    }

    /**
     * Carga los datos para la vista
     */
    private function loadData()
    {
        // Backups agrupados
        $this->backups = $this->backupManager->list_backups_grouped();

        // Información del actualizador
        $updaterManager = new updater_manager();
        $this->updaterInfo = $updaterManager->get_info();
        $this->updaterUpdate = $updaterManager->check_for_updates();

        // Actualizaciones disponibles
        $this->updates = $this->checkForUpdates();
    }

    /**
     * Verifica actualizaciones del núcleo y plugins
     * @return array
     */
    private function checkForUpdates()
    {
        $updates = [
            'core' => false,
            'core_new_version' => null,
            'plugins' => []
        ];

        // Verificar actualización del núcleo
        try {
            $remoteVersion = @file_get_contents('https://raw.githubusercontent.com/eltictacdicta/fs-framework/master/VERSION');
            if ($remoteVersion && $remoteVersion !== 'ERROR') {
                $remoteVersion = trim($remoteVersion);
                $localVersion = $this->plugin_manager->version;

                if (version_compare($remoteVersion, $localVersion, '>')) {
                    $updates['core'] = true;
                    $updates['core_new_version'] = $remoteVersion;
                }
            }
        } catch (Exception $e) {
            // Silently fail
        }

        // Verificar actualizaciones de plugins
        foreach ($this->plugin_manager->installed() as $plugin) {
            if (empty($plugin['version_url']) || empty($plugin['update_url'])) {
                continue;
            }

            try {
                $remoteIni = @parse_ini_string(@file_get_contents($plugin['version_url']));
                if ($remoteIni && isset($remoteIni['version'])) {
                    if (version_compare($remoteIni['version'], $plugin['version'], '>')) {
                        $updates['plugins'][] = [
                            'name' => $plugin['name'],
                            'current_version' => $plugin['version'],
                            'new_version' => $remoteIni['version'],
                            'update_url' => $plugin['update_url'],
                            'description' => $plugin['description'] ?? ''
                        ];
                    }
                }
            } catch (Exception $e) {
                // Silently fail
            }
        }

        return $updates;
    }

    /**
     * Actualiza el núcleo de FSFramework
     */
    private function updateCore()
    {
        // Crear backup antes de actualizar
        $backupResult = $this->backupManager->create_backup('pre_update_core_' . date('Y-m-d_H-i-s'));

        if (!$backupResult['complete']['success']) {
            $this->errorMessage = 'Error al crear copia de seguridad pre-actualización';
            $this->new_error_msg($this->errorMessage);
            return;
        }

        // Descargar actualización
        $urls = [
            'https://github.com/eltictacdicta/fs-framework/archive/refs/heads/master.zip',
            'https://codeload.github.com/eltictacdicta/fs-framework/zip/refs/heads/master'
        ];

        $downloaded = false;
        foreach ($urls as $url) {
            if (@fs_file_download($url, FS_FOLDER . '/update-core.zip')) {
                $downloaded = true;
                break;
            }
        }

        if (!$downloaded) {
            $this->errorMessage = 'Error al descargar la actualización del núcleo';
            $this->new_error_msg($this->errorMessage);
            return;
        }

        // Extraer
        require_once 'base/fs_file_manager.php';
        if (!fs_file_manager::extract_zip_safe(FS_FOLDER . '/update-core.zip', FS_FOLDER)) {
            $this->errorMessage = 'Error al extraer la actualización';
            $this->new_error_msg($this->errorMessage);
            @unlink(FS_FOLDER . '/update-core.zip');
            return;
        }

        @unlink(FS_FOLDER . '/update-core.zip');

        // Eliminar carpetas antiguas
        foreach (['base', 'controller', 'extras', 'model', 'view', 'src'] as $folder) {
            $folderPath = FS_FOLDER . '/' . $folder . '/';
            if (is_dir($folderPath)) {
                fs_file_manager::del_tree($folderPath);
            }
        }

        // Copiar archivos nuevos
        fs_file_manager::recurse_copy(FS_FOLDER . '/fs-framework-master/', FS_FOLDER);
        fs_file_manager::del_tree(FS_FOLDER . '/fs-framework-master/');

        $this->successMessage = 'Núcleo actualizado correctamente. Backup creado: ' . $backupResult['complete']['backup_name'];
        $this->new_message($this->successMessage);

        // Limpiar caché
        $this->cache->clean();
    }

    /**
     * Actualiza un plugin específico
     * @param string $pluginName
     */
    private function updatePlugin($pluginName)
    {
        foreach ($this->plugin_manager->installed() as $plugin) {
            if ($plugin['name'] !== $pluginName) {
                continue;
            }

            if (empty($plugin['update_url'])) {
                $this->errorMessage = 'El plugin no tiene URL de actualización';
                $this->new_error_msg($this->errorMessage);
                return;
            }

            // Descargar plugin
            if (!@fs_file_download($plugin['update_url'], FS_FOLDER . '/update-plugin.zip')) {
                $this->errorMessage = 'Error al descargar el plugin';
                $this->new_error_msg($this->errorMessage);
                return;
            }

            // Guardar lista previa
            $pluginsList = fs_file_manager::scan_folder(FS_FOLDER . '/plugins');

            // Eliminar versión anterior
            fs_file_manager::del_tree(FS_FOLDER . '/plugins/' . $pluginName);

            // Extraer
            if (!fs_file_manager::extract_zip_safe(FS_FOLDER . '/update-plugin.zip', FS_FOLDER . '/plugins/')) {
                $this->errorMessage = 'Error al extraer el plugin';
                $this->new_error_msg($this->errorMessage);
                return;
            }

            @unlink(FS_FOLDER . '/update-plugin.zip');

            // Renombrar si es necesario
            foreach (fs_file_manager::scan_folder(FS_FOLDER . '/plugins') as $f) {
                if (is_dir(FS_FOLDER . '/plugins/' . $f) && !in_array($f, $pluginsList)) {
                    rename(FS_FOLDER . '/plugins/' . $f, FS_FOLDER . '/plugins/' . $pluginName);
                    break;
                }
            }

            $this->successMessage = 'Plugin ' . $pluginName . ' actualizado correctamente';
            $this->new_message($this->successMessage);
            $this->cache->clean();
            return;
        }

        $this->errorMessage = 'Plugin no encontrado';
        $this->new_error_msg($this->errorMessage);
    }

    /**
     * Obtiene la ruta de backups
     * @return string
     */
    public function getBackupPath()
    {
        return $this->backupManager->get_backup_path();
    }

    /**
     * Detecta si la petición es AJAX
     * @return bool
     */
    public function isAjax(): bool
    {
        return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')
            || $this->getQueryParam('ajax');
    }

    /**
     * Envía una respuesta JSON y termina la ejecución
     * @param array $data
     */
    private function jsonResponse($data)
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Forzar la descarga de un backup
     * @param string $file Nombre del archivo
     * @param string $type Tipo (opcional)
     */
    private function downloadBackup($file, $type = null)
    {
        $path = $this->backupManager->get_backup_path() . DIRECTORY_SEPARATOR . basename($file);

        if (file_exists($path)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        } else {
            $this->new_error_msg("Archivo no encontrado: " . basename($file));
        }
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

    /**
     * Maneja la subida de archivos por chunks (Resumable.js)
     */
    protected function handleChunkUpload()
    {
        $this->template = false;

        // Asegurar que el directorio existe
        $targetDir = $this->backupManager->get_backup_path() . '/';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        try {
            // Crear el uploader seguro
            $uploader = new fs_secure_chunked_upload(
                'system_updater',      // Nombre del plugin
                $targetDir,            // Directorio destino
                ['zip', 'gz'],         // Extensiones permitidas
                2048                   // 2GB máximo para backups
            );

            // Desactivar CSRF para compatibilidad con Resumable.js
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
    }
}
