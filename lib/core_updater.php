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

        $reportProgress('copy_prepare', 'Preparando reemplazo de archivos del núcleo...', 65);

        $excludeFiles = ['config.php', 'plugins', 'backups', 'tmp'];
        if (file_exists($this->rootPath . '/.git')) {
            $excludeFiles[] = '.git';
        } else {
            $gitMetadataInstalled = file_exists($sourceDir . '/.git');
        }

        $this->copyDirectorySelective($sourceDir, $this->rootPath, $excludeFiles);

        $reportProgress('copy_complete', 'Archivos del núcleo actualizados.', 90);

        if (is_dir($extractPath)) {
            $this->deleteDirectoryRecursive($extractPath);
        }

        $this->clearTemplateCache();

        $installedVersion = $this->getInstalledCoreVersion();
        $message = 'Núcleo actualizado correctamente.';

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
     * @param string $extractPath
     *
     * @return string|false
     */
    private function downloadCoreZipSource($extractPath)
    {
        require_once $this->rootPath . '/base/fs_file_manager.php';

        $zipUrls = [
            'https://github.com/eltictacdicta/fs-framework/archive/refs/heads/master.zip',
            'https://github.com/eltictacdicta/fs-framework/archive/refs/heads/main.zip',
            'https://github.com/eltictacdicta/fs-framework/archive/master.zip',
            'https://github.com/eltictacdicta/fs-framework/archive/main.zip',
        ];

        $downloadPath = $this->rootPath . '/download_core.zip';

        foreach ($zipUrls as $zipUrl) {
            if (@fs_file_download($zipUrl, $downloadPath) && fs_file_manager::extract_zip_safe($downloadPath, $extractPath)) {
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
        if (!class_exists('fs_file_manager') && file_exists($this->rootPath . '/base/fs_file_manager.php')) {
            require_once $this->rootPath . '/base/fs_file_manager.php';
        }

        if (class_exists('fs_file_manager') && method_exists('fs_file_manager', 'clear_all_template_cache')) {
            @fs_file_manager::clear_all_template_cache();
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
}