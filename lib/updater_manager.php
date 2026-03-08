<?php
/**
 * Updater Manager - Gestiona las actualizaciones del propio actualizador
 * 
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 * @version 1.0.0
 */
class updater_manager
{
    const INI_FILE = 'fsframework.ini';
    const PLUGIN_NAME = 'system_updater';
    const MANIFEST_FILE = 'tmp/system_updater_self_update.json';
    const STAGING_DIR = 'tmp/system_updater_self_update';

    /**
     * @var string Ruta raíz del framework
     */
    private $rootPath;

    /**
     * @var string Ruta al directorio del actualizador
     */
    private $updaterPath;

    /**
     * @var array Configuración local del actualizador
     */
    private $localConfig;

    /**
     * @var array Configuración remota del actualizador
     */
    private $remoteConfig;

    /**
     * @var array Errores
     */
    private $errors = [];

    /**
     * @var array Mensajes
     */
    private $messages = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->rootPath = defined('FS_FOLDER') ? FS_FOLDER : dirname(dirname(dirname(__DIR__)));
        $this->updaterPath = $this->rootPath . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . self::PLUGIN_NAME;
        $this->loadLocalConfig();
    }

    /**
     * Carga la configuración local del actualizador
     */
    private function loadLocalConfig()
    {
        $iniPath = $this->updaterPath . DIRECTORY_SEPARATOR . self::INI_FILE;

        if (file_exists($iniPath)) {
            $this->localConfig = parse_ini_file($iniPath, true) ?: [];
        } else {
            $this->localConfig = [
                'plugin' => [
                    'version' => '0.0.0',
                    'name' => 'FSFramework Updater',
                    'description' => 'Sistema de actualización y backups',
                ]
            ];
        }
    }

    /**
     * Obtiene la sección principal de configuración.
     *
     * @return array
     */
    private function getConfigSection()
    {
        if (isset($this->localConfig['plugin']) && is_array($this->localConfig['plugin'])) {
            return $this->localConfig['plugin'];
        }

        if (isset($this->localConfig['updater']) && is_array($this->localConfig['updater'])) {
            return $this->localConfig['updater'];
        }

        return [];
    }

    /**
     * Obtiene un valor de configuración.
     *
     * @param string $key
     * @param mixed $default
     *
     * @return mixed
     */
    private function getConfigValue($key, $default = null)
    {
        $section = $this->getConfigSection();
        return $section[$key] ?? $default;
    }

    /**
     * Obtiene la versión local del actualizador
     * 
     * @return string
     */
    public function get_local_version()
    {
        return (string) $this->getConfigValue('version', '0.0.0');
    }

    /**
     * Obtiene la información del actualizador
     * 
     * @return array
     */
    public function get_info()
    {
        $pending = $this->get_pending_self_update();

        return [
            'version' => $this->get_local_version(),
            'name' => $this->getConfigValue('name', 'FSFramework Updater'),
            'description' => $this->getConfigValue('description', ''),
            'last_update' => $this->getConfigValue('last_update'),
            'author' => $this->getConfigValue('author', 'FSFramework Team'),
            'repository' => $this->getConfigValue('repository_url', 'https://github.com/eltictacdicta/system_updater'),
            'pending_update' => $pending,
        ];
    }

    /**
     * Obtiene las URLs remotas para comprobar la versión.
     *
     * @return array
     */
    private function getRemoteVersionUrls()
    {
        $urls = [
            $this->getConfigValue('remote_version_url'),
            $this->getConfigValue('version_url'),
            $this->getConfigValue('remote_version_url_alt'),
            $this->getConfigValue('version_url_alt'),
            'https://raw.githubusercontent.com/eltictacdicta/system_updater/refs/heads/master/fsframework.ini',
            'https://raw.githubusercontent.com/eltictacdicta/system_updater/refs/heads/main/fsframework.ini',
            'https://raw.githubusercontent.com/eltictacdicta/system_updater/master/fsframework.ini',
            'https://raw.githubusercontent.com/eltictacdicta/system_updater/main/fsframework.ini',
        ];

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * Obtiene las URLs del ZIP de actualización.
     *
     * @return array
     */
    private function getUpdateZipUrls()
    {
        $urls = [
            $this->getConfigValue('update_zip_url'),
            $this->getConfigValue('update_url'),
            $this->getConfigValue('update_zip_url_alt'),
            'https://github.com/eltictacdicta/system_updater/archive/refs/heads/master.zip',
            'https://github.com/eltictacdicta/system_updater/archive/refs/heads/main.zip',
        ];

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * Descarga un recurso remoto.
     *
     * @param string $url
     *
     * @return string|false
     */
    private function fetchRemoteContents($url)
    {
        if (empty($url)) {
            return false;
        }

        if (function_exists('fs_file_get_contents')) {
            $content = @fs_file_get_contents($url, 20);
            if ($content !== false && $content !== 'ERROR') {
                return $content;
            }
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 20,
                'header' => "User-Agent: FSFramework-System-Updater\r\n",
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        return @file_get_contents($url, false, $context);
    }

    /**
     * Descarga un archivo a disco intentando varias URLs.
     *
     * @param array $urls
     * @param string $destination
     *
     * @return string|false URL utilizada o false
     */
    private function downloadUpdatePackage(array $urls, $destination)
    {
        foreach ($urls as $url) {
            if (empty($url)) {
                continue;
            }

            if (function_exists('fs_file_download') && @fs_file_download($url, $destination)) {
                return $url;
            }

            $content = $this->fetchRemoteContents($url);
            if ($content !== false && @file_put_contents($destination, $content) !== false) {
                return $url;
            }
        }

        return false;
    }

    /**
     * Devuelve la ruta al manifiesto pendiente.
     *
     * @return string
     */
    private function getManifestPath()
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
    }

    /**
     * Devuelve la ruta base de staging.
     *
     * @return string
     */
    private function getStagingBasePath()
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . self::STAGING_DIR;
    }

    /**
     * Elimina un directorio de forma recursiva.
     *
     * @param string $path
     *
     * @return bool
     */
    private function removeTree($path)
    {
        if (!file_exists($path)) {
            return true;
        }

        if (!class_exists('fs_file_manager') && file_exists($this->rootPath . '/base/fs_file_manager.php')) {
            require_once $this->rootPath . '/base/fs_file_manager.php';
        }

        if (class_exists('fs_file_manager')) {
            return fs_file_manager::del_tree($path);
        }

        if (is_file($path)) {
            return @unlink($path);
        }

        $items = @scandir($path);
        if (!is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($fullPath)) {
                $this->removeTree($fullPath);
            } else {
                @unlink($fullPath);
            }
        }

        return @rmdir($path);
    }

    /**
     * Busca la carpeta válida del plugin en una extracción.
     *
     * @param string $extractPath
     *
     * @return string|false
     */
    private function findExtractedPluginPath($extractPath)
    {
        if ($this->isValidPluginFolder($extractPath)) {
            return $extractPath;
        }

        $entries = @scandir($extractPath);
        if (!is_array($entries)) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $candidate = $extractPath . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($candidate) && $this->isValidPluginFolder($candidate)) {
                return $candidate;
            }
        }

        return false;
    }

    /**
     * Valida una carpeta candidata del plugin.
     *
     * @param string $path
     *
     * @return bool
     */
    private function isValidPluginFolder($path)
    {
        if (!is_dir($path)) {
            return false;
        }

        if (!file_exists($path . DIRECTORY_SEPARATOR . self::INI_FILE)) {
            return false;
        }

        if (!file_exists($path . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'admin_updater.php')) {
            return false;
        }

        $ini = @parse_ini_file($path . DIRECTORY_SEPARATOR . self::INI_FILE, true);
        $section = $ini['plugin'] ?? $ini['updater'] ?? [];
        $name = $section['name'] ?? self::PLUGIN_NAME;

        return $name === self::PLUGIN_NAME;
    }

    /**
     * Limpia cualquier actualización pendiente previa.
     *
     * @return void
     */
    private function clearPendingState()
    {
        $manifest = $this->get_pending_self_update();
        if ($manifest && !empty($manifest['staging_root'])) {
            $this->removeTree($manifest['staging_root']);
        }

        if (file_exists($this->getManifestPath())) {
            @unlink($this->getManifestPath());
        }
    }

    /**
     * Obtiene la actualización pendiente si existe y es válida.
     *
     * @return array|false
     */
    public function get_pending_self_update()
    {
        $manifestPath = $this->getManifestPath();
        if (!file_exists($manifestPath)) {
            return false;
        }

        $manifest = json_decode((string) @file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            @unlink($manifestPath);
            return false;
        }

        if (($manifest['plugin'] ?? '') !== self::PLUGIN_NAME) {
            @unlink($manifestPath);
            return false;
        }

        if (empty($manifest['token']) || empty($manifest['staged_path']) || !is_dir($manifest['staged_path'])) {
            if (!empty($manifest['staging_root'])) {
                $this->removeTree($manifest['staging_root']);
            }
            @unlink($manifestPath);
            return false;
        }

        $manifest['finalize_url'] = 'updater.php?action=finalize_system_updater_update&token=' . rawurlencode($manifest['token']);
        return $manifest;
    }

    /**
     * Comprueba si hay actualizaciones disponibles para el actualizador
     * 
     * @return bool|array False si no hay actualizaciones, array con info si la hay
     */
    public function check_for_updates()
    {
        try {
            foreach ($this->getRemoteVersionUrls() as $url) {
                $remoteIni = $this->fetchRemoteContents($url);

                if ($remoteIni === false) {
                    continue;
                }

                $this->remoteConfig = @parse_ini_string($remoteIni, true);
                $remoteSection = $this->remoteConfig['plugin'] ?? $this->remoteConfig['updater'] ?? [];
                if (!$this->remoteConfig || empty($remoteSection['version'])) {
                    continue;
                }

                $remoteVersion = $this->normalizeVersion((string) $remoteSection['version']);
                $localVersion = $this->normalizeVersion($this->get_local_version());

                if ($remoteVersion === '' || $localVersion === '') {
                    continue;
                }

                if (version_compare($remoteVersion, $localVersion, '>')) {
                    return [
                        'available' => true,
                        'current_version' => $localVersion,
                        'new_version' => $remoteVersion,
                        'update_zip_url' => $remoteSection['update_zip_url'] ?? $remoteSection['update_url'] ?? $this->getConfigValue('update_zip_url'),
                        'update_zip_urls' => $this->getUpdateZipUrls(),
                        'description' => $remoteSection['description'] ?? '',
                        'repository_url' => $remoteSection['repository_url'] ?? $this->getConfigValue('repository_url'),
                        'version_source' => $url,
                    ];
                }

                return false;
            }

            return false;
        } catch (\Exception $e) {
            $this->errors[] = 'Error al comprobar actualizaciones: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Prepara la actualización del propio plugin y devuelve la URL de finalización.
     * 
     * @return bool|array
     */
    public function prepare_self_update()
    {
        $updateInfo = $this->check_for_updates();

        if (!$updateInfo || !$updateInfo['available']) {
            $this->errors[] = 'No hay actualizaciones disponibles para el actualizador.';
            return false;
        }

        if (!is_dir($this->updaterPath)) {
            $this->errors[] = 'No se encontró la carpeta actual del plugin system_updater.';
            return false;
        }

        $pluginsDir = $this->rootPath . DIRECTORY_SEPARATOR . 'plugins';
        $tmpDir = $this->rootPath . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_writable($pluginsDir) || !is_writable($tmpDir)) {
            $this->errors[] = 'Se requieren permisos de escritura en plugins/ y tmp/.';
            return false;
        }

        try {
            if (!class_exists('fs_file_manager') && file_exists($this->rootPath . '/base/fs_file_manager.php')) {
                require_once $this->rootPath . '/base/fs_file_manager.php';
            }

            $this->clearPendingState();

            $token = bin2hex(random_bytes(32));
            $stagingRoot = $this->getStagingBasePath() . DIRECTORY_SEPARATOR . date('Ymd_His') . '_' . substr($token, 0, 8);
            $extractPath = $stagingRoot . DIRECTORY_SEPARATOR . 'extract';
            $packagePath = $stagingRoot . DIRECTORY_SEPARATOR . 'system_updater.zip';
            $payloadPath = $stagingRoot . DIRECTORY_SEPARATOR . 'payload';

            if (!@mkdir($extractPath, 0755, true) && !is_dir($extractPath)) {
                $this->errors[] = 'No se pudo crear el directorio temporal de preparación.';
                return false;
            }

            $downloadUrl = $this->downloadUpdatePackage($updateInfo['update_zip_urls'], $packagePath);
            if ($downloadUrl === false || !file_exists($packagePath)) {
                $this->removeTree($stagingRoot);
                $this->errors[] = 'No se pudo descargar el paquete de actualización del actualizador.';
                return false;
            }

            if (!class_exists('fs_file_manager') || !fs_file_manager::extract_zip_safe($packagePath, $extractPath)) {
                $this->removeTree($stagingRoot);
                $this->errors[] = 'No se pudo extraer el paquete descargado del actualizador.';
                return false;
            }

            $sourcePath = $this->findExtractedPluginPath($extractPath);
            if ($sourcePath === false) {
                $this->removeTree($stagingRoot);
                $this->errors[] = 'El paquete descargado no contiene una versión válida de system_updater.';
                return false;
            }

            if (!@rename($sourcePath, $payloadPath)) {
                if (!fs_file_manager::recurse_copy($sourcePath, $payloadPath)) {
                    $this->removeTree($stagingRoot);
                    $this->errors[] = 'No se pudo preparar la carpeta de despliegue del actualizador.';
                    return false;
                }
            }

            $manifest = [
                'plugin' => self::PLUGIN_NAME,
                'token' => $token,
                'current_version' => $this->normalizeVersion($this->get_local_version()),
                'new_version' => $updateInfo['new_version'],
                'prepared_at' => date('c'),
                'staged_path' => $payloadPath,
                'staging_root' => $stagingRoot,
                'zip_url' => $downloadUrl,
            ];

            if (@file_put_contents($this->getManifestPath(), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
                $this->removeTree($stagingRoot);
                $this->errors[] = 'No se pudo guardar el estado pendiente de actualización.';
                return false;
            }

            $manifest['finalize_url'] = 'updater.php?action=finalize_system_updater_update&token=' . rawurlencode($token);
            $this->messages[] = 'Actualización del plugin preparada. Se procederá al intercambio externo.';
            return $manifest;

        } catch (\Exception $e) {
            $this->errors[] = 'Error durante la actualización: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Obtiene los errores
     * 
     * @return array
     */
    public function get_errors()
    {
        return $this->errors;
    }

    /**
     * Obtiene los mensajes
     * 
     * @return array
     */
    public function get_messages()
    {
        return $this->messages;
    }

    /**
     * Obtiene la ruta del directorio del actualizador
     * 
     * @return string
     */
    public function get_updater_path()
    {
        return $this->updaterPath;
    }

    /**
     * Normaliza una versión para compararla.
     *
     * @param string $version
     *
     * @return string
     */
    private function normalizeVersion($version)
    {
        $version = trim((string) $version);
        if ($version === '') {
            return '';
        }

        if (preg_match('/v?(\d+(?:\.\d+)+)/i', $version, $matches)) {
            return $matches[1];
        }

        return '';
    }
}
