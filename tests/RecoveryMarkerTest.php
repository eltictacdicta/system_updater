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
        'lib/recovery_session.php',
        'lib/recovery_handler.php',
        'lib/recovery_policy.php',
        'lib/recovery_legacy_hash.php',
        'lib/recovery_login_token.php',
        'lib/session_name.php',
        'lib/session_auth.php',
        'lib/backup_manager.php',
        'lib/SqlDumpReader.php',
        'lib/recovery_csrf.php',
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
            'post' => [
                'nick' => 'admin',
                'password' => 'whatever',
                'recovery_login_token' => $this->formToken($open['body']),
            ],
        ]);

        $this->assertSame(200, $post['status'], 'the login POST must not need the secret again: ' . $post['body']);
        $this->assertNotSame('Not Found', $post['body'], 'and must not receive the gate refusal');
        $this->assertStringContainsString('name="nick"', $post['body'], 'the console answered, it did not deny');
        $this->assertStringContainsString(
            'No se puede conectar a la base de datos',
            $post['body'],
            'the POST reached the credential boundary: with no database the console says so inside the form'
        );
        $this->assertStringNotContainsString($secret, $post['body'], 'and the secret is not re-supplied anywhere');
    }

    /**
     * The console's form token, as the rendered form carries it.
     *
     * A POST without it is refused by REC-01's verdict before anything else
     * happens, which is the correct behaviour and the wrong evidence for this
     * class: the case above is about RACC-05 (no second secret), so it has to
     * submit a form the console actually issued.
     */
    private function formToken(string $body): string
    {
        if (preg_match('/name="recovery_login_token" value="([0-9a-f]{64})"/', $body, $matches) !== 1) {
            $this->fail('the rendered login form carries no console token');
        }

        return $matches[1];
    }

    /**
     * The save path the marker is read from is the save path the session is
     * opened in — on **every** branch, including an installation that declares
     * `FS_SESSION_SAVE_PATH`.
     *
     * The two used to disagree: `recovery_console_read_marker()` inspected
     * `FS_SESSION_SAVE_PATH` with `is_file()` and then called `session_start()`
     * before `recovery_session_configure()` had applied that path, so the
     * session module looked for the same id in the ini `session.save_path` and
     * the `files` handler created an **empty** file there. The marker read
     * returned `false`, the login POST got the gate's 404 (RACC-05 broken on
     * every installation that declares the constant) and the refusal left a
     * stray empty session file behind.
     *
     * The fixture server is started with `-d session.save_path=<scratch>`, and
     * the installation declares a different, existing directory, so "which path
     * answered?" is a filesystem fact rather than an inference.
     */
    public function testTheMarkerIsReadFromTheSavePathTheSessionActuallyUses(): void
    {
        $secret = $this->newSecret();
        $harness = $this->newHarness(self::FILES);

        $declared = $harness->base . '/app_sessions';
        $this->assertTrue(mkdir($declared, 0777, true), 'premise: the declared save path exists');

        $this->bootMarkerFixture($harness, $secret, [
            "define('FS_SESSION_SAVE_PATH', '" . $declared . "');",
        ]);

        $open = $harness->request(self::CONSOLE . '?secret=' . $secret, ['jar' => true]);
        $this->assertSame(200, $open['status'], 'the secret opens the console: ' . $open['body']);
        $this->assertNotSame(
            [],
            glob($declared . '/sess_*') ?: [],
            'premise: the accepted request wrote its session where the installation says'
        );

        $post = $harness->request(self::CONSOLE, [
            'jar' => true,
            'post' => ['nick' => 'admin', 'password' => 'whatever'],
        ]);

        $this->assertSame(
            200,
            $post['status'],
            'the marker must be read from the same save path the session was opened in: ' . $post['body']
        );
        $this->assertStringContainsString('name="nick"', $post['body'], 'the console answered, it did not deny');
        $this->assertSame(
            [],
            $harness->sessionFiles(),
            'and the ini save path must hold no session: a session started there is a stray empty file'
        );
    }

    /**
     * RACC-06: rotation is the way a leaked secret is retired, so it must also
     * retire the sessions that secret already opened.
     *
     * The marker used to be `authorized => true` with nothing linking it to the
     * value that granted it, so `--rotate-secret` changed what the gate accepts
     * while every browser that had already opened the console kept entering for
     * the whole session lifetime — precisely the leak rotation exists to stop.
     */
    public function testRotatingTheSecretRetiresTheMarkerItGranted(): void
    {
        $secret = $this->newSecret();
        $harness = $this->markerFixture($secret);

        $open = $harness->request(self::CONSOLE . '?secret=' . $secret, ['jar' => true]);
        $this->assertSame(200, $open['status'], 'premise: the secret opened the console: ' . $open['body']);
        $this->assertArrayHasKey(
            self::SESSION_NAME,
            $harness->jarCookies(),
            'premise: the accepted request left a session cookie for the jar'
        );

        $rotated = $this->newSecret();
        $harness->persistSecret($rotated);

        $reload = $harness->request(self::CONSOLE, ['jar' => true]);

        $this->assertSame(
            404,
            $reload['status'],
            'a marker granted by the retired secret must stop opening the console: ' . $reload['body']
        );
        $this->assertStringNotContainsString('name="nick"', $reload['body'], 'and it must receive the gate refusal');
        $this->assertStringNotContainsString($rotated, $reload['body'], 'the new secret is not disclosed to it');

        $reopened = $harness->request(self::CONSOLE . '?secret=' . $rotated, ['jar' => true]);
        $this->assertSame(200, $reopened['status'], 'the current secret is the way back in: ' . $reopened['body']);
    }

    /**
     * The other half of the pin decision: while the pin is the credential the
     * gate answers against, the marker it granted keeps working. Refusing it
     * would send the operator back to the secret on every reload, which is what
     * the marker exists to prevent.
     */
    public function testAMarkerGrantedByTheOperatorPinKeepsWorkingWhileThePinIsCurrent(): void
    {
        $pin = $this->newSecret();
        $harness = $this->newHarness(self::FILES);
        $this->bootMarkerFixture($harness, null, [
            "define('SU_RECOVERY_SECRET', '" . $pin . "');",
        ]);

        $open = $harness->request(self::CONSOLE . '?secret=' . $pin, ['jar' => true]);
        $this->assertSame(200, $open['status'], 'premise: the pin opened the console: ' . $open['body']);

        $reload = $harness->request(self::CONSOLE, ['jar' => true]);

        $this->assertSame(
            200,
            $reload['status'],
            'nothing has been persisted and the pin still grants, so the marker is still current: '
            . $reload['body']
        );
        $this->assertStringContainsString('name="nick"', $reload['body']);
        $this->assertStringNotContainsString($pin, $reload['body'], 'and the pin is never echoed');
    }

    /**
     * The operator pin is a credential of its own, and it is not what rotation
     * changes — but a rotation that *persists* a value does displace it at the
     * gate, so the marker it granted must not outlive that moment.
     */
    public function testAMarkerGrantedByTheOperatorPinDiesWhenARotationPersistsAValue(): void
    {
        $pin = $this->newSecret();
        $harness = $this->newHarness(self::FILES);
        $this->bootMarkerFixture($harness, null, [
            "define('SU_RECOVERY_SECRET', '" . $pin . "');",
        ]);

        $open = $harness->request(self::CONSOLE . '?secret=' . $pin, ['jar' => true]);
        $this->assertSame(200, $open['status'], 'the operator pin opens the console: ' . $open['body']);
        $this->assertFalse($harness->secretExists(), 'premise: the pin grants while nothing has been persisted');

        $harness->persistSecret($this->newSecret());

        $reload = $harness->request(self::CONSOLE, ['jar' => true]);

        $this->assertSame(
            404,
            $reload['status'],
            'once a value is persisted it is what the gate answers against, so the pin-granted marker is stale: '
            . $reload['body']
        );
        $this->assertStringNotContainsString('name="nick"', $reload['body']);
    }

    /**
     * The marker names the credential that issued it, and nothing else is
     * accepted: a marker without that binding, or bound to some other value, is
     * the gate refusal — and it is not upgraded into a valid one by the read.
     */
    public function testAMarkerThatCannotNameItsGrantingSecretIsRefused(): void
    {
        $harness = $this->markerFixture($this->newSecret());

        $unbound = bin2hex(random_bytes(16));
        $harness->seedSession(self::SESSION_NAME, $unbound, [
            self::MARKER_NAMESPACE => ['authorized' => true, 'granted_at' => time()],
        ]);

        $foreign = bin2hex(random_bytes(16));
        $harness->seedSession(self::SESSION_NAME, $foreign, [
            self::MARKER_NAMESPACE => [
                'authorized' => true,
                'granted_at' => time(),
                'fingerprint' => self::fingerprint($this->newSecret()),
            ],
        ]);

        foreach (['unbound' => $unbound, 'foreign' => $foreign] as $label => $sessionId) {
            $before = (string) file_get_contents($harness->sessions . '/sess_' . $sessionId);

            $response = $harness->request(self::CONSOLE, [
                'cookies' => [self::SESSION_NAME => $sessionId],
            ]);

            $this->assertSame(404, $response['status'], 'the ' . $label . ' marker is not authorization: ' . $response['body']);
            $this->assertStringNotContainsString('name="nick"', $response['body']);
            $this->assertSame(
                $before,
                (string) file_get_contents($harness->sessions . '/sess_' . $sessionId),
                'a marker the read refuses must not be rewritten: it is not silently upgraded'
            );
        }
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
            self::MARKER_NAMESPACE => [
                'authorized' => true,
                'granted_at' => time(),
                'fingerprint' => self::fingerprint($secret),
            ],
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
     *
     * The carrier list is the console and the two libraries only it includes.
     * `lib/recovery_login_token.php` joins it by design: REC-01 puts the login
     * form's token in the same console-owned namespace, which is what keeps the
     * console's state in one bucket instead of two. What the list must never
     * grow is an entry the process scripts or `download_backup.php` can reach —
     * that would make the namespace transferable, which is the property this
     * test exists for.
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
            ['lib/recovery_login_token.php', 'lib/recovery_session.php', 'recovery.php'],
            $carriers,
            'only the console and the libraries it includes may know the console namespace'
        );

        $library = self::codeWithoutComments((string) file_get_contents($plugin . '/lib/recovery_session.php'));
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
        $this->bootMarkerFixture($harness, $secret);

        return $harness;
    }

    /**
     * @param string|null $secret persisted before the first request; `null`
     *                            leaves the installation with no persisted value,
     *                            which is how the operator-pin state is reached
     * @param string[]    $extraConfig appended verbatim to the fixture config.php
     */
    private function bootMarkerFixture(
        ConsoleHttpHarness $harness,
        ?string $secret,
        array $extraConfig = []
    ): void {
        $harness->boot(implode("\n", array_merge([
            "define('FS_DB_HOST', '127.0.0.1');",
            "define('FS_DB_PORT', 1);",
            "define('FS_DB_USER', 'nobody');",
            "define('FS_DB_PASS', 'nothing');",
            "define('FS_DB_NAME', 'none');",
            "define('FS_SESSION_NAME', '" . self::SESSION_NAME . "');",
        ], $extraConfig, [''])));

        if ($secret !== null) {
            $harness->persistSecret($secret);
        }
    }

    /**
     * The marker's binding, spelled the way the library spells it: an HMAC over
     * the marker namespace, keyed by the value that granted authorization.
     */
    private static function fingerprint(string $secret): string
    {
        return hash_hmac('sha256', self::MARKER_NAMESPACE, $secret);
    }

    private function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
