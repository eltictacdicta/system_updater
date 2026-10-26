<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/recovery_policy.php';

/**
 * The console's credential verdict and the count it is allowed to move.
 *
 * Covers REC-02 (a submission rejected before evaluation records **zero**
 * attempts, in every bucket) and REC-05 (one uniform failure outcome, one
 * password verification, comparable work on the unknown-nick path).
 *
 * Both requirements are asserted through the pure pipeline
 * {@see recovery_policy_evaluate()} with an injected verdict callable, because
 * this suite has no database: the "was the credential evaluated?" observable is
 * a call counter on that seam, never a wall-clock measurement and never a row
 * read.
 */
final class RecoveryCredentialVerdictTest extends TestCase
{
    /**
     * A verdict served by the seam, counting its own invocations.
     *
     * The pipeline is only allowed to reach credential evaluation through this
     * callable, so `$calls` is the falsifiable form of "no password comparison
     * occurred".
     *
     * @param array{calls: int} $counter
     */
    private static function countingVerdict(string $outcome, array &$counter): callable
    {
        $counter = ['calls' => 0];

        return function (?array $row, string $plain) use ($outcome, &$counter): string {
            $counter['calls']++;

            return $outcome;
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function facts(array $overrides = []): array
    {
        return array_merge([
            'token_valid' => true,
            'ip_banned' => false,
            'throttled' => false,
            'row' => [
                'nick' => 'admin',
                'password' => '$2y$10$abcdefghijklmnopqrstuv',
                'admin' => 1,
            ],
            'plain' => 'correct-horse-battery-staple',
        ], $overrides);
    }

    #[Test]
    public function aRejectedLoginTokenRecordsNoAttempt(): void
    {
        $counter = [];
        $verdict = self::countingVerdict('ok', $counter);

        $result = recovery_policy_evaluate(
            self::facts(['token_valid' => false]),
            $verdict,
            false
        );

        $this->assertSame('deny', $result['outcome']);
        $this->assertSame('none', $result['bucket'], 'a rejected token must not name a bucket');
        $this->assertFalse($result['record_failure'], 'a rejected token must record no attempt');
        $this->assertFalse($result['clear']);
        $this->assertSame(0, $counter['calls'], 'the credential must not be evaluated');
    }

    #[Test]
    public function aMissingTokenFactIsTreatedAsARejectedToken(): void
    {
        $counter = [];
        $verdict = self::countingVerdict('ok', $counter);

        $facts = self::facts();
        unset($facts['token_valid']);

        $result = recovery_policy_evaluate($facts, $verdict, true);

        $this->assertSame('deny', $result['outcome']);
        $this->assertSame('none', $result['bucket']);
        $this->assertSame(0, $counter['calls']);
    }

    #[Test]
    public function aRejectedLoginTokenRecordsNothingInSharedModeEither(): void
    {
        $counter = [];
        $verdict = self::countingVerdict('ok', $counter);

        $result = recovery_policy_evaluate(
            self::facts(['token_valid' => false]),
            $verdict,
            true
        );

        $this->assertSame('none', $result['bucket'], 'shared mode must not become the fallback bucket');
        $this->assertFalse($result['record_failure']);
        $this->assertSame(0, $counter['calls']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function provideClosedGates(): array
    {
        return [
            'banned address' => [['ip_banned' => true], 'banned'],
            'throttled nick' => [['throttled' => true], 'throttled'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[Test]
    #[DataProvider('provideClosedGates')]
    public function aGateThatClosedBeforeEvaluationNeverReachesTheCredential(
        array $overrides,
        string $expectedPhase
    ): void {
        $counter = [];
        $verdict = self::countingVerdict('ok', $counter);

        $result = recovery_policy_evaluate(self::facts($overrides), $verdict, false);

        $this->assertSame('deny', $result['outcome']);
        $this->assertSame($expectedPhase, $result['phase']);
        $this->assertSame('none', $result['bucket']);
        $this->assertFalse($result['record_failure']);
        $this->assertSame(0, $counter['calls']);
    }

    #[Test]
    public function aFailedCredentialRecordsExactlyOneFailureInExactlyOneBucket(): void
    {
        $counter = [];
        $verdict = self::countingVerdict('deny', $counter);

        $result = recovery_policy_evaluate(self::facts(), $verdict, false);

        $this->assertSame('deny', $result['outcome']);
        $this->assertSame('credential_failed', $result['phase']);
        $this->assertSame('fallback', $result['bucket']);
        $this->assertTrue($result['record_failure'], 'a real failed attempt records one failure');
        $this->assertFalse($result['clear'], 'a failure must not also clear the budget');
        $this->assertSame(1, $counter['calls']);
    }

    #[Test]
    public function theBucketIsTheSharedOneWhenThatModeIsInUse(): void
    {
        $counter = [];
        $verdict = self::countingVerdict('deny', $counter);

        $result = recovery_policy_evaluate(self::facts(), $verdict, true);

        $this->assertSame('shared', $result['bucket'], 'one mechanism, and it is the shared one');
        $this->assertTrue($result['record_failure']);
    }

    #[Test]
    public function aSuccessfulCredentialClearsTheCounterItRecordedIn(): void
    {
        $counter = [];
        $verdict = self::countingVerdict('ok', $counter);

        $result = recovery_policy_evaluate(self::facts(), $verdict, false);

        $this->assertSame('ok', $result['outcome']);
        $this->assertSame('credential_ok', $result['phase']);
        $this->assertSame('fallback', $result['bucket'], 'a success names the counter it clears');
        $this->assertFalse($result['record_failure']);
        $this->assertTrue($result['clear']);
        $this->assertSame(1, $counter['calls'], 'exactly one evaluation per request');
    }

    #[Test]
    public function aVerdictThatReturnsAnythingElseIsAFailure(): void
    {
        $counter = [];
        $verdict = self::countingVerdict('perhaps', $counter);

        $result = recovery_policy_evaluate(self::facts(), $verdict, false);

        $this->assertSame('deny', $result['outcome']);
        $this->assertTrue($result['record_failure']);
        $this->assertSame(1, $counter['calls']);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function providePhases(): array
    {
        return [
            'token rejected' => ['token_rejected', false],
            'banned' => ['banned', false],
            'throttled' => ['throttled', false],
            'credential failed' => ['credential_failed', true],
            'credential ok' => ['credential_ok', false],
        ];
    }

    #[Test]
    #[DataProvider('providePhases')]
    public function onlyAFailedEvaluationCounts(string $phase, bool $expected): void
    {
        $this->assertSame($expected, recovery_policy_should_count($phase));
    }

    #[Test]
    public function anUnknownPhaseCountsNothing(): void
    {
        $this->assertFalse(recovery_policy_should_count('made_up'));
        $this->assertFalse(recovery_policy_should_count(''));
        $this->assertSame('none', recovery_policy_count_bucket('made_up', true));
    }

    #[Test]
    public function theBucketRuleNeverAnswersBothMechanisms(): void
    {
        $this->assertSame('shared', recovery_policy_count_bucket('credential_failed', true));
        $this->assertSame('fallback', recovery_policy_count_bucket('credential_failed', false));
        $this->assertSame('shared', recovery_policy_count_bucket('credential_ok', true));
        $this->assertSame('fallback', recovery_policy_count_bucket('credential_ok', false));
        $this->assertSame('none', recovery_policy_count_bucket('token_rejected', false));
        $this->assertSame('none', recovery_policy_count_bucket('banned', true));
        $this->assertSame('none', recovery_policy_count_bucket('throttled', true));
    }

    /**
     * A verifier that counts its invocations, delegates to the real
     * `password_verify()` and records the hash it was handed.
     *
     * This is REC-05's seam: "exactly one `password_verify()`" and "the
     * unknown-nick path verifies against the declared dummy hash" are both facts
     * about this callable, not about wall-clock time.
     *
     * @param array{calls: int, hashes: list<string>} $observed
     */
    private static function observingVerifier(array &$observed): callable
    {
        $observed = ['calls' => 0, 'hashes' => []];

        return function (string $hash, string $plain) use (&$observed): bool {
            $observed['calls']++;
            $observed['hashes'][] = $hash;

            return password_verify($plain, $hash);
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function bcryptRow(string $plain, bool|int|string|null $admin = 1): array
    {
        return [
            'nick' => 'admin',
            'password' => password_hash($plain, PASSWORD_BCRYPT, ['cost' => 4]),
            'admin' => $admin,
        ];
    }

    #[Test]
    public function anUnknownNickPerformsExactlyOneVerificationAgainstTheDummyHash(): void
    {
        $observed = [];
        $outcome = recovery_policy_verify_credentials(null, 'anything', self::observingVerifier($observed));

        $this->assertSame('deny', $outcome);
        $this->assertSame(1, $observed['calls'], 'REC-05: the unknown-nick path must still do the work');
        $this->assertSame(
            [RECOVERY_POLICY_DUMMY_HASH],
            $observed['hashes'],
            'the dummy hash is the declared constant, not a per-request computation'
        );
    }

    #[Test]
    public function aWrongPasswordPerformsExactlyOneVerificationAgainstTheStoredHash(): void
    {
        $row = self::bcryptRow('correct-horse-battery-staple');
        $observed = [];

        $outcome = recovery_policy_verify_credentials(
            $row,
            'wrong-horse-battery-staple',
            self::observingVerifier($observed)
        );

        $this->assertSame('deny', $outcome);
        $this->assertSame(1, $observed['calls']);
        $this->assertSame([$row['password']], $observed['hashes']);
    }

    #[Test]
    public function aNonAdministratorWithTheCorrectPasswordIsIndistinguishable(): void
    {
        $row = self::bcryptRow('correct-horse-battery-staple', 0);
        $observed = [];

        $outcome = recovery_policy_verify_credentials(
            $row,
            'correct-horse-battery-staple',
            self::observingVerifier($observed)
        );

        $this->assertSame('deny', $outcome, 'REC-05: authenticated-but-not-administrator is the same failure');
        $this->assertSame(1, $observed['calls'], 'one verification, exactly like the other two failures');
        $this->assertSame([$row['password']], $observed['hashes']);
    }

    #[Test]
    public function anAdministratorWithTheCorrectPasswordIsAccepted(): void
    {
        $row = self::bcryptRow('correct-horse-battery-staple');
        $observed = [];

        $outcome = recovery_policy_verify_credentials(
            $row,
            'correct-horse-battery-staple',
            self::observingVerifier($observed)
        );

        $this->assertSame('ok', $outcome);
        $this->assertSame(1, $observed['calls']);
    }

    #[Test]
    public function theThreeFailuresProduceOneOutcomeTokenAndOneBody(): void
    {
        $observed = [];
        $verifier = self::observingVerifier($observed);

        $unknown = recovery_policy_verify_credentials(null, 'x', $verifier);
        $wrong = recovery_policy_verify_credentials(self::bcryptRow('right'), 'wrong', $verifier);
        $notAdmin = recovery_policy_verify_credentials(self::bcryptRow('right', 0), 'right', $verifier);

        $this->assertSame($unknown, $wrong);
        $this->assertSame($wrong, $notAdmin);

        $bodies = array_map(
            static fn (): string => recovery_policy_failure_message(
                RECOVERY_POLICY_PHASE_CREDENTIAL_FAILED
            ),
            [$unknown, $wrong, $notAdmin]
        );

        $this->assertCount(1, array_unique($bodies), 'one failure body, no reason-specific variant');
        $this->assertNotSame('', $bodies[0]);
    }

    /**
     * REC-05's third scenario: no cause leaks in the text an operator sees.
     */
    #[Test]
    public function theFailureBodyNamesNoReason(): void
    {
        $message = recovery_policy_failure_message(RECOVERY_POLICY_PHASE_CREDENTIAL_FAILED);

        foreach (['usuario no encontrado', 'contraseña incorrecta', 'no es administrador', 'usuario no existe'] as $hint) {
            $this->assertStringNotContainsStringIgnoringCase($hint, $message);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideEveryRefusalPhase(): array
    {
        return [
            'token rejected' => [RECOVERY_POLICY_PHASE_TOKEN_REJECTED],
            'banned' => [RECOVERY_POLICY_PHASE_BANNED],
            'throttled' => [RECOVERY_POLICY_PHASE_THROTTLED],
            'credential failed' => [RECOVERY_POLICY_PHASE_CREDENTIAL_FAILED],
        ];
    }

    /**
     * REC-03 and REC-04 both say the refusal "equals the uniform failure of
     * REC-05". In the default install state that is literally true: a banned or
     * throttled submission renders the same body as a wrong password, so the
     * gate cannot be used as an oracle for either.
     */
    #[Test]
    #[DataProvider('provideEveryRefusalPhase')]
    public function everyRefusalRendersTheSameBodyInSharedMode(string $phase): void
    {
        $this->assertSame(
            recovery_policy_failure_message(RECOVERY_POLICY_PHASE_CREDENTIAL_FAILED, true),
            recovery_policy_failure_message($phase, true)
        );
    }

    /**
     * REC-04's third scenario, and the one place the body is allowed to differ:
     * with no core throttle the operator is told the console is running its
     * local limiter, because that is a deployment fact they have to act on.
     */
    #[Test]
    public function aLocalLimiterRefusalSaysSoWhileTheSharedOneDoesNot(): void
    {
        $shared = recovery_policy_failure_message(RECOVERY_POLICY_PHASE_THROTTLED, true);
        $local = recovery_policy_failure_message(RECOVERY_POLICY_PHASE_THROTTLED, false);

        $this->assertSame(recovery_policy_failure_message(RECOVERY_POLICY_PHASE_CREDENTIAL_FAILED, true), $shared);
        $this->assertStringContainsString(RECOVERY_POLICY_FAILURE_MESSAGE, $local);
        $this->assertStringContainsStringIgnoringCase('limitador local', $local);
        $this->assertNotSame($shared, $local);
    }

    #[Test]
    public function anAcceptedSubmissionHasNoFailureBody(): void
    {
        $this->assertSame('', recovery_policy_failure_message(RECOVERY_POLICY_PHASE_CREDENTIAL_OK));
    }

    #[Test]
    public function theDummyHashIsADeclaredBcryptConstant(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/lib/recovery_policy.php');

        $this->assertStringContainsString('const RECOVERY_POLICY_DUMMY_HASH =', $source, 'declared, not computed');
        $this->assertDoesNotMatchRegularExpression(
            '/\bpassword_hash\s*\(/',
            $source,
            'REC-05: no per-request hash computation on the failure path'
        );

        $info = password_get_info(RECOVERY_POLICY_DUMMY_HASH);
        $this->assertSame(PASSWORD_BCRYPT, $info['algo'], 'a valid bcrypt hash, so password_verify does the full work');
        $this->assertSame('bcrypt', $info['algoName']);
        $this->assertSame(10, $info['options']['cost'] ?? null, 'the default cost, which is what a real row usually carries');
    }

    #[Test]
    public function theDummyHashCanNeverAuthenticateAnyone(): void
    {
        foreach (['', 'dummy', 'password', 'admin', 'dummy-timing-protection'] as $plain) {
            $this->assertFalse(password_verify($plain, RECOVERY_POLICY_DUMMY_HASH));
        }
    }

    /**
     * REC-07's shape must survive this function: a legacy digest is a real
     * stored value and has to reach W6's verifier unchanged, never be replaced by
     * the dummy.
     */
    #[Test]
    public function aLegacyDigestReachesTheVerifierUnchanged(): void
    {
        $row = ['nick' => 'admin', 'password' => sha1('legacy-plain'), 'admin' => 1];
        $observed = [];
        $legacy = function (string $hash, string $plain) use (&$observed): bool {
            $observed['calls'] = ($observed['calls'] ?? 0) + 1;

            return hash_equals($hash, sha1($plain));
        };

        $this->assertSame('ok', recovery_policy_verify_credentials($row, 'legacy-plain', $legacy));
        $this->assertSame(1, $observed['calls']);
    }

    #[Test]
    public function aRowWithoutAStoredPasswordCostsTheSameAsAnUnknownNick(): void
    {
        $observed = [];
        $outcome = recovery_policy_verify_credentials(
            ['nick' => 'admin', 'password' => '', 'admin' => 1],
            'irrelevant',
            self::observingVerifier($observed)
        );

        $this->assertSame('deny', $outcome);
        $this->assertSame(1, $observed['calls']);
        $this->assertSame([RECOVERY_POLICY_DUMMY_HASH], $observed['hashes']);
    }

    #[Test]
    public function anEmptyPasswordIsOneVerificationAndOneFailure(): void
    {
        $row = self::bcryptRow('correct-horse-battery-staple');
        $observed = [];

        $this->assertSame(
            'deny',
            recovery_policy_verify_credentials($row, '', self::observingVerifier($observed))
        );
        $this->assertSame(1, $observed['calls']);
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function provideAdminFlagShapes(): array
    {
        return [
            'mysql int' => [1, true],
            'mysql string' => ['1', true],
            'php bool' => [true, true],
            'postgres t' => ['t', true],
            'text true' => ['true', true],
            'mysql zero' => [0, false],
            'mysql zero string' => ['0', false],
            'php false' => [false, false],
            'postgres f' => ['f', false],
            'null' => [null, false],
            'empty string' => ['', false],
        ];
    }

    /**
     * The admin flag arrives in whatever shape the reader's driver produces, and
     * a strict `true` comparison would silently deny every PostgreSQL
     * administrator - the AUTH-06 class of regression.
     */
    #[Test]
    #[DataProvider('provideAdminFlagShapes')]
    public function theAdminFlagAcceptsTheShapesAReaderProduces(mixed $admin, bool $expected): void
    {
        $row = self::bcryptRow('right', $admin);
        $observed = [];

        $outcome = recovery_policy_verify_credentials($row, 'right', self::observingVerifier($observed));

        $this->assertSame($expected ? 'ok' : 'deny', $outcome);
        $this->assertSame(1, $observed['calls'], 'the flag is read after verification, never instead of it');
    }

    /**
     * And end to end: the pipeline plus the verdict performs exactly one
     * `password_verify()` for each of the three failures (REC-05).
     */
    #[Test]
    public function thePipelinePerformsExactlyOneVerificationPerSubmission(): void
    {
        $submissions = [
            'unknown nick' => ['row' => null, 'plain' => 'x'],
            'wrong password' => ['row' => self::bcryptRow('right'), 'plain' => 'wrong'],
            'not an administrator' => ['row' => self::bcryptRow('right', 0), 'plain' => 'right'],
        ];

        foreach ($submissions as $label => $submission) {
            $observed = [];
            $verifier = self::observingVerifier($observed);
            $verdict = static function (?array $row, string $plain) use ($verifier): string {
                return recovery_policy_verify_credentials($row, $plain, $verifier);
            };

            $result = recovery_policy_evaluate(self::facts($submission), $verdict, false);

            $this->assertSame('deny', $result['outcome'], $label);
            $this->assertSame(1, $observed['calls'], $label . ': exactly one verification');
            $this->assertTrue($result['record_failure'], $label . ': one recorded failure');
        }
    }
}
