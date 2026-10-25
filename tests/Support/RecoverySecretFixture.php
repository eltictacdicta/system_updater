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
 * A throwaway install root for the recovery console's per-installation secret.
 *
 * The plugin suite has no database and must never touch the real installation:
 * a first resolution against the shipped tree would write a real secret and a
 * real unlock value. Every test therefore builds its own tree under
 * `sys_get_temp_dir()` and tears it down, even when an assertion fails.
 *
 * Layout of one fixture (`$base` is a random directory, `$root` is the
 * installation the console believes it is running in):
 *
 *     <base>/                          the parent of the install root, i.e. the
 *                                      outside-webroot candidate location
 *     <base>/root/                     the install root (the webroot)
 *     <base>/root/plugins/system_updater/recovery.php
 *     <base>/root/plugins/system_updater/lib/recovery_secret.php
 *     <base>/root/config.php           optional; see writeSentinelConfig()
 *     <base>/root/config_loaded.sentinel
 *                                      exists iff config.php really executed
 *     <scratch>/secret_error_log.txt   the `error_log` sink of every subprocess
 *     <scratch>/procedure_stdout.txt   captured stdout of the last subprocess
 *     <scratch>/procedure_stderr.txt   captured stderr of the last subprocess
 *
 * `<scratch>` is a second temp directory, deliberately *not* under `<base>`:
 * the loud-error test makes the installation and its parent `0555`, and the
 * harness must still be able to observe the failure it caused.
 *
 * Subprocesses are the only way to observe a real CLI SAPI, a real exit code
 * and a real `error_log()` file. They run as the same user as the suite, so
 * `chmod 0555` on a directory refuses writes for both — unless that user is
 * privileged, which is why {@see makeSecretLocationsUnwritable()} reports
 * whether the state was reached instead of assuming it.
 */
final class RecoverySecretFixture
{
    /**
     * The operator-facing file name. It is part of the contract: an operator
     * who rescues an installation by hand needs to know what to look for.
     */
    public const SECRET_FILENAME = 'system_updater_recovery_secret.php';

    /** Parent of the install root: the outside-webroot candidate. */
    public string $base;

    /** The install root the console resolves its secret for. */
    public string $root;

    /** The file every subprocess writes `error_log()` output into. */
    public string $logFile;

    /**
     * Somewhere the harness can always write.
     *
     * It is deliberately outside `$base`: a test that makes the installation
     * and its parent unwritable (the RACC-02 loud-error state) must still be
     * able to capture stdout, stderr and the `error_log` sink, otherwise the
     * observation would fail for the very reason under test.
     */
    public string $scratch;

    public function __construct()
    {
        $suffix = bin2hex(random_bytes(6));
        $this->base = rtrim(sys_get_temp_dir(), '/') . '/su_recsec_' . $suffix;
        $this->root = $this->base . '/root';
        $this->scratch = rtrim(sys_get_temp_dir(), '/') . '/su_recsec_sinks_' . $suffix;
        $this->logFile = $this->scratch . '/secret_error_log.txt';

        foreach ([$this->root, $this->scratch] as $directory) {
            if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException('cannot create the fixture directory at ' . $directory);
            }
        }
    }

    /**
     * RACC-02's "no writable location" state: neither the sibling of the
     * installation nor the install root itself accepts a write, so the
     * last-resort `tmp/` cannot be created either.
     *
     * The return value is the *observation*, not the attempt: `chmod` is
     * enforced by the kernel's permission check, and a privileged process
     * (root, which is what a good many CI containers give PHPUnit) bypasses
     * that check entirely. There the modes are applied and the directory stays
     * writable, so the caller can skip a case whose precondition the host
     * cannot produce instead of asserting against a state that never happened.
     *
     * @return bool whether the state was actually reached
     */
    public function makeSecretLocationsUnwritable(): bool
    {
        @chmod($this->base, 0555);
        @chmod($this->root, 0555);

        clearstatcache();

        return !is_writable($this->base) && !is_writable($this->root);
    }

    public function restoreSecretLocationModes(): void
    {
        @chmod($this->base, 0777);
        @chmod($this->root, 0777);
    }

    /**
     * Copy the console and its plugin-local secret library into the install
     * root. Nothing else is copied: no `vendor/`, no `base/`, no `src/`, which
     * is exactly the damaged-core installation RACC-01 exists for.
     */
    public function installConsole(): void
    {
        $source = dirname(__DIR__, 2);

        foreach (['recovery.php', 'lib/recovery_secret.php'] as $relative) {
            $target = $this->root . '/plugins/system_updater/' . $relative;
            $directory = dirname($target);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException('cannot create ' . $directory);
            }

            $from = $source . '/' . $relative;
            if (!copy($from, $target)) {
                throw new \RuntimeException('cannot copy ' . $from . ' into the fixture');
            }
        }
    }

    /**
     * Copy the library alone into an arbitrary directory below the install
     * root, instead of the documented `<root>/plugins/<plugin>/lib` depth.
     *
     * That is the shape the fallback install-root resolution must refuse: from
     * a misplaced library the three-level guess names a directory that is not
     * the installation, and any candidate built from it cannot be proven to sit
     * outside the webroot.
     *
     * @return string the copied library
     */
    public function installLibraryAt(string $relativeDirectory): string
    {
        $directory = rtrim($this->root . '/' . trim($relativeDirectory, '/'), '/');
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('cannot create the fixture directory at ' . $directory);
        }

        $target = $directory . '/recovery_secret.php';
        if (!copy(dirname(__DIR__, 2) . '/lib/recovery_secret.php', $target)) {
            throw new \RuntimeException('cannot copy the library into ' . $directory);
        }

        return $target;
    }

    /**
     * The damaged-`config.php` shape: the first statement is a sentinel write,
     * so "was this file executed?" is a filesystem fact rather than a guess.
     * `$extra` is appended verbatim, which is how a parse error or an operator
     * pin is injected.
     */
    public function writeSentinelConfig(string $extra = ''): void
    {
        $sentinel = var_export($this->root . '/config_loaded.sentinel', true);
        $body = "<?php\nfile_put_contents({$sentinel}, 'loaded');\n" . $extra;
        file_put_contents($this->root . '/config.php', $body);
    }

    public function writeConfig(string $body): void
    {
        file_put_contents($this->root . '/config.php', $body);
    }

    public function configWasLoaded(): bool
    {
        return is_file($this->root . '/config_loaded.sentinel');
    }

    /** Directory that holds the outside-webroot candidate. */
    public function siblingDirectory(): string
    {
        return $this->base;
    }

    /** Directory that holds the inside-webroot, last-resort candidate. */
    public function nestedDirectory(): string
    {
        return $this->root . '/tmp';
    }

    public function siblingSecretPath(): string
    {
        return $this->siblingDirectory() . '/' . self::SECRET_FILENAME;
    }

    public function nestedSecretPath(): string
    {
        return $this->nestedDirectory() . '/' . self::SECRET_FILENAME;
    }

    /** Run the console as a real CLI process. */
    public function runConsole(string $arguments = ''): array
    {
        $script = $this->root . '/plugins/system_updater/recovery.php';
        $command = escapeshellarg(PHP_BINARY)
            . ' -d error_log=' . escapeshellarg($this->logFile)
            . ' ' . escapeshellarg($script)
            . ($arguments !== '' ? ' ' . $arguments : '');

        return $this->runProcess($command);
    }

    /**
     * Run a snippet with the plugin-local secret library preloaded, in a fresh
     * process. Used wherever a constant definition would otherwise leak into
     * the PHPUnit process (a pin, or a class-loading assertion).
     */
    public function runSnippet(string $phpCode): array
    {
        $runner = $this->root . '/runner.php';
        file_put_contents(
            $runner,
            "<?php\ndeclare(strict_types=1);\n"
            . "require_once __DIR__ . '/plugins/system_updater/lib/recovery_secret.php';\n"
            . $phpCode
        );

        return $this->runProcess(
            escapeshellarg(PHP_BINARY)
            . ' -d error_log=' . escapeshellarg($this->logFile)
            . ' ' . escapeshellarg($runner)
        );
    }

    /** Execute a single PHP file, the way a PHP-enabled web server would. */
    public function runPhpFile(string $path): array
    {
        return $this->runProcess(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path));
    }

    /** Everything `error_log()` wrote during this fixture's life. */
    public function errorLogContents(): string
    {
        clearstatcache(true, $this->logFile);

        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    public function clearErrorLog(): void
    {
        @unlink($this->logFile);
        clearstatcache(true, $this->logFile);
    }

    /** Read a file's mode the way the RACC-02 `0600` claim has to be checked. */
    public function modeOf(string $path): int
    {
        clearstatcache(true, $path);

        return fileperms($path) & 0777;
    }

    public function mtimeOf(string $path): int
    {
        clearstatcache(true, $path);

        return filemtime($path);
    }

    public function destroy(): void
    {
        @chmod($this->base, 0777);
        @chmod($this->root, 0777);
        self::removeTree($this->base);
        self::removeTree($this->scratch);
    }

    /**
     * Best-effort recursive removal. Directories are re-opened before they are
     * emptied so a test that made one unwritable can still clean up.
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
     * @return array{stdout: string, stderr: string, code: int}
     */
    private function runProcess(string $command): array
    {
        $stdout = $this->scratch . '/procedure_stdout.txt';
        $stderr = $this->scratch . '/procedure_stderr.txt';
        @unlink($stdout);
        @unlink($stderr);

        $exitCode = 0;
        $output = [];
        exec(
            $command
            . ' > ' . escapeshellarg($stdout)
            . ' 2> ' . escapeshellarg($stderr),
            $output,
            $exitCode
        );

        return [
            'stdout' => is_file($stdout) ? (string) file_get_contents($stdout) : '',
            'stderr' => is_file($stderr) ? (string) file_get_contents($stderr) : '',
            'code' => $exitCode,
        ];
    }
}
