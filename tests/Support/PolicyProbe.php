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
 * A throwaway PHP process, for the two facts this suite cannot observe in its
 * own.
 *
 * 1. **The autoloader is absent.** `recovery_policy.php` must answer "is the
 *    core throttle available?" without ever loading it (RACC-01), and this
 *    suite's bootstrap has already required the Composer autoloader. Only a
 *    process that never loaded it can prove the answer is `false` there.
 * 2. **The core's reader and the plugin's parser agree.** `fs_ip_filter` reads
 *    `FS_FOLDER . '/tmp/' . FS_TMP_NAME . 'ip.log'` from constants fixed by the
 *    bootstrap, so pointing it at a fixture is only possible before those
 *    constants are defined — i.e. in a child process that defines them itself.
 *
 * The script is written into `sys_get_temp_dir()` and always removed, and the
 * caller supplies nothing but code: no shell interpolation of test data happens
 * here, values travel through `var_export()`.
 */
final class PolicyProbe
{
    /**
     * @param list<string> $requires absolute paths, required in order
     * @param array<string, mixed> $defines constants defined before the requires
     *
     * @return array{output: string, exit: int}
     */
    public static function run(array $requires, array $defines, string $code): array
    {
        $script = "<?php\ndeclare(strict_types=1);\n";

        foreach ($defines as $name => $value) {
            $script .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
        }

        foreach ($requires as $require) {
            $script .= 'require_once ' . var_export($require, true) . ";\n";
        }

        $script .= $code . "\n";

        $file = rtrim(sys_get_temp_dir(), '/') . '/su_policy_probe_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($file, $script);

        try {
            $lines = [];
            $exit = 0;
            @exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $lines, $exit);

            return ['output' => implode("\n", $lines), 'exit' => $exit];
        } finally {
            @unlink($file);
        }
    }

    /**
     * The decoded JSON a probe printed, or `null` when it printed anything else.
     *
     * Every probe below writes exactly one JSON document and nothing more, so a
     * warning, a notice or a fatal error turns into `null` instead of being read
     * as a verdict.
     *
     * @return array<string, mixed>|null
     */
    public static function decodeJson(string $output): ?array
    {
        $decoded = json_decode($output, true);

        return is_array($decoded) ? $decoded : null;
    }
}
