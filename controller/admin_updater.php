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
        $action = $this->request->query->get('action');

        switch ($action) {
            case 'download_backup':
                $file = $this->request->query->get('file');
                $type = $this->request->query->get('type');
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
                $baseName = $this->request->query->get('base_name');
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
                $file = $this->request->query->get('file');
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
                $file = $this->request->query->get('file');
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
                $file = $this->request->query->get('file');
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

            case 'update_plugin':
                $pluginName = $this->request->query->get('plugin');
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
            $remoteVersion = @file_get_contents('https://raw.githubusercontent.com/eltictacdicta/fs-framework/refs/heads/master/VERSION');
            if ($remoteVersion && $remoteVersion !== 'ERROR') {
                $remoteVersion = trim($remoteVersion);
                $localVersion = $this->plugin_manager->version;

                if (floatval($remoteVersion) > floatval($localVersion)) {
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
                    if (intval($remoteIni['version']) > intval($plugin['version'])) {
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
            || $this->request->query->get('ajax');
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
}
