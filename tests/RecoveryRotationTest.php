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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/recovery_handler.php';
require_once dirname(__DIR__) . '/lib/session_auth.php';

/**
 * REC-06: a successful console login rotates the identifier, writes the canonical
 * identity, and the session it leaves behind passes the plugin's own guard.
 *
 * The defect this pins
 * --------------------
 * `recovery.php` used to write `$_SESSION['user_id']` and nothing else.
 * `system_updater_session_is_valid()` resolves identity from `user_nick`, so a
 * session the console had just granted failed the guard the plugin applies to
 * every `status`, `progress` and download request: the operator was let into the
 * console and silently lost the access the console exists to restore. The
 * proposal's risk 4 asks for exactly this check, and it is the reason the
 * identity is asserted here through the guard and not through the session array
 * alone.
 *
 * How the order is proved
 * -----------------------
 * "Rotation happens before the write" cannot be inferred from the result: a
 * handler that wrote first and rotated second leaves the same keys and a new
 * identifier. So the regeneration is supplied as a callable that looks at
 * `$_SESSION` at the moment it runs. If it can see an identity key, the write
 * came first — a session observation, not a self-report.
 *
 * `lib/session_auth.php` is loaded **by this test**, and by nothing the console
 * loads: it is the guard's implementation, and the point of the case is what the
 * guard answers about a session, not how the console avoids loading it.
 */
#[PreserveGlobalState(false)]
final class RecoveryRotationTest extends TestCase
{
    private string $savePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_SESSION = [];
        $this->savePath = sys_get_temp_dir() . '/su_rotation_' . bin2hex(random_bytes(6));
        mkdir($this->savePath, 0700, true);
        ini_set('session.save_path', $this->savePath);
        ini_set('session.use_cookies', '0');
        ini_set('session.use_strict_mode', '0');

        session_id(bin2hex(random_bytes(16)));
        @session_start();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_SESSION = [];

        foreach (glob($this->savePath . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->savePath);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private static function identity(string $nick = 'admin'): array
    {
        $now = time();

        return [
            'user_nick' => $nick,
            'user_id' => $nick,
            'login_time' => $now,
            'last_activity' => $now,
        ];
    }

    #[Test]
    public function theIdentifierRotatesBeforeTheFirstIdentityWrite(): void
    {
        $before = session_id();
        $sawIdentity = null;

        $witness = static function () use (&$sawIdentity): bool {
            $sawIdentity = array_key_exists('user_nick', $_SESSION)
                || array_key_exists('user_id', $_SESSION);

            return session_regenerate_id(true);
        };

        $result = recovery_handler_write_identity(self::identity(), $witness);

        $this->assertFalse(
            $sawIdentity,
            'regeneration must precede the first identity write: at regeneration time the session held an identity'
        );
        $this->assertNotSame($before, session_id(), 'the pre-authentication identifier must not survive the login');
        $this->assertSame(['regenerate', 'write'], $result['steps']);
    }

    #[Test]
    public function theWrittenSessionCarriesTheKeysThePluginGuardReads(): void
    {
        recovery_handler_write_identity(self::identity());

        $this->assertSame('admin', $_SESSION['user_nick'] ?? null, 'the guard resolves identity from user_nick');
        $this->assertSame('admin', $_SESSION['user_id'] ?? null);
        $this->assertIsInt($_SESSION['login_time'] ?? null);
        $this->assertIsInt($_SESSION['last_activity'] ?? null);
    }

    #[Test]
    public function theWrittenSessionPassesThePluginGuardOnItsOwnMerits(): void
    {
        recovery_handler_write_identity(self::identity());
        $session = $_SESSION;

        $decision = system_updater_guard_decision(
            ['FSSESS_console' => $session],
            [],
            static fn (string $nick): bool => $nick === 'admin'
        );

        $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_ACCEPT, $decision['verdict']);
        $this->assertSame('admin', $decision['nick']);
        $this->assertArrayNotHasKey(
            'system_updater',
            $session,
            'the verdict must not need a CSRF token of any kind (REC-06)'
        );
        $this->assertArrayNotHasKey('_csrf', $session);
        $this->assertTrue(
            system_updater_session_is_valid($session),
            'a console session that cannot pass the plugin guard loses the access the console grants'
        );
    }

    /**
     * The defect, stated as the shape it was: identity under `user_id` alone.
     *
     * It is kept because it is the reason the write has two identity keys. A
     * later edit that "simplifies" them back to one turns this case red for the
     * reason the console exists.
     */
    #[Test]
    public function aSessionCarryingOnlyUserIdNeverPassesTheGuard(): void
    {
        $legacyShape = [
            'user_id' => 'admin',
            'login_time' => time(),
            'last_activity' => time(),
        ];

        $this->assertFalse(
            system_updater_session_is_valid($legacyShape),
            'the guard resolves from user_nick, so a user_id-only session is not authenticated'
        );

        $decision = system_updater_guard_decision(
            ['FSSESS_console' => $legacyShape],
            [],
            static fn (string $nick): bool => true
        );

        $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_DENY, $decision['verdict']);
        $this->assertNull($decision['nick']);
    }

    /**
     * The whole acceptance path, in one case: the handler decides, the console
     * writes, and the guard agrees — with the canonical nick even when the
     * operator typed a different case.
     */
    #[Test]
    public function theHandlerResultIsTheIdentityTheConsoleWrites(): void
    {
        $plain = 'correct-horse-battery-staple';
        $row = [
            'nick' => 'admin',
            'password' => password_hash($plain, PASSWORD_BCRYPT, ['cost' => 4]),
            'admin' => 1,
        ];

        $token = recovery_login_token_mint();
        $this->assertNotSame('', $token, 'premise: the console issued a form token');

        $result = recovery_handler_authenticate(
            ['token' => $token, 'nick' => 'ADMIN', 'password' => $plain, 'ip' => '203.0.113.7', 'now' => time()],
            [
                'shared_available' => false,
                'lookup' => static fn (string $nick): ?array => $row,
                'is_banned' => static fn (string $ip, int $now): bool => false,
                'reserve' => static fn (string $nick, string $ip, int $now): array
                    => ['reserved' => true, 'throttled' => false],
                'release' => static fn (string $nick, string $ip): bool => true,
                'record' => static fn (string $bucket, string $nick, string $ip, int $now) => null,
                'clear' => static fn (string $bucket, string $nick, string $ip, int $now) => null,
            ]
        );

        $this->assertSame('ok', $result['outcome']);
        $this->assertSame('admin', $result['identity']['user_nick'], 'the row nick is canonical, not the typed case');

        recovery_handler_write_identity($result['identity']);

        $decision = system_updater_guard_decision(
            ['FSSESS_console' => $_SESSION],
            [],
            static fn (string $nick): bool => $nick === 'admin'
        );

        $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_ACCEPT, $decision['verdict']);
        $this->assertSame('admin', $decision['nick']);
    }

    /**
     * One writer, asserted on the script: the console delegates the identity to
     * the handler instead of assigning session keys of its own. Two writers
     * would be two chances to forget the rotation.
     */
    #[Test]
    public function theConsoleDelegatesTheIdentityWrite(): void
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents(dirname(__DIR__) . '/recovery.php')) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        $this->assertStringContainsString('recovery_handler_write_identity', $code);
        $this->assertStringNotContainsString(
            "\$_SESSION['user_id']",
            $code,
            'the console must not write the identity itself: the handler rotates first, and only it knows the order'
        );
        $this->assertStringContainsString(
            "\$_SESSION['user_nick']",
            $code,
            'REC-08: the revalidation branch reads the key the guard resolves from'
        );
    }
}
