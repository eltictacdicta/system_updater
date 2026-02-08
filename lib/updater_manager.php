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
        $this->updaterPath = dirname(__FILE__);
        $this->loadLocalConfig();
    }

    /**
     * Carga la configuración local del actualizador
     */
    private function loadLocalConfig()
    {
        $iniPath = $this->updaterPath . DIRECTORY_SEPARATOR . self::INI_FILE;

        if (file_exists($iniPath)) {
            $this->localConfig = parse_ini_file($iniPath, true);
        } else {
            $this->localConfig = [
                'updater' => [
                    'version' => 0,
                    'name' => 'FSFramework Updater',
                    'description' => 'Sistema de actualización y backups',
                ]
            ];
        }
    }

    /**
     * Obtiene la versión local del actualizador
     * 
     * @return int
     */
    public function get_local_version()
    {
        return isset($this->localConfig['updater']['version'])
            ? (int) $this->localConfig['updater']['version']
            : 0;
    }

    /**
     * Obtiene la información del actualizador
     * 
     * @return array
     */
    public function get_info()
    {
        return [
            'version' => $this->get_local_version(),
            'name' => $this->localConfig['updater']['name'] ?? 'FSFramework Updater',
            'description' => $this->localConfig['updater']['description'] ?? '',
            'last_update' => $this->localConfig['updater']['last_update'] ?? null,
            'author' => $this->localConfig['updater']['author'] ?? 'FSFramework Team',
        ];
    }

    /**
     * Comprueba si hay actualizaciones disponibles para el actualizador
     * 
     * @return bool|array False si no hay actualizaciones, array con info si la hay
     */
    public function check_for_updates()
    {
        if (!isset($this->localConfig['updater']['remote_version_url'])) {
            return false;
        }

        try {
            $remoteIni = @file_get_contents($this->localConfig['updater']['remote_version_url']);

            if ($remoteIni === false) {
                return false;
            }

            $this->remoteConfig = @parse_ini_string($remoteIni, true);

            if (!$this->remoteConfig || !isset($this->remoteConfig['updater']['version'])) {
                return false;
            }

            $remoteVersion = (int) $this->remoteConfig['updater']['version'];
            $localVersion = $this->get_local_version();

            if ($remoteVersion > $localVersion) {
                return [
                    'available' => true,
                    'current_version' => $localVersion,
                    'new_version' => $remoteVersion,
                    'update_url' => $this->localConfig['updater']['update_url'] ?? null,
                    'description' => $this->remoteConfig['updater']['description'] ?? '',
                ];
            }

            return false;
        } catch (\Exception $e) {
            $this->errors[] = 'Error al comprobar actualizaciones: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Actualiza el actualizador
     * 
     * @return bool
     */
    public function update_updater()
    {
        $updateInfo = $this->check_for_updates();

        if (!$updateInfo || !$updateInfo['available']) {
            $this->errors[] = 'No hay actualizaciones disponibles para el actualizador.';
            return false;
        }

        if (empty($updateInfo['update_url'])) {
            $this->errors[] = 'URL de actualización no disponible.';
            return false;
        }

        try {
            // Archivos a actualizar
            $files = [
                'index.php',
                'updater_manager.php',
                'fs_backup_manager.php',
                'fsframework.ini',
            ];

            $baseUrl = rtrim($updateInfo['update_url'], '/') . '/';
            $backupDir = $this->updaterPath . DIRECTORY_SEPARATOR . 'backup_' . date('Y-m-d_H-i-s');

            // Crear directorio de backup
            if (!@mkdir($backupDir, 0755, true)) {
                $this->errors[] = 'No se pudo crear el directorio de backup.';
                return false;
            }

            // Hacer backup y actualizar cada archivo
            foreach ($files as $file) {
                $localPath = $this->updaterPath . DIRECTORY_SEPARATOR . $file;

                // Backup del archivo actual
                if (file_exists($localPath)) {
                    copy($localPath, $backupDir . DIRECTORY_SEPARATOR . $file);
                }

                // Descargar nueva versión
                $remoteContent = @file_get_contents($baseUrl . $file);

                if ($remoteContent !== false) {
                    file_put_contents($localPath, $remoteContent);
                    $this->messages[] = 'Archivo actualizado: ' . $file;
                }
            }

            $this->messages[] = 'Actualizador actualizado correctamente.';
            return true;

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
}
