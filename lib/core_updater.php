<?php
/**
 * Core Updater - Gestiona la actualización del núcleo con soporte de progreso.
 *
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 * @version 1.0.0
 */

class core_updater
{
    private const CORE_REPOSITORY_URL = 'https://github.com/eltictacdicta/fs-framework.git';
    private const CORE_REPOSITORY_BRANCHES = ['master', 'main'];

    /**
     * @var string
     */
    private $rootPath;

    /**
     * @var array
     */
    private $errors = [];

    /**
     * @var array
     */
    private $messages = [];

    /**
     * @param string|null $rootPath
     */
    public function __construct($rootPath = null)
    {
        if ($rootPath !== null) {
            $this->rootPath = $rootPath;
        } elseif (defined('FS_FOLDER')) {
            $this->rootPath = FS_FOLDER;
        } else {
            $this->rootPath = dirname(dirname(dirname(__DIR__)));
        }
    }

    /**
     * @return array
     */
    public function get_errors()
    {
        return $this->errors;
    }

    /**
     * @return array
     */
    public function get_messages()
    {
        return $this->messages;
    }

    /**
     * Actualiza el núcleo descargando el código fuente y copiándolo de forma selectiva.
     *
     * @param bool $createBackup
     * @param callable|null $progressCallback function($step, $message, $percent)
     *
     * @return array
     */
    public function update_core($createBackup = true, $progressCallback = null)
    {
        $this->errors = [];
        $this->messages = [];

        $reportProgress = function ($step, $message, $percent) use ($progressCallback) {
            if ($progressCallback && is_callable($progressCallback)) {
                call_user_func($progressCallback, $step, $message, $percent);
            }
        };

        $reportProgress('init', 'Preparando actualización del núcleo...', 2);

        if ($createBackup) {
            require_once __DIR__ . '/backup_manager.php';

            $reportProgress('backup_start', 'Creando copia de seguridad previa...', 5);
            $backupManager = new backup_manager($this->rootPath);
            $backupResult = $backupManager->create_pre_update_backup('core');

            if (!isset($backupResult['complete']['success']) || !$backupResult['complete']['success']) {
                $this->errors[] = 'Error al crear backup previo: ' . implode(', ', $backupManager->get_errors());
                $reportProgress('backup_error', 'No se pudo crear la copia previa.', 5);
                return [
                    'success' => false,
                    'errors' => $this->errors,
                ];
            }

            $reportProgress('backup_complete', 'Copia previa creada correctamente.', 35);
        } else {
            $reportProgress('backup_skipped', 'Copia previa omitida por configuración.', 12);
        }

        $extractPath = $this->rootPath . '/tmp/core_update';

        if (is_dir($extractPath)) {
            $this->deleteDirectoryRecursive($extractPath);
        }

        $usedGitClone = false;
        $gitMetadataInstalled = false;

        $reportProgress('download', 'Descargando código fuente del núcleo...', 45);
        $sourceDir = $this->prepareCoreSource($extractPath, $usedGitClone);

        if (!$sourceDir || !is_dir($sourceDir)) {
            $this->errors[] = 'Error al descargar la actualización del núcleo.';
            $reportProgress('download_error', 'No se pudo descargar la actualización.', 45);
            return [
                'success' => false,
                'errors' => $this->errors,
            ];
        }

        $reportProgress('copy_prepare', 'Limpiando archivos antiguos del núcleo...', 60);
        
        // Limpiar directorio root antes de copiar nuevos archivos
        $this->cleanupRootDirectories($progressCallback);
        
        $reportProgress('copy_files', 'Copiando archivos del núcleo...', 70);

        $excludeFiles = ['config.php', 'plugins', 'backups', 'tmp', 'apk', 'imgs', '.ddev'];
        if (file_exists($this->rootPath . '/.git')) {
            $excludeFiles[] = '.git';
        } else {
            $gitMetadataInstalled = file_exists($sourceDir . '/.git');
        }

        $this->copyDirectorySelective($sourceDir, $this->rootPath, $excludeFiles);

        $reportProgress('plugins_sync', 'Sincronizando plugins integrados del núcleo...', 92);
        $pluginsSync = $this->syncBundledPlugins($sourceDir . '/plugins', $this->rootPath . '/plugins', $progressCallback);
        if (!empty($pluginsSync['errors'])) {
            foreach ($pluginsSync['errors'] as $error) {
                $this->errors[] = $error;
            }
        }

        $reportProgress('copy_complete', 'Archivos del núcleo y plugins integrados actualizados.', 95);

        if (is_dir($extractPath)) {
            $this->deleteDirectoryRecursive($extractPath);
        }

        $this->clearTemplateCache();

        $installedVersion = $this->getInstalledCoreVersion();
        $message = 'Núcleo actualizado correctamente.';

        if (!empty($pluginsSync['updated']) || !empty($pluginsSync['added'])) {
            $message .= ' Plugins del núcleo sincronizados';

            if (!empty($pluginsSync['updated'])) {
                $message .= ': actualizados ' . implode(', ', $pluginsSync['updated']);
            }

            if (!empty($pluginsSync['added'])) {
                $message .= (!empty($pluginsSync['updated']) ? ';' : ':') . ' añadidos ' . implode(', ', $pluginsSync['added']);
            }

            $message .= '.';
        }

        if ($gitMetadataInstalled) {
            $message .= ' Se ha descargado también el repositorio Git del núcleo.';
        } elseif ($usedGitClone) {
            $message .= ' Se ha utilizado Git para preparar la actualización.';
        }

        $this->messages[] = $message;
        $reportProgress('complete', $message, 100);

        return [
            'success' => true,
            'message' => $message,
            'installed_version' => $installedVersion,
            'used_git' => $usedGitClone,
            'git_metadata_installed' => $gitMetadataInstalled,
            'bundled_plugins_updated' => $pluginsSync['updated'],
            'bundled_plugins_added' => $pluginsSync['added'],
            'bundled_plugins_errors' => $pluginsSync['errors'],
            'backup_created' => (bool) $createBackup,
        ];
    }

    /**
     * @return string
     */
    public function getInstalledCoreVersion()
    {
        $versionFile = $this->rootPath . '/VERSION';
        if (!file_exists($versionFile)) {
            return '';
        }

        return trim((string) @file_get_contents($versionFile));
    }

    /**
     * @param string $extractPath
     * @param bool $usedGitClone
     *
     * @return string|false
     */
    private function prepareCoreSource($extractPath, &$usedGitClone)
    {
        $usedGitClone = false;

        if (!is_dir($extractPath) && !@mkdir($extractPath, 0755, true)) {
            return false;
        }

        if ($this->isGitAvailable()) {
            $clonePath = $extractPath . '/fs-framework';

            foreach (self::CORE_REPOSITORY_BRANCHES as $branch) {
                if ($this->cloneGitRepository(self::CORE_REPOSITORY_URL, $branch, $clonePath)) {
                    $usedGitClone = true;
                    return $clonePath;
                }
            }
        }

        return $this->downloadCoreZipSource($extractPath);
    }

    /**
     * Limpia los directorios root antes de actualizar, conservando solo lo necesario.
     *
     * @param callable|null $progressCallback
     *
     * @return bool
     */
    private function cleanupRootDirectories($progressCallback = null)
    {
        $reportProgress = function ($message, $percent) use ($progressCallback) {
            if ($progressCallback && is_callable($progressCallback)) {
                call_user_func($progressCallback, 'cleanup', $message, $percent);
            }
        };

        $reportProgress('Iniciando limpieza de archivos antiguos...', 0);

        // Directorios y archivos a CONSERVAR
        $keepItems = [
            'apk',
            'backups',
            'tmp',
            'imgs',
            '.ddev',
            'plugins',
            '.git',
            'config.php',

        ];

        // Directorios y archivos a ELIMINAR del root
        $rootItems = [
            'base',
            'controller',
            'model',
            'view',
            'src',
            'themes',
            'translations',
            'docs',
            'extras',
            'config',
            'vendor',
            'node_modules',
            'index.php',
            'api.php',
            'cron.php',
            'install.php',
            'updater.php',
            'debug_loader.php',
            'composer.json',
            'composer.lock',
            'package.json',
            'phpunit.xml',
            'VERSION',
            'build.sh',
            'htaccess-sample',
            'robots.txt',
            'COPYING',
            'README.md',
            'CONTRIBUTING.md',
            'SECURITY.md',
            'AGENTS.md',
            'CAMBIOS_PORTAL.md',
            'PROPUESTA_TERCEROS_CORE.md',
            'SISTEMA_BACKUP_PLUGINS.md',
            'apigen.neon-sample',
            'tailwind.config.js',
            '.gitignore',
            '.gitmodules',
        ];

        $totalItems = count($rootItems);
        $processed = 0;

        foreach ($rootItems as $item) {
            $itemPath = $this->rootPath . '/' . $item;

            if (file_exists($itemPath) || is_dir($itemPath)) {
                if (is_dir($itemPath)) {
                    $this->deleteDirectoryRecursive($itemPath);
                } else {
                    @unlink($itemPath);
                }
            }

            $processed++;
            $percent = (int) (($processed / $totalItems) * 100);
            $reportProgress("Limpiando $item...", $percent);
        }

        $reportProgress('Limpieza completada.', 100);
        return true;
    }

    /**
     * @param string $extractPath
     *
     * @return string|false
     */
    private function downloadCoreZipSource($extractPath)
    {
        $zipUrls = [
            'https://github.com/eltictacdicta/fs-framework/archive/refs/heads/master.zip',
            'https://github.com/eltictacdicta/fs-framework/archive/refs/heads/main.zip',
            'https://github.com/eltictacdicta/fs-framework/archive/master.zip',
            'https://github.com/eltictacdicta/fs-framework/archive/main.zip',
        ];

        $downloadPath = $this->rootPath . '/download_core.zip';

        foreach ($zipUrls as $zipUrl) {
            if ($this->downloadFile($zipUrl, $downloadPath) && $this->extractZipSafe($downloadPath, $extractPath)) {
                @unlink($downloadPath);
                return $this->findFirstDirectory($extractPath);
            }

            @unlink($downloadPath);
        }

        return false;
    }

    /**
     * @return bool
     */
    private function isGitAvailable()
    {
        if (!$this->shellFunctionsAvailable()) {
            return false;
        }

        $output = [];
        $returnVar = 1;
        @exec('git --version 2>&1', $output, $returnVar);

        return $returnVar === 0;
    }

    /**
     * @param string $repositoryUrl
     * @param string $branch
     * @param string $destination
     *
     * @return bool
     */
    private function cloneGitRepository($repositoryUrl, $branch, $destination)
    {
        if (!$this->shellFunctionsAvailable()) {
            return false;
        }

        if (is_dir($destination)) {
            $this->deleteDirectoryRecursive($destination);
        }

        $parentDir = dirname($destination);
        if (!is_dir($parentDir) && !@mkdir($parentDir, 0755, true)) {
            return false;
        }

        $command = 'git clone --depth 1 --branch ' . escapeshellarg($branch)
            . ' ' . escapeshellarg($repositoryUrl)
            . ' ' . escapeshellarg($destination)
            . ' 2>&1';

        $output = [];
        $returnVar = 1;
        @exec($command, $output, $returnVar);

        return $returnVar === 0 && is_dir($destination);
    }

    /**
     * @param string $path
     *
     * @return string|false
     */
    private function findFirstDirectory($path)
    {
        $entries = @scandir($path);
        if (!is_array($entries)) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $candidate = $path . '/' . $entry;
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return false;
    }

    /**
     * @return void
     */
    private function clearTemplateCache()
    {
        $this->clearDirectoryContents($this->rootPath . '/tmp/twig_cache', true);

        if (defined('FS_TMP_NAME') && FS_TMP_NAME !== '') {
            $this->deleteMatchingFiles($this->rootPath . '/tmp/' . FS_TMP_NAME, '.php');
        }
    }

    /**
     * @param string $dir
     *
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
     * @return bool
     */
    private function shellFunctionsAvailable()
    {
        if (!function_exists('exec')) {
            return false;
        }

        $disabled = (string) ini_get('disable_functions');
        if ($disabled === '') {
            return true;
        }

        $disabledFunctions = array_map('trim', explode(',', $disabled));
        return !in_array('exec', $disabledFunctions, true);
    }

    /**
     * @param string $url
     * @param string $destination
     *
     * @return bool
     */
    private function downloadFile($url, $destination)
    {
        if (function_exists('fs_file_download') && @fs_file_download($url, $destination)) {
            return file_exists($destination) && filesize($destination) > 0;
        }

        if (function_exists('curl_init')) {
            $handle = @fopen($destination, 'wb');
            if ($handle) {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_FILE, $handle);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 120);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
                curl_setopt($ch, CURLOPT_USERAGENT, 'FSFramework-System-Updater');
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                $result = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                fclose($handle);

                if ($result && $httpCode >= 200 && $httpCode < 400 && file_exists($destination) && filesize($destination) > 0) {
                    return true;
                }

                @unlink($destination);
            }
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 120,
                'follow_location' => 1,
                'user_agent' => 'FSFramework-System-Updater',
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        if ($content === false || $content === '') {
            return false;
        }

        return @file_put_contents($destination, $content) !== false;
    }

    /**
     * @param string $zipPath
     * @param string $destination
     *
     * @return bool
     */
    private function extractZipSafe($zipPath, $destination)
    {
        if (!class_exists('ZipArchive')) {
            $this->errors[] = 'ZipArchive no esta disponible en el servidor.';
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return false;
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $filename = $zip->getNameIndex($index);
            if ($filename === false) {
                $zip->close();
                return false;
            }

            if (strpos($filename, '../') !== false || strpos($filename, '..\\') !== false || strpos($filename, '/') === 0 || preg_match('/^[A-Za-z]:\\\\/', $filename)) {
                $zip->close();
                $this->errors[] = 'Se detecto una ruta no valida en el paquete de actualizacion.';
                return false;
            }
        }

        $result = $zip->extractTo($destination);
        $zip->close();
        return $result;
    }

    /**
     * @param string $dir
     * @param bool $recreate
     *
     * @return void
     */
    private function clearDirectoryContents($dir, $recreate = false)
    {
        if (!file_exists($dir)) {
            if ($recreate) {
                @mkdir($dir, 0777, true);
            }
            return;
        }

        if (is_dir($dir)) {
            $this->deleteDirectoryRecursive($dir);
            if ($recreate) {
                @mkdir($dir, 0777, true);
            }
            return;
        }

        @unlink($dir);
    }

    /**
     * @param string $dir
     * @param string $extension
     *
     * @return void
     */
    private function deleteMatchingFiles($dir, $extension)
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return;
        }

        $suffixLength = strlen($extension);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }

            if ($suffixLength === 0 || substr($entry, -$suffixLength) === $extension) {
                @unlink($path);
            }
        }
    }

    /**
     * @param string $source
     * @param string $dest
     * @param array $exclude
     *
     * @return void
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
     * Actualiza solo los plugins incluidos en el repositorio del núcleo.
     * Conserva cualquier otro plugin local que no forme parte del paquete descargado.
     *
     * @param string $sourcePluginsDir
     * @param string $targetPluginsDir
     *
     * @return array
     */
    private function syncBundledPlugins($sourcePluginsDir, $targetPluginsDir, $progressCallback = null)
    {
        $result = [
            'updated' => [],
            'added' => [],
            'errors' => [],
        ];

        $reportProgress = function ($message, $percent) use ($progressCallback) {
            if ($progressCallback && is_callable($progressCallback)) {
                call_user_func($progressCallback, 'plugins_sync', $message, $percent);
            }
        };

        if (!is_dir($sourcePluginsDir)) {
            return $result;
        }

        if (!is_dir($targetPluginsDir) && !@mkdir($targetPluginsDir, 0755, true)) {
            $result['errors'][] = 'No se pudo crear el directorio de plugins local.';
            return $result;
        }

        // Primero, obtener lista de plugins del núcleo en el nuevo paquete
        $bundledPluginNames = [];
        $entries = @scandir($sourcePluginsDir);
        if (!is_array($entries)) {
            $result['errors'][] = 'No se pudo leer la carpeta de plugins del paquete del núcleo.';
            return $result;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $sourcePluginsDir . '/' . $entry;
            if (!$this->isBundledPluginDirectory($sourcePath)) {
                continue;
            }

            $bundledPluginNames[] = $entry;
        }

        // Eliminar plugins del núcleo existentes antes de copiar los nuevos
        // Esto asegura que no queden restos de versiones anteriores
        $reportProgress('Eliminando plugins del núcleo existentes...', 10);

        $localEntries = @scandir($targetPluginsDir);
        if (is_array($localEntries)) {
            foreach ($localEntries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $targetPath = $targetPluginsDir . '/' . $entry;

                // Si es un plugin del núcleo (está en la lista de plugins del nuevo paquete), eliminarlo
                if (in_array($entry, $bundledPluginNames)) {
                    $reportProgress("Eliminando plugin antiguo: $entry...", 15);
                    $this->deleteDirectoryRecursive($targetPath);
                }
                // Nota: Los plugins que NO están en el nuevo paquete se quedan (plugins no-core del usuario)
            }
        }

        $reportProgress('Copiando nuevos plugins del núcleo...', 40);

        // Ahora copiar todos los plugins del núcleo desde el nuevo paquete
        $totalPlugins = count($bundledPluginNames);
        $processedPlugins = 0;

        foreach ($bundledPluginNames as $pluginName) {
            $sourcePath = $sourcePluginsDir . '/' . $pluginName;

            if (!$this->isBundledPluginDirectory($sourcePath)) {
                continue;
            }

            $targetPath = $targetPluginsDir . '/' . $pluginName;

            // Crear directorio destino
            if (!@mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                $result['errors'][] = 'No se pudo crear la carpeta del plugin del núcleo ' . $pluginName . '.';
                continue;
            }

            // Copiar archivos del plugin
            $this->copyDirectorySelective($sourcePath, $targetPath);

            if (!$this->pluginInstallLooksValid($targetPath)) {
                $result['errors'][] = 'La sincronización del plugin del núcleo ' . $pluginName . ' no se completó correctamente.';
                continue;
            }

            // Verificar si existía antes (si existía, es actualización; si no, es nuevo)
            // Como ya lo eliminamos arriba, todos aparecerán como "nuevos" en la primera pasada
            // Por eso usamos la lógica original de verificar
            $result['added'][] = $pluginName;

            $processedPlugins++;
            $percent = 40 + (int)(($processedPlugins / max($totalPlugins, 1)) * 50);
            $reportProgress("Procesando plugin: $pluginName...", $percent);
        }

        // Ordenar resultados
        sort($result['updated']);
        sort($result['added']);

        $reportProgress('Sincronización de plugins del núcleo completada.', 100);

        return $result;
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    private function isBundledPluginDirectory($path)
    {
        if (!is_dir($path)) {
            return false;
        }

        return file_exists($path . '/fsframework.ini')
            || file_exists($path . '/facturascripts.ini')
            || file_exists($path . '/Init.php');
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    private function pluginInstallLooksValid($path)
    {
        return is_dir($path)
            && (
                file_exists($path . '/fsframework.ini')
                || file_exists($path . '/facturascripts.ini')
                || file_exists($path . '/Init.php')
            );
    }
}