<?php
/**
 * Standalone Backup Manager for FSFramework
 * This class is designed to work independently from the framework,
 * allowing it to be used in older versions during the update process.
 *
 * Features:
 * - Complete system backup (files + database)
 * - Version tracking
 * - Restore: complete, files only, or database only
 * - Includes all plugins in backup
 *
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 * @version 2.1.0
 */
class backup_manager
{
    const BACKUP_DIR = 'backups';
    const VERSION = '2.1.0';

    /**
     * @var string
     */
    private $fsRoot;

    /**
     * @var string
     */
    private $backupPath;

    /**
     * @var array
     */
    private $errors = array();

    /**
     * @var array
     */
    private $messages = array();

    /**
     * Directories to exclude from file backups.
     * @var array
     */
    private $excludedDirs = array(
        'backups',           // Don't backup backups
        'tmp',
        '.git',
        '.idea',
        '.vscode',
        'node_modules',
        'vendor',            // Composer dependencies can be reinstalled
    );

    /**
     * Constructor.
     *
     * @param string|null $fsRoot The root directory of FSFramework.
     */
    public function __construct($fsRoot = null)
    {
        if ($fsRoot !== null) {
            $this->fsRoot = $fsRoot;
        } elseif (defined('FS_FOLDER')) {
            $this->fsRoot = FS_FOLDER;
        } else {
            $this->fsRoot = dirname(dirname(__DIR__));
        }

        // Store backups in /backups/ directory at project root
        $this->backupPath = $this->fsRoot . DIRECTORY_SEPARATOR . self::BACKUP_DIR;
        $this->ensureBackupDirectoryExists();
    }

    /**
     * Create the backup directory if it doesn't exist.
     */
    private function ensureBackupDirectoryExists()
    {
        if (!is_dir($this->backupPath)) {
            if (!@mkdir($this->backupPath, 0755, true)) {
                $this->errors[] = "No se puede crear el directorio de copias de seguridad: " . $this->backupPath;
                return;
            }
            // Create security files
            file_put_contents($this->backupPath . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
            file_put_contents($this->backupPath . '/index.php', "<?php\n// No directory listing\nheader('HTTP/1.0 403 Forbidden');\nexit;\n");
        }
    }

    /**
     * Get errors.
     * @return array
     */
    public function get_errors()
    {
        return $this->errors;
    }

    /**
     * Get messages.
     * @return array
     */
    public function get_messages()
    {
        return $this->messages;
    }

    /**
     * Get the backup directory path.
     * @return string
     */
    public function get_backup_path()
    {
        return $this->backupPath;
    }

    /**
     * Get current system version information.
     * @return array
     */
    private function get_version_info()
    {
        $versionFile = $this->fsRoot . '/VERSION';
        $version = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : 'unknown';

        // Get list of installed plugins with versions
        $plugins = array();
        $pluginsDir = $this->fsRoot . '/plugins';
        if (is_dir($pluginsDir)) {
            foreach (scandir($pluginsDir) as $plugin) {
                if ($plugin === '.' || $plugin === '..' || !is_dir($pluginsDir . '/' . $plugin)) {
                    continue;
                }

                $iniFile = $pluginsDir . '/' . $plugin . '/facturascripts.ini';
                $pluginVersion = 'unknown';
                if (file_exists($iniFile)) {
                    $ini = @parse_ini_file($iniFile);
                    if (isset($ini['version'])) {
                        $pluginVersion = $ini['version'];
                    }
                }
                $plugins[$plugin] = $pluginVersion;
            }
        }

        return array(
            'framework_version' => $version,
            'php_version' => PHP_VERSION,
            'backup_manager_version' => self::VERSION,
            'plugins' => $plugins,
            'created_at' => date('Y-m-d H:i:s'),
            'timestamp' => time(),
        );
    }

    /**
     * Create a complete backup (database + files) as a unified package.
     *
     * @param string $customName Optional custom name for the backup.
     * @param bool $includePlugins Whether to include all plugins (default: true).
     * @return array Results with 'database', 'files', and 'complete' keys.
     */
    public function create_backup($customName = '', $includePlugins = true)
    {
        $timestamp = date('Y-m-d_H-i-s');
        $baseName = $customName ? $customName : 'backup_' . $timestamp;

        // Get version info
        $versionInfo = $this->get_version_info();

        // Create database backup first
        $dbResult = $this->create_database_backup($baseName . '_db');

        // Create files backup (including plugins)
        $filesResult = $this->create_files_backup($baseName . '_files', $includePlugins);

        $results = array(
            'database' => $dbResult,
            'files' => $filesResult,
            'version_info' => $versionInfo,
        );

        if ($dbResult['success'] && $filesResult['success']) {
            // Create a unified backup package (ZIP containing both backups + metadata)
            $unifiedResult = $this->create_unified_package($baseName, $dbResult, $filesResult, $versionInfo);

            $results['complete'] = array(
                'success' => $unifiedResult['success'],
                'backup_name' => $baseName,
                'unified_file' => $unifiedResult['file'] ?? null,
                'database_file' => $dbResult['file'],
                'files_file' => $filesResult['file'],
                'version_info' => $versionInfo,
                'created_at' => date('Y-m-d H:i:s'),
            );

            if ($unifiedResult['success']) {
                $this->save_metadata($results['complete']);
                $this->messages[] = "Copia de seguridad unificada creada: " . $unifiedResult['file'];
            }
        } else {
            $results['complete'] = array('success' => false, 'backup_name' => $baseName);
        }

        // Clean old backups (keep last 5 unified backups)
        $this->clean_old_backups(5);

        return $results;
    }

    /**
     * Create a unified backup package containing both DB and files backup.
     *
     * @param string $baseName
     * @param array $dbResult
     * @param array $filesResult
     * @param array $versionInfo
     * @return array
     */
    private function create_unified_package($baseName, $dbResult, $filesResult, $versionInfo)
    {
        if (!extension_loaded('zip')) {
            $this->errors[] = "La extensión PHP ZIP no está instalada.";
            return array('success' => false, 'file' => null);
        }

        $packageName = $baseName . '_complete.zip';
        $packagePath = $this->backupPath . DIRECTORY_SEPARATOR . $packageName;

        $zip = new ZipArchive();
        $result = $zip->open($packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            $this->errors[] = "No se puede crear el paquete unificado: código de error " . $result;
            return array('success' => false, 'file' => null);
        }

        // Add database backup
        if (file_exists($dbResult['path'])) {
            $zip->addFile($dbResult['path'], 'database/' . $dbResult['file']);
        }

        // Add files backup
        if (file_exists($filesResult['path'])) {
            $zip->addFile($filesResult['path'], 'files/' . $filesResult['file']);
        }

        // Add metadata/version info
        $metadata = array(
            'backup_name' => $baseName,
            'backup_type' => 'complete',
            'version_info' => $versionInfo,
            'database_file' => $dbResult['file'],
            'files_file' => $filesResult['file'],
            'created_at' => date('Y-m-d H:i:s'),
            'restore_instructions' => array(
                'complete' => 'Para restaurar todo: use restore_complete()',
                'files_only' => 'Para restaurar solo archivos: use restore_files()',
                'database_only' => 'Para restaurar solo base de datos: use restore_database()',
            ),
        );
        $zip->addFromString('backup_metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (!$zip->close()) {
            $this->errors[] = "Error al cerrar el paquete unificado.";
            return array('success' => false, 'file' => null);
        }

        return array(
            'success' => true,
            'file' => $packageName,
            'path' => $packagePath,
            'size' => filesize($packagePath),
            'size_formatted' => $this->format_bytes(filesize($packagePath)),
        );
    }

    /**
     * Create a database backup.
     *
     * @param string $customName
     * @return array
     */
    public function create_database_backup($customName = '')
    {
        $timestamp = date('Y-m-d_H-i-s');
        $fileName = ($customName ? $customName : 'db_backup_' . $timestamp) . '.sql.gz';
        $filePath = $this->backupPath . DIRECTORY_SEPARATOR . $fileName;

        // Get DB credentials - compatible with older and newer versions
        $dbType = defined('FS_DB_TYPE') ? FS_DB_TYPE : 'MYSQL';
        $dbHost = defined('FS_DB_HOST') ? FS_DB_HOST : 'localhost';
        $dbPort = defined('FS_DB_PORT') ? FS_DB_PORT : '3306';
        $dbUser = defined('FS_DB_USER') ? FS_DB_USER : 'root';
        $dbPass = defined('FS_DB_PASS') ? FS_DB_PASS : '';
        $dbName = defined('FS_DB_NAME') ? FS_DB_NAME : 'facturascripts';

        if (strtoupper($dbType) === 'POSTGRESQL') {
            // PostgreSQL backup
            $command = sprintf(
                'PGPASSWORD=%s pg_dump --host=%s --port=%s --username=%s %s 2>&1 | gzip > %s',
                escapeshellarg($dbPass),
                escapeshellarg($dbHost),
                escapeshellarg($dbPort),
                escapeshellarg($dbUser),
                escapeshellarg($dbName),
                escapeshellarg($filePath)
            );
        } else {
            // MySQL backup
            $command = sprintf(
                'mysqldump --single-transaction --routines --triggers --host=%s --port=%s --user=%s --password=%s %s 2>&1 | gzip > %s',
                escapeshellarg($dbHost),
                escapeshellarg($dbPort),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName),
                escapeshellarg($filePath)
            );
        }

        $output = array();
        $returnVar = 0;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0 || !file_exists($filePath) || filesize($filePath) === 0) {
            $this->errors[] = "Error al crear la copia de la base de datos: " . implode("\n", $output);
            return array('success' => false, 'file' => null, 'error' => implode("\n", $output));
        }

        $this->messages[] = "Copia de base de datos creada: " . $fileName;
        return array(
            'success' => true,
            'file' => $fileName,
            'path' => $filePath,
            'size' => filesize($filePath),
            'size_formatted' => $this->format_bytes(filesize($filePath)),
        );
    }

    /**
     * Create a files backup.
     *
     * @param string $customName
     * @param bool $includePlugins Whether to include plugins folder
     * @return array
     */
    public function create_files_backup($customName = '', $includePlugins = true)
    {
        if (!extension_loaded('zip')) {
            $this->errors[] = "La extensión PHP ZIP no está instalada.";
            return array('success' => false, 'file' => null, 'error' => 'ZIP extension not loaded');
        }

        $timestamp = date('Y-m-d_H-i-s');
        $fileName = ($customName ? $customName : 'files_backup_' . $timestamp) . '.zip';
        $filePath = $this->backupPath . DIRECTORY_SEPARATOR . $fileName;

        $zip = new ZipArchive();
        $result = $zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            $this->errors[] = "No se puede crear el archivo ZIP: código de error " . $result;
            return array('success' => false, 'file' => null, 'error' => 'ZipArchive open failed');
        }

        // If not including plugins, add to exclusions temporarily
        if (!$includePlugins) {
            $this->excludedDirs[] = 'plugins';
        }

        $fileCount = $this->add_directory_to_zip($zip, $this->fsRoot);

        // Remove temporary exclusion
        if (!$includePlugins) {
            $key = array_search('plugins', $this->excludedDirs);
            if ($key !== false) {
                unset($this->excludedDirs[$key]);
            }
        }

        if (!$zip->close()) {
            $this->errors[] = "Error al cerrar el archivo ZIP.";
            return array('success' => false, 'file' => null, 'error' => 'ZipArchive close failed');
        }

        if ($fileCount === 0) {
            $this->errors[] = "No se añadieron archivos al backup.";
            @unlink($filePath);
            return array('success' => false, 'file' => null, 'error' => 'No files added');
        }

        $this->messages[] = "Copia de archivos creada: " . $fileName . " (" . $fileCount . " archivos)";
        return array(
            'success' => true,
            'file' => $fileName,
            'path' => $filePath,
            'size' => filesize($filePath),
            'size_formatted' => $this->format_bytes(filesize($filePath)),
            'file_count' => $fileCount,
        );
    }

    /**
     * Recursively add a directory to a ZipArchive.
     *
     * @param ZipArchive $zip
     * @param string $sourceDir
     * @return int Number of files added.
     */
    private function add_directory_to_zip($zip, $sourceDir)
    {
        $fileCount = 0;
        $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !$file->isReadable()) {
                continue;
            }

            $filePath = $file->getRealPath();
            $relPath = substr($filePath, strlen($this->fsRoot) + 1);

            // Check exclusions
            if ($this->should_exclude_file($relPath)) {
                continue;
            }

            $zip->addFile($filePath, $relPath);
            $fileCount++;

            // Prevent timeout
            if ($fileCount % 500 === 0) {
                @set_time_limit(300);
            }
        }

        return $fileCount;
    }

    /**
     * Check if a file should be excluded from the backup.
     *
     * @param string $relativePath
     * @return bool
     */
    private function should_exclude_file($relativePath)
    {
        // Normalize path separators
        $relativePath = str_replace('\\', '/', $relativePath);

        foreach ($this->excludedDirs as $excludedDir) {
            $excludedDir = str_replace('\\', '/', $excludedDir);
            if (strpos($relativePath, $excludedDir . '/') === 0 || $relativePath === $excludedDir) {
                return true;
            }
        }
        return false;
    }

    /**
     * Restore a complete backup (files + database).
     *
     * @param string $backupFile The unified backup file name or path
     * @return array Result with success status
     */
    public function restore_complete($backupFile)
    {
        $results = array(
            'success' => false,
            'files' => null,
            'database' => null,
        );

        // Get full path
        $backupPath = $this->get_backup_file_path($backupFile);
        if (!$backupPath) {
            $this->errors[] = "Archivo de backup no encontrado: " . $backupFile;
            return $results;
        }

        // Extract unified package to temp dir
        $tempDir = $this->backupPath . '/temp_restore_' . time();
        if (!$this->extract_unified_package($backupPath, $tempDir)) {
            return $results;
        }

        // Find the database and files backups inside
        $metadata = $this->read_package_metadata($tempDir);

        // Restore files first
        $filesBackup = $tempDir . '/files/' . ($metadata['files_file'] ?? '');
        if (file_exists($filesBackup)) {
            $results['files'] = $this->restore_files($filesBackup);
        } else {
            // Try to find any zip file in files folder
            $filesDir = $tempDir . '/files';
            if (is_dir($filesDir)) {
                foreach (scandir($filesDir) as $f) {
                    if (substr($f, -4) === '.zip') {
                        $results['files'] = $this->restore_files($filesDir . '/' . $f);
                        break;
                    }
                }
            }
        }

        // Then restore database
        $dbBackup = $tempDir . '/database/' . ($metadata['database_file'] ?? '');
        if (file_exists($dbBackup)) {
            $results['database'] = $this->restore_database($dbBackup);
        } else {
            // Try to find any sql.gz file in database folder
            $dbDir = $tempDir . '/database';
            if (is_dir($dbDir)) {
                foreach (scandir($dbDir) as $f) {
                    if (substr($f, -7) === '.sql.gz') {
                        $results['database'] = $this->restore_database($dbDir . '/' . $f);
                        break;
                    }
                }
            }
        }

        // Clean up temp dir
        $this->delete_directory($tempDir);

        $results['success'] = (
            ($results['files']['success'] ?? false) &&
            ($results['database']['success'] ?? false)
        );

        if ($results['success']) {
            $this->messages[] = "Restauración completa realizada correctamente.";
        }

        return $results;
    }

    /**
     * Restore only files from a backup.
     *
     * @param string $backupFile The files backup (zip) or unified backup
     * @return array Result with success status
     */
    public function restore_files($backupFile)
    {
        $result = array('success' => false);

        // Get full path
        $backupPath = $this->get_backup_file_path($backupFile);
        if (!$backupPath) {
            $this->errors[] = "Archivo de backup de archivos no encontrado: " . $backupFile;
            return $result;
        }

        if (!extension_loaded('zip')) {
            $this->errors[] = "La extensión PHP ZIP no está instalada.";
            return $result;
        }

        $zip = new ZipArchive();
        if ($zip->open($backupPath) !== true) {
            $this->errors[] = "No se puede abrir el archivo ZIP: " . $backupFile;
            return $result;
        }

        // Extract to temp folder first
        $tempDir = $this->backupPath . '/temp_files_' . time();
        if (!@mkdir($tempDir, 0755, true)) {
            $this->errors[] = "No se puede crear directorio temporal.";
            $zip->close();
            return $result;
        }

        if (!$zip->extractTo($tempDir)) {
            $this->errors[] = "Error al extraer el archivo ZIP.";
            $zip->close();
            $this->delete_directory($tempDir);
            return $result;
        }
        $zip->close();

        // Copy files to fsRoot, excluding config.php to preserve settings
        $excludeFromRestore = array('config.php', 'config2.php');
        $this->copy_directory($tempDir, $this->fsRoot, $excludeFromRestore);

        // Clean up
        $this->delete_directory($tempDir);

        $result['success'] = true;
        $this->messages[] = "Archivos restaurados correctamente desde: " . basename($backupFile);

        return $result;
    }

    /**
     * Restore only database from a backup.
     *
     * @param string $backupFile The database backup (sql.gz)
     * @return array Result with success status
     */
    public function restore_database($backupFile)
    {
        $result = array('success' => false);

        // Get full path
        $backupPath = $this->get_backup_file_path($backupFile);
        if (!$backupPath) {
            $this->errors[] = "Archivo de backup de base de datos no encontrado: " . $backupFile;
            return $result;
        }

        // Get DB credentials
        $dbType = defined('FS_DB_TYPE') ? FS_DB_TYPE : 'MYSQL';
        $dbHost = defined('FS_DB_HOST') ? FS_DB_HOST : 'localhost';
        $dbPort = defined('FS_DB_PORT') ? FS_DB_PORT : '3306';
        $dbUser = defined('FS_DB_USER') ? FS_DB_USER : 'root';
        $dbPass = defined('FS_DB_PASS') ? FS_DB_PASS : '';
        $dbName = defined('FS_DB_NAME') ? FS_DB_NAME : 'facturascripts';

        if (strtoupper($dbType) === 'POSTGRESQL') {
            // PostgreSQL restore
            $command = sprintf(
                'gunzip -c %s | PGPASSWORD=%s psql --host=%s --port=%s --username=%s %s 2>&1',
                escapeshellarg($backupPath),
                escapeshellarg($dbPass),
                escapeshellarg($dbHost),
                escapeshellarg($dbPort),
                escapeshellarg($dbUser),
                escapeshellarg($dbName)
            );
        } else {
            // MySQL restore
            $command = sprintf(
                'gunzip -c %s | mysql --host=%s --port=%s --user=%s --password=%s %s 2>&1',
                escapeshellarg($backupPath),
                escapeshellarg($dbHost),
                escapeshellarg($dbPort),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName)
            );
        }

        $output = array();
        $returnVar = 0;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            $this->errors[] = "Error al restaurar la base de datos: " . implode("\n", $output);
            return $result;
        }

        $result['success'] = true;
        $this->messages[] = "Base de datos restaurada correctamente desde: " . basename($backupFile);

        return $result;
    }

    /**
     * Get full path for a backup file.
     *
     * @param string $file
     * @return string|false
     */
    private function get_backup_file_path($file)
    {
        // If already full path
        if (file_exists($file)) {
            return $file;
        }

        // Try in backup directory
        $fullPath = $this->backupPath . DIRECTORY_SEPARATOR . basename($file);
        if (file_exists($fullPath)) {
            return $fullPath;
        }

        return false;
    }

    /**
     * Extract a unified package to a directory.
     *
     * @param string $packagePath
     * @param string $extractTo
     * @return bool
     */
    private function extract_unified_package($packagePath, $extractTo)
    {
        if (!extension_loaded('zip')) {
            $this->errors[] = "La extensión PHP ZIP no está instalada.";
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) {
            $this->errors[] = "No se puede abrir el paquete: " . basename($packagePath);
            return false;
        }

        if (!@mkdir($extractTo, 0755, true)) {
            $this->errors[] = "No se puede crear directorio de extracción.";
            $zip->close();
            return false;
        }

        if (!$zip->extractTo($extractTo)) {
            $this->errors[] = "Error al extraer el paquete.";
            $zip->close();
            return false;
        }

        $zip->close();
        return true;
    }

    /**
     * Read metadata from an extracted package.
     *
     * @param string $extractedDir
     * @return array
     */
    private function read_package_metadata($extractedDir)
    {
        $metadataFile = $extractedDir . '/backup_metadata.json';
        if (!file_exists($metadataFile)) {
            return array();
        }

        $content = file_get_contents($metadataFile);
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Copy a directory recursively.
     *
     * @param string $source
     * @param string $dest
     * @param array $excludeFiles Files to skip
     */
    private function copy_directory($source, $dest, $excludeFiles = array())
    {
        $source = rtrim($source, '/\\');
        $dest = rtrim($dest, '/\\');

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $destPath = $dest . '/' . $relativePath;

            // Skip excluded files
            if (in_array(basename($relativePath), $excludeFiles)) {
                continue;
            }

            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    @mkdir($destPath, 0755, true);
                }
            } else {
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    @mkdir($destDir, 0755, true);
                }
                @copy($item->getPathname(), $destPath);
            }
        }
    }

    /**
     * Delete a directory recursively.
     *
     * @param string $dir
     * @return bool
     */
    private function delete_directory($dir)
    {
        if (!is_dir($dir)) {
            return true;
        }

        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->delete_directory($path) : @unlink($path);
        }

        return @rmdir($dir);
    }

    /**
     * List available backups.
     *
     * @return array
     */
    public function list_backups()
    {
        $backups = array();
        if (!is_dir($this->backupPath)) {
            return $backups;
        }

        $files = scandir($this->backupPath);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === '.htaccess' || $file === 'index.php' || $file === 'metadata.json') {
                continue;
            }

            // Skip temp directories
            if (strpos($file, 'temp_') === 0) {
                continue;
            }

            $filePath = $this->backupPath . DIRECTORY_SEPARATOR . $file;
            if (!is_file($filePath)) {
                continue;
            }

            $type = $this->get_backup_type($file);
            $backups[] = array(
                'name' => $file,
                'size' => filesize($filePath),
                'size_formatted' => $this->format_bytes(filesize($filePath)),
                'date' => date('Y-m-d H:i:s', filemtime($filePath)),
                'timestamp' => filemtime($filePath),
                'type' => $type,
                'path' => $filePath,
                'can_restore_complete' => ($type === 'complete'),
                'can_restore_files' => ($type === 'files' || $type === 'complete'),
                'can_restore_database' => ($type === 'database' || $type === 'complete'),
            );
        }

        // Sort by date (most recent first)
        usort($backups, array($this, 'sort_by_timestamp'));

        return $backups;
    }

    /**
     * Sort callback for backups by timestamp descending.
     * @param array $a
     * @param array $b
     * @return int
     */
    private function sort_by_timestamp($a, $b)
    {
        return $b['timestamp'] - $a['timestamp'];
    }

    /**
     * Determine backup type from filename.
     *
     * @param string $filename
     * @return string
     */
    private function get_backup_type($filename)
    {
        if (strpos($filename, '_complete.zip') !== false) {
            return 'complete';
        }
        if (substr($filename, -7) === '.sql.gz' || strpos($filename, '_db') !== false) {
            return 'database';
        }
        if (substr($filename, -4) === '.zip' || strpos($filename, '_files') !== false) {
            return 'files';
        }
        return 'unknown';
    }

    /**
     * Delete a backup file.
     *
     * @param string $filename
     * @return bool
     */
    public function delete_backup($filename)
    {
        $filePath = $this->backupPath . DIRECTORY_SEPARATOR . basename($filename);

        if (!file_exists($filePath)) {
            $this->errors[] = "El archivo de copia no existe: " . $filename;
            return false;
        }

        if (!@unlink($filePath)) {
            $this->errors[] = "No se puede eliminar el archivo: " . $filename;
            return false;
        }

        $this->messages[] = "Copia eliminada: " . $filename;
        return true;
    }

    /**
     * List available backups grouped by base name (timestamp).
     * This groups database and files backups from the same backup operation together.
     *
     * @return array Grouped backups with 'base_name', 'database', 'files', 'complete' keys
     */
    public function list_backups_grouped()
    {
        $backups = $this->list_backups();
        $grouped = array();

        foreach ($backups as $backup) {
            // Extract base name by removing suffixes like _db.sql.gz, _files.zip, _complete.zip
            $baseName = $backup['name'];
            $baseName = preg_replace('/_db\.sql\.gz$/', '', $baseName);
            $baseName = preg_replace('/_files\.zip$/', '', $baseName);
            $baseName = preg_replace('/_complete\.zip$/', '', $baseName);

            if (!isset($grouped[$baseName])) {
                $grouped[$baseName] = array(
                    'base_name' => $baseName,
                    'database' => null,
                    'files' => null,
                    'complete' => null,
                    'date' => $backup['date'],
                    'timestamp' => $backup['timestamp'],
                );
            }

            // Update date to most recent
            if ($backup['timestamp'] > $grouped[$baseName]['timestamp']) {
                $grouped[$baseName]['date'] = $backup['date'];
                $grouped[$baseName]['timestamp'] = $backup['timestamp'];
            }

            // Assign to appropriate slot
            if ($backup['type'] === 'database') {
                $grouped[$baseName]['database'] = $backup;
            } elseif ($backup['type'] === 'files') {
                $grouped[$baseName]['files'] = $backup;
            } elseif ($backup['type'] === 'complete') {
                $grouped[$baseName]['complete'] = $backup;
            }
        }

        // Convert to indexed array and sort by timestamp descending
        $result = array_values($grouped);
        usort($result, function ($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        return $result;
    }

    /**
     * Delete all backup files associated with a base name (database + files + complete).
     *
     * @param string $baseName The base backup name (without _db, _files, _complete suffixes)
     * @return array Results with 'success', 'deleted', 'errors' keys
     */
    public function delete_backup_group($baseName)
    {
        $result = array(
            'success' => true,
            'deleted' => array(),
            'errors' => array(),
        );

        // Possible file patterns for this base name
        $patterns = array(
            $baseName . '_db.sql.gz',
            $baseName . '_files.zip',
            $baseName . '_complete.zip',
        );

        foreach ($patterns as $filename) {
            $filePath = $this->backupPath . DIRECTORY_SEPARATOR . $filename;
            if (file_exists($filePath)) {
                if (@unlink($filePath)) {
                    $result['deleted'][] = $filename;
                } else {
                    $result['errors'][] = "No se puede eliminar: " . $filename;
                    $result['success'] = false;
                }
            }
        }

        if (count($result['deleted']) > 0) {
            $this->messages[] = "Copias eliminadas: " . implode(', ', $result['deleted']);
        }

        if (count($result['errors']) > 0) {
            foreach ($result['errors'] as $error) {
                $this->errors[] = $error;
            }
        }

        return $result;
    }

    /**
     * Clean old backups, keeping only a specified number of each type.
     *
     * @param int $keepCount Number of unified backups to keep
     * @return int Number of files deleted.
     */
    public function clean_old_backups($keepCount = 5)
    {
        $backups = $this->list_backups();

        // Group by type
        $byType = array('complete' => array(), 'database' => array(), 'files' => array());
        foreach ($backups as $backup) {
            $type = $backup['type'];
            if (isset($byType[$type])) {
                $byType[$type][] = $backup;
            }
        }

        $deleted = 0;

        // For each type, keep only $keepCount
        foreach ($byType as $type => $typeBackups) {
            if (count($typeBackups) <= $keepCount) {
                continue;
            }

            $toDelete = array_slice($typeBackups, $keepCount);
            foreach ($toDelete as $backup) {
                if ($this->delete_backup($backup['name'])) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Create a backup before an update.
     * This is a convenience method to be called from the updater.
     *
     * @param string $updateType 'core' or plugin name
     * @return array Backup result
     */
    public function create_pre_update_backup($updateType = 'core')
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $updateType);
        $backupName = 'pre_update_' . $safeName . '_' . date('Y-m-d_H-i-s');

        return $this->create_backup($backupName, true);
    }

    /**
     * Save backup metadata.
     *
     * @param array $backupData
     */
    private function save_metadata($backupData)
    {
        $metadataFile = $this->backupPath . DIRECTORY_SEPARATOR . 'metadata.json';
        $metadata = array();

        if (file_exists($metadataFile)) {
            $content = file_get_contents($metadataFile);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $metadata[$backupData['backup_name']] = $backupData;
        file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Format bytes to human-readable format.
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    private function format_bytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $i = 0;
        while ($bytes > 1024 && $i < count($units) - 1) {
            $bytes = $bytes / 1024;
            $i++;
        }
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
