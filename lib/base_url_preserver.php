<?php

/**
 * Keeps the target server's public base URL (fs_vars.base_url, core >= 0.37)
 * across a database restore.
 *
 * A backup carries the URL of the server it was taken on. Restoring it on a
 * different host (staging, a migration, a new domain) would make that host
 * use the original URL in generated links, e-mails and for safe
 * redirects. So the row of THIS server is read before the restore and put
 * back afterwards: its value, or its absence.
 *
 * When the current state cannot be read (no connection, e.g. recovering a
 * broken database) nothing is reapplied and the backup's value stays.
 *
 * Self-contained (no core classes): recovery.php cannot load the core autoloader.
 */
final class base_url_preserver
{
    public const VAR_NAME = 'base_url';

    /** @var callable(): array{ok: bool, value: ?string} */
    private $read;

    /** @var callable(?string): bool */
    private $write;

    /**
     * @param callable(): array{ok: bool, value: ?string} $read  ok=false when the state is unknown
     * @param callable(?string): bool $write  null deletes the row
     */
    public function __construct(callable $read, callable $write)
    {
        $this->read = $read;
        $this->write = $write;
    }

    /** @return array{known: bool, value: ?string} */
    public function capture(): array
    {
        try {
            $state = ($this->read)();
        } catch (\Throwable $e) {
            return array('known' => false, 'value' => null);
        }

        $value = isset($state['value']) && $state['value'] !== '' ? (string) $state['value'] : null;

        return array('known' => !empty($state['ok']), 'value' => $value);
    }

    /**
     * Puts this server's state back. ok=false means the operator must check
     * the URL (state unknown or write failed): callers report it as a warning.
     *
     * @param array{known: bool, value: ?string}|null $state from capture()
     * @return array{ok: bool, message: string}
     */
    public function reapply(?array $state): array
    {
        if ($state === null || empty($state['known'])) {
            return array('ok' => false, 'message' => 'No se pudo leer la URL base de este servidor antes de restaurar: se mantiene la de la copia de seguridad. Revísala en Admin » Avanzado.');
        }

        $value = isset($state['value']) && $state['value'] !== '' ? (string) $state['value'] : null;
        try {
            $ok = (bool) ($this->write)($value);
        } catch (\Throwable $e) {
            $ok = false;
        }

        if (!$ok) {
            return array('ok' => false, 'message' => 'No se pudo conservar la URL base de este servidor tras restaurar: revísala en Admin » Avanzado.');
        }

        return array('ok' => true, 'message' => $value !== null
            ? 'URL base de este servidor conservada: ' . $value
            : 'Este servidor no tenía URL base guardada: se ha quitado la de la copia de seguridad.');
    }

    /**
     * Preserver bound to the installation's database (MySQL or PostgreSQL).
     */
    public static function for_database(string $dbType, string $host, string $port, string $user, string $pass, string $dbName): self
    {
        if ($dbType === 'POSTGRESQL') {
            $connect = static function () use ($host, $port, $user, $pass, $dbName) {
                return function_exists('pg_connect') ? pg_native_dump::connect($host, $port, $user, $pass, $dbName) : null;
            };

            return new self(
                static function () use ($connect): array {
                    $conn = $connect();
                    if (!$conn) {
                        return array('ok' => false, 'value' => null);
                    }
                    try {
                        $exists = @pg_query($conn, "SELECT to_regclass('fs_vars') IS NOT NULL");
                        if ($exists === false) {
                            return array('ok' => false, 'value' => null);
                        }
                        if (pg_fetch_result($exists, 0, 0) !== 't') {
                            return array('ok' => true, 'value' => null);
                        }
                        $res = @pg_query_params($conn, 'SELECT "varchar" FROM fs_vars WHERE name = $1', array(self::VAR_NAME));
                        if ($res === false) {
                            return array('ok' => false, 'value' => null);
                        }
                        $row = pg_fetch_row($res);

                        return array('ok' => true, 'value' => $row ? (string) $row[0] : null);
                    } finally {
                        pg_close($conn);
                    }
                },
                static function (?string $value) use ($connect): bool {
                    $conn = $connect();
                    if (!$conn) {
                        return false;
                    }
                    try {
                        // One statement each: the row is never left missing halfway (PostgreSQL >= 9.5).
                        $sql = $value === null
                            ? 'DELETE FROM fs_vars WHERE name = $1'
                            : 'INSERT INTO fs_vars (name, "varchar") VALUES ($1, $2) ON CONFLICT (name) DO UPDATE SET "varchar" = EXCLUDED."varchar"';
                        $params = $value === null ? array(self::VAR_NAME) : array(self::VAR_NAME, $value);

                        return @pg_query_params($conn, $sql, $params) !== false;
                    } finally {
                        pg_close($conn);
                    }
                }
            );
        }

        $connect = static function () use ($host, $port, $user, $pass, $dbName) {
            if (!class_exists('mysqli', false)) {
                return null;
            }
            try {
                $mysqli = @new mysqli($host, $user, $pass, $dbName, (int) $port);
            } catch (\Throwable $e) {
                return null; // mysqli may throw (PHP >= 8.1 default report mode); never change that global here
            }

            return $mysqli->connect_error ? null : $mysqli;
        };

        return new self(
            static function () use ($connect): array {
                $mysqli = $connect();
                if (!$mysqli) {
                    return array('ok' => false, 'value' => null);
                }
                try {
                    $exists = $mysqli->query("SHOW TABLES LIKE 'fs_vars'");
                    if ($exists === false) {
                        return array('ok' => false, 'value' => null);
                    }
                    if ($exists->num_rows === 0) {
                        return array('ok' => true, 'value' => null);
                    }
                    $stmt = $mysqli->prepare('SELECT `varchar` FROM fs_vars WHERE name = ?');
                    if ($stmt === false) {
                        return array('ok' => false, 'value' => null);
                    }
                    $name = self::VAR_NAME;
                    $stored = null;
                    // bind_result, not get_result: the latter needs mysqlnd.
                    if (!$stmt->bind_param('s', $name) || !$stmt->execute() || !$stmt->bind_result($stored)) {
                        return array('ok' => false, 'value' => null);
                    }
                    $found = $stmt->fetch();
                    if ($found === false) {
                        return array('ok' => false, 'value' => null);
                    }

                    return array('ok' => true, 'value' => $found === true && $stored !== null ? (string) $stored : null);
                } finally {
                    $mysqli->close();
                }
            },
            static function (?string $value) use ($connect): bool {
                $mysqli = $connect();
                if (!$mysqli) {
                    return false;
                }
                try {
                    $name = self::VAR_NAME;
                    if ($value === null) {
                        $stmt = $mysqli->prepare('DELETE FROM fs_vars WHERE name = ?');

                        return $stmt !== false && $stmt->bind_param('s', $name) && $stmt->execute();
                    }

                    // One statement: the row is never left missing halfway.
                    $stmt = $mysqli->prepare('INSERT INTO fs_vars (name, `varchar`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `varchar` = ?');

                    return $stmt !== false && $stmt->bind_param('sss', $name, $value, $value) && $stmt->execute();
                } finally {
                    $mysqli->close();
                }
            }
        );
    }
}
