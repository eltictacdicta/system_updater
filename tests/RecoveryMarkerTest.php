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

use Tests\SystemUpdater\Support\ConsoleHttpHarness;
use Tests\SystemUpdater\Support\ConsoleHttpTestCase;

require_once __DIR__ . '/Support/ConsoleHttpTestCase.php';

/**
 * RACC-05 — after the gate, authorization is carried by the session.
 *
 * The gate is a query parameter, so without a marker every reload and the login
 * POST itself would need the secret re-supplied: impractical, and it multiplies
 * the places the value leaks into (history, `Referer`, logs). The marker is
 * written only after a successful constant-time comparison, lives in the
 * console-only namespace of the session the console opened, and must satisfy
 * nothing outside the console.
 *
 * Everything here is observed over `php -S` with a curl cookie jar: "the second
 * request carries only the cookie" is a browser fact, and the CLI SAPI cannot
 * produce it.
 */
final class RecoveryMarkerTest extends ConsoleHttpTestCase
{
    private const CONSOLE = '/plugins/system_updater/recovery.php';
    private const DOWNLOAD = '/plugins/system_updater/download_backup.php?file=x.zip';
    private const PROCESS = '/plugins/system_updater/process_backup.php?action=status';
    private const SESSION_NAME = 'FSSESS_fixture';
    private const MARKER_NAMESPACE = 'recovery_console';

    /**
     * The console graph plus the two process endpoints the marker must not
     * satisfy. `download_backup.php` and `process_backup.php` both refuse in
     * their bootstrap, before any of their later includes run.
     */
    private const FILES = [
        'recovery.php',
        'lib/recovery_secret.php',
        'lib/session_auth.php',
        'lib/backup_manager.php',
        'lib/SqlDumpReader.php',
        'download_backup.php',
        'process_backup.php',
        'lib/process_bootstrap.php',
        'lib/csrf_guard.php',
        'lib/csrf_token.php',
    ];

    /**
     * The requirement in one case: open the console once with the secret, then
     * submit the form with the cookie alone.
     */
    public function testTheLoginPostDoesNotNeedTheSecretAgain(): void
    {
        $secret = $this->newSecret();
        $harness = $this->markerFixture($secret);

        $open = $harness->request(self::CONSOLE . '?secret=' . $secret, ['jar' => true]);
        $this->assertSame(200, $open['status'], 'the secret opens the console: ' . $open['body']);
        $this->assertArrayHasKey(
            self::SESSION_NAME,
            $harness->jarCookies(),
            'premise: the accepted request left a session cookie for the jar'
        );

        $post = $harness->request(self::CONSOLE, [
            'jar' => true,
            'post' => ['nick' => 'admin', 'password' => 'whatever'],
        ]);

        $this->assertSame(200, $post['status'], 'the login POST must not need the secret again: ' . $post['body']);
        $this->assertNotSame('Not Found', $post['body'], 'and must not receive the gate refusal');
        $this->assertStringContainsString('name="nick"', $post['body'], 'the console answered, it did not deny');
        $this->assertStringContainsString(
            'No se puede conectar a la base de datos',
            $post['body'],
            'the POST reached credential evaluation: with no database the console says so inside the form'
        );
        $this->assertStringNotContainsString($secret, $post['body'], 'and the secret is not re-supplied anywhere');
    }

    /**
     * RACC-05 "a marker cannot be minted without the secret": the refusal must
     * leave no state behind, and a forged cookie must not create one.
     */
    public function testARequestWithoutTheSecretWritesNoMarkerAndNoSession(): void
    {
        $secret = $this->newSecret();
        $harness = $this->markerFixture($secret);

        $anonymous = $harness->request(self::CONSOLE);
        $forged = $harness->request(self::CONSOLE, [
            'cookies' => [self::SESSION_NAME => bin2hex(random_bytes(16))],
        ]);

        $this->assertSame(404, $anonymous['status'], 'without the secret there is no console: ' . $anonymous['body']);
        $this->assertSame(404, $forged['status'], 'a forged cookie is not a path in: ' . $forged['body']);
        $this->assertSame(
            [],
            $harness->sessionFiles(),
            'neither request may create a session file: no session is opened before the gate grants'
        );
    }

    /**
     * RACC-05 "the marker is not a plugin credential".
     *
     * A session holding *only* the marker opens the console — that is what the
     * marker is for — and must satisfy `download_backup.php` and a `process_*`
     * action not at all. Those two keep authenticating on the session guard
     * and their own token; neither reads the marker.
     */
    public function testTheMarkerAuthorizesNothingOutsideTheConsole(): void
    {
        $secret = $this->newSecret();
        $harness = $this->markerFixture($secret);

        $sessionId = bin2hex(random_bytes(16));
        $harness->seedSession(self::SESSION_NAME, $sessionId, [
            self::MARKER_NAMESPACE => ['authorized' => true, 'granted_at' => time()],
        ]);

        $console = $harness->request(self::CONSOLE, [
            'cookies' => [self::SESSION_NAME => $sessionId],
        ]);
        $download = $harness->request(self::DOWNLOAD, [
            'cookies' => [self::SESSION_NAME => $sessionId],
        ]);
        $process = $harness->request(self::PROCESS, [
            'cookies' => [self::SESSION_NAME => $sessionId],
        ]);

        $this->assertSame(200, $console['status'], 'premise: the marker does open the console: ' . $console['body']);

        $this->assertSame(401, $download['status'], 'download_backup.php must refuse a marker-only session: ' . $download['body']);
        $this->assertStringNotContainsString('"success":true', $download['body']);
        $this->assertStringNotContainsString('Content-Disposition', $download['headers']);

        $this->assertSame(401, $process['status'], 'a process_* action must refuse a marker-only session: ' . $process['body']);
        $this->assertStringNotContainsString('event: complete', $process['body']);
    }

    /**
     * The marker is confined to the console's own files (RACC-05's
     * non-transferability, asserted as provenance rather than behaviour alone),
     * and it is never an identity key: no `_sf2_attributes`, no `_csrf`, no
     * `user_nick`.
     */
    public function testTheMarkerNamespaceIsConfinedToTheConsole(): void
    {
        $plugin = dirname(__DIR__);
        $carriers = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($plugin, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = (string) $file->getPathname();
            if (str_contains($path, '/tests/') || str_contains($path, '/vendor/')) {
                continue;
            }

            if (str_contains((string) file_get_contents($path), self::MARKER_NAMESPACE)) {
                $carriers[] = substr($path, strlen($plugin) + 1);
            }
        }

        sort($carriers);

        $this->assertSame(
            ['lib/recovery_session.php', 'recovery.php'],
            $carriers,
            'only the console and its own session library may know the marker namespace'
        );

        $library = (string) file_get_contents($plugin . '/lib/recovery_session.php');
        foreach (['_sf2_attributes', '_csrf', 'user_nick', 'user_id'] as $identityKey) {
            $this->assertStringNotContainsString(
                $identityKey,
                $library,
                'the marker is not an identity key: it must not touch ' . $identityKey
            );
        }
    }

    private function markerFixture(string $secret): ConsoleHttpHarness
    {
        $harness = $this->newHarness(self::FILES);
        $harness->boot(implode("\n", [
            "define('FS_DB_HOST', '127.0.0.1');",
            "define('FS_DB_PORT', 1);",
            "define('FS_DB_USER', 'nobody');",
            "define('FS_DB_PASS', 'nothing');",
            "define('FS_DB_NAME', 'none');",
            "define('FS_SESSION_NAME', '" . self::SESSION_NAME . "');",
            '',
        ]));
        $harness->persistSecret($secret);

        return $harness;
    }

    private function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
