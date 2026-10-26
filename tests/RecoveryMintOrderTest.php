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

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use Tests\SystemUpdater\Support\ConsoleHttpHarness;
use Tests\SystemUpdater\Support\ConsoleHttpTestCase;

require_once dirname(__DIR__) . '/lib/recovery_session.php';
require_once dirname(__DIR__) . '/lib/recovery_csrf.php';
require_once __DIR__ . '/Support/ConsoleHttpTestCase.php';

/**
 * RACC-07 — the console's mint happens after **both** gates, in that order.
 *
 * Why an injected recorder and not a reading of the source
 * -------------------------------------------------------
 * "The token is minted after the secret comparison and after administrator
 * authentication" is a statement about *what happened first*. The returned
 * value cannot tell the three cases apart: a mint that ran before the
 * comparison would still return a token, and a render that minted on the login
 * form would still look like a page. So the console's mint takes a recorder, the
 * test injects one, and the assertion is over the recorded sequence — the order
 * of the effects, not the order of the lines.
 *
 * What is asserted, and why each half matters
 * -------------------------------------------
 *   - both gates open ⇒ `secret_compared` → `config_loaded` →
 *     `admin_authenticated` → `token_minted`, in exactly that order;
 *   - a failed secret ⇒ only `secret_compared`, and the plugin store is absent;
 *   - the secret but no administrator (the login render) ⇒ no `token_minted`,
 *     the store is absent, and the console's own login token (`REC-01`) is not
 *     in it either;
 *   - a secret but no `config.php` (the `RACC-04` refusal boundary) ⇒ no mint;
 *   - the mint does not destroy the console's session (identity keys and the
 *     `RACC-05` marker survive it) and does not copy that identity into the
 *     plugin session;
 *   - a minted token is refused under another session, and the marker and the
 *     token are not interchangeable (`RACC-07` points 6 and 7).
 *
 * No database is involved: the administrator's presence is a fact the console
 * has already established by the time it calls the mint, so it is passed in —
 * exactly as the two gates are.
 */
#[PreserveGlobalState(false)]
final class RecoveryMintOrderTest extends ConsoleHttpTestCase
{
    private const CONSOLE_SESSION = 'FSSESS_mint_fixture';
    private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';
    private const TOKEN_SLOT = 'system_updater_csrf';

    private const CONSOLE_URL = '/plugins/system_updater/recovery.php';
    private const HTTP_SESSION = 'FSSESS_mint_http';

    private string $savePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_SESSION = [];
        $_COOKIE = [];

        $this->savePath = sys_get_temp_dir() . '/su_mint_order_' . bin2hex(random_bytes(6));
        mkdir($this->savePath, 0700, true);

        ini_set('session.save_path', $this->savePath);
        ini_set('session.use_cookies', '0');
        ini_set('session.use_strict_mode', '0');
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_SESSION = [];
        $_COOKIE = [];

        foreach (glob($this->savePath . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->savePath);

        parent::tearDown();
    }

    /**
     * The requirement in one case: both gates open, in order, and then a token.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function theTokenIsMintedAfterTheSecretAndAfterAuthenticationInThatOrder(): void
    {
        $console = $this->openConsoleSession(['user_nick' => 'admin']);

        $steps = [];
        $token = recovery_csrf_mint_token(
            ['secret_granted' => true, 'config_loaded' => true, 'authenticated' => true],
            $this->recorder($steps)
        );

        $this->assertIsString($token, 'both gates are open, so the console mints');
        $this->assertMatchesRegularExpression(self::TOKEN_PATTERN, $token, 'the format is unchanged: 64 hex characters');

        $this->assertSame(
            ['secret_compared', 'config_loaded', 'admin_authenticated', 'token_minted'],
            $steps,
            'the mint must happen after the comparison and after authentication, and last'
        );

        $sequence = array_flip($steps);
        $this->assertLessThan(
            $sequence['token_minted'],
            $sequence['secret_compared'],
            'the secret comparison precedes the mint'
        );
        $this->assertLessThan(
            $sequence['token_minted'],
            $sequence['admin_authenticated'],
            'administrator authentication precedes the mint'
        );

        $this->assertSame(
            $token,
            $_SESSION['system_updater']['csrf_tokens'][self::TOKEN_SLOT]['value'] ?? null,
            'the minted value is what the process scripts will compare against'
        );

        $this->assertTrue(
            system_updater_csrf_validate($token),
            'the token validates in the session that minted it'
        );

        $this->assertSame('admin', $console['identity'], 'premise: the console was authenticated');
    }

    /**
     * A wrong (or absent) secret: the gate refused, so nothing is minted and the
     * plugin store is never created.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function aFailedSecretMintsNothingAndLeavesNoStore(): void
    {
        $consoleId = bin2hex(random_bytes(16));
        $this->openConsoleSession(['user_nick' => 'admin'], $consoleId);

        $steps = [];
        $token = recovery_csrf_mint_token(
            ['secret_granted' => false, 'config_loaded' => true, 'authenticated' => true],
            $this->recorder($steps)
        );

        $this->assertNull($token, 'no secret, no token');
        $this->assertSame(['secret_compared'], $steps, 'and the pipeline stops at the comparison');
        $this->assertArrayNotHasKey('system_updater', $_SESSION, 'no plugin store is created');
        $this->assertSame(
            [$this->sessionFileName($consoleId)],
            $this->sessionFiles(),
            'and no plugin session is opened: the refusal leaves no state behind'
        );
        $this->assertStringNotContainsString(
            'csrf_tokens',
            $this->sessionFile($consoleId),
            'and nothing was written into the console session either'
        );
    }

    /**
     * The second gate, alone: this is the login render. The secret is right, no
     * administrator has signed in, and the console must mint nothing — in
     * particular not the `su_csrf_token` that `REC-01` keeps out of the login
     * form's namespace.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function theLoginRenderMintsNoPluginToken(): void
    {
        $this->openConsoleSession([]);

        $steps = [];
        $token = recovery_csrf_mint_token(
            ['secret_granted' => true, 'config_loaded' => true, 'authenticated' => false],
            $this->recorder($steps)
        );

        $this->assertNull($token, 'an unauthenticated render mints nothing');
        $this->assertSame(
            ['secret_compared', 'config_loaded', 'admin_authenticated'],
            $steps,
            'the pipeline reaches the authentication gate and stops there'
        );
        $this->assertArrayNotHasKey('system_updater', $_SESSION, 'system_updater.csrf_tokens stays absent');
    }

    /**
     * The refusal boundary: without `config.php` the console answers the
     * `RACC-04` 404 before any credential exists, so the mint cannot be reached.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function aRequestThatNeverLoadedTheConfigMintsNothing(): void
    {
        $steps = [];
        $token = recovery_csrf_mint_token(
            ['secret_granted' => true, 'config_loaded' => false, 'authenticated' => true],
            $this->recorder($steps)
        );

        $this->assertNull($token);
        $this->assertSame(['secret_compared', 'config_loaded'], $steps);
        $this->assertArrayNotHasKey('system_updater', $_SESSION);
    }

    /**
     * RACC-07 point 5: opening `SU_SESS` to write the token must not corrupt the
     * console's own session — its identity keys and the `RACC-05` marker survive
     * — and the login identity must not be copied into the plugin session.
     *
     * The two sessions are given different identifiers here, so "the identity
     * survived" is a statement about the console's session file and "the
     * identity was not copied" is a statement about the plugin's.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function theMintKeepsTheConsoleSessionAndCopiesNoIdentityIntoThePluginSession(): void
    {
        $consoleId = bin2hex(random_bytes(16));
        $this->openConsoleSession(['user_nick' => 'admin'], $consoleId);

        $pluginId = bin2hex(random_bytes(16));
        $_COOKIE[system_updater_resolve_session_name()] = $pluginId;

        $steps = [];
        $token = recovery_csrf_mint_token(
            ['secret_granted' => true, 'config_loaded' => true, 'authenticated' => true],
            $this->recorder($steps)
        );

        $this->assertIsString($token);

        $plugin = $this->sessionFile($pluginId);
        $this->assertStringContainsString('csrf_tokens', $plugin, 'the plugin session holds the minted token');
        $this->assertStringNotContainsString('user_nick', $plugin, 'and no login identity is written into it');
        $this->assertStringNotContainsString('recovery_console', $plugin, 'and not the console marker either');

        $console = $this->sessionFile($consoleId);
        $this->assertStringContainsString('user_nick', $console, 'the console session keeps its identity');
        $this->assertStringContainsString('recovery_console', $console, 'and the RACC-05 marker survives the mint');
    }

    /**
     * The production shape of the same guarantee: the browser has no `SU_SESS`
     * cookie yet, so the plugin session is opened on the console's identifier.
     * Nothing may be lost in that switch.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function openingThePluginSessionOnTheConsoleIdentifierLosesNothing(): void
    {
        $consoleId = bin2hex(random_bytes(16));
        $this->openConsoleSession(['user_nick' => 'admin'], $consoleId);

        unset($_COOKIE[system_updater_resolve_session_name()]);

        $token = recovery_csrf_mint_token(
            ['secret_granted' => true, 'config_loaded' => true, 'authenticated' => true]
        );

        $this->assertIsString($token);

        $shared = $this->sessionFile($consoleId);
        $this->assertStringContainsString('user_nick', $shared, 'the console identity survives');
        $this->assertStringContainsString('recovery_console', $shared, 'the marker survives');
        $this->assertStringContainsString('csrf_tokens', $shared, 'and the token is where the process scripts read it');
    }

    /**
     * Point 6: bound to the minting session. A token lifted into another
     * browser's session is refused, because the store it is compared against is
     * that other session's store — which is empty.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function aTokenIsRefusedFromAnotherSession(): void
    {
        $this->openConsoleSession(['user_nick' => 'admin']);

        $pluginId = bin2hex(random_bytes(16));
        $_COOKIE[system_updater_resolve_session_name()] = $pluginId;

        $token = recovery_csrf_mint_token(
            ['secret_granted' => true, 'config_loaded' => true, 'authenticated' => true]
        );
        $this->assertIsString($token);
        $this->assertTrue(system_updater_csrf_validate($token), 'premise: valid where it was minted');

        // The next request starts with a different plugin cookie. Closing the
        // session is what makes that a new request rather than a second read of
        // the one this test already opened: production never keeps a session
        // open across two of them.
        session_write_close();
        $_COOKIE[system_updater_resolve_session_name()] = bin2hex(random_bytes(16));

        $this->assertFalse(
            system_updater_csrf_validate($token),
            'a token presented with another session cookie must be refused'
        );
    }

    /**
     * Point 7: the marker is not the token and the token is not the marker.
     *
     * Both directions, because each one alone would be satisfied by a design
     * that simply stored the same bytes twice.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function theMarkerAndTheTokenAreNotInterchangeable(): void
    {
        $this->openConsoleSession(['user_nick' => 'admin']);

        $secret = bin2hex(random_bytes(32));
        recovery_console_mark_authorized($secret);
        $marker = $_SESSION[RECOVERY_CONSOLE_NAMESPACE];

        $token = recovery_csrf_mint_token(
            ['secret_granted' => true, 'config_loaded' => true, 'authenticated' => true]
        );
        $this->assertIsString($token);

        // The marker, in every shape a request could carry it, is not a token.
        $this->assertFalse(
            system_updater_csrf_validate((string) json_encode($marker)),
            'a replayed marker is not a su_csrf_token'
        );
        $this->assertFalse(
            system_updater_csrf_validate((string) ($marker['fingerprint'] ?? '')),
            'and neither is the marker fingerprint'
        );

        // And a minted token is not the credential the marker is bound to, nor a
        // valid fingerprint for it.
        $this->assertFalse(
            recovery_console_has_marker($_SESSION, $token),
            'presenting a minted token as the granting credential does not satisfy the marker'
        );
        $this->assertFalse(
            recovery_console_has_marker(
                [RECOVERY_CONSOLE_NAMESPACE => ['authorized' => true, 'granted_at' => time(), 'fingerprint' => $token]],
                $token
            ),
            'a raw token is not a valid marker fingerprint'
        );
    }

    /**
     * The same two properties, observed on the running script instead of on the
     * function: after the secret gate the console renders the login form and
     * mints nothing, and no session file holds a token store afterwards.
     *
     * The authenticated render cannot be reached here — `recovery.php` connects
     * with `new mysqli()` and this suite has no database — so what the HTTP seam
     * proves is the negative half, which is the half a mistake would break first:
     * a mint that ran before the second gate would leave the store on disk.
     */
    #[Test]
    public function theRunningConsoleMintsNothingBeforeTheSecondGate(): void
    {
        $harness = $this->consoleHarness();
        $secret = bin2hex(random_bytes(32));
        $harness->persistSecret($secret);

        $response = $harness->request(self::CONSOLE_URL . '?secret=' . $secret);

        $this->assertSame(200, $response['status'], 'the secret opens the console: ' . $response['body']);
        $this->assertStringContainsString(
            'name="nick"',
            $response['body'],
            'premise: no administrator is authenticated, so the login form is what renders'
        );
        $this->assertStringContainsString(
            'name="recovery_login_token"',
            $response['body'],
            'and it carries the console form token (REC-01), not the plugin token'
        );

        $this->assertSame(
            [],
            $this->filesCarryingTheStore($harness),
            'the login render must leave no system_updater.csrf_tokens entry on disk'
        );
    }

    /**
     * RACC-07 point 8, pinned as a regression: a request that carries restore
     * parameters but not the secret is the `RACC-04` 404, and it mints nothing —
     * there is no session, no store and no token anywhere.
     */
    #[Test]
    public function aRestoreRequestWithoutTheSecretIsAnIndistinguishable404ThatMintsNothing(): void
    {
        $harness = $this->consoleHarness();
        $secret = bin2hex(random_bytes(32));
        $harness->persistSecret($secret);

        $response = $harness->request(
            self::CONSOLE_URL . '?action=begin&file=x_complete.zip&type=complete&secret=wrong'
        );

        $this->assertSame(404, $response['status'], 'no secret, no console: ' . $response['body']);
        $this->assertSame('Not Found', $response['body'], 'and the refusal is the plain body, not a page');
        $this->assertSame([], $harness->sessionFiles(), 'the refusal opens no session and leaves no state');
        $this->assertSame([], $this->filesCarryingTheStore($harness), 'and mints nothing');
        $this->assertStringNotContainsString(
            $secret,
            $response['body'] . $response['headers'],
            'the refusal never discloses the real secret'
        );
    }

    /**
     * The fixture the two HTTP cases start: the console graph, an application
     * session name and a database that cannot be reached. The last one is what
     * keeps `$user` null, which is exactly the state under test.
     */
    private function consoleHarness(): ConsoleHttpHarness
    {
        // The shared fixture list, so a file joining the console's graph is
        // added once and these cases keep booting the shipped script.
        $harness = $this->newHarness();

        $harness->boot(implode("\n", [
            "define('FS_DB_HOST', '127.0.0.1');",
            "define('FS_DB_PORT', 1);",
            "define('FS_DB_USER', 'nobody');",
            "define('FS_DB_PASS', 'nothing');",
            "define('FS_DB_NAME', 'none');",
            "define('FS_SESSION_NAME', '" . self::HTTP_SESSION . "');",
            '',
        ]));

        return $harness;
    }

    /**
     * Session files whose contents hold the plugin token store.
     *
     * A `system_updater` entry in a session file is the recorded fact behind "no
     * token was minted": the store is what `system_updater_csrf_generate()`
     * writes, and nothing else creates it.
     *
     * @return array<int, string>
     */
    private function filesCarryingTheStore(ConsoleHttpHarness $harness): array
    {
        $carrying = [];

        foreach ($harness->sessionFiles() as $file) {
            $contents = (string) @file_get_contents($harness->sessions . '/' . $file);

            if (str_contains($contents, 'system_updater')) {
                $carrying[] = $file . ': ' . $contents;
            }
        }

        return $carrying;
    }

    /**
     * Open the console's session with the given identity, the way the console
     * does after `REC-06` writes it, and close it so its file exists on disk.
     *
     * @param array<string, mixed> $identity
     * @return array{identity: mixed}
     */
    private function openConsoleSession(array $identity, ?string $sessionId = null): array
    {
        session_name(self::CONSOLE_SESSION);
        session_id($sessionId ?? bin2hex(random_bytes(16)));
        session_start();

        $_SESSION = $identity;

        if ($identity !== []) {
            recovery_console_mark_authorized(bin2hex(random_bytes(32)));
        }

        session_write_close();

        return ['identity' => $identity['user_nick'] ?? null];
    }

    /**
     * @param array<int, string> $steps
     * @return callable(string): void
     */
    private function recorder(array &$steps): callable
    {
        return static function (string $step) use (&$steps): void {
            $steps[] = $step;
        };
    }

    private function sessionFile(string $sessionId): string
    {
        $file = $this->savePath . '/sess_' . $sessionId;

        $this->assertFileExists($file, 'premise: the session the case reasons about was written');

        return (string) file_get_contents($file);
    }

    private function sessionFileName(string $sessionId): string
    {
        return 'sess_' . $sessionId;
    }

    /**
     * @return array<int, string>
     */
    private function sessionFiles(): array
    {
        clearstatcache();

        return array_values(array_diff(scandir($this->savePath) ?: [], ['.', '..']));
    }
}
