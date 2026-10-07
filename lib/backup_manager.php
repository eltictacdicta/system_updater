<?php

require_once __DIR__ . '/SqlDumpReader.php';

/**
 * MySQL helper used by the standalone backup manager.
 */
class BackupMysqlHelper
{
    /**
     * @var array
     */
    private $errors;

    /**
     * @param array $errors
     */
    public function __construct(array &$errors)
    {
        $this->errors =& $errors;
    }

    /**
     * @param string $identifier
     * @return string|false
     */
    public function quoteIdentifier($identifier)
    {
        $identifier = (string) $identifier;

        if (!preg_match('/^\w+$/', $identifier)) {
            $this->errors[] = 'Nombre de tabla no valido: ' . $identifier;
            return false;
        }

        return '`' . $identifier . '`';
    }

    /**
     * @param mysqli $mysqli
     * @param string $tableName
     * @return bool
     */
    public function tableExists($mysqli, $tableName)
    {
        $dbName = $mysqli->query('SELECT DATABASE()')->fetch_row()[0] ?? '';
        $stmt = $mysqli->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
        );
        if (!$stmt) {
            $this->errors[] = 'No se pudo preparar la comprobacion de tablas: ' . $mysqli->error;
            return false;
        }

        $stmt->bind_param('ss', $dbName, $tableName);

        if (!$stmt->execute()) {
            $this->errors[] = 'No se pudo ejecutar la comprobacion de tablas: ' . $stmt->error;
            $stmt->close();
            return false;
        }

        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        return $exists;
    }

    /**
     * @param mysqli $mysqli
     * @param string $tableName
     * @return mysqli_result|false
     */
    public function showCreateTable($mysqli, $tableName)
    {
        $quotedTable = $this->quoteIdentifier($tableName);
        if ($quotedTable === false) {
            return false;
        }

        return $mysqli->query('SHOW CREATE TABLE ' . $quotedTable);
    }

    /**
     * @param mysqli $mysqli
     * @param string $tableName
     * @param int $resultMode
     * @return mysqli_result|false
     */
    public function selectAllFromTable($mysqli, $tableName, $resultMode = MYSQLI_STORE_RESULT)
    {
        $quotedTable = $this->quoteIdentifier($tableName);
        if ($quotedTable === false) {
            return false;
        }

        return $mysqli->query('SELECT * FROM ' . $quotedTable, $resultMode);
    }

    /**
     * @param mysqli $mysqli
     * @param string $tableName
     * @return bool
     */
    public function dropTableIfExists($mysqli, $tableName)
    {
        $quotedTable = $this->quoteIdentifier($tableName);
        if ($quotedTable === false) {
            return false;
        }

        return (bool) $mysqli->query('DROP TABLE IF EXISTS ' . $quotedTable);
    }

    /**
     * @param mysqli $mysqli
     * @param string $targetTable
     * @param string $sourceTable
     * @return bool
     */
    public function createTableLike($mysqli, $targetTable, $sourceTable)
    {
        $quotedTarget = $this->quoteIdentifier($targetTable);
        $quotedSource = $this->quoteIdentifier($sourceTable);
        if ($quotedTarget === false || $quotedSource === false) {
            return false;
        }

        return (bool) $mysqli->query('CREATE TABLE ' . $quotedTarget . ' LIKE ' . $quotedSource);
    }

    /**
     * @param mysqli $mysqli
     * @param string $targetTable
     * @param string $sourceTable
     * @return bool
     */
    public function copyTableRows($mysqli, $targetTable, $sourceTable)
    {
        $quotedTarget = $this->quoteIdentifier($targetTable);
        $quotedSource = $this->quoteIdentifier($sourceTable);
        if ($quotedTarget === false || $quotedSource === false) {
            return false;
        }

        return (bool) $mysqli->query('INSERT INTO ' . $quotedTarget . ' SELECT * FROM ' . $quotedSource);
    }

    /**
     * @param mysqli $mysqli
     * @param string $oldTableName
     * @param string $newTableName
     * @return bool
     */
    public function renameTable($mysqli, $oldTableName, $newTableName)
    {
        $quotedOld = $this->quoteIdentifier($oldTableName);
        $quotedNew = $this->quoteIdentifier($newTableName);
        if ($quotedOld === false || $quotedNew === false) {
            return false;
        }

        return (bool) $mysqli->query('RENAME TABLE ' . $quotedOld . ' TO ' . $quotedNew);
    }

    /**
     * @param string $tableName
     * @param array $values
     * @return string
     */
    public function buildInsertValuesStatement($tableName, array $values)
    {
        $quotedTable = $this->quoteIdentifier($tableName);
        if ($quotedTable === false) {
            return '';
        }

        return 'INSERT INTO ' . $quotedTable . ' VALUES (' . implode(', ', $values) . ");\n";
    }

    /**
     * @param string $tableName
     * @return string
     */
    public function buildDropTableStatement($tableName)
    {
        $quotedTable = $this->quoteIdentifier($tableName);
        if ($quotedTable === false) {
            return '';
        }

        return 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n";
    }
}

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
 * @version 2.3.0
 */
class backup_manager
{
    const BACKUP_DIR = 'backups';
    const VERSION = '2.3.2';
    const HOST_SLUG_MAX_LEN = 63;
    const HOST_HEADER_MAX_LEN = 253;

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
     * @var BackupMysqlHelper
     */
    private $mysqlHelper;

    /**
     * Directories to exclude from file backups.
     * @var array
     */
    private $excludedDirs = array(
        'backups',           // Don't backup backups
        'plugins/system_updater', // Don't backup/restore the updater plugin itself (could cause issues during restore)
        'tmp',
        '.git',
        '.idea',
        '.vscode',
        'node_modules',
    );

    /**
     * Constructor.
     *
     * @param string|null $fsRoot The root directory of FSFramework.
     * @param string|null $homeDir Optional user-home override (test seam).
     */
    public function __construct($fsRoot = null, $homeDir = null)
    {
        if ($fsRoot !== null) {
            $this->fsRoot = $fsRoot;
        } elseif (defined('FS_FOLDER')) {
            $this->fsRoot = FS_FOLDER;
        } else {
            $this->fsRoot = dirname(dirname(dirname(__DIR__)));
        }

        // Resolve the effective backup dir at runtime (zero-ops contract):
        //  1. `FS_BACKUP_DIR` override when defined (used verbatim).
        //  2. Sibling of the framework root (`dirname(FS_FOLDER)/backups`) —
        //     outside the webroot (PrestaShop-style fixed path + guards).
        //  3. Home directory of the web user (`<home>/backups`) — outside the
        //     webroot, writable in shared hosting with no admin step.
        //  4. Protected legacy dir inside the webroot (`FS_FOLDER/backups`) as
        //     last resort — guarded by `.htaccess` + `index.php`.
        // `self::BACKUP_DIR` is kept for BC but no longer composed into paths.
        $this->backupPath = self::resolve_usable_backup_dir($this->fsRoot, $homeDir);

        // When we fell back inside the webroot, exclude the backup dir from
        // file backups (prevent recursive backups) and inform the operator.
        $backupDirName = basename($this->backupPath);
        $inWebroot = str_starts_with(
            rtrim($this->backupPath, '/\\') . DIRECTORY_SEPARATOR,
            rtrim($this->fsRoot, '/\\') . DIRECTORY_SEPARATOR
        );
        if ($inWebroot) {
            if (!in_array($backupDirName, $this->excludedDirs, true)) {
                $this->excludedDirs[] = $backupDirName;
            }
            $this->messages[] = "Directorio de copias de seguridad en modo compatibilidad (dentro del webroot): "
                . $this->backupPath
                . ". Defina FS_BACKUP_DIR para moverlo fuera del webroot si lo desea.";
        }

        // Honest nginx note: .htaccess does not apply on nginx, so a backup
        // dir inside the webroot has NO server-side barrier there. The real
        // fix is FS_BACKUP_DIR outside the webroot (or a server-block deny rule).
        if ($inWebroot) {
            $serverSoftware = isset($_SERVER['SERVER_SOFTWARE'])
                ? strtolower((string) $_SERVER['SERVER_SOFTWARE']) : '';
            if (str_contains($serverSoftware, 'nginx')) {
                $this->messages[] = "AVISO: se detectó nginx con el directorio de copias dentro del webroot. "
                    . "En nginx .htaccess no aplica: defina FS_BACKUP_DIR fuera del webroot "
                    . "(o bloquee el directorio en el server block) para protección real.";
            }
        }

        $this->mysqlHelper = new BackupMysqlHelper($this->errors);
        $this->ensureBackupDirectoryExists();

        // Best-effort migration from legacy fixed-name backup dirs.
        $this->migrateLegacyBackups();
    }

    /**
     * Sanitize a raw host string into a slug safe for filenames and DNS labels.
     *
     * Rules:
     *  - lowercased
     *  - any char outside [a-z0-9-] becomes a dash
     *  - consecutive dashes collapse to a single dash
     *  - leading and trailing dashes are trimmed
     *  - capped at 63 chars (DNS label limit) AFTER trimming
     *  - empty result falls back to "unknown"
     *
     * @param string $raw
     * @return string
     */
    private static function slugify_host(string $raw): string
    {
        $slug = strtolower((string) $raw);
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug);
        if ($slug === null) {
            $slug = '';
        }
        $slug = preg_replace('/-+/', '-', $slug);
        if ($slug === null) {
            $slug = '';
        }
        $slug = trim($slug, '-');
        if ($slug === '') {
            return 'unknown';
        }
        if (strlen($slug) > self::HOST_SLUG_MAX_LEN) {
            $slug = substr($slug, 0, self::HOST_SLUG_MAX_LEN);
        }
        return $slug;
    }

    /**
     * Resolve the host slug used as a filename prefix for new backups.
     *
     * Chain: $_SERVER['HTTP_HOST'] -> $_SERVER['SERVER_NAME'] -> gethostname() -> 'unknown'.
     * Each candidate must match `/^[a-zA-Z0-9.\-:]{1,' . self::HOST_HEADER_MAX_LEN . '}$/`
     * (i.e. 1-253 chars; defends against
     * Host header injection like `../../etc/passwd` and oversized strings);
     * candidates that fail the guard fall through to the next link.
     * The surviving candidate is then run through {@see self::slugify_host()}.
     *
     * @return string
     */
    private function resolve_host_slug(): string
    {
        $sources = array(
            isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '',
            isset($_SERVER['SERVER_NAME']) ? (string) $_SERVER['SERVER_NAME'] : '',
            function_exists('gethostname') ? (string) gethostname() : '',
        );

        foreach ($sources as $raw) {
            if ($raw === '' || !preg_match('/^[a-zA-Z0-9.\-:]{1,' . self::HOST_HEADER_MAX_LEN . '}$/', $raw)) {
                continue;
            }
            $slug = self::slugify_host($raw);
            if ($slug !== 'unknown') {
                return $slug;
            }
        }

        return 'unknown';
    }

    /**
     * Resolve the directory where backup files live.
     *
     * The default is a SIBLING of `$fsFolder` (i.e. `dirname($fsFolder) . '/backups'`)
     * so that backups are unreachable by the web server by definition.
     * Operators MAY override the default by defining the `FS_BACKUP_DIR` constant
     * (used in `resolve_backup_dir_with` when not null).
     *
     * Order of precedence (when called with no arguments):
     *  1. `FS_BACKUP_DIR` constant (if defined and non-empty).
     *  2. `dirname(FS_FOLDER) . '/backups'` (sibling of the framework root).
     *
     * Tests and the constructor use {@see self::resolve_backup_dir_with()}
     * to pass an explicit `$fsFolder` instead of relying on the global
     * `FS_FOLDER` constant.
     *
     * @return string
     */
    public static function resolve_backup_dir(): string
    {
        return self::resolve_backup_dir_with(
            defined('FS_BACKUP_DIR') ? (string) FS_BACKUP_DIR : null,
            defined('FS_FOLDER') ? (string) FS_FOLDER : null
        );
    }

    /**
     * Pure seam for {@see self::resolve_backup_dir()}.
     *
     * Returns the absolute backup directory for the given framework root,
     * honouring an explicit override (e.g. `FS_BACKUP_DIR`) when it is
     * non-empty. Passing `null` for `$override` or `$fsFolder` falls back
     * to the appropriate global / `__DIR__`-derived default.
     *
     * @param string|null $override Optional override (FS_BACKUP_DIR).
     * @param string|null $fsFolder Framework root (FS_FOLDER).  Defaults to
     *                              the directory three levels above this file.
     * @return string
     */
    public static function resolve_backup_dir_with(?string $override, ?string $fsFolder): string
    {
        if ($override !== null && trim($override) !== '') {
            return rtrim($override, '/\\');
        }

        if ($fsFolder === null) {
            // __DIR__ here = .../plugins/system_updater/lib
            $fsFolder = dirname(dirname(dirname(__DIR__)));
        }

        return dirname($fsFolder) . DIRECTORY_SEPARATOR . 'backups';
    }

    /**
     * Effective backup dir: first writable candidate in the resolution chain.
     *
     * @param string|null $fsFolder Framework root (FS_FOLDER).
     * @param string|null $homeDir  Optional user-home override (test seam).
     * @return string
     */
    public static function resolve_effective_backup_dir(?string $fsFolder = null, ?string $homeDir = null): string
    {
        return self::resolve_usable_backup_dir($fsFolder, $homeDir);
    }

    /**
     * Ordered list of backup-dir candidates (from safest to last resort).
     *
     *   1. `FS_BACKUP_DIR` override (verbatim).
     *   2. Sibling of the framework root — outside the webroot.
     *   3. Home directory of the web user — outside the webroot, writable in
     *      shared hosting without any admin step (solves nginx too).
     *   4. Protected legacy dir inside the webroot (last resort).
     *
     * @param string|null $fsFolder Framework root (FS_FOLDER).
     * @param string|null $homeDir  Optional user-home override (test seam).
     * @return array<int, string>
     */
    public static function backup_dir_candidates(?string $fsFolder = null, ?string $homeDir = null): array
    {
        if ($fsFolder === null) {
            $fsFolder = defined('FS_FOLDER') ? (string) FS_FOLDER : dirname(dirname(dirname(__DIR__)));
        }

        $override = defined('FS_BACKUP_DIR') ? (string) FS_BACKUP_DIR : null;

        // Explicit override is STRICT: the operator chose this exact path,
        // so it is the only candidate. An unusable override must surface as
        // a loud configuration error, never silently fall back to another
        // directory the operator did not choose.
        if ($override !== null && trim($override) !== '') {
            return array(rtrim($override, '/\\'));
        }

        $candidates = array();
        $candidates[] = dirname($fsFolder) . DIRECTORY_SEPARATOR . 'backups';

        if ($homeDir === null && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            $homeDir = is_array($pw) && isset($pw['dir']) ? (string) $pw['dir'] : null;
        }
        if ($homeDir !== null && $homeDir !== '') {
            $candidates[] = rtrim($homeDir, '/\\') . DIRECTORY_SEPARATOR . 'backups';
        }

        $candidates[] = $fsFolder . DIRECTORY_SEPARATOR . 'backups';
        return array_values(array_unique($candidates));
    }

    /**
     * Return the first candidate that exists+writable or can be created.
     *
     * @param array<int, string> $candidates
     * @return string|null
     */
    public static function resolve_first_usable_dir(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (self::is_dir_usable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Usable backup dir: first writable candidate in the chain.
     *
     * Zero-ops contract: no manual mkdir/chown/mv. The only case that still
     * surfaces a hard error is an explicit `FS_BACKUP_DIR` that cannot be
     * used (the operator asked for that exact path).
     *
     * @param string|null $fsFolder Framework root (FS_FOLDER).
     * @param string|null $homeDir  Optional user-home override (test seam).
     * @return string
     */
    public static function resolve_usable_backup_dir(?string $fsFolder = null, ?string $homeDir = null): string
    {
        if ($fsFolder === null) {
            $fsFolder = defined('FS_FOLDER') ? (string) FS_FOLDER : dirname(dirname(dirname(__DIR__)));
        }

        $override = defined('FS_BACKUP_DIR') ? (string) FS_BACKUP_DIR : null;
        $candidates = self::backup_dir_candidates($fsFolder, $homeDir);

        $usable = self::resolve_first_usable_dir($candidates);
        if ($usable !== null) {
            return $usable;
        }

        // Explicit override that is unusable → keep it so the constructor
        // surfaces a loud error (operator asked for this exact path).
        if ($override !== null && trim($override) !== '') {
            return $candidates[0];
        }

        // Last resort: the protected legacy dir inside the webroot. It may
        // not be creatable either (e.g. read-only webroot) — the constructor
        // will surface that error.
        return $candidates[count($candidates) - 1];
    }

    /**
     * Check whether a directory exists and is writable, or can be created.
     *
     * @param string $dir
     * @return bool
     */
    private static function is_dir_usable(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        return @mkdir($dir, 0700, true) === true;
    }

    /**
     * Create the backup directory if it doesn't exist.
     *
     * Newly-created directories use restrictive 0700 permissions
     * (defence in depth: even if a sibling directory is readable by other
     * users on the host, the backup files inside are not).  For pre-existing
     * directories we only check writability — we do NOT silently chmod them,
     * because that would mask real ownership/perms problems the operator needs
     * to see.
     *
     * The web-access guards (.htaccess + index.php) are written IDEMPOTENTLY
     * on every construction, so they also protect pre-existing directories
     * (e.g. a legacy fallback dir from an older version). The .htaccess uses
     * BOTH the Apache 2.4 `Require all denied` syntax and the legacy
     * `Order/Deny` form so it works on every Apache version. A failure to
     * write these guards is surfaced as a hard error: when the backup dir
     * lives inside the webroot they are the only Apache-side barrier against
     * direct download.
     *
     * Surfaces a loud error when the directory is not writable so the
     * operator notices the misconfiguration immediately instead of
     * receiving confusing "permission denied" mid-backup.
     */
    private function ensureBackupDirectoryExists()
    {
        if (!is_dir($this->backupPath)) {
            if (!@mkdir($this->backupPath, 0700, true)) {
                $this->errors[] = "No se puede crear el directorio de copias de seguridad: " . $this->backupPath;
                return;
            }
        }

        // Defence in depth: (re)write the guards on every run so pre-existing
        // dirs get the reinforced version too.
        $htaccess = $this->backupPath . '/.htaccess';
        $htaccessOk = @file_put_contents(
            $htaccess,
            "# Deny all web access to backup files (Apache 2.4 + legacy syntax)\n"
            . "Require all denied\n"
            . "Order Deny,Allow\n"
            . "Deny from all\n"
        ) !== false;
        $index = $this->backupPath . '/index.php';
        $indexOk = @file_put_contents(
            $index,
            "<?php\n// No directory listing\nheader('HTTP/1.0 403 Forbidden');\nexit;\n"
        ) !== false;

        if (!$htaccessOk || !$indexOk) {
            $this->errors[] = "No se pudieron crear los archivos de protección en " . $this->backupPath;
        }

        if (!is_writable($this->backupPath)) {
            $this->errors[] = "El directorio de copias de seguridad no es escribible: " . $this->backupPath
                . " (permisos=" . substr(sprintf('%o', (int) @fileperms($this->backupPath)), -4) . ")";
        }
    }

    /**
     * Best-effort migration from legacy fixed-name backup dirs.
     *
     * Older versions stored backups in `FS_FOLDER/backups` (fixed name inside
     * the webroot). When the effective dir is writable and a legacy dir still
     * holds files, move them over (copy + size verify + delete source) so the
     * operator never has to do it manually. Never fatal: on any failure we
     * leave the legacy dir in place and keep going.
     */
    private function migrateLegacyBackups()
    {
        $active = rtrim($this->backupPath, '/\\') . DIRECTORY_SEPARATOR;
        $activeBasename = basename($this->backupPath);

        $candidates = array($this->fsRoot . DIRECTORY_SEPARATOR . 'backups');
        // Also any suffixed dir from older versions (backups-<hex>).
        foreach ((array) glob($this->fsRoot . DIRECTORY_SEPARATOR . 'backups-*') as $other) {
            if (basename($other) !== $activeBasename) {
                $candidates[] = $other;
            }
        }
        foreach ((array) glob(dirname($this->fsRoot) . DIRECTORY_SEPARATOR . 'backups-*') as $other) {
            if (basename($other) !== $activeBasename) {
                $candidates[] = $other;
            }
        }

        foreach (array_unique($candidates) as $legacy) {
            if (!is_dir($legacy) || rtrim($legacy, '/\\') . DIRECTORY_SEPARATOR === $active) {
                continue;
            }
            $entries = array_diff((array) scandir($legacy), array('.', '..'));
            if (!$entries) {
                continue; // nothing to migrate
            }

            $moved = 0;
            $failed = false;
            foreach ($entries as $entry) {
                $src = $legacy . DIRECTORY_SEPARATOR . $entry;
                if (!is_file($src)) {
                    continue;
                }
                $dst = $this->backupPath . DIRECTORY_SEPARATOR . $entry;
                if (is_file($dst)) {
                    continue; // already present
                }
                if (!@copy($src, $dst)) {
                    $failed = true;
                    continue;
                }
                if ((int) @filesize($src) !== (int) @filesize($dst)) {
                    $failed = true;
                    @unlink($dst);
                    continue;
                }
                @unlink($src);
                $moved++;
            }

            if ($moved > 0 && !$failed) {
                $this->messages[] = "Copias de seguridad migradas desde " . $legacy . " a " . $this->backupPath;
            }
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
        $databaseType = $this->get_database_type();

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
            'database_type' => $databaseType,
            'database_port' => $this->get_database_port($databaseType),
            'plugins' => $plugins,
            'created_at' => date('Y-m-d H:i:s'),
            'timestamp' => time(),
        );
    }

    /**
     * Normaliza el tipo de base de datos a un valor consistente.
     *
     * @param string $dbType
     * @return string
     */
    private function normalize_database_type($dbType)
    {
        $normalized = strtoupper(trim((string) $dbType));

        if (in_array($normalized, array('POSTGRES', 'POSTGRESQL', 'PGSQL'), true)) {
            return 'POSTGRESQL';
        }

        if (in_array($normalized, array('MYSQL', 'MARIADB'), true)) {
            return 'MYSQL';
        }

        return $normalized ?: 'MYSQL';
    }

    /**
     * Devuelve el tipo de base de datos actual.
     *
     * @return string
     */
    private function get_database_type()
    {
        return $this->normalize_database_type(defined('FS_DB_TYPE') ? FS_DB_TYPE : 'MYSQL');
    }

    /**
     * Devuelve el puerto por defecto según el motor.
     *
     * @param string|null $dbType
     * @return string
     */
    private function get_database_port($dbType = null)
    {
        $normalized = $this->normalize_database_type($dbType ?: $this->get_database_type());

        if (defined('FS_DB_PORT') && FS_DB_PORT !== '') {
            return (string) FS_DB_PORT;
        }

        return $normalized === 'POSTGRESQL' ? '5432' : '3306';
    }

    /**
     * Indica si un backup es compatible con el motor actual.
     *
     * @param string $backupDbType
     * @param string $currentDbType
     * @return bool
     */
    private function is_database_restore_compatible($backupDbType, $currentDbType)
    {
        if ($backupDbType === null || trim((string) $backupDbType) === '') {
            return true;
        }

        $backupDbType = $this->normalize_database_type($backupDbType);
        $currentDbType = $this->normalize_database_type($currentDbType);

        return empty($backupDbType) || $backupDbType === 'UNKNOWN' || $backupDbType === $currentDbType;
    }

    /**
     * Obtiene el motor de base de datos indicado en los metadatos del backup.
     *
     * @param array $metadata
     * @return string
     */
    private function get_backup_database_type_from_metadata($metadata)
    {
        $candidates = array(
            $metadata['source_database']['type'] ?? null,
            $metadata['database_type'] ?? null,
            $metadata['version_info']['database_type'] ?? null,
            $metadata['version_info']['database']['type'] ?? null,
        );

        foreach ($candidates as $candidate) {
            if (!empty($candidate)) {
                return $this->normalize_database_type($candidate);
            }
        }

        return 'UNKNOWN';
    }

    /**
     * Intenta detectar el motor de base de datos a partir del contenido del dump.
     *
     * @param string $backupPath
     * @return string
     */
    private function detect_database_backup_type($backupPath)
    {
        if (!is_file($backupPath) || substr($backupPath, -7) !== '.sql.gz') {
            return 'UNKNOWN';
        }

        $gzFile = @gzopen($backupPath, 'rb');
        if (!$gzFile) {
            return 'UNKNOWN';
        }

        $sample = '';
        $lineCount = 0;
        while (!gzeof($gzFile) && strlen($sample) < 65536 && $lineCount < 200) {
            $line = gzgets($gzFile, 4096);
            if ($line === false) {
                break;
            }

            $sample .= $line;
            $lineCount++;
        }

        gzclose($gzFile);

        if (preg_match('/Source-Database-Type:\s*(MYSQL|MARIADB|POSTGRES|POSTGRESQL|PGSQL)/i', $sample, $matches)) {
            return $this->normalize_database_type($matches[1]);
        }

        if (preg_match('/\b(ENGINE=|AUTO_INCREMENT|LOCK TABLES|UNLOCK TABLES|SET SQL_MODE|FOREIGN_KEY_CHECKS|\/\*!\d+)/i', $sample)
            || strpos($sample, '`') !== false) {
            return 'MYSQL';
        }

        if (preg_match('/\b(SET search_path|CREATE SCHEMA|DROP SCHEMA|ALTER TABLE ONLY|OWNER TO|COPY\s+[^\n]+\s+FROM stdin|SELECT pg_catalog\.setval)/i', $sample)) {
            return 'POSTGRESQL';
        }

        return 'UNKNOWN';
    }

    /**
     * Comprueba si existe un comando del sistema disponible.
     *
     * @param string $command
     * @return bool
     */
    private function shell_command_available($command)
    {
        if (!$this->shell_functions_available()) {
            return false;
        }

        $output = array();
        $returnVar = 0;
        exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $output, $returnVar);
        return $returnVar === 0 && !empty($output);
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
        return $this->create_backup_with_progress($customName, $includePlugins, null);
    }

    /**
     * Create a complete backup (database + files) with progress reporting.
     *
     * @param string $customName Optional custom name for the backup.
     * @param bool $includePlugins Whether to include all plugins (default: true).
     * @param callable|null $progressCallback Callback function($step, $message, $percent) for progress.
     * @return array Results with 'database', 'files', and 'complete' keys.
     */
    public function create_backup_with_progress($customName = '', $includePlugins = true, $progressCallback = null)
    {
        // Helper to call progress callback
        $reportProgress = function ($step, $message, $percent) use ($progressCallback) {
            if ($progressCallback && is_callable($progressCallback)) {
                call_user_func($progressCallback, $step, $message, $percent);
            }
        };

        $timestamp = date('Y-m-d_H-i-s');
        $baseName = $customName ? $customName : $this->resolve_host_slug() . '_' . $timestamp;

        $reportProgress('init', 'Obteniendo información del sistema...', 5);

        // Get version info
        $versionInfo = $this->get_version_info();

        $reportProgress('database_start', 'Iniciando copia de base de datos...', 10);

        // Create database backup first
        $dbResult = $this->create_database_backup_with_progress($baseName . '_db', $progressCallback);

        if (!$dbResult['success']) {
            $reportProgress('error', 'Error en la copia de base de datos', 0);
            return array(
                'database' => $dbResult,
                'files' => array('success' => false),
                'complete' => array('success' => false, 'backup_name' => $baseName)
            );
        }

        $reportProgress('files_start', 'Iniciando copia de archivos...', 50);

        // Create files backup (including plugins)
        $filesResult = $this->create_files_backup_with_progress($baseName . '_files', $includePlugins, $progressCallback);

        $results = array(
            'database' => $dbResult,
            'files' => $filesResult,
            'version_info' => $versionInfo,
        );

        if ($dbResult['success'] && $filesResult['success']) {
            $reportProgress('unify', 'Creando paquete unificado...', 90);

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
                $reportProgress('cleanup', 'Limpiando archivos antiguos...', 95);
            }
        } else {
            $results['complete'] = array('success' => false, 'backup_name' => $baseName);
        }

        // Clean old backups (keep last 5 unified backups)
        $this->clean_old_backups(5);

        $reportProgress('complete', '¡Copia de seguridad completada!', 100);

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
            'database_type' => $versionInfo['database_type'] ?? $this->get_database_type(),
            'source_database' => array(
                'type' => $versionInfo['database_type'] ?? $this->get_database_type(),
                'port' => $versionInfo['database_port'] ?? $this->get_database_port(),
            ),
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

        // Building the unified package re-compresses every file; keep the
        // background phase unbounded just like the files ZIP close.
        @set_time_limit(0);

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
     * Check if shell functions are available on this server.
     * Many shared hosting providers disable these for security.
     *
     * @return bool
     */
    private function shell_functions_available()
    {
        $disabled = explode(',', ini_get('disable_functions'));
        $disabled = array_map('trim', $disabled);

        // Check the essential functions we need
        $required = array('escapeshellarg', 'exec');
        foreach ($required as $func) {
            if (in_array($func, $disabled) || !function_exists($func)) {
                return false;
            }
        }
        return true;
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
        $fileName = ($customName ? $customName : $this->resolve_host_slug() . '_' . $timestamp) . '.sql.gz';
        $filePath = $this->backupPath . DIRECTORY_SEPARATOR . $fileName;

        // Get DB credentials - compatible with older and newer versions
        $dbType = $this->get_database_type();
        $dbHost = defined('FS_DB_HOST') ? FS_DB_HOST : 'localhost';
        $dbPort = $this->get_database_port($dbType);
        $dbUser = defined('FS_DB_USER') ? FS_DB_USER : 'root';
        $dbPass = defined('FS_DB_PASS') ? FS_DB_PASS : '';
        $dbName = defined('FS_DB_NAME') ? FS_DB_NAME : 'facturascripts';

        // Check if shell functions are available
        if (!$this->shell_functions_available()) {
            // Use PHP-native backup method
            if ($dbType === 'POSTGRESQL') {
                $this->errors[] = "El backup nativo de PostgreSQL no está soportado en servidores con funciones shell deshabilitadas.";
                return array('success' => false, 'file' => null, 'error' => 'PostgreSQL native backup not supported');
            }
            return $this->create_database_backup_native($filePath, $fileName, $dbHost, $dbPort, $dbUser, $dbPass, $dbName);
        }

        if ($dbType === 'POSTGRESQL') {
            if (!$this->shell_command_available('pg_dump') || !$this->shell_command_available('gzip')) {
                $this->errors[] = "No se encontraron los comandos pg_dump/gzip necesarios para exportar PostgreSQL.";
                return array('success' => false, 'file' => null, 'error' => 'pg_dump or gzip not available');
            }

            // PostgreSQL backup
            $command = sprintf(
                'pg_dump --host=%s --port=%s --username=%s %s 2>&1 | gzip > %s',
                escapeshellarg($dbHost),
                escapeshellarg($dbPort),
                escapeshellarg($dbUser),
                escapeshellarg($dbName),
                escapeshellarg($filePath)
            );
            putenv('PGPASSWORD=' . $dbPass);
        } else {
            if (!$this->shell_command_available('mysqldump') || !$this->shell_command_available('gzip')) {
                return $this->create_database_backup_native($filePath, $fileName, $dbHost, $dbPort, $dbUser, $dbPass, $dbName);
            }

            // MySQL backup - use --defaults-extra-file to avoid exposing password in process list
            $defaultsFile = $this->create_mysql_defaults_file($dbHost, $dbPort, $dbUser, $dbPass);
            if ($defaultsFile === false) {
                return $this->create_database_backup_native($filePath, $fileName, $dbHost, $dbPort, $dbUser, $dbPass, $dbName);
            }

            $command = sprintf(
                'mysqldump --defaults-extra-file=%s --single-transaction --routines --triggers %s 2>&1 | gzip > %s',
                escapeshellarg($defaultsFile),
                escapeshellarg($dbName),
                escapeshellarg($filePath)
            );
        }

        $output = array();
        $returnVar = 0;
        exec($command, $output, $returnVar);

        // Clean up defaults file if it was created
        if (isset($defaultsFile) && file_exists($defaultsFile)) {
            @unlink($defaultsFile);
        }

        if ($dbType === 'POSTGRESQL') {
            putenv('PGPASSWORD');
        }

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
     * Create a database backup using PHP-native functions (no shell commands).
     * This is a fallback for servers with disabled shell functions.
     *
     * @param string $filePath Full path to the output file
     * @param string $fileName Name of the output file
     * @param string $dbHost Database host
     * @param string $dbPort Database port
     * @param string $dbUser Database username
     * @param string $dbPass Database password
     * @param string $dbName Database name
     * @return array
     */
    private function create_database_backup_native($filePath, $fileName, $dbHost, $dbPort, $dbUser, $dbPass, $dbName)
    {
        // Connect to database
        $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int) $dbPort);
        if ($mysqli->connect_error) {
            $this->errors[] = "Error de conexión a la base de datos: " . $mysqli->connect_error;
            return array('success' => false, 'file' => null, 'error' => $mysqli->connect_error);
        }

        $mysqli->set_charset('utf8mb4');

        // Open gzip file for writing
        $gzFile = gzopen($filePath, 'wb9');
        if (!$gzFile) {
            $this->errors[] = "No se puede crear el archivo de backup comprimido.";
            $mysqli->close();
            return array('success' => false, 'file' => null, 'error' => 'Cannot create gzip file');
        }

        // Write header
        $header = "-- FSFramework Database Backup\n";
        $header .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $header .= "-- Database: " . $dbName . "\n";
        $header .= "-- Source-Database-Type: MYSQL\n";
        $header .= "-- PHP Native Backup (shell functions disabled)\n";
        $header .= "-- --------------------------------------------------------\n\n";
        $header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $header .= "SET AUTOCOMMIT = 0;\n";
        $header .= "START TRANSACTION;\n";
        $header .= "SET time_zone = \"+00:00\";\n";
        $header .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        $header .= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
        $header .= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
        $header .= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
        $header .= "/*!40101 SET NAMES utf8mb4 */;\n\n";
        gzwrite($gzFile, $header);

        // Get all tables
        $tables = array();
        $result = $mysqli->query("SHOW TABLES");
        if (!$result) {
            $this->errors[] = "Error al obtener lista de tablas: " . $mysqli->error;
            gzclose($gzFile);
            $mysqli->close();
            @unlink($filePath);
            return array('success' => false, 'file' => null, 'error' => $mysqli->error);
        }

        while ($row = $result->fetch_array(MYSQLI_NUM)) {
            $tables[] = $row[0];
        }
        $result->free();

        // Process each table
        $tableCount = count($tables);
        foreach ($tables as $i => $table) {
            // Prevent timeout on large databases
            if ($i % 10 === 0) {
                @set_time_limit(300);
            }

            // Get CREATE TABLE statement
            $result = $this->mysqlHelper->showCreateTable($mysqli, $table);
            if ($result) {
                $row = $result->fetch_array(MYSQLI_NUM);
                gzwrite($gzFile, "\n-- --------------------------------------------------------\n");
                gzwrite($gzFile, "-- Table structure for table `{$table}`\n");
                gzwrite($gzFile, "-- --------------------------------------------------------\n\n");
                gzwrite($gzFile, $this->mysqlHelper->buildDropTableStatement($table));
                gzwrite($gzFile, $row[1] . ";\n\n");
                $result->free();
            }

            // Get table data
            $result = $this->mysqlHelper->selectAllFromTable($mysqli, $table, MYSQLI_USE_RESULT);
            if ($result) {
                $columnCount = $result->field_count;
                $hasData = false;
                $rowBuffer = array();
                $bufferSize = 0;
                $maxBufferSize = 1024 * 1024; // 1MB buffer

                while ($row = $result->fetch_array(MYSQLI_NUM)) {
                    if (!$hasData) {
                        gzwrite($gzFile, "-- Dumping data for table `{$table}`\n\n");
                        $hasData = true;
                    }

                    $values = array();
                    for ($j = 0; $j < $columnCount; $j++) {
                        if ($row[$j] === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = "'" . $mysqli->real_escape_string($row[$j]) . "'";
                        }
                    }

                    $insertLine = $this->mysqlHelper->buildInsertValuesStatement($table, $values);
                    $rowBuffer[] = $insertLine;
                    $bufferSize += strlen($insertLine);

                    // Flush buffer when it gets large enough
                    if ($bufferSize >= $maxBufferSize) {
                        gzwrite($gzFile, implode('', $rowBuffer));
                        $rowBuffer = array();
                        $bufferSize = 0;
                    }
                }

                // Flush remaining buffer
                if (!empty($rowBuffer)) {
                    gzwrite($gzFile, implode('', $rowBuffer));
                }

                if ($hasData) {
                    gzwrite($gzFile, "\n");
                }

                $result->free();
            }
        }

        // Write footer
        $footer = "\nSET FOREIGN_KEY_CHECKS = 1;\n";
        $footer .= "COMMIT;\n\n";
        $footer .= "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
        $footer .= "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
        $footer .= "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";
        gzwrite($gzFile, $footer);

        gzclose($gzFile);
        $mysqli->close();

        if (!file_exists($filePath) || filesize($filePath) === 0) {
            $this->errors[] = "Error: El archivo de backup está vacío.";
            return array('success' => false, 'file' => null, 'error' => 'Backup file is empty');
        }

        $this->messages[] = "Copia de base de datos creada (modo nativo): " . $fileName . " ({$tableCount} tablas)";
        return array(
            'success' => true,
            'file' => $fileName,
            'path' => $filePath,
            'size' => filesize($filePath),
            'size_formatted' => $this->format_bytes(filesize($filePath)),
        );
    }

    /**
     * Create a database backup with progress reporting.
     *
     * @param string $customName
     * @param callable|null $progressCallback
     * @return array
     */
    public function create_database_backup_with_progress($customName = '', $progressCallback = null)
    {
        // Helper to call progress callback
        $reportProgress = function ($step, $message, $percent) use ($progressCallback) {
            if ($progressCallback && is_callable($progressCallback)) {
                call_user_func($progressCallback, $step, $message, $percent);
            }
        };

        $timestamp = date('Y-m-d_H-i-s');
        $fileName = ($customName ? $customName : $this->resolve_host_slug() . '_' . $timestamp) . '.sql.gz';
        $filePath = $this->backupPath . DIRECTORY_SEPARATOR . $fileName;

        // Get DB credentials
        $dbType = $this->get_database_type();
        $dbHost = defined('FS_DB_HOST') ? FS_DB_HOST : 'localhost';
        $dbPort = $this->get_database_port($dbType);
        $dbUser = defined('FS_DB_USER') ? FS_DB_USER : 'root';
        $dbPass = defined('FS_DB_PASS') ? FS_DB_PASS : '';
        $dbName = defined('FS_DB_NAME') ? FS_DB_NAME : 'facturascripts';

        if ($dbType === 'POSTGRESQL') {
            $reportProgress('db_export', 'Exportando base de datos PostgreSQL...', 18);

            $result = $this->create_database_backup($customName);
            if (!($result['success'] ?? false)) {
                $reportProgress('db_error', 'No se pudo exportar PostgreSQL', 18);
                return $result;
            }

            $reportProgress('db_complete', 'Backup de PostgreSQL completado', 48);
            return $result;
        }

        $reportProgress('db_connect', 'Conectando a la base de datos...', 12);

        // Connect to database
        $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int) $dbPort);
        if ($mysqli->connect_error) {
            $this->errors[] = "Error de conexión a la base de datos: " . $mysqli->connect_error;
            return array('success' => false, 'file' => null, 'error' => $mysqli->connect_error);
        }

        $mysqli->set_charset('utf8mb4');

        // Open gzip file for writing
        $gzFile = gzopen($filePath, 'wb9');
        if (!$gzFile) {
            $this->errors[] = "No se puede crear el archivo de backup comprimido.";
            $mysqli->close();
            return array('success' => false, 'file' => null, 'error' => 'Cannot create gzip file');
        }

        $reportProgress('db_header', 'Escribiendo encabezado...', 14);

        // Write header
        $header = "-- FSFramework Database Backup\n";
        $header .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $header .= "-- Database: " . $dbName . "\n";
        $header .= "-- Source-Database-Type: MYSQL\n";
        $header .= "-- PHP Native Backup with Progress\n";
        $header .= "-- --------------------------------------------------------\n\n";
        $header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $header .= "SET AUTOCOMMIT = 0;\n";
        $header .= "START TRANSACTION;\n";
        $header .= "SET time_zone = \"+00:00\";\n";
        $header .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        $header .= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
        $header .= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
        $header .= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
        $header .= "/*!40101 SET NAMES utf8mb4 */;\n\n";
        gzwrite($gzFile, $header);

        // Get all tables
        $tables = array();
        $result = $mysqli->query("SHOW TABLES");
        if (!$result) {
            $this->errors[] = "Error al obtener lista de tablas: " . $mysqli->error;
            gzclose($gzFile);
            $mysqli->close();
            @unlink($filePath);
            return array('success' => false, 'file' => null, 'error' => $mysqli->error);
        }

        while ($row = $result->fetch_array(MYSQLI_NUM)) {
            $tables[] = $row[0];
        }
        $result->free();

        $tableCount = count($tables);
        $reportProgress('db_tables', "Encontradas {$tableCount} tablas para respaldar...", 15);

        // Process each table (progress from 15% to 45%)
        foreach ($tables as $i => $table) {
            $tableProgress = 15 + (($i / max(1, $tableCount)) * 30);
            $reportProgress('db_table', "Tabla {$table} (" . ($i + 1) . "/{$tableCount})...", intval($tableProgress));

            // Prevent timeout
            @set_time_limit(300);

            // Get CREATE TABLE statement
            $result = $this->mysqlHelper->showCreateTable($mysqli, $table);
            if ($result) {
                $row = $result->fetch_array(MYSQLI_NUM);
                gzwrite($gzFile, "\n-- --------------------------------------------------------\n");
                gzwrite($gzFile, "-- Table structure for table `{$table}`\n");
                gzwrite($gzFile, "-- --------------------------------------------------------\n\n");
                gzwrite($gzFile, $this->mysqlHelper->buildDropTableStatement($table));
                gzwrite($gzFile, $row[1] . ";\n\n");
                $result->free();
            }

            // Get table data
            $result = $this->mysqlHelper->selectAllFromTable($mysqli, $table, MYSQLI_USE_RESULT);
            if ($result) {
                $columnCount = $result->field_count;
                $hasData = false;
                $rowBuffer = array();
                $bufferSize = 0;
                $maxBufferSize = 1024 * 1024; // 1MB buffer

                while ($row = $result->fetch_array(MYSQLI_NUM)) {
                    if (!$hasData) {
                        gzwrite($gzFile, "-- Dumping data for table `{$table}`\n\n");
                        $hasData = true;
                    }

                    $values = array();
                    for ($j = 0; $j < $columnCount; $j++) {
                        if ($row[$j] === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = "'" . $mysqli->real_escape_string($row[$j]) . "'";
                        }
                    }

                    $insertLine = $this->mysqlHelper->buildInsertValuesStatement($table, $values);
                    $rowBuffer[] = $insertLine;
                    $bufferSize += strlen($insertLine);

                    // Flush buffer when it gets large enough
                    if ($bufferSize >= $maxBufferSize) {
                        gzwrite($gzFile, implode('', $rowBuffer));
                        $rowBuffer = array();
                        $bufferSize = 0;
                    }
                }

                // Flush remaining buffer
                if (!empty($rowBuffer)) {
                    gzwrite($gzFile, implode('', $rowBuffer));
                }

                if ($hasData) {
                    gzwrite($gzFile, "\n");
                }

                $result->free();
            }
        }

        $reportProgress('db_footer', 'Finalizando backup de base de datos...', 47);

        // Write footer
        $footer = "\nSET FOREIGN_KEY_CHECKS = 1;\n";
        $footer .= "COMMIT;\n\n";
        $footer .= "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
        $footer .= "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
        $footer .= "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";
        gzwrite($gzFile, $footer);

        gzclose($gzFile);
        $mysqli->close();

        if (!file_exists($filePath) || filesize($filePath) === 0) {
            $this->errors[] = "Error: El archivo de backup está vacío.";
            return array('success' => false, 'file' => null, 'error' => 'Backup file is empty');
        }

        $reportProgress('db_complete', 'Backup de base de datos completado', 48);

        $this->messages[] = "Copia de base de datos creada: " . $fileName . " ({$tableCount} tablas)";
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
        $fileName = ($customName ? $customName : $this->resolve_host_slug() . '_' . $timestamp) . '.zip';
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
     * Create a files backup with progress reporting.
     *
     * @param string $customName
     * @param bool $includePlugins Whether to include plugins folder
     * @param callable|null $progressCallback
     * @return array
     */
    public function create_files_backup_with_progress($customName = '', $includePlugins = true, $progressCallback = null)
    {
        // Helper to call progress callback
        $reportProgress = function ($step, $message, $percent) use ($progressCallback) {
            if ($progressCallback && is_callable($progressCallback)) {
                call_user_func($progressCallback, $step, $message, $percent);
            }
        };

        if (!extension_loaded('zip')) {
            $this->errors[] = "La extensión PHP ZIP no está instalada.";
            return array('success' => false, 'file' => null, 'error' => 'ZIP extension not loaded');
        }

        $timestamp = date('Y-m-d_H-i-s');
        $fileName = ($customName ? $customName : $this->resolve_host_slug() . '_' . $timestamp) . '.zip';
        $filePath = $this->backupPath . DIRECTORY_SEPARATOR . $fileName;

        $reportProgress('files_init', 'Preparando backup de archivos...', 52);

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

        $reportProgress('files_scan', 'Escaneando archivos...', 54);

        // Count files first for progress reporting
        $allFiles = $this->get_files_to_backup();
        $totalFiles = count($allFiles);

        $reportProgress('files_count', "Encontrados {$totalFiles} archivos para respaldar...", 55);

        // Add files with progress (progress from 55% to 88%)
        $fileCount = 0;
        foreach ($allFiles as $i => $fileInfo) {
            $filePath = $fileInfo['path'];
            $relPath = $fileInfo['rel_path'];

            $zip->addFile($filePath, $relPath);
            $fileCount++;

            // Report progress every 100 files
            if ($fileCount % 100 === 0 || $fileCount === $totalFiles) {
                $filesProgress = 55 + (($fileCount / max(1, $totalFiles)) * 33);
                $reportProgress('files_progress', "Archivos procesados: {$fileCount}/{$totalFiles}", intval($filesProgress));
                @set_time_limit(0);
            }
        }

        // Remove temporary exclusion
        if (!$includePlugins) {
            $key = array_search('plugins', $this->excludedDirs);
            if ($key !== false) {
                unset($this->excludedDirs[$key]);
            }
        }

        $reportProgress('files_close', 'Finalizando archivo ZIP...', 88);

        // The detached finalization (ZipArchive::close) must not be bounded by
        // max_execution_time: the SSE response is already closed and the client
        // polls for the terminal state.
        @set_time_limit(0);

        if (!$zip->close()) {
            $this->errors[] = "Error al cerrar el archivo ZIP.";
            return array('success' => false, 'file' => null, 'error' => 'ZipArchive close failed');
        }

        if ($fileCount === 0) {
            $this->errors[] = "No se añadieron archivos al backup.";
            @unlink($filePath);
            return array('success' => false, 'file' => null, 'error' => 'No files added');
        }

        $reportProgress('files_complete', 'Backup de archivos completado', 89);

        $this->messages[] = "Copia de archivos creada: " . $fileName . " (" . $fileCount . " archivos)";
        return array(
            'success' => true,
            'file' => $fileName,
            'path' => $this->backupPath . DIRECTORY_SEPARATOR . $fileName,
            'size' => filesize($this->backupPath . DIRECTORY_SEPARATOR . $fileName),
            'size_formatted' => $this->format_bytes(filesize($this->backupPath . DIRECTORY_SEPARATOR . $fileName)),
            'file_count' => $fileCount,
        );
    }

    /**
     * Get list of files to backup (for progress calculation).
     *
     * @return array Array of ['path' => fullPath, 'rel_path' => relativePath]
     */
    private function get_files_to_backup()
    {
        $files = array();
        $sourceDir = rtrim($this->fsRoot, DIRECTORY_SEPARATOR);

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

            $files[] = array(
                'path' => $filePath,
                'rel_path' => $relPath,
            );
        }

        return $files;
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
                @set_time_limit(0);
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
     * @param callable|null $progressCallback Optional callback function($step, $message, $percent) for progress updates
     * @return array Result with success status
     */
    public function restore_complete($backupFile, $progressCallback = null)
    {
        $results = array(
            'success' => false,
            'files' => null,
            'database' => null,
        );

        // Helper to call progress callback
        $reportProgress = function ($step, $message, $percent) use ($progressCallback) {
            if ($progressCallback && is_callable($progressCallback)) {
                call_user_func($progressCallback, $step, $message, $percent);
            }
        };

        $reportProgress('start', 'Iniciando restauración completa...', 0);

        // Get full path
        $backupPath = $this->get_backup_file_path($backupFile);
        if (!$backupPath) {
            $this->errors[] = "Archivo de backup no encontrado: " . $backupFile;
            $reportProgress('error', 'Archivo de backup no encontrado', 0);
            return $results;
        }

        $reportProgress('extract', 'Extrayendo paquete de backup...', 5);

        // Extract unified package to temp dir
        $tempDir = $this->backupPath . '/temp_restore_' . time();
        if (!$this->extract_unified_package($backupPath, $tempDir, $progressCallback)) {
            $reportProgress('error', 'Error al extraer el paquete', 0);
            return $results;
        }

        $reportProgress('extract_done', 'Paquete extraído correctamente', 20);

        // Find the database and files backups inside
        $metadata = $this->read_package_metadata($tempDir);
        $dbBackup = $tempDir . '/database/' . ($metadata['database_file'] ?? '');
        if (!file_exists($dbBackup)) {
            $dbBackup = false;
            $dbDir = $tempDir . '/database';
            if (is_dir($dbDir)) {
                foreach (scandir($dbDir) as $f) {
                    if (substr($f, -7) === '.sql.gz') {
                        $dbBackup = $dbDir . '/' . $f;
                        break;
                    }
                }
            }
        }

        $backupDbType = $this->get_backup_database_type_from_metadata($metadata);
        if ($backupDbType === 'UNKNOWN' && $dbBackup) {
            $backupDbType = $this->detect_database_backup_type($dbBackup);
        }

        $currentDbType = $this->get_database_type();
        if (!$this->is_database_restore_compatible($backupDbType, $currentDbType)) {
            $this->errors[] = 'El backup fue generado para ' . $backupDbType . ' y la instalación actual usa ' . $currentDbType . '. La restauración de base de datos entre motores distintos no es compatible.';
            $this->delete_directory($tempDir);
            $reportProgress('error', 'Backup incompatible con el motor actual', 20);
            return $results;
        }

        // Restore files first
        $reportProgress('files', 'Preparando restauración de archivos...', 25);
        $filesBackup = $tempDir . '/files/' . ($metadata['files_file'] ?? '');
        if (file_exists($filesBackup)) {
            $results['files'] = $this->restore_files($filesBackup, $progressCallback);
        } else {
            // Try to find any zip file in files folder
            $filesDir = $tempDir . '/files';
            if (is_dir($filesDir)) {
                foreach (scandir($filesDir) as $f) {
                    if (substr($f, -4) === '.zip') {
                        $results['files'] = $this->restore_files($filesDir . '/' . $f, $progressCallback);
                        break;
                    }
                }
            }
        }

        if (!($results['files']['success'] ?? false)) {
            $reportProgress('error', 'Error al restaurar archivos', 50);
        } else {
            $reportProgress('files_done', 'Archivos restaurados correctamente', 50);
        }

        // Then restore database
        $reportProgress('database', 'Preparando restauración de base de datos...', 55);
        if ($dbBackup && file_exists($dbBackup)) {
            $results['database'] = $this->restore_database($dbBackup, $progressCallback);
        } else {
            // Try to find any sql.gz file in database folder
            $dbDir = $tempDir . '/database';
            if (is_dir($dbDir)) {
                foreach (scandir($dbDir) as $f) {
                    if (substr($f, -7) === '.sql.gz') {
                        $results['database'] = $this->restore_database($dbDir . '/' . $f, $progressCallback);
                        break;
                    }
                }
            }
        }

        if (!($results['database']['success'] ?? false)) {
            $reportProgress('error', 'Error al restaurar base de datos', 95);
        } else {
            $reportProgress('database_done', 'Base de datos restaurada correctamente', 95);
        }

        // Clean up temp dir
        $reportProgress('cleanup', 'Limpiando archivos temporales...', 98);
        $this->delete_directory($tempDir);

        $results['success'] = (
            ($results['files']['success'] ?? false) &&
            ($results['database']['success'] ?? false)
        );

        if ($results['success']) {
            $this->messages[] = "Restauración completa realizada correctamente.";
            $reportProgress('complete', '¡Restauración completada con éxito!', 100);
        } else {
            $reportProgress('error', 'La restauración no se completó correctamente', 100);
        }

        return $results;
    }

    /**
     * Restore only files from a backup.
     *
     * @param string $backupFile The files backup (zip) or unified backup
     * @param callable|null $progressCallback Optional callback function($step, $message, $percent) for progress updates
     * @return array Result with success status
     */
    public function restore_files($backupFile, $progressCallback = null)
    {
        $result = array('success' => false);

        // Helper to call progress callback
        $reportProgress = function ($step, $message, $percent) use ($progressCallback) {
            if ($progressCallback && is_callable($progressCallback)) {
                call_user_func($progressCallback, $step, $message, $percent);
            }
        };

        $reportProgress('files_start', 'Preparando archivos para restauración...', 25);

        // Get full path
        $backupPath = $this->get_backup_file_path($backupFile);
        if (!$backupPath) {
            $this->errors[] = "Archivo de backup de archivos no encontrado: " . $backupFile;
            $reportProgress('files_error', 'Archivo de backup no encontrado', 25);
            return $result;
        }

        if (!extension_loaded('zip')) {
            $this->errors[] = "La extensión PHP ZIP no está instalada.";
            $reportProgress('files_error', 'Extensión ZIP no disponible', 25);
            return $result;
        }

        $reportProgress('files_extract', 'Extrayendo archivos del backup...', 28);

        $zip = new ZipArchive();
        if ($zip->open($backupPath) !== true) {
            $this->errors[] = "No se puede abrir el archivo ZIP: " . $backupFile;
            $reportProgress('files_error', 'No se puede abrir el archivo ZIP', 28);
            return $result;
        }

        // Extract to temp folder first
        $tempDir = $this->backupPath . '/temp_files_' . time();
        if (!@mkdir($tempDir, 0755, true)) {
            $this->errors[] = "No se puede crear directorio temporal.";
            $zip->close();
            $reportProgress('files_error', 'Error al crear directorio temporal', 28);
            return $result;
        }

        if (!$zip->extractTo($tempDir)) {
            $this->errors[] = "Error al extraer el archivo ZIP.";
            $zip->close();
            $this->delete_directory($tempDir);
            $reportProgress('files_error', 'Error al extraer archivos', 30);
            return $result;
        }
        $zip->close();

        $reportProgress('files_copy', 'Copiando archivos al sistema...', 30);

        // Copy files to fsRoot, excluding config.php to preserve server-specific settings
        $excludeFromRestore = array('config.php');
        $this->copy_directory_with_progress($tempDir, $this->fsRoot, $excludeFromRestore, $progressCallback, 30, 48);

        $reportProgress('files_cleanup', 'Limpiando archivos temporales...', 48);

        // Clean up
        $this->delete_directory($tempDir);

        $result['success'] = true;
        $this->messages[] = "Archivos restaurados correctamente desde: " . basename($backupFile);
        $reportProgress('files_done', 'Archivos restaurados correctamente', 50);

        return $result;
    }

    /**
     * Resolve a database backup from a standalone dump or a unified package.
     *
     * @param string $backupFile Backup filename or path (.sql.gz or _complete.zip)
     * @return array{path: string, temp_dir: string|null}|false
     */
    public function resolve_database_backup_source($backupFile)
    {
        $backupPath = $this->get_backup_file_path($backupFile);
        if (!$backupPath) {
            $this->errors[] = "Archivo de backup no encontrado: " . $backupFile;
            return false;
        }

        if (substr($backupPath, -7) === '.sql.gz') {
            return array(
                'path' => $backupPath,
                'temp_dir' => null,
            );
        }

        if (strpos(basename($backupPath), '_complete.zip') === false) {
            $this->errors[] = "El archivo no es un backup de base de datos válido: " . $backupFile;
            return false;
        }

        $tempDir = $this->backupPath . '/temp_db_restore_' . time();
        if (!$this->extract_unified_package($backupPath, $tempDir)) {
            $this->delete_directory($tempDir);
            return false;
        }

        $metadata = $this->read_package_metadata($tempDir);
        $dbBackup = $tempDir . '/database/' . ($metadata['database_file'] ?? '');
        if (!file_exists($dbBackup)) {
            $dbBackup = false;
            $dbDir = $tempDir . '/database';
            if (is_dir($dbDir)) {
                foreach (scandir($dbDir) as $f) {
                    if (substr($f, -7) === '.sql.gz') {
                        $dbBackup = $dbDir . '/' . $f;
                        break;
                    }
                }
            }
        }

        if (!$dbBackup || !file_exists($dbBackup)) {
            $this->errors[] = "No se encontró backup de base de datos dentro del paquete: " . $backupFile;
            $this->delete_directory($tempDir);
            return false;
        }

        return array(
            'path' => $dbBackup,
            'temp_dir' => $tempDir,
        );
    }

    /**
     * Restore only database from a backup.
     * First drops all tables to ensure a clean restore.
     *
     * @param string $backupFile The database backup (.sql.gz or _complete.zip)
     * @param callable|null $progressCallback Optional callback function($step, $message, $percent) for progress updates
     * @return array Result with success status
     */
    public function restore_database($backupFile, $progressCallback = null)
    {
        $result = array('success' => false);
        $tempDir = null;

        $reportProgress = function ($step, $message, $percent) use ($progressCallback) {
            if ($progressCallback && is_callable($progressCallback)) {
                call_user_func($progressCallback, $step, $message, $percent);
            }
        };

        $reportProgress('db_start', 'Preparando restauración de base de datos...', 55);

        $resolved = $this->resolve_database_backup_source($backupFile);
        if (!$resolved) {
            $reportProgress('db_error', 'Archivo de backup no encontrado o inválido', 55);
            return $result;
        }

        $backupPath = $resolved['path'];
        $tempDir = $resolved['temp_dir'];

        try {
            return $this->execute_database_restore($backupPath, $reportProgress, $result);
        } finally {
            if ($tempDir) {
                $this->delete_directory($tempDir);
            }
        }
    }

    /**
     * Execute database restore from a resolved SQL dump path.
     *
     * @param string $backupPath
     * @param callable $reportProgress
     * @param array $result
     * @return array
     */
    private function execute_database_restore($backupPath, $reportProgress, $result)
    {
        $dbHost = defined('FS_DB_HOST') ? FS_DB_HOST : 'localhost';
        $dbType = $this->get_database_type();
        $dbPort = $this->get_database_port($dbType);
        $dbUser = defined('FS_DB_USER') ? FS_DB_USER : 'root';
        $dbPass = defined('FS_DB_PASS') ? FS_DB_PASS : '';
        $dbName = defined('FS_DB_NAME') ? FS_DB_NAME : 'facturascripts';

        $backupDbType = $this->detect_database_backup_type($backupPath);
        if (!$this->is_database_restore_compatible($backupDbType, $dbType)) {
            $this->errors[] = 'El backup de base de datos fue generado para ' . $backupDbType . ' y la instalación actual usa ' . $dbType . '. La importación/exportación entre MySQL y PostgreSQL no es compatible de forma directa.';
            $reportProgress('db_error', 'Backup incompatible con el motor actual', 55);
            return $result;
        }

        if ($dbType === 'POSTGRESQL') {
            if (!$this->shell_functions_available()) {
                $this->errors[] = "La restauración de PostgreSQL no está soportada en servidores con funciones shell deshabilitadas.";
                $reportProgress('db_error', 'PostgreSQL no soportado sin funciones shell', 55);
                return $result;
            }
            return $this->restore_database_postgresql($backupPath, $dbHost, $dbPort, $dbUser, $dbPass, $dbName, $reportProgress, $result);
        }

        // MySQL: Limpiar completamente la base de datos primero
        $reportProgress('db_clean', 'Limpiando base de datos actual...', 60);

        // Conectar a MySQL para limpiar la base de datos
        $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
        if ($mysqli->connect_error) {
            $this->errors[] = "Error de conexión: " . $mysqli->connect_error;
            $reportProgress('db_error', 'Error de conexión a la base de datos', 60);
            return $result;
        }

        // Desactivar foreign key checks temporalmente
        $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");

        // =====================================================================
        // FASE 1: Backup temporal de usuarios actuales (para seguridad)
        // Esto permite restaurar los usuarios si algo falla, manteniendo
        // acceso a recovery.php
        // =====================================================================
        $reportProgress('db_backup_users', 'Respaldando usuarios actuales (para seguridad)...', 58);
        $usersBackedUp = $this->backup_users_to_temp($mysqli);

        if ($usersBackedUp) {
            $reportProgress('db_backup_users_done', 'Usuarios respaldados en tabla temporal', 59);
        }

        // Obtener todas las tablas
        $tables = array();
        $res = $mysqli->query("SHOW TABLES");
        if ($res) {
            while ($row = $res->fetch_array(MYSQLI_NUM)) {
                // Excluir tablas temporales de backup
                if (strpos($row[0], '_backup_temp') === false) {
                    $tables[] = $row[0];
                }
            }
            $res->free();
        }

        // =====================================================================
        // FASE 2: Eliminar TODAS las tablas (incluidas las de usuarios)
        // Los usuarios se restaurarán desde el backup importado
        // =====================================================================
        $totalTablesToDrop = count($tables);

        if ($totalTablesToDrop > 0) {
            $reportProgress('db_drop', "Eliminando {$totalTablesToDrop} tablas...", 62);

            foreach ($tables as $i => $table) {
                $this->mysqlHelper->dropTableIfExists($mysqli, $table);

                if ($i % 10 === 0) {
                    $pct = 62 + (($i / $totalTablesToDrop) * 3);
                    $reportProgress('db_drop_progress', "Eliminando tablas... ({$i} de {$totalTablesToDrop})", intval($pct));
                }
            }
        } else {
            $reportProgress('db_drop', "No hay tablas para eliminar", 65);
        }

        // Reactivar foreign key checks
        $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");

        // Mantener conexión abierta para posible recuperación
        $reportProgress('db_import', 'Importando datos del backup (incluye usuarios)...', 65);

        // =====================================================================
        // FASE 2 (continuación): Importar backup completo
        // Si falla, restaurar usuarios desde backup temporal
        // =====================================================================
        $importSuccess = false;
        $importError = null;

        // Check if shell functions are available
        $shellRestoreAttempted = false;
        if ($this->shell_functions_available()) {
            // Use shell command for faster restore
            // Use --defaults-extra-file to avoid exposing password in process list
            $defaultsFile = $this->create_mysql_defaults_file($dbHost, $dbPort, $dbUser, $dbPass);
            if ($defaultsFile !== false) {
                $shellRestoreAttempted = true;
                $command = sprintf(
                    'gunzip -c %s | mysql --defaults-extra-file=%s %s 2>&1',
                    escapeshellarg($backupPath),
                    escapeshellarg($defaultsFile),
                    escapeshellarg($dbName)
                );

                $output = array();
                $returnVar = 0;
                exec($command, $output, $returnVar);

                // Clean up defaults file
                if (file_exists($defaultsFile)) {
                    @unlink($defaultsFile);
                }

                if ($returnVar !== 0) {
                    $importError = implode("\n", $output);
                } else {
                    $importSuccess = true;
                }
            }
        }
        
        // Fallback to native restore if shell restore was not attempted or failed
        if (!$importSuccess) {
            // Use PHP-native restore method
            $restoreResult = $this->restore_database_native($backupPath, $dbHost, $dbPort, $dbUser, $dbPass, $dbName, $reportProgress);
            if ($restoreResult['success']) {
                $importSuccess = true;
                $importError = null;
            } else {
                $importError = 'Error en restauración nativa PHP';
            }
        }

        // =====================================================================
        // FASE 3: Manejo de errores - restaurar usuarios si falló
        // =====================================================================
        if (!$importSuccess) {
            $reportProgress('db_error_recovery', 'Error en importación - Recuperando usuarios...', 80);

            // Reconectar si es necesario
            if (!$mysqli->ping()) {
                $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
            }

            // Restaurar usuarios desde backup temporal
            if ($this->restore_users_from_temp($mysqli)) {
                $this->errors[] = "Error al restaurar la base de datos: " . $importError . ". Los usuarios actuales han sido recuperados.";
                $reportProgress('db_error', 'Error en restauración - Usuarios recuperados para acceso a recovery.php', 90);
            } else {
                $this->errors[] = "Error crítico al restaurar: " . $importError;
                $reportProgress('db_error', 'Error crítico: ' . $importError, 90);
            }

            $mysqli->close();
            return $result;
        }

        // Restauración exitosa - limpiar tablas temporales
        $reportProgress('db_cleanup', 'Limpiando datos temporales...', 92);

        // Reconectar si es necesario
        if (!$mysqli->ping()) {
            $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
        }

        $this->cleanup_temp_users($mysqli);
        $mysqli->close();

        $reportProgress('db_verify', 'Verificando restauración...', 93);

        $result['success'] = true;
        $this->messages[] = "Base de datos restaurada correctamente (incluidos usuarios)";
        $reportProgress('db_done', '¡Base de datos restaurada correctamente!', 95);

        return $result;
    }

    /**
     * Restore database using PHP-native functions (no shell commands).
     * This is a fallback for servers with disabled shell functions.
     *
     * @param string $backupPath Path to the backup file (.sql.gz)
     * @param string $dbHost Database host
     * @param string $dbPort Database port
     * @param string $dbUser Database username
     * @param string $dbPass Database password
     * @param string $dbName Database name
     * @param callable $reportProgress Progress callback
     * @return array
     */
    private function restore_database_native($backupPath, $dbHost, $dbPort, $dbUser, $dbPass, $dbName, $reportProgress)
    {
        $result = array('success' => false);

        // Connect to database
        $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int) $dbPort);
        if ($mysqli->connect_error) {
            $this->errors[] = "Error de conexión a la base de datos: " . $mysqli->connect_error;
            $reportProgress('db_error', 'Error de conexión', 70);
            return $result;
        }

        $mysqli->set_charset('utf8mb4');
        $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
        $mysqli->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

        // Open gzip file for reading
        $gzFile = gzopen($backupPath, 'rb');
        if (!$gzFile) {
            $this->errors[] = "No se puede abrir el archivo de backup.";
            $mysqli->close();
            $reportProgress('db_error', 'No se puede abrir el backup', 70);
            return $result;
        }

        $currentStatement = '';
        $lineCount = 0;
        $statementCount = 0;
        $delimiter = ';';

        $reportProgress('db_import_native', 'Importando SQL (modo nativo PHP)...', 70);

        while (!gzeof($gzFile)) {
            $line = gzgets($gzFile, 65536);
            if ($line === false) {
                break;
            }

            $lineCount++;
            $trimmedLine = trim($line);

            // Skip empty lines and comments
            if ($trimmedLine === '' || strpos($trimmedLine, '--') === 0 || strpos($trimmedLine, '/*') === 0) {
                continue;
            }

            // Handle DELIMITER statements
            if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmedLine, $matches)) {
                $delimiter = trim($matches[1]);
                continue;
            }

            $currentStatement .= $line;

            // Check if statement is complete
            if (substr(rtrim($currentStatement), -strlen($delimiter)) === $delimiter) {
                // Remove delimiter from end
                $sql = substr(rtrim($currentStatement), 0, -strlen($delimiter));
                $currentStatement = '';

                if (trim($sql) !== '') {
                    if (!$mysqli->query($sql)) {
                        // Log error but continue with other statements
                        $this->errors[] = "Error SQL (línea ~{$lineCount}): " . $mysqli->error;
                    }
                    $statementCount++;

                    // Report progress every 100 statements
                    if ($statementCount % 100 === 0) {
                        $pct = min(85, 70 + ($statementCount / 100));
                        $reportProgress('db_import_progress', "Importando... ({$statementCount} sentencias)", intval($pct));
                        @set_time_limit(300);
                    }
                }
            }
        }

        // Process any remaining statement
        if (trim($currentStatement) !== '') {
            $sql = trim($currentStatement);
            if (substr($sql, -1) === ';') {
                $sql = substr($sql, 0, -1);
            }
            if ($sql !== '') {
                $mysqli->query($sql);
                $statementCount++;
            }
        }

        gzclose($gzFile);

        $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
        $mysqli->close();

        $reportProgress('db_import_done', "Importación completada ({$statementCount} sentencias)", 88);

        $result['success'] = true;
        $this->messages[] = "Base de datos restaurada (modo nativo): {$statementCount} sentencias ejecutadas";

        return $result;
    }

    /**
     * Restore PostgreSQL database.
     */
    private function restore_database_postgresql($backupPath, $dbHost, $dbPort, $dbUser, $dbPass, $dbName, $reportProgress, $result)
    {
        if (!$this->shell_command_available('psql') || !$this->shell_command_available('gunzip')) {
            $this->errors[] = 'No se encontraron los comandos psql/gunzip necesarios para restaurar PostgreSQL.';
            $reportProgress('db_error', 'psql o gunzip no disponibles', 60);
            return $result;
        }

        $reportProgress('db_clean', 'Limpiando base de datos PostgreSQL...', 60);

        // Limpiar todas las tablas en PostgreSQL
        $command = sprintf(
            'PGPASSWORD=%s psql --host=%s --port=%s --username=%s -d %s -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public;" 2>&1',
            escapeshellarg($dbPass),
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            escapeshellarg($dbName)
        );

        $cleanOutput = array();
        $cleanReturnVar = 0;
        exec($command, $cleanOutput, $cleanReturnVar);

        if ($cleanReturnVar !== 0) {
            $this->errors[] = 'Error al limpiar PostgreSQL antes de restaurar: ' . implode("\n", $cleanOutput);
            $reportProgress('db_error', 'Error limpiando la base de datos PostgreSQL', 60);
            return $result;
        }

        $reportProgress('db_import', 'Importando backup PostgreSQL...', 65);

        $command = sprintf(
            'gunzip -c %s | PGPASSWORD=%s psql --host=%s --port=%s --username=%s -d %s 2>&1',
            escapeshellarg($backupPath),
            escapeshellarg($dbPass),
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            escapeshellarg($dbName)
        );

        $output = array();
        $returnVar = 0;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            $this->errors[] = "Error al restaurar PostgreSQL: " . implode("\n", $output);
            $reportProgress('db_error', 'Error: ' . implode("\n", $output), 90);
            return $result;
        }

        $result['success'] = true;
        $this->messages[] = "PostgreSQL restaurado correctamente";
        $reportProgress('db_done', '¡Base de datos restaurada!', 95);

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
     * @param callable|null $progressCallback Optional callback for progress
     * @return bool
     */
    private function extract_unified_package($packagePath, $extractTo, $progressCallback = null)
    {
        // Helper to call progress callback
        $reportProgress = function ($step, $message, $percent) use ($progressCallback) {
            if ($progressCallback && is_callable($progressCallback)) {
                call_user_func($progressCallback, $step, $message, $percent);
            }
        };

        if (!extension_loaded('zip')) {
            $this->errors[] = "La extensión PHP ZIP no está instalada.";
            $reportProgress('extract_error', 'Extensión ZIP no disponible', 5);
            return false;
        }

        $reportProgress('extract_open', 'Abriendo archivo de backup...', 6);

        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) {
            $this->errors[] = "No se puede abrir el paquete: " . basename($packagePath);
            $reportProgress('extract_error', 'No se puede abrir el paquete', 6);
            return false;
        }

        $numFiles = $zip->numFiles;
        $reportProgress('extract_init', "Extrayendo {$numFiles} elementos...", 8);

        if (!@mkdir($extractTo, 0755, true)) {
            $this->errors[] = "No se puede crear directorio de extracción.";
            $zip->close();
            $reportProgress('extract_error', 'Error al crear directorio temporal', 8);
            return false;
        }

        if (!$zip->extractTo($extractTo)) {
            $this->errors[] = "Error al extraer el paquete.";
            $zip->close();
            $reportProgress('extract_error', 'Error al extraer archivos', 15);
            return false;
        }

        $zip->close();
        $reportProgress('extract_complete', 'Extracción completada', 18);
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
     * Copy a directory recursively with progress reporting.
     *
     * @param string $source
     * @param string $dest
     * @param array $excludeFiles Files to skip
     * @param callable|null $progressCallback Optional callback for progress
     * @param int $startPercent Starting percentage for progress
     * @param int $endPercent Ending percentage for progress
     */
    private function copy_directory_with_progress($source, $dest, $excludeFiles = array(), $progressCallback = null, $startPercent = 0, $endPercent = 100)
    {
        $source = rtrim($source, '/\\');
        $dest = rtrim($dest, '/\\');

        // Directories to exclude during restore (in addition to files)
        $excludeDirs = array(
            'backups',              // Never overwrite backups directory
            'plugins/system_updater', // Don't overwrite the updater plugin during restore
        );

        // Helper to call progress callback
        $reportProgress = function ($step, $message, $percent) use ($progressCallback) {
            if ($progressCallback && is_callable($progressCallback)) {
                call_user_func($progressCallback, $step, $message, $percent);
            }
        };

        // Helper to check if path should be excluded
        $shouldExclude = function ($relativePath) use ($excludeFiles, $excludeDirs) {
            // Normalize path separators
            $relativePath = str_replace('\\', '/', $relativePath);

            // Check if file is in exclude list
            if (in_array(basename($relativePath), $excludeFiles)) {
                return true;
            }

            // Check if path starts with any excluded directory
            foreach ($excludeDirs as $excludedDir) {
                $excludedDir = str_replace('\\', '/', $excludedDir);
                if (strpos($relativePath, $excludedDir . '/') === 0 || $relativePath === $excludedDir) {
                    return true;
                }
            }

            return false;
        };

        // First pass: count total files
        $totalFiles = 0;
        $countIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($countIterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            if (!$shouldExclude($relativePath)) {
                $totalFiles++;
            }
        }

        if ($totalFiles === 0) {
            $reportProgress('copy_progress', 'No hay archivos para copiar', $startPercent);
            return;
        }

        $reportProgress('copy_start', "Copiando {$totalFiles} archivos...", $startPercent);

        // Second pass: copy files with progress
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $currentFile = 0;
        $lastReportedPercent = $startPercent;

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $destPath = $dest . '/' . $relativePath;

            // Skip excluded files and directories
            if ($shouldExclude($relativePath)) {
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

                $currentFile++;

                // Report progress every 100 files or at significant milestones
                $percentRange = $endPercent - $startPercent;
                $currentPercent = $startPercent + (($currentFile / $totalFiles) * $percentRange);

                // Only report every 2% to avoid flooding
                if ($currentPercent - $lastReportedPercent >= 2 || $currentFile === $totalFiles) {
                    $reportProgress('copy_progress', "Copiando archivos... ({$currentFile} de {$totalFiles})", intval($currentPercent));
                    $lastReportedPercent = $currentPercent;
                }

                // Prevent timeout every 500 files
                if ($currentFile % 500 === 0) {
                    @set_time_limit(300);
                }
            }
        }

        $reportProgress('copy_complete', "Archivos copiados: {$currentFile}", $endPercent);
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
        foreach ($grouped as $baseName => $group) {
            $grouped[$baseName]['can_restore_complete'] = ($group['complete'] !== null);
            $grouped[$baseName]['can_restore_files'] = ($group['files'] !== null || $group['complete'] !== null);
            $grouped[$baseName]['can_restore_database'] = ($group['database'] !== null || $group['complete'] !== null);
            $grouped[$baseName]['database_restore_file'] = null;
            if ($group['database']) {
                $grouped[$baseName]['database_restore_file'] = $group['database']['name'];
            } elseif ($group['complete']) {
                $grouped[$baseName]['database_restore_file'] = $group['complete']['name'];
            }
        }

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

    /**
     * Nombres de tablas de usuarios que deben respaldarse temporalmente.
     * @return array
     */
    private function get_user_table_names()
    {
        return array('fs_users', 'users', 'user');
    }

    /**
     * Hace backup temporal de las tablas de usuarios actuales.
     * Esto permite restaurar los usuarios si la restauración falla,
     * manteniendo acceso a recovery.php.
     *
     * @param mysqli $mysqli Conexión a la base de datos
     * @return bool True si se creó el backup correctamente
     */
    private function backup_users_to_temp($mysqli)
    {
        $userTables = $this->get_user_table_names();
        $backedUp = false;

        foreach ($userTables as $table) {
            // Verificar si la tabla existe
            if ($this->mysqlHelper->tableExists($mysqli, $table)) {
                $tempTable = '_' . $table . '_backup_temp';

                // Eliminar tabla temporal anterior si existe
                $this->mysqlHelper->dropTableIfExists($mysqli, $tempTable);

                // Crear copia de la tabla
                $this->mysqlHelper->createTableLike($mysqli, $tempTable, $table);
                $this->mysqlHelper->copyTableRows($mysqli, $tempTable, $table);

                $backedUp = true;
            }
        }

        return $backedUp;
    }

    /**
     * Restaura usuarios desde backup temporal (en caso de error durante restauración).
     * Esto permite que recovery.php siga funcionando si algo falla.
     *
     * @param mysqli $mysqli Conexión a la base de datos
     * @return bool True si se restauraron los usuarios
     */
    private function restore_users_from_temp($mysqli)
    {
        $userTables = $this->get_user_table_names();
        $restored = false;

        $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");

        foreach ($userTables as $table) {
            $tempTable = '_' . $table . '_backup_temp';

            // Verificar si existe la tabla temporal
            if ($this->mysqlHelper->tableExists($mysqli, $tempTable)) {
                // Eliminar tabla actual si existe (puede estar corrupta)
                $this->mysqlHelper->dropTableIfExists($mysqli, $table);

                // Renombrar tabla temporal a la original
                $this->mysqlHelper->renameTable($mysqli, $tempTable, $table);

                $restored = true;
            }
        }

        $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");

        return $restored;
    }

    /**
     * Limpia las tablas temporales de usuarios (después de restauración exitosa).
     *
     * @param mysqli $mysqli Conexión a la base de datos
     */
    private function cleanup_temp_users($mysqli)
    {
        $userTables = $this->get_user_table_names();

        foreach ($userTables as $table) {
            $tempTable = '_' . $table . '_backup_temp';
            $this->mysqlHelper->dropTableIfExists($mysqli, $tempTable);
        }
    }

    /**
     * Crea un archivo temporal de configuración MySQL (--defaults-extra-file)
     * para evitar exponer la contraseña en el command line.
     *
     * @param string $host Host de la base de datos
     * @param string $port Puerto de la base de datos
     * @param string $user Usuario de la base de datos
     * @param string $pass Contraseña de la base de datos
     * @return string|false Ruta al archivo creado o false si falla
     */
    private function create_mysql_defaults_file($host, $port, $user, $pass)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'mysql_defaults_');
        if ($tempFile === false) {
            return false;
        }

        $content = "[client]\n";
        $content .= "host=" . $host . "\n";
        $content .= "port=" . $port . "\n";
        $content .= "user=" . $user . "\n";
        $content .= "password=" . $pass . "\n";

        if (file_put_contents($tempFile, $content) === false) {
            @unlink($tempFile);
            return false;
        }

        // Set restrictive permissions (owner read/write only)
        @chmod($tempFile, 0600);

        return $tempFile;
    }

    // =========================================================================
    // Restauración resumible por pasos (API aditiva; no altera restore_* existente)
    // =========================================================================

    const RESTORE_STEP_BUDGET = 5.0;

    /**
     * Ruta del archivo de estado para una sesión de restauración.
     *
     * @param string $sessionId
     * @return string
     */
    public function restore_session_state_file(string $sessionId): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'fs_restore_state_' . $this->restore_session_safe_id($sessionId) . '.json';
    }

    /**
     * Carga el estado de una sesión de restauración, o `null` si no existe.
     *
     * @param string $sessionId
     * @return array|null
     */
    public function restore_session_load(string $sessionId): ?array
    {
        $file = $this->restore_session_state_file($sessionId);
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $this->restore_session_normalize($decoded);
    }

    /**
     * Inicia (o reinicia) una restauración por pasos y ejecuta el primer tramo.
     *
     * @param string $backupFile
     * @param string $restoreType complete|files|database
     * @param string $sessionId
     * @param float|null $budget Segundos de trabajo máximos para este paso
     * @return array
     */
    public function restore_session_start(string $backupFile, string $restoreType, string $sessionId, ?float $budget = null): array
    {
        $restoreType = in_array($restoreType, array('complete', 'files', 'database'), true)
            ? $restoreType
            : 'complete';

        $state = $this->restore_session_initial_state($sessionId, $backupFile, $restoreType);

        if ($restoreType !== 'files' && $this->get_database_type() === 'POSTGRESQL') {
            $message = 'El modo de restauración resumible solo soporta MySQL. '
                . 'PostgreSQL requiere shell, deshabilitado en producción.';
            $this->errors[] = $message;
            return $this->restore_session_response(false, false, $message, 'prepare', $message, 0, $state);
        }

        $this->restore_session_save($state);

        return $this->restore_session_step($state, $budget);
    }

    /**
     * Ejecuta un tramo acotado por presupuesto del paso actual y persiste el estado.
     *
     * @param array $state
     * @param float|null $budget
     * @return array
     */
    public function restore_session_step(array $state, ?float $budget = null): array
    {
        $state = $this->restore_session_normalize($state);
        $budget = $this->restore_session_resolve_budget($budget);
        $deadline = microtime(true) + $budget;

        $lastStep = (string) $state['phase'];
        $lastMessage = 'Continuando restauración...';
        $lastPercent = $this->restore_phase_percent($state);

        try {
            $firstUnit = true;
            while (true) {
                if ($state['phase'] === 'done') {
                    break;
                }

                // Garantiza al menos una unidad de trabajo por paso: nunca un
                // chunk sin avance aunque el presupuesto llegue agotado.
                if (!$firstUnit && microtime(true) >= $deadline) {
                    break;
                }
                $firstUnit = false;

                $unit = $this->restore_session_run_phase($state, $deadline);

                $lastStep = (string) $unit['step'];
                $lastMessage = (string) $unit['message'];
                $lastPercent = (int) $unit['percent'];

                if (!empty($unit['fatal'])) {
                    // Un fallo fatal no ejecuta cleanup: sin esto quedaban en
                    // disco el paquete extraído y el dump descomprimido (gigas
                    // invisibles para el operador).
                    if (!empty($state['temp_dir']) && is_dir((string) $state['temp_dir'])) {
                        $this->delete_directory((string) $state['temp_dir']);
                    }
                    if (!empty($state['dump_path']) && is_file((string) $state['dump_path'])) {
                        @unlink((string) $state['dump_path']);
                    }
                    $state['temp_dir'] = null;
                    $state['dump_path'] = null;
                    $state['db_backup_path'] = null;

                    $state['updated_at'] = time();
                    $this->restore_session_save($state);
                    return $this->restore_session_response(
                        false,
                        false,
                        (string) $unit['error'],
                        $lastStep,
                        $lastMessage,
                        $lastPercent,
                        $state
                    );
                }

                if (!empty($unit['complete'])) {
                    $state['phase'] = (string) $unit['next'];
                }
            }
        } catch (\Throwable $e) {
            $message = 'Error inesperado en la restauración por pasos: ' . $e->getMessage();
            $this->errors[] = $message;
            $state['errors'][] = $message;
            $state['updated_at'] = time();
            $this->restore_session_save($state);
            return $this->restore_session_response(false, false, $message, (string) $state['phase'], $message, $lastPercent, $state);
        }

        $done = ($state['phase'] === 'done');
        $errorCount = count((array) $state['errors']);

        if ($done) {
            $state['done'] = true;
            $lastStep = 'done';
            $lastPercent = 100;
            $lastMessage = $errorCount > 0
                ? 'Restauración terminada con ' . $errorCount . ' error(es). Revisá el detalle antes de dar por buena la copia.'
                : 'Restauración completada correctamente.';
            // Terminar con errores NO es un éxito: el endpoint y la UI tienen
            // que poder distinguirlo, si no se reporta como copia buena una
            // importación incompleta. En ese caso se conserva el estado para
            // que el operador pueda leer los errores.
            if ($errorCount > 0) {
                $state['updated_at'] = time();
                $this->restore_session_save($state);

                return $this->restore_session_response(
                    false,
                    true,
                    $lastMessage,
                    $lastStep,
                    $lastMessage,
                    $lastPercent,
                    $state
                );
            }

            $this->restore_session_forget((string) $state['session_id']);
        } else {
            $state['updated_at'] = time();
            $this->restore_session_save($state);
        }

        return $this->restore_session_response(true, $done, null, $lastStep, $lastMessage, $lastPercent, $state);
    }

    /**
     * Elimina el archivo de estado de una sesión.
     *
     * @param string $sessionId
     * @return void
     */
    public function restore_session_forget(string $sessionId): void
    {
        $file = $this->restore_session_state_file($sessionId);
        if (is_file($file)) {
            @unlink($file);
        }
        if (is_file($file . '.tmp')) {
            @unlink($file . '.tmp');
        }
    }

    /**
     * Indica si el estado de una sesión no existe o está viejo (sin heartbeat).
     *
     * @param string $sessionId
     * @param int $maxAgeSeconds
     * @return bool
     */
    public function restore_session_is_stale(string $sessionId, int $maxAgeSeconds = 120): bool
    {
        $state = $this->restore_session_load($sessionId);
        if ($state === null) {
            return true;
        }

        $updated = (int) $state['updated_at'];
        if ($updated <= 0) {
            return true;
        }

        return (time() - $updated) > $maxAgeSeconds;
    }

    /**
     * Ejecuta el manejador de la fase actual.
     *
     * @param array $state
     * @param float $deadline
     * @return array
     */
    private function restore_session_run_phase(array &$state, float $deadline): array
    {
        switch ((string) $state['phase']) {
            case 'prepare':
                return $this->restore_step_prepare($state, $deadline);

            case 'decompress':
                return $this->restore_step_decompress($state, $deadline);

            case 'users':
                return $this->restore_step_users($state, $deadline);

            case 'files':
                return $this->restore_step_files($state, $deadline);

            case 'drop':
                return $this->restore_step_drop($state, $deadline);

            case 'import':
                return $this->restore_step_import($state, $deadline);

            case 'cleanup':
                return $this->restore_step_cleanup($state, $deadline);

            default:
                return $this->restore_unit(true, 'done', 'done', 'Restauración completada.', 100);
        }
    }

    /**
     * Fase prepare: resuelve el backup, extrae el paquete, valida el motor,
     * respalda usuarios, lista tablas y descomprime el dump.
     *
     * @param array $state
     * @param float $deadline
     * @return array
     */
    private function restore_step_prepare(array &$state, float $deadline): array
    {
        $startedAt = microtime(true);
        $type = (string) $state['type'];
        $needsDatabase = in_array($type, array('complete', 'database'), true);

        $backupPath = $this->get_backup_file_path((string) $state['file']);
        if (!$backupPath) {
            return $this->restore_fatal('prepare', 'Archivo de backup no encontrado: ' . $state['file'], 5, 'done');
        }

        $tempDir = !empty($state['temp_dir']) ? (string) $state['temp_dir'] : null;
        $dbBackupPath = null;

        if ($type === 'files') {
            if (substr($backupPath, -4) !== '.zip' || strpos(basename($backupPath), '_complete.zip') !== false) {
                $resolved = $this->resolve_database_backup_source((string) $state['file']);
                if (!$resolved) {
                    return $this->restore_fatal('prepare', $this->last_error_message('No se pudo resolver el paquete de backup.'), 5, 'done');
                }
                $tempDir = $resolved['temp_dir'];
            }
        } else {
            $resolved = $this->resolve_database_backup_source((string) $state['file']);
            if (!$resolved) {
                return $this->restore_fatal('prepare', $this->last_error_message('No se pudo resolver el backup de base de datos.'), 5, 'done');
            }
            $dbBackupPath = $resolved['path'];
            $tempDir = $resolved['temp_dir'];
        }

        if ($needsDatabase && ($tempDir === null || $tempDir === '')) {
            $tempDir = $this->restore_create_temp_dir((string) $state['session_id']);
            if ($tempDir === null) {
                return $this->restore_fatal('prepare', 'No se pudo crear el directorio temporal de restauración.', 5, 'done');
            }
        }

        if ($tempDir !== null && $tempDir !== '') {
            $state['temp_dir'] = $tempDir;
        }

        if ($needsDatabase) {
            if (!$dbBackupPath || !is_file($dbBackupPath)) {
                return $this->restore_fatal('prepare', 'No se encontró backup de base de datos dentro del paquete: ' . $state['file'], 5, 'done');
            }

            $metadata = $this->read_package_metadata((string) $tempDir);
            $backupDbType = $this->get_backup_database_type_from_metadata($metadata);
            if ($backupDbType === 'UNKNOWN') {
                $backupDbType = $this->detect_database_backup_type($dbBackupPath);
            }
            $currentDbType = $this->get_database_type();
            if (!$this->is_database_restore_compatible($backupDbType, $currentDbType)) {
                $message = 'El backup fue generado para ' . $backupDbType . ' y la instalación actual usa '
                    . $currentDbType . '. La restauración de base de datos entre motores distintos no es compatible.';
                return $this->restore_fatal('prepare', $message, 5, 'done');
            }

            // La descompresión y el respaldo de usuarios viven en sus propias
            // fases (decompress / users): juntarlos acá hacía que un solo
            // request superara el límite del host y lo mataran.
            $state['db_backup_path'] = $dbBackupPath;
        }

        if ($needsDatabase) {
            $next = 'decompress';
            $message = 'Paquete preparado. Descomprimiendo el dump...';
        } else {
            $next = 'files';
            $message = 'Archivos preparados para restaurar.';
        }

        $this->restore_add_warning_if_slow($state, 'prepare', $startedAt, $deadline);

        return $this->restore_unit(true, $next, 'prepare', $message, 20);
    }

    /**
     * Fase decompress: descomprime el dump en su propia unidad de trabajo.
     *
     * @param array $state
     * @param float $deadline
     * @return array
     */
    private function restore_step_decompress(array &$state, float $deadline): array
    {
        $startedAt = microtime(true);

        $dbBackupPath = isset($state['db_backup_path']) ? (string) $state['db_backup_path'] : '';
        if ($dbBackupPath === '' || !is_file($dbBackupPath)) {
            return $this->restore_fatal('decompress', 'No se encontró el dump comprimido del backup.', 25, 'done');
        }

        $tempDir = isset($state['temp_dir']) ? (string) $state['temp_dir'] : '';
        if ($tempDir === '') {
            return $this->restore_fatal('decompress', 'No hay directorio temporal para descomprimir el dump.', 25, 'done');
        }

        $dumpPath = rtrim($tempDir, '/\\') . DIRECTORY_SEPARATOR . 'dump.sql';
        if (!$this->restore_decompress_sql($dbBackupPath, $dumpPath)) {
            return $this->restore_fatal(
                'decompress',
                $this->last_error_message('No se pudo descomprimir el dump de base de datos.'),
                25,
                'done'
            );
        }

        $state['dump_path'] = $dumpPath;
        $this->restore_add_warning_if_slow($state, 'decompress', $startedAt, $deadline);

        return $this->restore_unit(true, 'users', 'decompress', 'Dump descomprimido.', 30);
    }

    /**
     * Fase users: respalda las tablas de usuarios y lista las tablas a eliminar.
     *
     * @param array $state
     * @param float $deadline
     * @return array
     */
    private function restore_step_users(array &$state, float $deadline): array
    {
        $startedAt = microtime(true);

        $connectionError = null;
        $mysqli = $this->restore_open_mysqli($connectionError);
        if ($mysqli === null) {
            return $this->restore_fatal('users', (string) $connectionError, 30, 'done');
        }

        try {
            $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
            $this->backup_users_to_temp($mysqli);

            $tables = array();
            $result = $mysqli->query('SHOW TABLES');
            if ($result) {
                while ($row = $result->fetch_array(MYSQLI_NUM)) {
                    if (strpos((string) $row[0], '_backup_temp') === false) {
                        $tables[] = (string) $row[0];
                    }
                }
                $result->free();
            }
            $state['tables'] = $tables;
        } catch (\Throwable $e) {
            $mysqli->close();
            return $this->restore_fatal('users', 'Error preparando la base de datos: ' . $e->getMessage(), 30, 'done');
        }

        $mysqli->close();
        $this->restore_add_warning_if_slow($state, 'users', $startedAt, $deadline);

        return $this->restore_unit(
            true,
            ((string) $state['type'] === 'complete') ? 'files' : 'drop',
            'users',
            'Usuarios respaldados y tablas listadas (' . count($state['tables']) . ').',
            58
        );
    }

    /**
     * Registra una advertencia cuando una fase no interrumpible supera el
     * presupuesto: no se puede cortar a la mitad, así que queda visible.
     *
     * @param array $state
     * @param string $phase
     * @param float $startedAt
     * @param float $deadline
     * @return void
     */
    private function restore_add_warning_if_slow(array &$state, string $phase, float $startedAt, float $deadline): void
    {
        $elapsed = microtime(true) - $startedAt;
        $budget = max(0.0, $deadline - $startedAt);
        if ($elapsed <= $budget) {
            return;
        }

        $message = sprintf(
            'La fase "%s" tardó %.1fs y superó el presupuesto de %.1fs; es una operación no interrumpible.',
            $phase,
            $elapsed,
            $budget
        );

        if (!in_array($message, $state['warnings'], true)) {
            $state['warnings'][] = $message;
        }
    }

    /**
     * Fase files: delega en el restore_files() existente (un solo paso).
     *
     * @param array $state
     * @param float $deadline
     * @return array
     */
    private function restore_step_files(array &$state, float $deadline): array
    {
        $startedAt = microtime(true);
        $type = (string) $state['type'];
        $filesZip = null;

        // Un paquete completo extraído expone los archivos en <temp_dir>/files.
        if (!empty($state['temp_dir'])) {
            $filesZip = $this->restore_find_files_zip((string) $state['temp_dir']);
        }

        // Backup de archivos independiente (_files.zip).
        if ($filesZip === null) {
            $candidate = $this->get_backup_file_path((string) $state['file']);
            if ($candidate && substr($candidate, -4) === '.zip' && strpos(basename($candidate), '_complete.zip') === false) {
                $filesZip = $candidate;
            }
        }

        if ($filesZip === null || !is_file($filesZip)) {
            return $this->restore_fatal('files', 'No se encontró backup de archivos en el paquete.', 20, 'cleanup');
        }

        $result = $this->restore_files($filesZip);
        if (!($result['success'] ?? false)) {
            return $this->restore_fatal('files', $this->last_error_message('Error al restaurar archivos.'), 20, 'cleanup');
        }

        $state['files_done'] = true;
        $next = $type === 'complete' ? 'drop' : 'cleanup';
        $this->restore_add_warning_if_slow($state, 'files', $startedAt, $deadline);

        return $this->restore_unit(true, $next, 'files', 'Archivos restaurados correctamente.', 50);
    }

    /**
     * Fase drop: elimina tablas en lotes de 25.
     *
     * @param array $state
     * @param float $deadline
     * @return array
     */
    private function restore_step_drop(array &$state, float $deadline): array
    {
        $tables = (array) $state['tables'];
        $total = count($tables);
        $index = (int) $state['drop_index'];

        if ($total === 0 || $index >= $total) {
            $state['drop_index'] = $total;
            return $this->restore_unit(true, 'import', 'drop', 'No hay tablas pendientes de eliminar.', 65);
        }

        $connectionError = null;
        $mysqli = $this->restore_open_mysqli($connectionError);
        if ($mysqli === null) {
            return $this->restore_fatal('drop', (string) $connectionError, 60, 'import');
        }

        try {
            $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
        } catch (\Throwable $e) {
            $this->errors[] = 'No se pudo desactivar FOREIGN_KEY_CHECKS: ' . $e->getMessage();
        }

        $processed = 0;
        do {
            $table = (string) $tables[$index];
            try {
                $this->mysqlHelper->dropTableIfExists($mysqli, $table);
            } catch (\Throwable $e) {
                $message = 'Error al eliminar la tabla ' . $table . ': ' . $e->getMessage();
                $this->errors[] = $message;
                $state['errors'][] = $message;
            }

            $index++;
            $processed++;
        } while ($index < $total && $processed < 25 && microtime(true) < $deadline);

        $state['drop_index'] = $index;
        $mysqli->close();

        if ($index >= $total) {
            return $this->restore_unit(true, 'import', 'drop', 'Tablas eliminadas.', 65);
        }

        $percent = 60 + (int) floor(($index / max(1, $total)) * 5);
        return $this->restore_unit(false, 'import', 'drop', 'Eliminando tablas (' . $index . '/' . $total . ')...', min(65, $percent));
    }

    /**
     * Fase import: ejecuta el dump por offset de bytes usando el reader seguro.
     *
     * @param array $state
     * @param float $deadline
     * @return array
     */
    private function restore_step_import(array &$state, float $deadline): array
    {
        $dumpPath = isset($state['dump_path']) ? (string) $state['dump_path'] : '';
        if ($dumpPath === '' || !is_file($dumpPath)) {
            return $this->restore_fatal('import', 'No se encontró el dump SQL temporal.', 65, 'cleanup');
        }

        $connectionError = null;
        $mysqli = $this->restore_open_mysqli($connectionError);
        if ($mysqli === null) {
            return $this->restore_fatal('import', (string) $connectionError, 65, 'cleanup');
        }

        try {
            $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
            $mysqli->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
            // Cada paso usa una conexión propia. Si el dump deja `autocommit=0`
            // y abre una transacción, al cerrar la conexión MySQL revierte todo
            // lo ejecutado en el paso: se perdían las filas del primer tramo.
            $mysqli->query('SET SESSION autocommit = 1');
        } catch (\Throwable $e) {
            $this->errors[] = 'No se pudieron preparar las variables de sesión: ' . $e->getMessage();
        }

        $reader = new \SystemUpdaterSqlDumpReader($dumpPath);
        $reader->seek((int) $state['sql_offset'], (string) $state['delimiter']);

        $eof = false;

        // Al menos una sentencia por paso para garantizar avance con cualquier
        // presupuesto; luego se corta al agotarlo.
        do {
            $statement = $reader->nextStatement();
            if ($statement === null) {
                $eof = true;
                break;
            }

            $sql = (string) $statement['sql'];
            if (trim($sql) === '') {
                continue;
            }

            // El control transaccional del dump no aplica a un import por pasos:
            // cada paso es otra conexión y no puede ser atómico. Ejecutarlo
            // dejaría trabajo sin commitear que MySQL revierte al cerrar la
            // conexión. Se neutraliza y el import queda durable por sentencia.
            if (!$this->restore_is_transaction_control($sql)
                && !$this->restore_restores_foreign_session_state($sql)
            ) {
                try {
                    $mysqli->query($sql);
                } catch (\Throwable $e) {
                    $message = 'Error SQL: ' . $e->getMessage();
                    $this->errors[] = $message;
                    $state['errors'][] = $message;
                }
            }

            $state['sql_offset'] = $reader->tell();
            $state['delimiter'] = $reader->delimiter();
            // El contador se incrementa DENTRO del bucle para que el checkpoint
            // por sentencia lo persista: si no, tras un kill quedaba en 0 aunque
            // el offset ya hubiera avanzado.
            $state['statement_count'] = (int) $state['statement_count'] + 1;

            // Checkpoint POR SENTENCIA. Si el host mata el proceso a mitad de
            // paso, el próximo chunk reanuda desde acá. Residual honesto: como
            // máximo la sentencia en vuelo puede reejecutarse. Antes el estado
            // se guardaba sólo al final del paso, así que un kill reejecutaba
            // TODAS las sentencias del paso (filas duplicadas).
            $state['updated_at'] = time();
            $this->restore_session_save($state);
        } while (microtime(true) < $deadline);

        $state['sql_offset'] = $reader->tell();
        $state['delimiter'] = $reader->delimiter();

        foreach ($reader->issues() as $issue) {
            if (!in_array($issue, $state['warnings'], true)) {
                $state['warnings'][] = $issue;
            }
        }

        $mysqli->close();

        if ($eof) {
            return $this->restore_unit(
                true,
                'cleanup',
                'import',
                'Importación completada (' . (int) $state['statement_count'] . ' sentencias).',
                88
            );
        }

        $size = (int) @filesize($dumpPath);
        $fraction = $size > 0 ? min(1.0, ((int) $state['sql_offset']) / $size) : 0.0;
        $percent = 65 + (int) floor($fraction * 23);

        return $this->restore_unit(
            false,
            'cleanup',
            'import',
            'Importando sentencias (' . (int) $state['statement_count'] . ')...',
            min(88, $percent)
        );
    }

    /**
     * Normaliza una sentencia del dump para poder clasificarla.
     *
     * MySQL envuelve sentencias de sesión en comentarios condicionales
     * (`/*!40101 SET ... *\/`), así que hay que quitar el envoltorio antes de
     * comparar.
     *
     * @param string $sql
     * @return string
     */
    private function restore_normalize_statement(string $sql): string
    {
        $normalized = strtoupper((string) preg_replace('/\s+/', ' ', trim($sql)));
        $normalized = (string) preg_replace('#^/\*!\d*\s*#', '', $normalized);
        $normalized = (string) preg_replace('#\s*\*/$#', '', $normalized);

        return trim($normalized);
    }

    /**
     * Indica si una sentencia del dump sólo controla transacciones.
     *
     * El import por pasos usa una conexión por paso y no puede ser atómico, así
     * que `START TRANSACTION` / `COMMIT` / `ROLLBACK` / `SET AUTOCOMMIT` del dump
     * se neutralizan: ejecutarlos dejaría trabajo sin commitear que MySQL
     * revierte al cerrar la conexión del paso.
     *
     * @param string $sql
     * @return bool
     */
    private function restore_is_transaction_control(string $sql): bool
    {
        $normalized = $this->restore_normalize_statement($sql);

        if (in_array($normalized, array('START TRANSACTION', 'BEGIN', 'BEGIN WORK', 'COMMIT', 'ROLLBACK'), true)) {
            return true;
        }

        return (bool) preg_match('/^SET\s+(SESSION\s+|GLOBAL\s+|LOCAL\s+)?AUTOCOMMIT\s*=/', $normalized);
    }

    /**
     * Indica si una sentencia restaura estado de sesión guardado en otra conexión.
     *
     * El pie del volcado hace `SET character_set_client = @OLD_CHARACTER_SET_CLIENT`,
     * pero esa variable de usuario la guardó el encabezado en la conexión del
     * primer paso. En un paso posterior llega `NULL` y MySQL rechaza el SET:
     * "Variable 'character_set_client' can't be set to the value of 'NULL'".
     * Sólo restauran ajustes de la sesión del dump, así que se omiten.
     *
     * @param string $sql
     * @return bool
     */
    private function restore_restores_foreign_session_state(string $sql): bool
    {
        $normalized = $this->restore_normalize_statement($sql);

        return (bool) preg_match(
            '/^SET\s+(SESSION\s+|LOCAL\s+|GLOBAL\s+)?[A-Za-z_][A-Za-z0-9_]*\s*=\s*@(?!@)/',
            $normalized
        );
    }

    /**
     * Comprueba si alguna tabla de usuarios quedó con filas tras el import.
     *
     * Sirve para no descartar una tabla de usuarios correctamente restaurada
     * sólo porque hubo errores sin relación (por ejemplo, un SET de sesión).
     *
     * @param \mysqli $mysqli
     * @return bool
     */
    private function restore_users_tables_have_rows($mysqli): bool
    {
        foreach ($this->get_user_table_names() as $table) {
            $quoted = $this->mysqlHelper->quoteIdentifier($table);
            if ($quoted === false) {
                continue;
            }

            try {
                $result = $mysqli->query('SELECT COUNT(*) FROM ' . $quoted);
            } catch (\Throwable $e) {
                continue;
            }

            if (!$result) {
                continue;
            }

            $row = $result->fetch_row();
            $result->free();

            if ($row && (int) $row[0] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fase cleanup: limpia tablas temporales de usuarios y borra temporales.
     *
     * @param array $state
     * @param float $deadline
     * @return array
     */
    private function restore_step_cleanup(array &$state, float $deadline): array
    {
        $type = (string) $state['type'];
        $errorCount = count((array) $state['errors']);

        if (in_array($type, array('complete', 'database'), true)) {
            $connectionError = null;
            $mysqli = $this->restore_open_mysqli($connectionError);
            if ($mysqli !== null) {
                try {
                    if ($errorCount > 0) {
                        if ($this->restore_users_tables_have_rows($mysqli)) {
                            // Hubo errores, pero no afectaron a los usuarios: la
                            // tabla del backup quedó con datos, así que se
                            // conserva. Descartarla era destruir un import
                            // correcto por errores ajenos (p. ej. un SET de sesión).
                            $this->cleanup_temp_users($mysqli);
                            $state['warnings'][] = 'La importación tuvo ' . $errorCount . ' error(es), '
                                . 'pero las tablas de usuarios quedaron con datos: se conserva la del backup.';
                        } else {
                            // La importación falló en serio: se recuperan las
                            // tablas de usuarios anteriores, igual que el flujo
                            // legacy, para no perder el acceso a recovery.php.
                            $recovered = $this->restore_users_from_temp($mysqli);
                            $state['warnings'][] = $recovered
                                ? 'La importación tuvo ' . $errorCount . ' error(es): se restauraron las tablas de usuarios anteriores para conservar el acceso.'
                                : 'La importación tuvo ' . $errorCount . ' error(es) y no había copia temporal de usuarios.';
                        }
                    } else {
                        $this->cleanup_temp_users($mysqli);
                    }
                } catch (\Throwable $e) {
                    $this->errors[] = 'No se pudieron limpiar las tablas temporales: ' . $e->getMessage();
                }

                try {
                    $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
                } catch (\Throwable $e) {
                    // El ajuste es por conexión; se reaplica en cada paso.
                }

                $mysqli->close();
            }
        }

        if (!empty($state['temp_dir']) && is_dir((string) $state['temp_dir'])) {
            $this->delete_directory((string) $state['temp_dir']);
        }
        if (!empty($state['dump_path']) && is_file((string) $state['dump_path'])) {
            @unlink((string) $state['dump_path']);
        }

        $state['temp_dir'] = null;
        $state['dump_path'] = null;

        return $this->restore_unit(true, 'done', 'cleanup', 'Temporales limpiados.', 95);
    }

    /**
     * Construye la respuesta estándar de start/step.
     *
     * @return array
     */
    private function restore_session_response(bool $ok, bool $done, ?string $error, string $step, string $message, int $percent, array $state): array
    {
        return array(
            'ok' => $ok,
            'done' => $done,
            'error' => $error,
            'progress' => array(
                'step' => $step,
                'message' => $message,
                'percent' => $percent,
                'errors_count' => count((array) ($state['errors'] ?? array())),
                'warnings_count' => count((array) ($state['warnings'] ?? array())),
                // Detalle acotado: un contador sin los mensajes no le sirve al
                // operador para saber qué falló.
                'errors' => array_slice(array_values((array) ($state['errors'] ?? array())), -10),
                'warnings' => array_slice(array_values((array) ($state['warnings'] ?? array())), -10),
            ),
            'state' => $state,
        );
    }

    /**
     * @return array
     */
    private function restore_session_initial_state(string $sessionId, string $backupFile, string $restoreType): array
    {
        $now = time();

        return array(
            'version' => 1,
            'session_id' => $sessionId,
            'type' => $restoreType,
            'file' => basename($backupFile),
            'started_at' => $now,
            'updated_at' => $now,
            'phase' => 'prepare',
            'temp_dir' => null,
            'dump_path' => null,
            'db_backup_path' => null,
            'tables' => array(),
            'drop_index' => 0,
            'sql_offset' => 0,
            'delimiter' => ';',
            'statement_count' => 0,
            'files_done' => false,
            'errors' => array(),
            'warnings' => array(),
        );
    }

    /**
     * Normaliza un estado cargado para garantizar todas las claves esperadas.
     *
     * @return array
     */
    private function restore_session_normalize(array $state): array
    {
        $defaults = array(
            'version' => 1,
            'session_id' => '',
            'type' => 'complete',
            'file' => '',
            'started_at' => 0,
            'updated_at' => 0,
            'phase' => 'prepare',
            'temp_dir' => null,
            'dump_path' => null,
            'db_backup_path' => null,
            'tables' => array(),
            'drop_index' => 0,
            'sql_offset' => 0,
            'delimiter' => ';',
            'statement_count' => 0,
            'files_done' => false,
            'errors' => array(),
            'warnings' => array(),
        );

        $state = array_merge($defaults, $state);
        $state['version'] = 1;
        $state['type'] = in_array($state['type'], array('complete', 'files', 'database'), true) ? $state['type'] : 'complete';
        $state['file'] = (string) $state['file'];
        $state['tables'] = is_array($state['tables']) ? array_values(array_map('strval', $state['tables'])) : array();
        $state['errors'] = is_array($state['errors']) ? array_values(array_map('strval', $state['errors'])) : array();
        $state['warnings'] = is_array($state['warnings']) ? array_values(array_map('strval', $state['warnings'])) : array();
        $state['delimiter'] = trim((string) $state['delimiter']) === '' ? ';' : (string) $state['delimiter'];
        $state['drop_index'] = max(0, (int) $state['drop_index']);
        $state['sql_offset'] = max(0, (int) $state['sql_offset']);
        $state['statement_count'] = max(0, (int) $state['statement_count']);
        $state['files_done'] = (bool) $state['files_done'];

        return $state;
    }

    /**
     * Persiste el estado de forma atómica, sin credenciales ni rutas no permitidas.
     *
     * @return void
     */
    private function restore_session_save(array $state): void
    {
        $state['temp_dir'] = $this->restore_session_allowed_path($state['temp_dir']) ? $state['temp_dir'] : null;
        $state['dump_path'] = $this->restore_session_allowed_path($state['dump_path']) ? $state['dump_path'] : null;
        $state['db_backup_path'] = $this->restore_session_allowed_path($state['db_backup_path'] ?? null)
            ? $state['db_backup_path']
            : null;

        $file = $this->restore_session_state_file((string) $state['session_id']);
        // JSON_INVALID_UTF8_SUBSTITUTE: los errores de mysqli y los textos del
        // dump pueden traer UTF-8 inválido. Sin esto, json_encode devolvía false
        // y el checkpoint se perdía en silencio, con lo que el chunk siguiente
        // volvía a ejecutar las mismas sentencias.
        $json = json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            $this->errors[] = 'No se pudo serializar el estado de restauración: ' . json_last_error_msg();
            return;
        }

        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }

        // El estado no lleva credenciales, pero el nombre es predecible en el
        // directorio temporal compartido: se restringe a 0600.
        @chmod($tmp, 0600);
        @rename($tmp, $file);
    }

    /**
     * Sólo se admiten rutas dentro del directorio temporal del sistema o de backups.
     *
     * @param mixed $path
     * @return bool
     */
    private function restore_session_allowed_path($path): bool
    {
        if ($path === null || $path === '') {
            return true;
        }

        $path = (string) $path;
        $roots = array(
            rtrim(sys_get_temp_dir(), '/\\'),
            rtrim($this->backupPath, '/\\'),
        );

        foreach ($roots as $root) {
            if ($root !== '' && strpos($path, $root . DIRECTORY_SEPARATOR) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string
     */
    private function restore_session_safe_id(string $sessionId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $sessionId);
        if ($safe === null || $safe === '') {
            $safe = sha1($sessionId);
        }

        return $safe;
    }

    /**
     * Resuelve el presupuesto del paso (paramétrico > constante > default).
     *
     * @return float
     */
    private function restore_session_resolve_budget(?float $budget): float
    {
        if ($budget !== null && $budget > 0) {
            return $budget;
        }

        if (defined('FS_RESTORE_STEP_BUDGET')) {
            $configured = (float) FS_RESTORE_STEP_BUDGET;
            if ($configured > 0) {
                return $configured;
            }
        }

        return self::RESTORE_STEP_BUDGET;
    }

    /**
     * Porcentaje inicial de la fase actual.
     *
     * @return int
     */
    private function restore_phase_percent(array $state): int
    {
        switch ((string) $state['phase']) {
            case 'prepare':
                return 5;
            case 'decompress':
                return 25;
            case 'users':
                return 30;
            case 'files':
                return 30;
            case 'drop':
                return 60;
            case 'import':
                return 65;
            case 'cleanup':
                return 92;
            case 'done':
                return 100;
            default:
                return 0;
        }
    }

    /**
     * Unidad de trabajo de una fase.
     *
     * @return array
     */
    private function restore_unit(bool $complete, string $next, string $step, string $message, int $percent): array
    {
        return array(
            'complete' => $complete,
            'next' => $next,
            'step' => $step,
            'message' => $message,
            'percent' => $percent,
            'fatal' => false,
            'error' => '',
        );
    }

    /**
     * Unidad fatal: registra el error y corta el paso.
     *
     * @return array
     */
    private function restore_fatal(string $step, string $message, int $percent, string $next): array
    {
        $this->errors[] = $message;

        return array(
            'complete' => false,
            'next' => $next,
            'step' => $step,
            'message' => $message,
            'percent' => $percent,
            'fatal' => true,
            'error' => $message,
        );
    }

    /**
     * Abre una conexión mysqli fresca leyendo credenciales de las constantes FS_DB_*.
     *
     * @param string|null $error
     * @return mysqli|null
     */
    private function restore_open_mysqli(?string &$error = null)
    {
        $error = null;

        $dbHost = defined('FS_DB_HOST') ? FS_DB_HOST : 'localhost';
        $dbType = $this->get_database_type();
        $dbPort = (int) $this->get_database_port($dbType);
        $dbUser = defined('FS_DB_USER') ? FS_DB_USER : 'root';
        $dbPass = defined('FS_DB_PASS') ? FS_DB_PASS : '';
        $dbName = defined('FS_DB_NAME') ? FS_DB_NAME : 'facturascripts';

        try {
            $mysqli = new \mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
        } catch (\Throwable $e) {
            $error = 'Error de conexión a la base de datos: ' . $e->getMessage();
            return null;
        }

        if ($mysqli->connect_error) {
            $error = 'Error de conexión a la base de datos: ' . $mysqli->connect_error;
            return null;
        }

        @$mysqli->set_charset('utf8mb4');

        return $mysqli;
    }

    /**
     * Crea un directorio temporal de trabajo para la restauración.
     *
     * @return string|null
     */
    private function restore_create_temp_dir(string $sessionId): ?string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs_restore_'
            . $this->restore_session_safe_id($sessionId) . '_' . time() . '_' . mt_rand(1000, 9999);

        if (!@mkdir($dir, 0700, true)) {
            $this->errors[] = 'No se pudo crear el directorio temporal: ' . $dir;
            return null;
        }

        return $dir;
    }

    /**
     * Descomprime un dump .sql.gz a un archivo .sql en streaming.
     *
     * @return bool
     */
    private function restore_decompress_sql(string $gzPath, string $dumpPath): bool
    {
        $gz = @gzopen($gzPath, 'rb');
        if (!$gz) {
            $this->errors[] = 'No se puede abrir el dump comprimido: ' . basename($gzPath);
            return false;
        }

        $out = @fopen($dumpPath, 'wb');
        if (!$out) {
            gzclose($gz);
            $this->errors[] = 'No se puede crear el dump temporal: ' . basename($dumpPath);
            return false;
        }

        while (!gzeof($gz)) {
            $chunk = @gzread($gz, 1048576);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                fwrite($out, $chunk);
            }
        }

        gzclose($gz);
        fclose($out);

        if (!is_file($dumpPath) || filesize($dumpPath) === 0) {
            $this->errors[] = 'El dump descomprimido quedó vacío.';
            return false;
        }

        return true;
    }

    /**
     * Busca el primer .zip dentro de `<tempDir>/files`.
     *
     * @return string|null
     */
    private function restore_find_files_zip(?string $tempDir): ?string
    {
        if ($tempDir === null || $tempDir === '' || !is_dir($tempDir)) {
            return null;
        }

        $filesDir = rtrim($tempDir, '/\\') . DIRECTORY_SEPARATOR . 'files';
        if (!is_dir($filesDir)) {
            return null;
        }

        foreach ((array) scandir($filesDir) as $file) {
            if (substr((string) $file, -4) === '.zip') {
                return $filesDir . DIRECTORY_SEPARATOR . $file;
            }
        }

        return null;
    }

    /**
     * Último error registrado, o un texto de respaldo.
     *
     * @return string
     */
    private function last_error_message(string $fallback): string
    {
        $count = count($this->errors);
        if ($count > 0) {
            return (string) $this->errors[$count - 1];
        }

        return $fallback;
    }
}
