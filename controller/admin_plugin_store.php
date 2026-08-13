<?php
/**
 * Tienda de Plugins - Plugin system_updater
 * 
 * Extiende fs_controller para compatibilidad total con el framework.
 * Usa el sistema de templates del tema actual.
 * 
 * Maneja:
 * - Descarga de plugins públicos desde repositorio
 * - Descarga de plugins privados con autenticación GitHub
 * - Configuración de repositorios privados
 * 
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 * @version 1.0.0
 */

require_once 'base/fs_controller.php';

class admin_plugin_store extends fs_controller
{
    /**
     * @var plugin_downloader
     */
    public $downloader;

    /**
     * @var array Lista de plugins públicos
     */
    public $publicPlugins;

    /**
     * @var array Lista de plugins privados
     */
    public $privatePlugins;

    /**
     * @var array Configuración de plugins privados
     */
    public $privateConfig;

    /**
     * @var bool Si los plugins privados están habilitados
     */
    public $privateEnabled;

    /**
     * @var string Mensaje de éxito
     */
    public $successMessage;

    /**
     * @var string Mensaje de error
     */
    public $errorMessage;

    /**
     * @var string Pestaña activa
     */
    public $activeTab;

    /**
     * @var array Configuración del catálogo público
     */
    public $catalogConfig;

    /**
     * @var string JSON del catálogo local
     */
    public $catalogJson;

    /**
     * @var string URL raw del catálogo remoto
     */
    public $catalogRemoteUrl;

    /**
     * @var plugin_catalog_manager
     */
    public $catalogManager;

    /**
     * @var bool Indica si hay token de GitHub configurado para el catálogo
     */
    public $catalogHasGithubToken;

    /**
     * @var bool Indica si hay token de GitHub configurado para plugins privados
     */
    public $privateHasGithubToken;

    /**
     * @var fs_plugin_manager
     */
    public $plugin_manager;

    /**
     * Constructor - registra la página en el menú admin
     */
    public function __construct()
    {
        parent::__construct(__CLASS__, 'Tienda de Plugins', 'admin', TRUE, TRUE);
    }

    /**
     * Lógica principal del controlador (usuario autenticado)
     */
    protected function private_core()
    {
        // Cargar el plugin_downloader
        require_once __DIR__ . '/../lib/plugin_downloader.php';
        require_once __DIR__ . '/../lib/plugin_catalog_manager.php';
        require_once 'base/fs_plugin_manager.php';
        $this->downloader = new plugin_downloader();
        $this->catalogManager = new plugin_catalog_manager();
        $this->plugin_manager = new fs_plugin_manager();

        $this->successMessage = '';
        $this->errorMessage = '';
        $this->activeTab = $this->getQueryParam('tab', 'public');

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

        switch ($action) {
            case 'download':
                if (!$this->requireCsrf()) {
                    return;
                }
                $pluginId = $this->getPostParam('plugin_id') ?: $this->getQueryParam('plugin_id');
                if ($pluginId) {
                    if ($this->downloader->download($pluginId)) {
                        $this->successMessage = 'Plugin descargado correctamente';
                        $plugins = $this->downloader->downloads();
                        foreach ($plugins as $p) {
                            if ($p['id'] == $pluginId) {
                                $this->plugin_manager->enable($p['nombre']);
                                $this->successMessage = 'Plugin descargado y activado correctamente';
                                break;
                            }
                        }
                        $this->new_message($this->successMessage);
                    } else {
                        $this->errorMessage = implode(', ', $this->downloader->get_errors()) ?: 'Error al descargar el plugin';
                        $this->new_error_msg($this->errorMessage);
                    }
                }
                break;

            case 'update_public':
                if (!$this->requireCsrf()) {
                    return;
                }
                $pluginId = $this->getPostParam('plugin_id') ?: $this->getQueryParam('plugin_id');
                if ($pluginId && $this->updatePublicPlugin((int) $pluginId)) {
                    $this->new_message($this->successMessage ?: 'Plugin actualizado correctamente.');
                } else {
                    $this->errorMessage = $this->errorMessage
                        ?: (implode(', ', $this->downloader->get_errors()) ?: 'Error al actualizar el plugin');
                    $this->new_error_msg($this->errorMessage);
                }
                $this->activeTab = 'public';
                break;

            case 'download_private':
                if (!$this->requireCsrf()) {
                    return;
                }
                $pluginId = $this->getPostParam('plugin_id') ?: $this->getQueryParam('plugin_id');
                if ($pluginId) {
                    if ($this->downloader->download_private($pluginId)) {
                        $this->successMessage = 'Plugin privado descargado correctamente';
                        $plugins = $this->downloader->private_downloads();
                        foreach ($plugins as $p) {
                            if ($p['id'] == $pluginId) {
                                $this->plugin_manager->enable($p['nombre']);
                                $this->successMessage = 'Plugin privado descargado y activado correctamente';
                                break;
                            }
                        }
                        $this->new_message($this->successMessage);
                    } else {
                        $this->errorMessage = implode(', ', $this->downloader->get_errors()) ?: 'Error al descargar el plugin privado';
                        $this->new_error_msg($this->errorMessage);
                    }
                }
                $this->activeTab = 'private';
                break;

            case 'save_private_config':
                if (!$this->requireCsrf()) {
                    return;
                }
                $token = $this->getPostParam('github_token');
                $url = $this->getPostParam('private_plugins_url');

                if ($this->downloader->save_private_config($token, $url)) {
                    $this->successMessage = 'Configuración guardada correctamente';
                    $this->new_message($this->successMessage);
                } else {
                    $this->errorMessage = 'Error al guardar la configuración';
                    $this->new_error_msg($this->errorMessage);
                }
                $this->activeTab = 'private';
                break;

            case 'test_private_connection':
                if (!$this->requireCsrf()) {
                    return;
                }
                $result = $this->downloader->test_private_connection();
                if ($result['success']) {
                    $this->successMessage = $result['message'];
                    $this->new_message($this->successMessage);
                } else {
                    $this->errorMessage = $result['message'];
                    $this->new_error_msg($this->errorMessage);
                }
                $this->activeTab = 'private';
                break;

            case 'delete_private_config':
                if (!$this->requireCsrf()) {
                    return;
                }
                $this->downloader->delete_private_config();
                $this->successMessage = 'Configuración de plugins privados eliminada';
                $this->new_message($this->successMessage);
                $this->activeTab = 'private';
                break;

            case 'refresh':
                if (!$this->requireCsrf()) {
                    return;
                }
                $this->downloader->refresh();
                $this->successMessage = 'Caché actualizada. Lista de plugins recargada.';
                $this->new_message($this->successMessage);
                break;

            case 'save_catalog':
                if (!$this->requireCsrf()) {
                    return;
                }
                $catalogJson = (string) $this->getPostParam('catalog_json', '');
                $entries = $this->catalogManager->parseCatalogJson($catalogJson);
                if ($entries !== null && $this->catalogManager->saveLocalCatalog($entries)) {
                    $this->successMessage = implode(' ', $this->catalogManager->getMessages()) ?: 'Catálogo guardado.';
                    $this->new_message($this->successMessage);
                } else {
                    $this->errorMessage = implode(', ', $this->catalogManager->getErrors()) ?: 'Error al guardar el catálogo.';
                    $this->new_error_msg($this->errorMessage);
                }
                $this->activeTab = 'catalog';
                break;

            case 'sync_catalog':
                if (!$this->requireCsrf()) {
                    return;
                }
                if ($this->catalogManager->syncFromRemote()) {
                    $this->successMessage = implode(' ', $this->catalogManager->getMessages()) ?: 'Catálogo sincronizado.';
                    $this->new_message($this->successMessage);
                } else {
                    $this->errorMessage = implode(', ', $this->catalogManager->getErrors()) ?: 'Error al sincronizar el catálogo.';
                    $this->new_error_msg($this->errorMessage);
                }
                $this->downloader->refresh();
                $this->activeTab = 'catalog';
                break;

            case 'publish_catalog':
                if (!$this->requireCsrf()) {
                    return;
                }
                $commitMessage = trim((string) $this->getPostParam('commit_message', ''));
                if ($this->catalogManager->publishToRemote($commitMessage !== '' ? $commitMessage : null)) {
                    $this->successMessage = implode(' ', $this->catalogManager->getMessages()) ?: 'Catálogo publicado.';
                    $this->new_message($this->successMessage);
                } else {
                    $this->errorMessage = implode(', ', $this->catalogManager->getErrors()) ?: 'Error al publicar el catálogo.';
                    $this->new_error_msg($this->errorMessage);
                }
                $this->downloader->refresh();
                $this->activeTab = 'catalog';
                break;

            case 'save_catalog_config':
                if (!$this->requireCsrf()) {
                    return;
                }
                if ($this->catalogManager->saveConfig(
                    (string) $this->getPostParam('github_token', ''),
                    (string) $this->getPostParam('catalog_repo', ''),
                    (string) $this->getPostParam('catalog_branch', ''),
                    (string) $this->getPostParam('catalog_file', '')
                )) {
                    $this->successMessage = 'Configuración del catálogo guardada.';
                    $this->new_message($this->successMessage);
                } else {
                    $this->errorMessage = implode(', ', $this->catalogManager->getErrors()) ?: 'Error al guardar la configuración.';
                    $this->new_error_msg($this->errorMessage);
                }
                $this->activeTab = 'catalog';
                break;
        }
    }

    /**
     * Carga los datos para la vista
     */
    private function loadData()
    {
        require_once __DIR__ . '/../lib/plugin_compatibility_checker.php';

        // Plugins públicos
        $this->publicPlugins = $this->downloader->downloads();
        $installedByName = [];
        foreach ($this->plugin_manager->installed() as $installed) {
            $installedByName[(string) ($installed['name'] ?? '')] = $installed;
        }

        foreach ($this->publicPlugins as $key => $plugin) {
            $name = (string) ($plugin['nombre'] ?? '');
            $localVersion = isset($installedByName[$name]['version'])
                ? (string) $installedByName[$name]['version']
                : '';
            $remoteVersion = isset($plugin['version']) ? (string) $plugin['version'] : '';

            $this->publicPlugins[$key]['local_version'] = $localVersion;
            $this->publicPlugins[$key]['update_available'] = $localVersion !== ''
                && $remoteVersion !== ''
                && plugin_compatibility_checker::isRemoteVersionNewer($remoteVersion, $localVersion);
        }

        // Plugins privados
        $this->privateConfig = $this->downloader->get_private_config();
        $this->privateEnabled = $this->downloader->is_private_plugins_enabled();
        $this->privatePlugins = $this->privateEnabled ? $this->downloader->private_downloads() : [];

        // Catálogo público editable
        $this->catalogConfig = $this->catalogManager->getConfig();
        $this->catalogHasGithubToken = ($this->catalogConfig['github_token'] ?? '') !== '';
        $this->catalogConfig['github_token'] = '';
        $this->catalogJson = $this->catalogManager->getLocalCatalogJson();
        $this->catalogRemoteUrl = $this->catalogManager->getRemoteRawUrl();

        $this->privateHasGithubToken = ($this->privateConfig['github_token'] ?? '') !== '';
        $this->privateConfig['github_token'] = '';
    }

    /**
     * Comprueba si un plugin está instalado
     * @param string $name
     * @return bool
     */
    public function isInstalled($name)
    {
        return file_exists(FS_FOLDER . '/plugins/' . $name);
    }

    /**
     * Comprueba si un plugin está activo
     * @param string $name
     * @return bool
     */
    public function isActive($name)
    {
        return in_array($name, $this->plugin_manager->enabled());
    }

    /**
     * Actualiza un plugin público del catálogo conservando el estado activo/inactivo.
     */
    private function updatePublicPlugin(int $pluginId): bool
    {
        require_once __DIR__ . '/../lib/maintenance_mode_compat.php';

        $targetName = null;
        $targetEntry = null;
        foreach ($this->downloader->downloads() as $plugin) {
            if ((int) ($plugin['id'] ?? 0) === $pluginId) {
                $targetName = (string) ($plugin['nombre'] ?? '');
                $targetEntry = $plugin;
                break;
            }
        }

        if ($targetName === null || $targetName === '' || !is_array($targetEntry)) {
            return false;
        }

        require_once __DIR__ . '/../lib/plugin_compatibility_checker.php';
        $coreVersion = (string) $this->plugin_manager->version;
        $evaluation = plugin_compatibility_checker::validateRemotePluginForCore(
            $coreVersion,
            plugin_compatibility_checker::boundsFromCatalogEntry($targetEntry)
        );
        if (!$evaluation['compatible']) {
            $this->errorMessage = 'No se puede actualizar ' . $targetName . ': la versión remota '
                . ($evaluation['message'] ?? 'no es compatible con el núcleo actual.');
            return false;
        }

        $wasEnabled = $this->isActive($targetName);

        if (!system_updater_begin_maintenance([
            'message' => 'Actualización del plugin ' . $targetName . ' en curso.',
            'source' => 'system_updater.plugin_store_update',
            'plugin' => $targetName,
            'retry_after' => 180,
        ])) {
            $this->errorMessage = 'No se pudo activar el modo mantenimiento.';
            return false;
        }

        try {
            if (!$this->downloader->download($pluginId)) {
                return false;
            }

            foreach ($this->downloader->get_errors() as $error) {
                if (str_starts_with($error, 'Esquema BD')) {
                    $this->new_advice($error);
                }
            }

            if ($wasEnabled && !$this->plugin_manager->is_plugin_enabled($targetName)) {
                if (!$this->plugin_manager->enable($targetName)) {
                    $this->successMessage = 'Plugin actualizado, pero no se pudo reactivar.';
                    return true;
                }
            }

            $this->successMessage = $wasEnabled
                ? 'Plugin actualizado correctamente.'
                : 'Plugin actualizado correctamente.';
            return true;
        } finally {
            system_updater_end_maintenance();
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
}
