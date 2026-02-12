<?php
/**
 * Plugin Downloader - Plugin system_updater
 * 
 * Maneja la descarga e instalación de plugins públicos y privados.
 * Extraído de fs_plugin_manager.php para independizar del core.
 * 
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 * @version 1.0.0
 */

class plugin_downloader
{
    /**
     * @var array Lista de plugins públicos
     */
    private $download_list;

    /**
     * @var array Lista de plugins privados
     */
    private $private_download_list;

    /**
     * @var array Configuración de plugins privados
     */
    private $private_config;

    /**
     * @var object Cache
     */
    private $cache;

    /**
     * @var array Errores
     */
    private $errors = [];

    /**
     * @var array Mensajes
     */
    private $messages = [];

    /**
     * @var string Ruta raíz del framework
     */
    private $fsRoot;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->fsRoot = defined('FS_FOLDER') ? FS_FOLDER : dirname(dirname(__DIR__));

        // Cargar cache si está disponible
        if (file_exists($this->fsRoot . '/base/fs_cache.php')) {
            require_once $this->fsRoot . '/base/fs_cache.php';
            $this->cache = new fs_cache();
        }
    }

    /**
     * Obtiene la lista de plugins públicos para descargar
     * @return array
     */
    public function downloads()
    {
        if (isset($this->download_list)) {
            return $this->download_list;
        }

        // Buscar en cache
        if ($this->cache) {
            $this->download_list = $this->cache->get('download_list');
            if ($this->download_list) {
                return $this->download_list;
            }
        }

        // Descargar lista de plugins de la comunidad
        $json = @file_get_contents('https://raw.githubusercontent.com/eltictacdicta/fs-cusmtom-plugins/main/custom_plugins.json');
        if ($json && $json !== 'ERROR') {
            $this->download_list = json_decode($json, true);
            if (is_array($this->download_list)) {
                foreach ($this->download_list as $key => $value) {
                    $this->download_list[$key]['instalado'] = file_exists($this->fsRoot . '/plugins/' . $value['nombre']);

                    // Mapear autor desde creador o nick
                    if (!isset($this->download_list[$key]['autor'])) {
                        $this->download_list[$key]['autor'] = isset($value['creador']) ? $value['creador'] : (isset($value['nick']) ? $value['nick'] : 'Desconocido');
                    }

                    // Intentar obtener versión y descripción del repo si no están en el JSON o son N/A
                    if (!isset($this->download_list[$key]['version']) || $this->download_list[$key]['version'] == 'N/A' || !isset($this->download_list[$key]['descripcion'])) {
                        $remote_data = $this->get_remote_plugin_ini($value);
                        if ($remote_data) {
                            if ((!isset($this->download_list[$key]['version']) || $this->download_list[$key]['version'] == 'N/A') && isset($remote_data['version'])) {
                                $this->download_list[$key]['version'] = $remote_data['version'];
                            }
                            if ((!isset($this->download_list[$key]['descripcion']) || empty($this->download_list[$key]['descripcion'])) && isset($remote_data['description'])) {
                                $this->download_list[$key]['descripcion'] = $remote_data['description'];
                            }
                            if (isset($remote_data['require'])) {
                                $this->download_list[$key]['require'] = $remote_data['require'];
                            }
                        }
                    }
                }

                if ($this->cache) {
                    $this->cache->set('download_list', $this->download_list);
                }
                return $this->download_list;
            }
        }

        $this->errors[] = 'Error al descargar la lista de plugins.';
        $this->download_list = [];
        return $this->download_list;
    }

    /**
     * Descarga e instala un plugin público
     * @param int $plugin_id ID del plugin
     * @return bool
     */
    public function download($plugin_id)
    {
        foreach ($this->downloads() as $item) {
            if ($item['id'] != (int) $plugin_id) {
                continue;
            }

            $this->messages[] = 'Descargando el plugin ' . $item['nombre'];

            // Descargar ZIP
            $zipPath = $this->fsRoot . '/download.zip';
            $content = @file_get_contents($item['zip_link']);
            if (!$content || !@file_put_contents($zipPath, $content)) {
                $this->errors[] = 'Error al descargar. Tendrás que descargarlo manualmente desde '
                    . '<a href="' . $item['zip_link'] . '" target="_blank">aquí</a>.';
                return false;
            }

            // Obtener lista antes de extraer
            $pluginsList = scandir($this->fsRoot . '/plugins');

            // Extraer ZIP
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                $this->errors[] = 'Error al abrir el archivo ZIP.';
                @unlink($zipPath);
                return false;
            }

            $zip->extractTo($this->fsRoot . '/plugins/');
            $zip->close();
            @unlink($zipPath);

            // Renombrar si es necesario
            foreach (scandir($this->fsRoot . '/plugins') as $f) {
                if ($f === '.' || $f === '..')
                    continue;
                if (is_dir($this->fsRoot . '/plugins/' . $f) && !in_array($f, $pluginsList)) {
                    // Eliminar existente si hay que sobrescribir
                    $targetPath = $this->fsRoot . '/plugins/' . $item['nombre'];
                    if (file_exists($targetPath)) {
                        $this->delTree($targetPath);
                    }
                    rename($this->fsRoot . '/plugins/' . $f, $targetPath);
                    break;
                }
            }

            $this->messages[] = 'Plugin añadido correctamente.';
            return true;
        }

        $this->errors[] = 'Descarga no encontrada.';
        return false;
    }

    /**
     * Obtiene la configuración de plugins privados
     * @return array
     */
    public function get_private_config()
    {
        if (isset($this->private_config)) {
            return $this->private_config;
        }

        $this->private_config = [
            'github_token' => '',
            'private_plugins_url' => '',
            'enabled' => false
        ];

        // Intentar cargar desde fs_var
        if (file_exists($this->fsRoot . '/model/fs_var.php')) {
            require_once $this->fsRoot . '/model/fs_var.php';
            $fs_var = new fs_var();
            $saved_config = $fs_var->simple_get('private_plugins_config');
            if ($saved_config) {
                $decoded = json_decode($saved_config, true);
                if (is_array($decoded)) {
                    $this->private_config = array_merge($this->private_config, $decoded);
                }
            }
        }

        return $this->private_config;
    }

    /**
     * Guarda la configuración de plugins privados
     * @param string $github_token
     * @param string $private_plugins_url
     * @return bool
     */
    public function save_private_config($github_token, $private_plugins_url)
    {
        if (!file_exists($this->fsRoot . '/model/fs_var.php')) {
            $this->errors[] = 'No se puede guardar la configuración sin fs_var.';
            return false;
        }

        require_once $this->fsRoot . '/model/fs_var.php';
        $fs_var = new fs_var();

        $this->private_config = [
            'github_token' => $github_token,
            'private_plugins_url' => $private_plugins_url,
            'enabled' => !empty($github_token) && !empty($private_plugins_url)
        ];

        $result = $fs_var->simple_save('private_plugins_config', json_encode($this->private_config));

        // Limpiar cache
        if ($this->cache) {
            $this->cache->delete('private_download_list');
        }

        return $result;
    }

    /**
     * Elimina la configuración de plugins privados
     * @return bool
     */
    public function delete_private_config()
    {
        if (!file_exists($this->fsRoot . '/model/fs_var.php')) {
            return false;
        }

        require_once $this->fsRoot . '/model/fs_var.php';
        $fs_var = new fs_var();
        $fs_var->simple_delete('private_plugins_config');

        if ($this->cache) {
            $this->cache->delete('private_download_list');
        }

        $this->private_config = null;
        return true;
    }

    /**
     * Verifica si plugins privados están habilitados
     * @return bool
     */
    public function is_private_plugins_enabled()
    {
        $config = $this->get_private_config();
        return !empty($config['enabled']) && !empty($config['github_token']) && !empty($config['private_plugins_url']);
    }

    /**
     * Obtiene la lista de plugins privados
     * @param bool $force_reload
     * @return array
     */
    public function private_downloads($force_reload = false)
    {
        if (!$force_reload && isset($this->private_download_list)) {
            return $this->private_download_list;
        }

        if (!$this->is_private_plugins_enabled()) {
            $this->private_download_list = [];
            return $this->private_download_list;
        }

        // Buscar en cache
        if (!$force_reload && $this->cache) {
            $cached = $this->cache->get('private_download_list');
            if ($cached !== false && is_array($cached) && !empty($cached)) {
                $this->private_download_list = $cached;
                return $this->private_download_list;
            }
        }

        $config = $this->get_private_config();

        // Descargar lista usando autenticación
        $context = stream_context_create([
            'http' => [
                'header' => "Authorization: token " . $config['github_token'] . "\r\n" .
                    "User-Agent: FSFramework-Updater\r\n"
            ]
        ]);

        $json = @file_get_contents($config['private_plugins_url'], false, $context);

        if ($json && $json !== 'ERROR') {
            $this->private_download_list = json_decode($json, true);

            if (!is_array($this->private_download_list)) {
                $this->errors[] = 'Error al parsear el JSON de plugins privados.';
                $this->private_download_list = [];
                return $this->private_download_list;
            }

            // Marcar cada plugin
            foreach ($this->private_download_list as $key => $value) {
                $this->private_download_list[$key]['instalado'] = file_exists($this->fsRoot . '/plugins/' . $value['nombre']);
                $this->private_download_list[$key]['privado'] = true;

                if (!isset($this->private_download_list[$key]['id'])) {
                    $this->private_download_list[$key]['id'] = 'priv_' . $key;
                } else {
                    $this->private_download_list[$key]['id'] = 'priv_' . $this->private_download_list[$key]['id'];
                }

                // Obtener datos del fsframework.ini del repositorio remoto
                $remote_ini_data = $this->get_remote_plugin_ini($value, $config['github_token']);
                if ($remote_ini_data) {
                    if (isset($remote_ini_data['version'])) {
                        $this->private_download_list[$key]['version'] = $remote_ini_data['version'];
                    }
                    if (isset($remote_ini_data['description'])) {
                        $this->private_download_list[$key]['descripcion'] = $remote_ini_data['description'];
                    }
                    if (isset($remote_ini_data['require'])) {
                        $this->private_download_list[$key]['require'] = $remote_ini_data['require'];
                    }
                }
            }

            if ($this->cache) {
                $this->cache->set('private_download_list', $this->private_download_list, 3600);
            }
            return $this->private_download_list;
        }

        $this->errors[] = 'Error al descargar la lista de plugins privados.';
        $this->private_download_list = [];
        return $this->private_download_list;
    }

    /**
     * Descarga e instala un plugin privado
     * @param string $plugin_id ID del plugin (prefijado con 'priv_')
     * @return bool
     */
    public function download_private($plugin_id)
    {
        if (!$this->is_private_plugins_enabled()) {
            $this->errors[] = 'Los plugins privados no están configurados.';
            return false;
        }

        $config = $this->get_private_config();

        foreach ($this->private_downloads() as $item) {
            if ($item['id'] !== $plugin_id) {
                continue;
            }

            $this->messages[] = 'Descargando plugin privado ' . $item['nombre'];

            // Preparar contexto con autenticación
            $context = stream_context_create([
                'http' => [
                    'header' => "Authorization: token " . $config['github_token'] . "\r\n" .
                        "User-Agent: FSFramework-Updater\r\n" .
                        "Accept: application/vnd.github.v3.raw\r\n"
                ]
            ]);

            $content = @file_get_contents($item['zip_link'], false, $context);
            if (!$content) {
                $this->errors[] = 'Error al descargar el plugin privado.';
                return false;
            }

            $zipPath = $this->fsRoot . '/download.zip';
            if (!@file_put_contents($zipPath, $content)) {
                $this->errors[] = 'Error al guardar el archivo ZIP.';
                return false;
            }

            // Guardar lista antes
            $pluginsList = scandir($this->fsRoot . '/plugins');

            // Extraer
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                $this->errors[] = 'Error al abrir el archivo ZIP.';
                @unlink($zipPath);
                return false;
            }

            $zip->extractTo($this->fsRoot . '/plugins/');
            $zip->close();
            @unlink($zipPath);

            // Renombrar si es necesario
            foreach (scandir($this->fsRoot . '/plugins') as $f) {
                if ($f === '.' || $f === '..')
                    continue;
                if (is_dir($this->fsRoot . '/plugins/' . $f) && !in_array($f, $pluginsList)) {
                    $targetPath = $this->fsRoot . '/plugins/' . $item['nombre'];
                    if (file_exists($targetPath)) {
                        $this->delTree($targetPath);
                    }
                    rename($this->fsRoot . '/plugins/' . $f, $targetPath);
                    break;
                }
            }

            $this->messages[] = 'Plugin privado añadido correctamente.';
            return true;
        }

        $this->errors[] = 'Plugin privado no encontrado.';
        return false;
    }

    /**
     * Prueba la conexión con plugins privados
     * @return array
     */
    public function test_private_connection()
    {
        if (!$this->is_private_plugins_enabled()) {
            return [
                'success' => false,
                'message' => 'La configuración de plugins privados no está completa.'
            ];
        }

        $config = $this->get_private_config();

        $context = stream_context_create([
            'http' => [
                'header' => "Authorization: token " . $config['github_token'] . "\r\n" .
                    "User-Agent: FSFramework-Updater\r\n"
            ]
        ]);

        $json = @file_get_contents($config['private_plugins_url'], false, $context);

        if ($json && $json !== 'ERROR') {
            $data = json_decode($json, true);
            if (is_array($data)) {
                return [
                    'success' => true,
                    'message' => 'Conexión exitosa. Se encontraron ' . count($data) . ' plugins privados.'
                ];
            }
        }

        return [
            'success' => false,
            'message' => 'No se pudo conectar. Verifica el token y la URL.'
        ];
    }

    /**
     * Obtiene errores
     * @return array
     */
    public function get_errors()
    {
        return $this->errors;
    }

    /**
     * Obtiene mensajes
     * @return array
     */
    public function get_messages()
    {
        return $this->messages;
    }

    /**
     * Limpia la cache de listas
     */
    public function refresh()
    {
        if ($this->cache) {
            $this->cache->delete('download_list');
            $this->cache->delete('private_download_list');
        }
        $this->download_list = null;
        $this->private_download_list = null;
    }

    /**
     * Obtiene los datos del fsframework.ini de un repositorio remoto.
     * @param array $plugin_data Datos del plugin del JSON
     * @param string $token Token de GitHub (opcional)
     * @return array|false Array con los datos del ini o false si falla
     */
    private function get_remote_plugin_ini($plugin_data, $token = null)
    {
        if (!isset($plugin_data['link']) || empty($plugin_data['link'])) {
            return false;
        }

        // Extraer usuario y repo del link
        $parsed = parse_url(trim($plugin_data['link']));
        if (!isset($parsed['path'])) {
            return false;
        }

        $path_parts = explode('/', trim($parsed['path'], '/'));
        if (count($path_parts) < 2) {
            return false;
        }

        $user = $path_parts[0];
        $repo = $path_parts[1];
        $branch = isset($plugin_data['branch']) ? $plugin_data['branch'] : 'master';
        $ini_files = ['fsframework.ini', 'facturascripts.ini'];

        foreach ($ini_files as $ini_file) {
            $content = false;
            
            if ($token) {
                // Usar API de GitHub para repos privados
                $api_url = "https://api.github.com/repos/{$user}/{$repo}/contents/{$ini_file}?ref={$branch}";
                $content = @fs_file_get_contents_github_api($api_url, $token, 5);
            } else {
                // Usar raw content para repos públicos (evita rate limits de la API)
                $raw_url = "https://raw.githubusercontent.com/{$user}/{$repo}/{$branch}/{$ini_file}";
                $content = @fs_file_get_contents($raw_url, 5);
            }

            if ($content && $content != 'ERROR') {
                $ini_data = @parse_ini_string($content, true);
                if ($ini_data && is_array($ini_data)) {
                    if (isset($ini_data['plugin']) && is_array($ini_data['plugin'])) {
                        return $ini_data['plugin'];
                    }
                    if (isset($ini_data['version']) || isset($ini_data['description']) || isset($ini_data['name'])) {
                        return $ini_data;
                    }
                    foreach ($ini_data as $section => $values) {
                        if (is_array($values) && (isset($values['version']) || isset($values['description']))) {
                            return $values;
                        }
                    }
                    return $ini_data;
                }
            }
        }

        return false;
    }

    /**
     * Elimina un directorio recursivamente
     * @param string $dir
     * @return bool
     */
    private function delTree($dir)
    {
        if (!is_dir($dir))
            return false;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->delTree($path) : unlink($path);
        }
        return rmdir($dir);
    }
}
