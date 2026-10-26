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

require_once dirname(__DIR__) . '/lib/recovery_legacy_hash.php';
require_once dirname(__DIR__) . '/lib/recovery_policy.php';

// The unit under construction. Required only when it exists: an unconditional
// include of a missing file is fatal at *load* time, which aborts the whole
// runner and hides every assertion this file exists to record. Guarded, the RED
// commit reports one error per case instead of one dead suite.
$suHandlerModule = dirname(__DIR__) . '/lib/recovery_handler.php';
if (is_file($suHandlerModule)) {
    require_once $suHandlerModule;
}

/**
 * The console's credential handler: the order of its reads and the oneness of
 * its refusal (REC-01, REC-02, REC-03, REC-05).
 *
 * What this file is the authority for
 * -----------------------------------
 * `lib/recovery_policy.php` decides *what a closed gate means* and in which
 * order the gates are consulted; it deliberately takes the gate **facts**, not
 * the request, so that it stays pure and property-testable. The other half of
 * the contract is the console's: **which reads happen, and when**. That half is
 * asserted here, and it is the half a refactor is most likely to break —
 * moving the row lookup above the ban read, or charging a counter on the
 * rejection path, changes no policy unit test at all.
 *
 * The observables
 * ---------------
 *   - `steps` — the ordered list of reads the handler performed. It is the
 *     handler's own report, so it is never trusted alone;
 *   - the counters on every injected seam — an independent witness that the
 *     read did or did not happen. Both are asserted together, so a handler that
 *     reports a step it skipped is caught by the counter and a handler that
 *     performs a read silently is caught by the list.
 *
 * The login token is real, not injected: the case opens a session, mints a
 * token through `lib/recovery_login_token.php` and submits its own value. The
 * token verdict is the first thing the handler is allowed to ask, so it is
 * also the one seam a test must not fake.
 */
#[PreserveGlobalState(false)]
final class RecoveryHandlerTest extends TestCase
{
    private const PLAIN = 'correct-horse-battery-staple';

    private string $savePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_SESSION = [];
        $this->savePath = sys_get_temp_dir() . '/su_handler_' . bin2hex(random_bytes(6));
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
     * A row whose password is a real bcrypt hash of {@see self::PLAIN}.
     *
     * Cost 4 on purpose: the work this suite is proving is "the verifier was
     * called once", not "bcrypt is slow", and a cost-10 hash per case would add
     * seconds without asserting anything more.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function row(array $overrides = []): array
    {
        return array_merge([
            'nick' => 'admin',
            'password' => password_hash(self::PLAIN, PASSWORD_BCRYPT, ['cost' => 4]),
            'admin' => 1,
        ], $overrides);
    }

    /**
     * The seam set, every entry counting itself.
     *
     * `$counts` is written by reference so an assertion can say "the lookup
     * never happened" instead of inferring it from the body.
     *
     * @param array<string, mixed> $options
     * @param array<string, int>|null $counts
     * @return array<string, mixed>
     */
    private static function seams(array $options = [], ?array &$counts = null): array
    {
        $counts = [
            'lookup' => 0,
            'verify' => 0,
            'ban' => 0,
            'reserve' => 0,
            'release' => 0,
            'record' => 0,
            'clear' => 0,
            'throttle' => 0,
        ];

        $row = $options['row'] ?? null;
        $banned = (bool) ($options['banned'] ?? false);
        $throttled = (bool) ($options['throttled'] ?? false);
        $reserve = (bool) ($options['reserve'] ?? false);
        $verifier = $options['verifier'] ?? null;

        $seams = [
            // Always stated, never detected: `recovery_policy_shared_throttle_available()`
            // answers from ambient class state, and in this suite the Composer
            // autoloader has already loaded `LoginThrottle` by the time some
            // other test runs. A case that depended on it would pass alone and
            // fail in the full suite — which is the definition of a flaky test.
            'shared_available' => (bool) ($options['shared'] ?? false),
            'lookup' => static function (string $nick) use ($row, &$counts): ?array {
                $counts['lookup']++;

                return is_array($row) ? $row : null;
            },
            'is_banned' => static function (string $ip, int $now) use ($banned, &$counts): bool {
                $counts['ban']++;

                return $banned;
            },
            'reserve' => static function (string $nick, string $ip, int $now) use ($reserve, &$counts): array {
                $counts['reserve']++;

                return ['reserved' => $reserve, 'throttled' => false];
            },
            'release' => static function (string $nick, string $ip) use (&$counts): bool {
                $counts['release']++;

                return true;
            },
            'record' => static function (string $bucket, string $nick, string $ip, int $now) use (&$counts): void {
                $counts['record']++;
            },
            'clear' => static function (string $bucket, string $nick, string $ip, int $now) use (&$counts): void {
                $counts['clear']++;
            },
            'shared_throttled' => static function (string $nick, string $ip) use ($throttled, &$counts): bool {
                $counts['throttle']++;

                return $throttled;
            },
        ];

        if ($verifier !== null) {
            $seams['verifier'] = $verifier;
        }

        return $seams;
    }

    /**
     * The counting verifier REC-05 is measured with: the real bridge, wrapped.
     *
     * A fake would prove nothing about "exactly one password comparison"; the
     * real W6 bridge is what production binds, so the count is taken around the
     * production callable.
     *
     * @param array<string, int> $counts
     */
    private static function countingVerifier(array &$counts): callable
    {
        return static function (string $stored, string $plain, ?string $salt) use (&$counts): bool {
            $counts['verify']++;

            return recovery_legacy_hash_verify($stored, $plain, $salt);
        };
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function submission(array $overrides = []): array
    {
        $token = recovery_login_token_read() ?? recovery_login_token_mint();

        return array_merge([
            'token' => (string) $token,
            'nick' => 'admin',
            'password' => self::PLAIN,
            'ip' => '203.0.113.7',
            'now' => 1_700_000_000,
        ], $overrides);
    }

    #[Test]
    public function aRejectedTokenIsRefusedBeforeAnyOtherRead(): void
    {
        recovery_login_token_mint();

        $counts = [];
        $seams = self::seams(['row' => self::row()], $counts);
        $seams['verifier'] = self::countingVerifier($counts);

        $result = recovery_handler_authenticate(
            self::submission(['token' => str_repeat('b', 64)]),
            $seams
        );

        $this->assertSame('deny', $result['outcome']);
        $this->assertSame(RECOVERY_POLICY_PHASE_TOKEN_REJECTED, $result['phase']);
        $this->assertSame(['token'], $result['steps'], 'the token verdict is the only read a rejected token allows');
        $this->assertSame(0, $counts['ban'], 'a rejected token must not read the ban bucket');
        $this->assertSame(0, $counts['throttle'], 'a rejected token must not consult a throttle');
        $this->assertSame(0, $counts['reserve'], 'a rejected token must not reserve a budget');
        $this->assertSame(0, $counts['lookup'], 'a rejected token must not look an account up');
        $this->assertSame(0, $counts['verify'], 'a rejected token must not compare a password');
        $this->assertSame(0, $counts['record'], 'a rejected token records nothing');
        $this->assertNull($result['identity'], 'a rejected token writes no identity');
        $this->assertSame(RECOVERY_POLICY_FAILURE_MESSAGE, $result['message']);
    }

    #[Test]
    public function aMissingTokenBehavesExactlyLikeAWrongOne(): void
    {
        recovery_login_token_mint();

        $wrong = recovery_handler_authenticate(
            self::submission(['token' => str_repeat('b', 64)]),
            self::seams(['row' => self::row()])
        );
        $absent = recovery_handler_authenticate(
            self::submission(['token' => '']),
            self::seams(['row' => self::row()])
        );

        $this->assertSame($wrong['message'], $absent['message'], 'the missing-token body is the wrong-token body');
        $this->assertSame($wrong['phase'], $absent['phase']);
        $this->assertSame($wrong['steps'], $absent['steps']);
        $this->assertSame($wrong['outcome'], $absent['outcome']);
    }

    #[Test]
    public function aBannedAddressIsRefusedBeforeAnyLookupOrVerification(): void
    {
        $counts = [];
        $seams = self::seams(['row' => self::row(), 'banned' => true], $counts);
        $seams['verifier'] = self::countingVerifier($counts);

        $result = recovery_handler_authenticate(self::submission(), $seams);

        $this->assertSame('deny', $result['outcome']);
        $this->assertSame(RECOVERY_POLICY_PHASE_BANNED, $result['phase']);
        $this->assertSame(['token', 'ban'], $result['steps'], 'the ban read closes the request before the budget');
        $this->assertSame(1, $counts['ban']);
        $this->assertSame(0, $counts['reserve'], 'a banned address is never charged');
        $this->assertSame(0, $counts['lookup'], 'a banned address is refused without touching the password');
        $this->assertSame(0, $counts['verify']);
        $this->assertSame(0, $counts['record']);
        $this->assertSame(RECOVERY_POLICY_FAILURE_MESSAGE, $result['message'], 'the refusal is the uniform one');
    }

    #[Test]
    public function anEmptySubmissionIsRefusedBeforeTheBudget(): void
    {
        $counts = [];
        $seams = self::seams(['row' => self::row()], $counts);
        $seams['verifier'] = self::countingVerifier($counts);

        $result = recovery_handler_authenticate(self::submission(['nick' => '']), $seams);

        $this->assertSame(RECOVERY_POLICY_PHASE_INPUT_REJECTED, $result['phase']);
        $this->assertSame(['token'], $result['steps'], 'an empty form reads nothing but the token');
        $this->assertSame(0, $counts['ban']);
        $this->assertSame(0, $counts['reserve']);
        $this->assertSame(0, $counts['lookup']);
        $this->assertSame(0, $counts['verify']);
    }

    #[Test]
    public function aThrottledNickIsRefusedBeforeEvaluation(): void
    {
        $counts = [];
        $seams = self::seams(
            ['row' => self::row(), 'throttled' => true, 'shared' => true],
            $counts
        );
        $seams['verifier'] = self::countingVerifier($counts);

        $result = recovery_handler_authenticate(self::submission(), $seams);

        $this->assertSame(RECOVERY_POLICY_PHASE_THROTTLED, $result['phase']);
        $this->assertSame(1, $counts['throttle'], 'the shared throttle is consulted once');
        $this->assertSame(0, $counts['lookup']);
        $this->assertSame(0, $counts['verify'], 'a throttled nick is refused before evaluation');
        $this->assertSame(0, $counts['record'], 'the shared mode records through its own seam, not on the refusal');
    }

    /**
     * REC-05's three reasons, one body and one verification each.
     *
     * The unknown-nick case is the one that has to reach the verifier at all:
     * it verifies against the constant dummy, which is what keeps its cost in
     * the same order as the wrong-password path.
     *
     * @return array<string, array{0: ?array<string, mixed>, 1: string}>
     */
    public static function credentialFailureCases(): array
    {
        return [
            'unknown nick' => [null, self::PLAIN],
            'wrong password' => [self::row(), 'not-the-password'],
            'authenticated but not administrator' => [self::row(['admin' => 0]), self::PLAIN],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('credentialFailureCases')]
    public function theThreeCredentialReasonsRenderOneIdenticalBody(
        ?array $row,
        string $plain
    ): void {
        $counts = [];
        $seams = self::seams(['row' => $row, 'shared' => true], $counts);
        $seams['verifier'] = self::countingVerifier($counts);

        $result = recovery_handler_authenticate(self::submission(['password' => $plain]), $seams);

        $this->assertSame('deny', $result['outcome']);
        $this->assertSame(RECOVERY_POLICY_PHASE_CREDENTIAL_FAILED, $result['phase']);
        $this->assertSame(RECOVERY_POLICY_FAILURE_MESSAGE, $result['message']);
        $this->assertSame(1, $counts['lookup'], 'exactly one account lookup');
        $this->assertSame(1, $counts['verify'], 'exactly one password comparison');
        $this->assertSame(1, $counts['record'], 'exactly one mechanism moves, and it moves once');
        $this->assertSame(0, $counts['reserve'], 'the shared mode never charges the fallback counter');
        $this->assertSame(
            ['token', 'ban', 'throttle', 'lookup', 'verify', 'record'],
            $result['steps'],
            'the pipeline reaches the credential once, after both gates, and counts the failure once'
        );
        $this->assertNull($result['identity']);
    }

    #[Test]
    public function aFailedLoginIsChargedExactlyOnce(): void
    {
        $counts = [];
        $seams = self::seams(['row' => self::row(), 'reserve' => true], $counts);
        $seams['verifier'] = self::countingVerifier($counts);

        $result = recovery_handler_authenticate(self::submission(['password' => 'wrong']), $seams);

        $this->assertSame('deny', $result['outcome']);
        $this->assertSame(1, $counts['reserve'], 'the reservation is the charge');
        $this->assertSame(0, $counts['record'], 'a submission the pipeline already charged is never counted twice');
        $this->assertSame(0, $counts['clear'], 'a failure leaves its charge behind');
    }

    #[Test]
    public function aSuccessfulLoginReleasesItsOwnReservation(): void
    {
        $counts = [];
        $seams = self::seams(['row' => self::row(), 'reserve' => true], $counts);
        $seams['verifier'] = self::countingVerifier($counts);

        $result = recovery_handler_authenticate(self::submission(), $seams);

        $this->assertSame('ok', $result['outcome']);
        $this->assertSame(RECOVERY_POLICY_PHASE_CREDENTIAL_OK, $result['phase']);
        $this->assertSame('', $result['message'], 'success renders no failure body');
        $this->assertSame(1, $counts['reserve']);
        $this->assertSame(1, $counts['release'], 'the success-clear runs through the release seam');
        $this->assertSame(0, $counts['record']);
        $this->assertSame(0, $counts['clear'], 'the released reservation is not cleared a second time');
        $this->assertSame('admin', $result['identity']['user_nick'] ?? null);
        $this->assertSame(1_700_000_000, $result['identity']['login_time'] ?? null);
        $this->assertSame(1_700_000_000, $result['identity']['last_activity'] ?? null);
    }

    #[Test]
    public function aFormatInvalidStoredHashIsAUniformFailureAndWritesNoIdentity(): void
    {
        $counts = [];
        $seams = self::seams(['row' => self::row(['password' => 'not-a-recognizable-hash'])], $counts);
        $seams['verifier'] = self::countingVerifier($counts);

        $result = recovery_handler_authenticate(self::submission(), $seams);

        $this->assertSame('deny', $result['outcome']);
        $this->assertSame(1, $counts['verify']);
        $this->assertSame(RECOVERY_POLICY_FAILURE_MESSAGE, $result['message']);
        $this->assertNull($result['identity'], 'an unrecognizable hash is never accepted and writes nothing');
    }

    /**
     * The binding REC-07 exists for: with no verifier injected, the handler
     * verifies through W6's bridge, so a SHA-1 administrator authenticates in a
     * process with no autoloader, no core class and no `salt` column.
     */
    #[Test]
    public function theDefaultVerifierIsThePluginLocalLegacyBridge(): void
    {
        $legacy = self::row(['password' => sha1(self::PLAIN)]);
        $result = recovery_handler_authenticate(self::submission(), self::seams(['row' => $legacy]));

        $this->assertSame('ok', $result['outcome'], 'a SHA-1 administrator authenticates without the autoloader');
        $this->assertSame('admin', $result['identity']['user_nick'] ?? null);

        $md5 = self::row(['password' => md5(self::PLAIN)]);
        $this->assertSame('ok', recovery_handler_authenticate(self::submission(), self::seams(['row' => $md5]))['outcome']);
    }

    #[Test]
    public function theRowsSaltTravelsIntoTheVerifier(): void
    {
        $salted = self::row(['password' => sha1('per-row-salt' . self::PLAIN), 'salt' => 'per-row-salt']);

        $result = recovery_handler_authenticate(self::submission(), self::seams(['row' => $salted]));

        $this->assertSame('ok', $result['outcome'], 'the row salt is bound into the comparison');
    }

    /**
     * The rendered failure body, asserted on the scripts that produce it.
     *
     * This is a source assertion and it is deliberate: the three strings the
     * console shipped are the oracle REC-05 closes, and their absence is only
     * observable by reading the file. The behavioural half — one message for
     * every cause — is the rest of this class.
     *
     * The message is built by the handler (`recovery_policy_failure_message()`)
     * and rendered by the console, so both halves are asserted: a console that
     * builds its own strings would still pass a handler-only check.
     */
    #[Test]
    public function theConsoleRendersOneFailureBody(): void
    {
        $console = $this->codeWithoutComments(
            (string) file_get_contents(dirname(__DIR__) . '/recovery.php')
        );

        foreach (['no encontrado', 'no es administrador', 'Contraseña incorrecta'] as $distinct) {
            $this->assertStringNotContainsStringIgnoringCase(
                $distinct,
                $console,
                'a distinct credential failure string turns the console into an oracle'
            );
        }

        $this->assertStringContainsString(
            'recovery_handler_authenticate',
            $console,
            'the console renders the one body the handler returns'
        );
        $this->assertStringContainsString('recovery_login_token_field', $console, 'the login form carries the console token');

        $handler = $this->codeWithoutComments(
            (string) file_get_contents(dirname(__DIR__) . '/lib/recovery_handler.php')
        );

        $this->assertStringContainsString('recovery_policy_failure_message', $handler);
        $this->assertStringContainsString('recovery_login_token_validate', $handler);
        $this->assertStringContainsString('recovery_legacy_hash_verify', $handler, 'REC-07 is the default verifier');
    }

    private function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
