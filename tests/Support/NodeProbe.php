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
 * Runs the JavaScript the console emits, because the browser is not available
 * to this suite.
 *
 * The restore driver is behaviour, not markup: "which action does the retry
 * send?" is answered by executing the generated script, not by reading it. A
 * `node` process is the same interpreter a browser is, minus the DOM, so the
 * driver is pointed at the stubbed browser in `node/restore-drive-harness.js`.
 *
 * `node --check` is exposed too: it is the parse gate for generated JavaScript,
 * and the driver is generated twice per render (with and without a token).
 *
 * No test data is interpolated into a shell: the driver travels as a file, and
 * arguments are escaped.
 */
final class NodeProbe
{
    private static ?bool $available = null;

    public static function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        if (!function_exists('exec')) {
            return self::$available = false;
        }

        $lines = [];
        $exit = 1;
        @exec('command -v node 2>/dev/null', $lines, $exit);

        return self::$available = $exit === 0 && $lines !== [];
    }

    /**
     * `node --check` over one script.
     *
     * @return array{output: string, exit: int}
     */
    public static function check(string $source): array
    {
        return self::withScript($source, static function (string $file): array {
            $lines = [];
            $exit = 0;
            @exec('node --check ' . escapeshellarg($file) . ' 2>&1', $lines, $exit);

            return ['output' => implode("\n", $lines), 'exit' => $exit];
        });
    }

    /**
     * Drive one scenario of the emitted restore client.
     *
     * @return array<string, mixed>|null the harness JSON, or `null` when it printed anything else
     */
    public static function driveRestore(string $driverScript, string $scenario): ?array
    {
        $harness = __DIR__ . '/node/restore-drive-harness.js';

        $result = self::withScript($driverScript, static function (string $file) use ($harness, $scenario): array {
            $lines = [];
            $exit = 0;
            @exec(
                'node ' . escapeshellarg($harness)
                . ' ' . escapeshellarg($file)
                . ' ' . escapeshellarg($scenario)
                . ' 2>&1',
                $lines,
                $exit
            );

            return ['output' => implode("\n", $lines), 'exit' => $exit];
        });

        $decoded = json_decode($result['output'], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param callable(string): array{output: string, exit: int} $run
     *
     * @return array{output: string, exit: int}
     */
    private static function withScript(string $source, callable $run): array
    {
        $dir = rtrim(sys_get_temp_dir(), '/') . '/su_node_' . bin2hex(random_bytes(6));
        @mkdir($dir, 0700, true);
        $file = $dir . '/driver.js';
        file_put_contents($file, $source);

        try {
            return $run($file);
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }
}
