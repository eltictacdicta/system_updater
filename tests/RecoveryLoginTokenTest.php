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
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/recovery_login_token.php';

/**
 * REC-01's token primitive — the console's login form.
 *
 * The form must carry a CSRF token and the handler must reject a submission
 * before it reads the ban bucket, consults a throttle, looks up an account or
 * compares a password. A1 forbids `CsrfManager`, so the token is self-contained:
 * it is minted into the session the console opened, it lives under a namespace
 * of the console's own, it is compared with `hash_equals()` and it is bound to
 * the session that minted it.
 *
 * The exclusion that gives this test its shape
 * --------------------------------------------
 * `REC-01` forbids the plugin token store — `system_updater.csrf_tokens` in
 * `SU_SESS`. That store is what `start`, `begin` and `chunk` accept, so a value
 * written there by the login form would be a plugin credential handed to an
 * unauthenticated visitor. The console mints no `su_csrf_token` before both
 * gates (`RACC-07`), and the form's token is not one: it must be provably
 * incapable of authorizing a process action, which is what
 * {@see testMintingWritesNothingIntoThePluginStore()} and
 * {@see testATokenFromThePluginStoreIsNotAccepted()} assert from both
 * directions.
 *
 * Every test re-initialises its own session — a fresh identifier, a private
 * `save_path` and an empty `$_SESSION` — so "another session" is a fact rather
 * than a fixture. The cross-session case additionally runs in its own process
 * (`#[RunInSeparateProcess]`: the attribute targets methods, not classes in
 * PHPUnit 11), because that is the one assertion an inherited session file could
 * accidentally satisfy.
 */
#[PreserveGlobalState(false)]
final class RecoveryLoginTokenTest extends TestCase
{
    private string $savePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_SESSION = [];
        $this->savePath = sys_get_temp_dir() . '/su_login_token_' . bin2hex(random_bytes(6));
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

    #[Test]
    public function mintingRequiresAnOpenConsoleSession(): void
    {
        session_write_close();
        $this->assertSame(PHP_SESSION_NONE, session_status(), 'premise: no session is open');

        $this->assertSame('', recovery_login_token_mint(), 'no token is minted without a session to hold it');
        $this->assertSame('', recovery_login_token_field());
        $this->assertFalse(recovery_login_token_validate(str_repeat('a', 64)));
        $this->assertNull(recovery_login_token_read());
        $this->assertArrayNotHasKey(RECOVERY_LOGIN_TOKEN_NAMESPACE, $_SESSION);
    }

    #[Test]
    public function aMintedTokenIsSixtyFourHexInTheConsoleNamespace(): void
    {
        $_SESSION['_csrf'] = 'framework-sentinel';

        $token = recovery_login_token_mint();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame(
            $token,
            $_SESSION[RECOVERY_LOGIN_TOKEN_NAMESPACE][RECOVERY_LOGIN_TOKEN_SLOT]['value']
        );
        $this->assertIsInt($_SESSION[RECOVERY_LOGIN_TOKEN_NAMESPACE][RECOVERY_LOGIN_TOKEN_SLOT]['created_at']);
        $this->assertSame(
            'framework-sentinel',
            $_SESSION['_csrf'],
            'the framework token is not this module business'
        );
        $this->assertNotSame($token, recovery_login_token_mint(), 'a second mint replaces the first');
    }

    #[Test]
    public function theMintedTokenValidatesOnlyAgainstItself(): void
    {
        $token = recovery_login_token_mint();

        $this->assertSame($token, recovery_login_token_read());
        $this->assertTrue(recovery_login_token_validate($token));

        $mutated = ($token[0] === 'a' ? 'b' : 'a') . substr($token, 1);
        $this->assertFalse(recovery_login_token_validate($mutated));
        $this->assertFalse(recovery_login_token_validate(''));
        $this->assertFalse(recovery_login_token_validate(str_repeat('a', 64)));
        $this->assertFalse(recovery_login_token_validate($token . ' '));
    }

    /**
     * REC-01: "bound to the session, so a token harvested from another session is
     * refused".
     */
    #[Test]
    #[RunInSeparateProcess]
    public function aTokenFromAnotherSessionIsRefused(): void
    {
        $harvested = recovery_login_token_mint();
        $this->assertTrue(recovery_login_token_validate($harvested));

        // The next visitor's session: a different identifier and an empty store.
        session_write_close();
        session_id(bin2hex(random_bytes(16)));
        @session_start();
        $_SESSION = [];

        $this->assertFalse(
            recovery_login_token_validate($harvested),
            'a value lifted from another browser must not validate here'
        );
        $this->assertTrue(
            recovery_login_token_validate(recovery_login_token_mint()),
            'the new session still gets a working token of its own'
        );
    }

    #[Test]
    public function anExpiredTokenIsRefusedAndPruned(): void
    {
        $token = recovery_login_token_mint();
        $_SESSION[RECOVERY_LOGIN_TOKEN_NAMESPACE][RECOVERY_LOGIN_TOKEN_SLOT]['created_at'] = time()
            - RECOVERY_LOGIN_TOKEN_TTL;

        $this->assertTrue(recovery_login_token_validate($token), 'the boundary is inclusive, as in the plugin token');

        $_SESSION[RECOVERY_LOGIN_TOKEN_NAMESPACE][RECOVERY_LOGIN_TOKEN_SLOT]['created_at'] = time()
            - RECOVERY_LOGIN_TOKEN_TTL
            - 1;

        $this->assertFalse(recovery_login_token_validate($token));
        $this->assertArrayNotHasKey(
            RECOVERY_LOGIN_TOKEN_SLOT,
            $_SESSION[RECOVERY_LOGIN_TOKEN_NAMESPACE],
            'an expired entry is removed, so the next render mints a fresh one'
        );
        $this->assertNull(recovery_login_token_read());
    }

    /**
     * REC-01's "The token is not the plugin credential" scenario, read from the
     * other side: a value that authorizes `start`/`begin`/`chunk` is not a form
     * token, and this module must not honour it.
     */
    #[Test]
    public function aTokenFromThePluginStoreIsNotAccepted(): void
    {
        $pluginToken = bin2hex(random_bytes(32));
        $_SESSION['system_updater']['csrf_tokens']['system_updater_csrf'] = [
            'value' => $pluginToken,
            'created_at' => time(),
        ];

        $this->assertFalse(recovery_login_token_validate($pluginToken));
        $this->assertNull(recovery_login_token_read());
    }

    #[Test]
    public function mintingWritesNothingIntoThePluginStore(): void
    {
        recovery_login_token_mint();

        $this->assertArrayNotHasKey('system_updater', $_SESSION);
        $this->assertArrayNotHasKey('_csrf', $_SESSION);
        $this->assertArrayHasKey(RECOVERY_LOGIN_TOKEN_NAMESPACE, $_SESSION);
    }

    #[Test]
    public function theFormFieldCarriesTheMintedToken(): void
    {
        $field = recovery_login_token_field();
        $token = recovery_login_token_read();

        $this->assertNotNull($token);
        $this->assertSame(
            '<input type="hidden" name="' . RECOVERY_LOGIN_TOKEN_FIELD . '" value="' . $token . '">',
            $field
        );
        $this->assertSame(
            $field,
            recovery_login_token_field(),
            'rendering twice must not invalidate the form the operator already has open'
        );
        $this->assertStringContainsString('type="hidden"', $field);
    }

    /**
     * A token is only as good as the comparison that accepts it.
     */
    #[Test]
    public function theComparisonIsConstantTime(): void
    {
        $source = $this->source();
        $body = $this->functionBody($source, 'recovery_login_token_validate');

        $this->assertStringContainsString('hash_equals(', $body);
        $this->assertStringNotContainsString('strcmp(', $body);
        $this->assertStringNotContainsString('substr_compare(', $body);
        $this->assertDoesNotMatchRegularExpression(
            '/\\$stored\s*===?\s*\\$submitted|\\$submitted\s*===?\s*\\$stored/',
            $body,
            'a short-circuiting comparison against the submitted value is the defect'
        );
    }

    /**
     * RACC-01 and REC-01's storage exclusions, asserted on the file rather than
     * promised in prose.
     *
     * Importing the plugin token module would be the quiet version of the bug:
     * it opens the plugin session and writes the wrong store, so the console
     * would be handing out plugin credentials instead of form tokens. The TTL is
     * aligned with that module without importing it, and the alignment is read
     * off its source so the two cannot drift apart unnoticed.
     */
    #[Test]
    public function theTokenIsSelfContained(): void
    {
        $source = $this->source();

        $this->assertDoesNotMatchRegularExpression('/^\s*(namespace|use)\s/m', $source);
        $this->assertDoesNotMatchRegularExpression('/^\s*(require|include)(_once)?\b/m', $source);
        $this->assertStringNotContainsString('class_exists(', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/\bCsrfManager\s*(::|\()/',
            $source,
            'the core CSRF manager must not be called, whatever the surrounding prose names'
        );
        $this->assertStringNotContainsString('\\FSFramework', $source);
        $this->assertStringNotContainsString("\$_SESSION['system_updater']", $source);
        $this->assertStringNotContainsString("\$_SESSION['_csrf']", $source);
        $this->assertStringNotContainsString('system_updater_csrf', $source);

        $console = (string) file_get_contents(dirname(__DIR__) . '/lib/recovery_session.php');
        $this->assertSame(
            1,
            preg_match("/const RECOVERY_CONSOLE_NAMESPACE = '([^']+)'/", $console, $matches),
            'premise: the console names its namespace exactly once'
        );
        $this->assertSame(
            $matches[1],
            RECOVERY_LOGIN_TOKEN_NAMESPACE,
            'the form token lives in the session the console opened, not in a bucket of its own'
        );

        $pluginToken = (string) file_get_contents(dirname(__DIR__) . '/lib/csrf_token.php');
        $this->assertSame(
            1,
            preg_match("/define\('SYSTEM_UPDATER_CSRF_TTL',\s*([^)]*)\)/", $pluginToken, $ttl),
            'premise: the plugin token publishes its TTL as a constant'
        );
        $this->assertSame('4 * 3600', trim($ttl[1]));
        $this->assertSame(4 * 3600, RECOVERY_LOGIN_TOKEN_TTL);
    }

    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/lib/recovery_login_token.php');
    }

    private function functionBody(string $source, string $function): string
    {
        $pattern = '/function\s+' . preg_quote($function, '/') . '\s*\([^)]*\)[^{]*\{(.*?)\n\}/s';
        $this->assertSame(1, preg_match($pattern, $source, $matches), 'premise: ' . $function . '() exists');

        return $matches[1];
    }
}
