<?php

declare(strict_types=1);

/**
 * This file is part of FSFramework
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * PostgreSQL backup/restore in pure PHP (pgsql extension), for hosts where
 * exec() is disabled and pg_dump/psql cannot run.
 *
 * The dump is plain SQL in pg_dump's shape (COPY ... FROM stdin blocks), so
 * psql can load it too. Scope: schema `public` — sequences, tables, columns,
 * defaults, identity/generated columns, constraints, indexes and data. That is
 * everything FSFramework creates. Views, functions, triggers or partitioned
 * tables in `public` make the dump fail (a restore would drop them); tables in
 * other schemas are not dumped and are reported in $warnings.
 *
 * - Dump: one REPEATABLE READ READ ONLY transaction (consistent snapshot), rows
 *   streamed through a cursor (bounded memory), search_path '' so the catalog
 *   renders schema-qualified names (defaults like nextval('public.x_seq')).
 * - Restore: wipe + import in ONE transaction; any error, or a file without the
 *   end-of-dump marker (truncated/corrupt), rolls back and leaves the database
 *   untouched. Also loads plain pg_dump files made by the shell backup path.
 */
final class pg_native_dump
{
    public const END_MARKER = '-- FSFramework PostgreSQL dump complete';
    private const PG_DUMP_END_MARKER = '-- PostgreSQL database dump complete';
    private const FETCH_SIZE = 1000;

    /** @var list<string> Objects present in the database but not included in the dump. */
    public array $warnings = [];

    /** @var \PgSql\Connection|resource */
    private $conn;
    private int $version;
    /** Open quote while scanning a statement: '', "'", '"' or a dollar tag like '$fn$'. */
    private string $quote = '';

    /** @param \PgSql\Connection|resource $conn */
    public function __construct($conn)
    {
        $this->conn = $conn;
        $this->version = (int) pg_parameter_status($conn, 'server_version_num') ?: (int) pg_version($conn)['server'] * 10000;
        if ($this->version < 100000) {
            throw new RuntimeException('Se necesita PostgreSQL 10 o superior para el backup nativo.');
        }
        if (pg_set_client_encoding($conn, 'UTF8') !== 0) {
            throw new RuntimeException('No se pudo fijar la codificación UTF8 de la conexión.');
        }
    }

    /** @return \PgSql\Connection|resource */
    public static function connect(string $host, string $port, string $user, string $pass, string $dbName)
    {
        $parts = [];
        foreach (['host' => $host, 'port' => $port, 'dbname' => $dbName, 'user' => $user, 'password' => $pass] as $key => $value) {
            if ($value !== '') {
                // libpq conninfo quoting (not SQL quoting): passwords with spaces/quotes work.
                $parts[] = $key . "='" . addcslashes($value, "'\\") . "'";
            }
        }

        $conn = @pg_connect(implode(' ', $parts), PGSQL_CONNECT_FORCE_NEW);
        if ($conn === false) {
            throw new RuntimeException('No se puede conectar a la base de datos PostgreSQL.');
        }

        return $conn;
    }

    /**
     * Write the dump of schema `public` to an open gz handle.
     *
     * @param resource $gz
     * @param callable(string): void|null $progress receives a short status line per table
     * @return int number of tables dumped
     */
    public function dump($gz, string $dbName, ?callable $progress = null): int
    {
        $this->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
        try {
            $this->exec("SET LOCAL search_path = ''");
            $this->checkSupportedObjects();

            $this->write($gz, "-- FSFramework Database Backup\n"
                . '-- Generated: ' . date('Y-m-d H:i:s') . "\n"
                . '-- Database: ' . str_replace(["\r", "\n"], ' ', $dbName) . "\n"
                . "-- Source-Database-Type: POSTGRESQL\n"
                . "-- PHP Native Backup (pgsql extension, shell functions disabled)\n\n"
                . "SET statement_timeout = 0;\n"
                . "SET client_encoding = 'UTF8';\n"
                . "SET standard_conforming_strings = on;\n"
                . "SELECT pg_catalog.set_config('search_path', '', false);\n"
                . "SET check_function_bodies = false;\n"
                . "SET client_min_messages = warning;\n\n");

            $sequences = $this->rows(
                "SELECT s.sequencename, s.data_type, s.start_value, s.min_value, s.max_value, s.increment_by,
                        s.cycle, s.cache_size, s.last_value, d.deptype, t.relname AS owner_table, a.attname AS owner_column,
                        pg_catalog.has_sequence_privilege(c.oid, 'SELECT') AS readable
                   FROM pg_catalog.pg_sequences s
                   JOIN pg_catalog.pg_namespace n ON n.nspname = s.schemaname
                   JOIN pg_catalog.pg_class c ON c.relnamespace = n.oid AND c.relname = s.sequencename
              LEFT JOIN pg_catalog.pg_depend d ON d.classid = 'pg_catalog.pg_class'::pg_catalog.regclass
                        AND d.objid = c.oid AND d.refclassid = 'pg_catalog.pg_class'::pg_catalog.regclass
                        AND d.deptype IN ('a', 'i')
              LEFT JOIN pg_catalog.pg_class t ON t.oid = d.refobjid
              LEFT JOIN pg_catalog.pg_attribute a ON a.attrelid = d.refobjid AND a.attnum = d.refobjsubid
                  WHERE s.schemaname = 'public'
               ORDER BY s.sequencename"
            );
            $identities = [];
            foreach ($sequences as $seq) {
                // last_value is also NULL without privileges: restoring it as "unused" would reuse existing keys.
                if ($seq['readable'] !== 't') {
                    throw new RuntimeException('Sin permiso para leer la secuencia ' . $seq['sequencename'] . '; el backup no sería fiable.');
                }
                if ($seq['deptype'] === 'i') {
                    $identities[$seq['owner_table'] . '.' . $seq['owner_column']] = $seq; // created by its column
                    continue;
                }
                $this->write($gz, 'CREATE SEQUENCE public.' . $this->ident($seq['sequencename'])
                    . ' AS ' . $seq['data_type'] . ' ' . $this->sequenceOptions($seq) . ";\n");
            }

            $tables = $this->rows(
                "SELECT c.oid, c.relname FROM pg_catalog.pg_class c
                   JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                  WHERE n.nspname = 'public' AND c.relkind = 'r' AND NOT c.relispartition
               ORDER BY c.relname"
            );

            $copyColumns = [];
            $postData = [];
            $foreignKeys = [];
            foreach ($tables as $table) {
                $qualified = 'public.' . $this->ident($table['relname']);
                [$ddl, $copyColumns[$table['relname']]] = $this->createTable($table['oid'], $table['relname'], $qualified, $identities);
                $this->write($gz, "\n" . $ddl);

                foreach ($this->rows(
                    "SELECT conname, contype, pg_catalog.pg_get_constraintdef(oid) AS def
                       FROM pg_catalog.pg_constraint WHERE conrelid = $1 AND contype IN ('p', 'u', 'c', 'x', 'f')
                   ORDER BY contype, conname",
                    [$table['oid']]
                ) as $con) {
                    $sql = 'ALTER TABLE ONLY ' . $qualified . ' ADD CONSTRAINT ' . $this->ident($con['conname']) . ' ' . $con['def'] . ";\n";
                    if ($con['contype'] === 'f') {
                        $foreignKeys[] = $sql;
                    } else {
                        $postData[] = $sql;
                    }
                }

                foreach ($this->rows(
                    "SELECT pg_catalog.pg_get_indexdef(i.indexrelid) AS def FROM pg_catalog.pg_index i
                      WHERE i.indrelid = $1 AND NOT EXISTS (
                            SELECT 1 FROM pg_catalog.pg_constraint c WHERE c.conindid = i.indexrelid AND c.contype IN ('p', 'u', 'x'))
                   ORDER BY 1",
                    [$table['oid']]
                ) as $index) {
                    $postData[] = $index['def'] . ";\n";
                }
            }

            foreach ($sequences as $seq) {
                if ($seq['deptype'] === 'a' && $seq['owner_table'] !== null) {
                    $this->write($gz, 'ALTER SEQUENCE public.' . $this->ident($seq['sequencename']) . ' OWNED BY public.'
                        . $this->ident($seq['owner_table']) . '.' . $this->ident($seq['owner_column']) . ";\n");
                }
            }

            $count = count($tables);
            foreach ($tables as $i => $table) {
                if ($progress !== null) {
                    $progress('Exportando tabla ' . $table['relname'] . ' (' . ($i + 1) . '/' . $count . ')');
                }
                @set_time_limit(300);
                $this->copyOut($gz, $table['relname'], $copyColumns[$table['relname']]);
            }

            foreach ($sequences as $seq) {
                $target = $seq['deptype'] === 'i'
                    ? 'pg_catalog.pg_get_serial_sequence(' . $this->literal('public.' . $this->ident($seq['owner_table'])) . ', ' . $this->literal($seq['owner_column']) . ')'
                    : $this->literal('public.' . $this->ident($seq['sequencename']));
                $value = $seq['last_value'] ?? $seq['start_value'];
                $this->write($gz, 'SELECT pg_catalog.setval(' . $target . ', ' . $value . ', ' . ($seq['last_value'] === null ? 'false' : 'true') . ");\n");
            }

            $this->write($gz, "\n" . implode('', $postData) . implode('', $foreignKeys) . "\n" . self::END_MARKER . "\n");
            $this->exec('COMMIT');
        } catch (Throwable $e) {
            @pg_query($this->conn, 'ROLLBACK');
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }

        return count($tables);
    }

    /**
     * Replace schema `public` with the dump's content, atomically.
     *
     * @param resource $gz
     * @param callable(int): void|null $progress receives the number of statements executed
     * @return int statements executed
     */
    public function restore($gz, ?callable $progress = null): int
    {
        $this->exec('BEGIN');
        try {
            $this->exec('DROP SCHEMA public CASCADE');
            $this->exec('CREATE SCHEMA public');

            $statement = '';
            $executed = 0;
            $complete = false;
            $this->quote = '';
            while (($line = gzgets($gz)) !== false) {
                if ($statement === '') {
                    $trimmed = trim($line);
                    if ($trimmed === self::END_MARKER || $trimmed === self::PG_DUMP_END_MARKER) {
                        $complete = true;
                        continue;
                    }
                    // Comments and psql meta-commands (pg_dump's \restrict / \unrestrict).
                    if ($trimmed === '' || strncmp($trimmed, '--', 2) === 0 || $trimmed[0] === '\\') {
                        continue;
                    }
                }

                $statement .= $line;
                if (!$this->statementEnds($line)) {
                    continue;
                }

                $sql = trim($statement);
                $statement = '';
                if (preg_match('/^COPY\s.+\sFROM\s+stdin;$/is', $sql) === 1) {
                    $this->copyIn($gz, $sql);
                } elseif (preg_match('/^SET\s+transaction_timeout\b/i', $sql) !== 1) {
                    // transaction_timeout: emitted by pg_dump 17+, unknown to older servers, harmless to skip.
                    $this->exec($sql);
                }

                if (++$executed % 200 === 0) {
                    @set_time_limit(300);
                    if ($progress !== null) {
                        $progress($executed);
                    }
                }
            }

            if (trim($statement) !== '' || !$complete) {
                throw new RuntimeException('El backup está incompleto o dañado (no termina con el marcador de fin). No se ha modificado la base de datos.');
            }

            $this->exec('COMMIT');
        } catch (Throwable $e) {
            @pg_query($this->conn, 'ROLLBACK');
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }

        return $executed;
    }

    /**
     * Track quotes across lines; true when this line ends the statement with ';'.
     */
    private function statementEnds(string $line): bool
    {
        $last = '';
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($this->quote !== '') {
                if ($this->quote[0] === '$') {
                    if (substr_compare($line, $this->quote, $i, strlen($this->quote)) === 0) {
                        $i += strlen($this->quote) - 1;
                        $this->quote = '';
                        $last = '$';
                    }
                } elseif ($char === $this->quote) {
                    $this->quote = ''; // a doubled '' just closes and reopens
                    $last = $char;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $this->quote = $char;
                continue;
            }
            if ($char === '$' && preg_match('/\G\$[A-Za-z_][A-Za-z_0-9]*\$|\G\$\$/', $line, $m, 0, $i) === 1) {
                $this->quote = $m[0];
                $i += strlen($m[0]) - 1;
                continue;
            }
            if ($char === '-' && ($line[$i + 1] ?? '') === '-') {
                break;
            }
            if (!ctype_space($char)) {
                $last = $char;
            }
        }

        return $this->quote === '' && $last === ';';
    }

    /**
     * @return array{0: string, 1: list<string>} CREATE TABLE statement and the columns COPY carries
     */
    /**
     * @param array<string, array<string, string|null>> $identities identity sequences keyed by "table.column"
     */
    private function createTable(string $oid, string $table, string $qualified, array $identities): array
    {
        $generated = $this->version >= 120000 ? 'a.attgenerated' : "''";
        $columns = $this->rows(
            "SELECT a.attname, pg_catalog.format_type(a.atttypid, a.atttypmod) AS type, a.attnotnull,
                    pg_catalog.pg_get_expr(d.adbin, d.adrelid) AS def, a.attidentity, $generated AS attgenerated,
                    CASE WHEN a.attcollation <> 0 AND a.attcollation <> ty.typcollation THEN
                        (SELECT pg_catalog.quote_ident(cn.nspname) || '.' || pg_catalog.quote_ident(co.collname)
                           FROM pg_catalog.pg_collation co JOIN pg_catalog.pg_namespace cn ON cn.oid = co.collnamespace
                          WHERE co.oid = a.attcollation)
                    END AS collation
               FROM pg_catalog.pg_attribute a
               JOIN pg_catalog.pg_type ty ON ty.oid = a.atttypid
          LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
              WHERE a.attrelid = $1 AND a.attnum > 0 AND NOT a.attisdropped
           ORDER BY a.attnum",
            [$oid]
        );

        $definitions = [];
        $copy = [];
        foreach ($columns as $col) {
            $sql = '    ' . $this->ident($col['attname']) . ' ' . $col['type'];
            if ($col['collation'] !== null) {
                $sql .= ' COLLATE ' . $col['collation'];
            }
            if ($col['attgenerated'] === 's' || $col['attgenerated'] === 'v') {
                // Computed by the server, never loaded. 'v' = virtual (PostgreSQL 18), the default kind there.
                $sql .= ' GENERATED ALWAYS AS (' . $col['def'] . ')' . ($col['attgenerated'] === 's' ? ' STORED' : '');
            } elseif ($col['attidentity'] === 'a' || $col['attidentity'] === 'd') {
                $sql .= ' GENERATED ' . ($col['attidentity'] === 'a' ? 'ALWAYS' : 'BY DEFAULT') . ' AS IDENTITY';
                $seq = $identities[$table . '.' . $col['attname']] ?? null;
                if ($seq !== null) {
                    $sql .= ' (' . $this->sequenceOptions($seq) . ')';
                }
            } elseif ($col['def'] !== null) {
                $sql .= ' DEFAULT ' . $col['def'];
            }
            if ($col['attnotnull'] === 't') {
                $sql .= ' NOT NULL';
            }
            $definitions[] = $sql;
            if ($col['attgenerated'] !== 's' && $col['attgenerated'] !== 'v') {
                $copy[] = $col['attname'];
            }
        }

        return ['CREATE TABLE ' . $qualified . " (\n" . implode(",\n", $definitions) . "\n);\n", $copy];
    }

    /**
     * @param resource $gz
     * @param list<string> $columns
     */
    private function copyOut($gz, string $table, array $columns): void
    {
        if ($columns === []) {
            return;
        }

        $list = implode(', ', array_map([$this, 'ident'], $columns));
        $qualified = 'public.' . $this->ident($table);
        // OVERRIDING SYSTEM VALUE is not needed: COPY always accepts values for identity columns.
        $this->write($gz, "\nCOPY " . $qualified . ' (' . $list . ") FROM stdin;\n");

        $this->exec('DECLARE su_dump NO SCROLL CURSOR FOR SELECT ' . $list . ' FROM ' . $qualified);
        do {
            @set_time_limit(300); // per batch: one big table must not hit the host's limit
            $result = $this->exec('FETCH ' . self::FETCH_SIZE . ' FROM su_dump');
            $fetched = 0;
            $buffer = '';
            while (($row = pg_fetch_row($result)) !== false) {
                $fetched++;
                $buffer .= implode("\t", array_map([self::class, 'copyValue'], $row)) . "\n";
            }
            pg_free_result($result);
            if ($buffer !== '') {
                $this->write($gz, $buffer);
            }
        } while ($fetched === self::FETCH_SIZE);
        $this->exec('CLOSE su_dump');

        $this->write($gz, "\\.\n");
    }

    /**
     * @param resource $gz
     */
    private function copyIn($gz, string $copySql): void
    {
        $this->exec($copySql);
        $rows = 0;
        while (true) {
            if (++$rows % 5000 === 0) {
                @set_time_limit(300);
            }
            $line = gzgets($gz);
            if ($line === false) {
                throw new RuntimeException('El backup está truncado dentro de un bloque COPY.');
            }
            if (rtrim($line, "\r\n") === '\\.') {
                break;
            }
            if (!@pg_put_line($this->conn, $line)) {
                throw new RuntimeException('Error enviando datos COPY: ' . pg_last_error($this->conn));
            }
        }
        pg_put_line($this->conn, "\\.\n");
        if (!@pg_end_copy($this->conn)) { // @: the error is rethrown below with pg_last_error()
            throw new RuntimeException('Error importando datos (' . $copySql . '): ' . pg_last_error($this->conn));
        }
    }

    /**
     * Text COPY format: NULL is \N; backslash, newline, CR and tab are escaped.
     */
    public static function copyValue(?string $value): string
    {
        if ($value === null) {
            return '\N';
        }

        return strtr($value, ['\\' => '\\\\', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']);
    }

    /**
     * Objects in `public` this dump cannot recreate block the backup: restoring
     * it would DROP them with the schema and lose them. Tables in other schemas
     * are only a warning, because a restore does not touch them.
     */
    private function checkSupportedObjects(): void
    {
        $checks = [
            'vistas' => "SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                          WHERE n.nspname = 'public' AND c.relkind IN ('v', 'm')",
            'funciones' => "SELECT count(*) FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                             WHERE n.nspname = 'public'",
            'triggers' => "SELECT count(*) FROM pg_catalog.pg_trigger t JOIN pg_catalog.pg_class c ON c.oid = t.tgrelid
                            JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND NOT t.tgisinternal",
            'tablas particionadas' => "SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                                        WHERE n.nspname = 'public' AND (c.relkind = 'p' OR c.relispartition)",
            'tipos propios (enum, dominio, compuesto, rango)' => "SELECT count(*) FROM pg_catalog.pg_type t JOIN pg_catalog.pg_namespace n ON n.oid = t.typnamespace
                          LEFT JOIN pg_catalog.pg_class c ON c.oid = t.typrelid
                          WHERE n.nspname = 'public' AND t.typtype IN ('e', 'd', 'r', 'm', 'c') AND (c.oid IS NULL OR c.relkind = 'c')
                            AND t.typname NOT LIKE '\\_%'",
            'tablas con seguridad a nivel de fila (RLS)' => "SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                          WHERE n.nspname = 'public' AND (c.relrowsecurity OR c.relforcerowsecurity
                                OR EXISTS (SELECT 1 FROM pg_catalog.pg_policy p WHERE p.polrelid = c.oid))",
            // DROP SCHEMA public CASCADE on restore would drop these too.
            'objetos de otros esquemas que dependen de public (vistas, claves foráneas)' => "SELECT count(*) FROM pg_catalog.pg_depend d
                          JOIN pg_catalog.pg_class r ON r.oid = d.refobjid AND d.refclassid = 'pg_catalog.pg_class'::pg_catalog.regclass
                          JOIN pg_catalog.pg_namespace rn ON rn.oid = r.relnamespace AND rn.nspname = 'public'
                     LEFT JOIN pg_catalog.pg_rewrite rw ON d.classid = 'pg_catalog.pg_rewrite'::pg_catalog.regclass AND rw.oid = d.objid
                     LEFT JOIN pg_catalog.pg_constraint co ON d.classid = 'pg_catalog.pg_constraint'::pg_catalog.regclass AND co.oid = d.objid
                          JOIN pg_catalog.pg_class oc ON oc.oid = COALESCE(rw.ev_class, co.conrelid)
                          JOIN pg_catalog.pg_namespace on2 ON on2.oid = oc.relnamespace AND on2.nspname <> 'public'",
            'tablas fuera del esquema public' => "SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                                                   WHERE c.relkind = 'r' AND n.nspname NOT IN ('public', 'pg_catalog', 'information_schema')
                                                     AND n.nspname NOT LIKE 'pg\\_%'",
        ];
        $blocking = [];
        foreach ($checks as $what => $sql) {
            $count = (int) pg_fetch_result($this->exec($sql), 0, 0);
            if ($count === 0) {
                continue;
            }
            if ($what === 'tablas fuera del esquema public') {
                $this->warnings[] = 'Aviso: el backup nativo de PostgreSQL no incluye ' . $what . ' (' . $count . ').';
            } else {
                $blocking[] = $what . ' (' . $count . ')';
            }
        }
        if ($blocking !== []) {
            throw new RuntimeException('El esquema public contiene objetos que el backup nativo no puede recrear: '
                . implode(', ', $blocking) . '. Habilita exec() y pg_dump para hacer la copia.');
        }
    }

    /**
     * @param array<string, string|null> $seq row of pg_sequences
     */
    private function sequenceOptions(array $seq): string
    {
        return 'START WITH ' . $seq['start_value'] . ' INCREMENT BY ' . $seq['increment_by']
            . ' MINVALUE ' . $seq['min_value'] . ' MAXVALUE ' . $seq['max_value']
            . ' CACHE ' . $seq['cache_size'] . ($seq['cycle'] === 't' ? ' CYCLE' : ' NO CYCLE');
    }

    /** @return \PgSql\Result|resource */
    private function exec(string $sql)
    {
        $result = @pg_query($this->conn, $sql);
        if ($result === false) {
            throw new RuntimeException(pg_last_error($this->conn) ?: 'Error de PostgreSQL');
        }

        return $result;
    }

    /**
     * @param list<string> $params
     * @return list<array<string, string|null>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $result = $params === [] ? @pg_query($this->conn, $sql) : @pg_query_params($this->conn, $sql, $params);
        if ($result === false) {
            throw new RuntimeException(pg_last_error($this->conn) ?: 'Error de PostgreSQL');
        }
        $rows = pg_fetch_all($result) ?: [];
        pg_free_result($result);

        return $rows;
    }

    /**
     * @param resource $gz
     */
    private function write($gz, string $data): void
    {
        if (gzwrite($gz, $data) !== strlen($data)) {
            throw new RuntimeException('No se pudo escribir el backup (¿disco lleno?).');
        }
    }

    private function ident(string $name): string
    {
        return pg_escape_identifier($this->conn, $name);
    }

    private function literal(string $value): string
    {
        return pg_escape_literal($this->conn, $value);
    }
}
