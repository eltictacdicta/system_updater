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

namespace Tests\SystemUpdater;

use Tests\SystemUpdater\Support\ConsoleHttpTestCase;

require_once __DIR__ . '/Support/ConsoleHttpTestCase.php';

/**
 * RACC-08 — the session bootstrap degrades without core code.
 *
 * The name must resolve from `config.php` alone when no core class is loadable,
 * and a name that does not match the browser's cookie is **not** an
 * authorization failure: it only means the operator is not single-signed-on from
 * the panel and will be asked for credentials. Neither case may show an error or
 * log a security event — the console is reachable, it just wants a login.
 *
 * Both halves are observed over `php -S`: `session_start()` writes the cookie
 * name into `Set-Cookie`, and the CLI SAPI has no header list at all. The
 * fixture has no `vendor/`, no `base/` and no `src/`, so "no core class is
 * loadable" is a property of the tree rather than a mock.
 */
final class RecoverySessionBootstrapTest extends ConsoleHttpTestCase
{
    private const CONSOLE = '/plugins/system_updater/recovery.php';
    private const CONFIGURED_NAME = 'FSSESS_fixture';

    private const FILES = [
        'recovery.php',
        'lib/recovery_secret.php',
        'lib/recovery_session.php',
        'lib/session_auth.php',
        'lib/backup_manager.php',
        'lib/SqlDumpReader.php',
    ];

    /**
     * RACC-08 "the name resolves from `config.php` alone".
     */
    public function testTheSessionNameComesFromConfigWithNoCoreClassLoadable(): void
    {
        $secret = bin2hex(random_bytes(32));
        $harness = $this->newHarness(self::FILES);
        $harness->boot(implode("\n", $this->configLines([
            "define('FS_SESSION_NAME', '" . self::CONFIGURED_NAME . "');",
        ])));
        $harness->persistSecret($secret);

        $response = $harness->request(self::CONSOLE . '?secret=' . $secret);

        $this->assertSame(200, $response['status'], 'the console boots: ' . $response['body']);
        $this->assertSame(
            [self::CONFIGURED_NAME],
            $harness->setCookieNames($response),
            'FS_SESSION_NAME from config.php is the session the console opens'
        );

        $this->assertDirectoryDoesNotExist($harness->root . '/vendor', 'premise: no Composer autoloader exists');
        $this->assertDirectoryDoesNotExist($harness->root . '/base');
        $this->assertDirectoryDoesNotExist($harness->root . '/src');

        $library = self::codeWithoutComments(
            (string) file_get_contents(dirname(__DIR__) . '/lib/recovery_session.php')
        );

        $this->assertSame(1, substr_count($library, 'class_exists('), 'the library performs exactly one class lookup');
        $this->assertMatchesRegularExpression(
            '/class_exists\([^)]*,\s*false\s*\)/',
            $library,
            'and it passes false as the second argument: the core class is consulted only when it is'
            . ' already loaded, never autoloaded (RACC-01)'
        );
    }

    /**
     * RACC-08 "a name mismatch asks for credentials, it does not deny".
     *
     * The config defines no `FS_SESSION_NAME` and the fixture holds no secret
     * key, so the name comes from the third step of the resolution — the local
     * derivation. It is compared against the core's own formula through
     * `fs_session_identity_name_from_seed()`, which is the point: the console
     * must derive the same name the panel does, not a parallel one.
     */
    public function testANameNoBrowserCookieMatchesAsksForCredentialsWithoutDenying(): void
    {
        $secret = bin2hex(random_bytes(32));
        $harness = $this->newHarness(self::FILES);
        $harness->boot(implode("\n", $this->configLines([])));
        $harness->persistSecret($secret);

        $response = $harness->request(self::CONSOLE . '?secret=' . $secret);

        $this->assertSame(200, $response['status'], 'a name mismatch asks for credentials: ' . $response['body']);
        $this->assertStringContainsString('name="nick"', $response['body'], 'the login form is rendered');
        $this->assertStringNotContainsString('alert-danger', $response['body'], 'with no error banner');
        $this->assertStringNotContainsString('Acceso denegado', $response['body']);

        require_once FS_FOLDER . '/base/fs_session_identity.php';
        $expected = fs_session_identity_name_from_seed($harness->root);

        $this->assertSame(
            [$expected],
            $harness->setCookieNames($response),
            'the local derivation reproduces the core formula over the same seed'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/SECURITY|security_event|Acceso denegado|rejected/i',
            $harness->errorLogContents(),
            'a mismatch is not a security event and is not logged as one'
        );
    }

    /**
     * @param string[] $extra
     * @return string[]
     */
    private function configLines(array $extra): array
    {
        return array_merge([
            "define('FS_DB_HOST', '127.0.0.1');",
            "define('FS_DB_PORT', 1);",
            "define('FS_DB_USER', 'nobody');",
            "define('FS_DB_PASS', 'nothing');",
            "define('FS_DB_NAME', 'none');",
        ], $extra, ['']);
    }
}
