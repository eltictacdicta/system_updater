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
        require_once 'base/fs_plugin_manager.php';
        $this->downloader = new plugin_downloader();
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
                $pluginId = $this->getQueryParam('plugin_id');
                if ($pluginId) {
                    if ($this->downloader->download($pluginId)) {
                        $this->successMessage = 'Plugin descargado correctamente';
                        // Activar plugin
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

            case 'download_private':
                $pluginId = $this->getQueryParam('plugin_id');
                if ($pluginId) {
                    if ($this->downloader->download_private($pluginId)) {
                        $this->successMessage = 'Plugin privado descargado correctamente';
                        // Activar plugin
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
                $this->downloader->delete_private_config();
                $this->successMessage = 'Configuración de plugins privados eliminada';
                $this->new_message($this->successMessage);
                $this->activeTab = 'private';
                break;

            case 'refresh':
                $this->downloader->refresh();
                $this->successMessage = 'Caché actualizada. Lista de plugins recargada.';
                $this->new_message($this->successMessage);
                break;
        }
    }

    /**
     * Carga los datos para la vista
     */
    private function loadData()
    {
        // Plugins públicos
        $this->publicPlugins = $this->downloader->downloads();

        // Plugins privados
        $this->privateConfig = $this->downloader->get_private_config();
        $this->privateEnabled = $this->downloader->is_private_plugins_enabled();
        $this->privatePlugins = $this->privateEnabled ? $this->downloader->private_downloads() : [];
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
