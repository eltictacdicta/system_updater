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

namespace Tests\SystemUpdater\Support;

/**
 * The `php -S` + `curl` seam for the recovery console.
 *
 * Why a subprocess server and not a unit test
 * -------------------------------------------
 * Everything RACC-04 and RACC-05 claim is a *response* fact: HTTP status, the
 * header set, `Set-Cookie`, the body bytes and whether a session file appeared.
 * Under the CLI SAPI `headers_list()` is empty and `header()`/`setcookie()` are
 * no-ops, so none of that is observable in-process. `php-cgi` is not installed
 * in this container either. The built-in server is therefore the only SAPI in
 * which a status code and a header list exist at all.
 *
 * The one thing it cannot prove, recorded here for the next reader (risk D3 in
 * `tasks.md`): the server generates its own 404 page and echoes the requested
 * path into it, so `/nope.php` (541 bytes) and `/other.php` (542 bytes) are not
 * byte-identical to each other and no PHP script can match them. "Byte-identical
 * to a non-existent path" is therefore asserted as the falsifiable subset the
 * spec records: equal status, equal header set that PHP controls, and a body
 * that does not vary with secret correctness.
 *
 * Layout of one fixture
 * ---------------------
 *     <base>/                                   parent of the install root, i.e.
 *                                               the outside-webroot secret candidate
 *     <base>/root/                              the install root == document root
 *     <base>/root/plugins/system_updater/...    the copied console graph
 *     <base>/root/config.php                    optional sentinel config
 *     <base>/root/config_loaded.sentinel        exists iff config.php really ran
 *     <scratch>/sessions/                       session.save_path of the server
 *     <scratch>/server_error.log                error_log() of the server
 *     <scratch>/server.log                      stdout/stderr of `php -S`
 *     <scratch>/cookie_jar.txt                  curl's cookie jar
 *
 * The scratch tree is deliberately outside `<base>`: a case that makes the
 * installation unwritable must still be able to capture what the server did.
 *
 * Safety: this never points at the real installation. A first run against
 * `FS_FOLDER` would generate and log a real secret and a real unlock value, so
 * every document root here is a throwaway tree that `destroy()` removes —
 * including when an assertion fails, through both the test's `finally` and a
 * `register_shutdown_function` backstop.
 */
final class ConsoleHttpHarness
{
    /** The operator-facing secret file name; part of the contract, not a detail. */
    public const SECRET_FILENAME = 'system_updater_recovery_secret.php';

    /**
     * The console's own render graph, as the plugin ships it.
     *
     * Kept as a list instead of "copy the whole plugin" on purpose: the damaged
     * core installation RACC-01 exists for has no `vendor/`, no `base/` and no
     * `src/`, and a copy of the whole plugin would hide a new dependency the
     * console grows on some file that is not listed here.
     */
    public const CONSOLE_FILES = [
        'recovery.php',
        'lib/recovery_secret.php',
    ];

    /** Parent of the install root: the outside-webroot secret candidate. */
    public string $base;

    /** The install root, and the document root of the server. */
    public string $root;

    /** Always writable; holds the sinks, the session store and the cookie jar. */
    public string $scratch;

    public string $sessions;

    public string $errorLog;

    public string $serverLog;

    public string $cookieJar;

    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    private bool $destroyed = false;

    /**
     * @param string[] $files plugin-relative files to copy into the install root
     */
    public function __construct(private readonly array $files = self::CONSOLE_FILES)
    {
        $suffix = bin2hex(random_bytes(6));
        $this->base = rtrim(sys_get_temp_dir(), '/') . '/su_console_' . $suffix;
        $this->root = $this->base . '/root';
        $this->scratch = rtrim(sys_get_temp_dir(), '/') . '/su_console_sinks_' . $suffix;
        $this->sessions = $this->scratch . '/sessions';
        $this->errorLog = $this->scratch . '/server_error.log';
        $this->serverLog = $this->scratch . '/server.log';
        $this->cookieJar = $this->scratch . '/cookie_jar.txt';

        foreach ([$this->root, $this->sessions] as $directory) {
            if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException('cannot create the harness directory at ' . $directory);
            }
        }
    }

    /**
     * `curl` is the client the tasks declare, and it is the only one that gives
     * a cookie jar with no extra dependency. Without it every case that needs a
     * header list is skipped visibly rather than passing without observing.
     */
    public static function curlAvailable(): bool
    {
        $output = [];
        $code = 0;
        @exec('command -v curl', $output, $code);

        return $code === 0 && $output !== [];
    }

    /**
     * Copy the console graph, write the config and start the server.
     *
     * `$configBody` is appended verbatim to the sentinel preamble, which is how
     * an `FS_SESSION_NAME`, database constants or a parse error are injected.
     */
    public function boot(string $configBody = ''): void
    {
        $this->installFiles();
        $this->writeConfig($configBody);
        $this->startServer();

        register_shutdown_function(function (): void {
            $this->destroy();
        });
    }

    /**
     * Copy the declared files into `<root>/plugins/system_updater/`, creating
     * the documented depth so the console's own install-root arithmetic and the
     * secret library's verification see a real installation.
     */
    public function installFiles(): void
    {
        $source = dirname(__DIR__, 2);

        foreach ($this->files as $relative) {
            $from = $source . '/' . $relative;
            if (!is_file($from)) {
                throw new \RuntimeException('the console graph is missing ' . $from);
            }

            $this->installFixtureFile($relative, $from);
        }
    }

    /**
     * Copy one plugin file into the fixture tree.
     *
     * Exposed so a case can add a file the default graph does not carry (the
     * marker's non-transferability case needs `download_backup.php`).
     */
    public function installFixtureFile(string $relative, ?string $from = null): void
    {
        $target = $this->root . '/plugins/system_updater/' . $relative;
        $directory = dirname($target);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('cannot create ' . $directory);
        }

        $source = $from ?? dirname(__DIR__, 2) . '/' . $relative;
        if (!is_file($source)) {
            throw new \RuntimeException('cannot copy ' . $source . ' into the fixture');
        }

        if (!copy($source, $target)) {
            throw new \RuntimeException('cannot copy ' . $source . ' into the fixture');
        }
    }

    /**
     * The sentinel shape: the first statement writes a file, so "was
     * `config.php` executed?" is a filesystem fact and not an inference from
     * the body. `$extra` is appended verbatim.
     */
    public function writeConfig(string $extra = ''): void
    {
        $sentinel = var_export($this->root . '/config_loaded.sentinel', true);
        file_put_contents(
            $this->root . '/config.php',
            "<?php\nfile_put_contents({$sentinel}, 'loaded');\n" . $extra
        );
    }

    public function writeRawConfig(string $body): void
    {
        file_put_contents($this->root . '/config.php', $body);
    }

    public function configWasLoaded(): bool
    {
        clearstatcache(true, $this->root . '/config_loaded.sentinel');

        return is_file($this->root . '/config_loaded.sentinel');
    }

    public function forgetConfigLoad(): void
    {
        @unlink($this->root . '/config_loaded.sentinel');
        clearstatcache(true, $this->root . '/config_loaded.sentinel');
    }

    public function siblingSecretPath(): string
    {
        return $this->base . '/' . self::SECRET_FILENAME;
    }

    /**
     * Persist a secret the way the library does, so the gate has a value to
     * compare against without a first-use generation in the middle of a case.
     */
    public function persistSecret(string $value): void
    {
        $payload = "<?php\n// fixture\nhttp_response_code(404);\nexit;\n" . '# secret: ' . $value . "\n";
        file_put_contents($this->siblingSecretPath(), $payload);
        chmod($this->siblingSecretPath(), 0600);
    }

    public function secretExists(): bool
    {
        clearstatcache(true, $this->siblingSecretPath());

        return is_file($this->siblingSecretPath());
    }

    /** Everything `error_log()` wrote during this fixture's life. */
    public function errorLogContents(): string
    {
        clearstatcache(true, $this->errorLog);

        return is_file($this->errorLog) ? (string) file_get_contents($this->errorLog) : '';
    }

    /**
     * Session files the server created, sorted.
     *
     * "No session file" is one of RACC-04's asserted facts; it only means
     * something because `session.save_path` points at this empty directory.
     *
     * @return string[]
     */
    public function sessionFiles(): array
    {
        clearstatcache();

        $entries = is_dir($this->sessions) ? scandir($this->sessions) : [];
        $files = [];

        foreach ($entries ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $files[] = $entry;
        }

        sort($files);

        return $files;
    }

    /**
     * Write a session file with PHP's own encoder, so a case can present a
     * cookie for a session it controls — the only way to ask "does the marker
     * alone authorize anything?" without a login.
     *
     * @param array<string, mixed> $data
     */
    public function seedSession(string $sessionName, string $sessionId, array $data): void
    {
        $runner = $this->scratch . '/seed_session_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents(
            $runner,
            "<?php\n"
            . 'ini_set("session.save_path", ' . var_export($this->sessions, true) . ");\n"
            . 'session_name(' . var_export($sessionName, true) . ");\n"
            . 'session_id(' . var_export($sessionId, true) . ");\n"
            . "session_start();\n"
            . '$_SESSION = ' . var_export($data, true) . ";\n"
            . "session_write_close();\n"
        );

        $output = [];
        $code = 0;
        @exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1',
            $output,
            $code
        );
        @unlink($runner);

        if ($code !== 0) {
            throw new \RuntimeException('cannot seed the fixture session: ' . implode("\n", $output));
        }

        if (!is_file($this->sessions . '/sess_' . $sessionId)) {
            throw new \RuntimeException('the seeded session file was not written: ' . implode("\n", $output));
        }
    }

    /**
     * One HTTP request.
     *
     * @param array{jar?: bool, cookies?: array<string, string>, post?: array<string, string>, follow?: bool} $options
     * @return array{status: int, headers: string, headerLines: string[], body: string}
     */
    public function request(string $path, array $options = []): array
    {
        $headerFile = $this->scratch . '/response_headers_' . bin2hex(random_bytes(4)) . '.txt';
        $bodyFile = $this->scratch . '/response_body_' . bin2hex(random_bytes(4)) . '.txt';

        $command = 'curl -sS --max-time 20 -D ' . escapeshellarg($headerFile)
            . ' -o ' . escapeshellarg($bodyFile)
            . ' -w %{http_code}';

        if (!empty($options['jar'])) {
            $command .= ' -c ' . escapeshellarg($this->cookieJar)
                . ' -b ' . escapeshellarg($this->cookieJar);
        } elseif (isset($options['cookies']) && $options['cookies'] !== []) {
            $pairs = [];
            foreach ($options['cookies'] as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $command .= ' -H ' . escapeshellarg('Cookie: ' . implode('; ', $pairs));
        }

        if (!empty($options['follow'])) {
            $command .= ' -L';
        }

        $command .= ' -X ' . (isset($options['post']) ? 'POST' : 'GET');

        foreach ($options['post'] ?? [] as $field => $value) {
            $command .= ' --data-urlencode ' . escapeshellarg($field . '=' . $value);
        }

        $command .= ' ' . escapeshellarg($this->url($path));

        $statusFile = $this->scratch . '/response_status_' . bin2hex(random_bytes(4)) . '.txt';
        $errorFile = $this->scratch . '/response_error_' . bin2hex(random_bytes(4)) . '.txt';

        $output = [];
        $code = 0;
        @exec(
            $command
            . ' > ' . escapeshellarg($statusFile)
            . ' 2> ' . escapeshellarg($errorFile),
            $output,
            $code
        );

        $rawHeaders = is_file($headerFile) ? (string) file_get_contents($headerFile) : '';
        $body = is_file($bodyFile) ? (string) file_get_contents($bodyFile) : '';
        $status = (int) trim(is_file($statusFile) ? (string) file_get_contents($statusFile) : '0');
        $curlError = is_file($errorFile) ? trim((string) file_get_contents($errorFile)) : '';

        foreach ([$headerFile, $bodyFile, $statusFile, $errorFile] as $file) {
            @unlink($file);
        }

        return [
            'status' => $status,
            'headers' => $rawHeaders,
            'headerLines' => self::splitHeaders($rawHeaders),
            'body' => $body,
            'curlExit' => $code,
            'curlError' => $curlError,
        ];
    }

    public function url(string $path): string
    {
        return 'http://127.0.0.1:' . $this->port . '/' . ltrim($path, '/');
    }

    /** The `Set-Cookie` names in a response, so a case can name the session. */
    public function setCookieNames(array $response): array
    {
        $names = [];

        foreach ($response['headerLines'] as $line) {
            if (stripos($line, 'set-cookie:') !== 0) {
                continue;
            }

            $value = trim(substr($line, strlen('set-cookie:')));
            $pair = explode(';', $value, 2)[0];
            $names[] = explode('=', $pair, 2)[0];
        }

        return $names;
    }

    /** @return string[] */
    public function headersMatching(array $response, string $name): array
    {
        $matched = [];

        foreach ($response['headerLines'] as $line) {
            if (stripos($line, $name . ':') === 0) {
                $matched[] = $line;
            }
        }

        return $matched;
    }

    /** Cookies curl's jar holds now, as name => value. */
    public function jarCookies(): array
    {
        clearstatcache(true, $this->cookieJar);
        if (!is_file($this->cookieJar)) {
            return [];
        }

        $cookies = [];
        foreach (explode("\n", (string) file_get_contents($this->cookieJar)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#HttpOnly_')) {
                // `#HttpOnly_<domain>` is a real cookie line, not a comment.
                $line = substr($line, strlen('#HttpOnly_'));
            } elseif (str_starts_with($line, '#')) {
                continue;
            }

            $columns = explode("\t", $line);
            if (count($columns) < 7) {
                continue;
            }

            $cookies[$columns[5]] = $columns[6];
        }

        return $cookies;
    }

    public function clearCookieJar(): void
    {
        @unlink($this->cookieJar);
        clearstatcache(true, $this->cookieJar);
    }

    /**
     * Stop the server and remove every tree this fixture created.
     *
     * Idempotent: the test's `finally` and the shutdown backstop both call it.
     */
    public function destroy(): void
    {
        if ($this->destroyed) {
            return;
        }
        $this->destroyed = true;

        if (is_resource($this->server)) {
            @proc_terminate($this->server);
            @proc_close($this->server);
        }
        $this->server = null;

        self::removeTree($this->base);
        self::removeTree($this->scratch);
    }

    /**
     * Best-effort recursive removal.
     */
    public static function removeTree(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @chmod($path, 0600);
            @unlink($path);

            return;
        }

        @chmod($path, 0700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    /**
     * @return string[]
     */
    private static function splitHeaders(string $raw): array
    {
        $lines = [];

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (trim($line) !== '') {
                $lines[] = rtrim($line, "\r");
            }
        }

        return $lines;
    }

    /**
     * Start `php -S` on an explicit port.
     *
     * `php -S 127.0.0.1:0` does not bind an ephemeral port, so the port is
     * drawn from the documented range and a collision is retried once. Every
     * failure mode here is fatal to the fixture rather than a silent skip: a
     * server that does not answer would make the assertions pass against an
     * empty response.
     */
    private function startServer(): void
    {
        $lastError = '';

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $port = random_int(20000, 40000);
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $this->serverLog, 'a'],
                2 => ['file', $this->serverLog, 'a'],
            ];

            $server = @proc_open(
                [
                    PHP_BINARY,
                    '-d', 'error_log=' . $this->errorLog,
                    '-d', 'session.save_path=' . $this->sessions,
                    '-S', '127.0.0.1:' . $port,
                    '-t', $this->root,
                ],
                $descriptors,
                $pipes
            );

            if (!is_resource($server)) {
                $lastError = 'proc_open failed';

                continue;
            }

            if ($this->waitForServer($port)) {
                $this->server = $server;
                $this->port = $port;

                return;
            }

            @proc_terminate($server);
            @proc_close($server);
            $lastError = 'the built-in server did not accept a connection on port ' . $port;
        }

        throw new \RuntimeException('the console harness could not start: ' . $lastError);
    }

    private function waitForServer(int $port): bool
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorString, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return true;
            }

            usleep(50_000);
        }

        return false;
    }
}
