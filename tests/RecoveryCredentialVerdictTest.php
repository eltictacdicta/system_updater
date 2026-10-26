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
        $this->assertSame('none', recovery_policy_count_bucket('credential_ok', true));
        $this->assertSame('none', recovery_policy_count_bucket('token_rejected', false));
    }
}
