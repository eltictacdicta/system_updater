<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\SystemUpdater\Support\PolicyProbe;

require_once dirname(__DIR__) . '/lib/recovery_policy.php';
require_once __DIR__ . '/Support/PolicyProbe.php';

/**
 * REC-04: the console shares the primary login's budget, and bounds guessing
 * when that budget cannot be reached.
 *
 * Two modes, one budget each:
 *
 * - **shared** - the core throttle is loadable, so `isThrottled()` /
 *   `recordFailure()` / `clear()` keep the two doors on one counter by
 *   construction. If the console had a local counter as well, an attacker who
 *   alternated doors would get both allowances.
 * - **fallback** - the damaged-core state the console exists for. The local
 *   counter's thresholds are declared constants, asserted equal to the core's
 *   in {@see testTheDeclaredBudgetIsTheCoreBudget()} whenever the class is
 *   loadable. This is not a second budget in any single install state: where
 *   the class is unreachable the primary login cannot consult it either.
 *
 * Everything here is driven by an injected clock and a throwaway directory, so
 * no test sleeps and none touches the real installation.
 */
final class RecoveryFallbackLimiterTest extends TestCase
{
    private const NOW = 1_700_000_000;

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            foreach (glob($root . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($root);
        }
        $this->roots = [];

        parent::tearDown();
    }

    private function tempDir(): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/') . '/su_limit_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->roots[] = $dir;

        return $dir;
    }

    private function counterPath(): string
    {
        return recovery_policy_fallback_path('/tmp/install-root', $this->tempDir());
    }

    /**
     * Record `$count` failures for one nick/address pair, all at the same
     * instant so the window arithmetic stays honest.
     */
    private function recordFailures(string $path, string $nick, string $ip, int $count, int $now = self::NOW): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->assertTrue(recovery_policy_fallback_record($path, $nick, $ip, $now));
        }
    }

    #[Test]
    public function theDeclaredBudgetIsTheCoreBudget(): void
    {
        if (!class_exists(\FSFramework\Security\LoginThrottle::class)) {
            $this->markTestSkipped(
                'FSFramework\\Security\\LoginThrottle is not loadable in this run, so the declared budget'
                . ' has nothing to drift against. The constants are still exercised by every case below.'
            );
        }

        $this->assertSame(\FSFramework\Security\LoginThrottle::MAX_ATTEMPTS, RECOVERY_POLICY_MAX_ATTEMPTS);
        $this->assertSame(\FSFramework\Security\LoginThrottle::THROTTLE_WINDOW, RECOVERY_POLICY_THROTTLE_WINDOW);
        $this->assertSame(
            \FSFramework\Security\LoginThrottle::ACCOUNT_MAX_ATTEMPTS,
            RECOVERY_POLICY_ACCOUNT_MAX_ATTEMPTS
        );
        $this->assertSame(\FSFramework\Security\LoginThrottle::ACCOUNT_WINDOW, RECOVERY_POLICY_ACCOUNT_WINDOW);
    }

    #[Test]
    public function sharedModeIsDetectedWithoutLoadingTheClass(): void
    {
        $before = class_exists('FSFramework\\Security\\LoginThrottle', false);
        $policyFile = dirname(__DIR__) . '/lib/recovery_policy.php';

        $probe = PolicyProbe::run(
            [$policyFile],
            [],
            'echo json_encode(["shared" => recovery_policy_shared_throttle_available()]);'
        );

        $this->assertSame(0, $probe['exit'], 'the policy module must load with nothing else: ' . $probe['output']);
        $this->assertSame(
            ['shared' => false],
            PolicyProbe::decodeJson($probe['output']),
            'RACC-01: with no autoloader the core throttle is unavailable, never autoloaded'
        );

        // The same call in this process must not have pulled the class in either.
        recovery_policy_shared_throttle_available();
        $this->assertSame($before, class_exists('FSFramework\\Security\\LoginThrottle', false));
    }

    #[Test]
    public function sixFailuresFromOneAddressCloseTheBudgetForTheSeventh(): void
    {
        $path = $this->counterPath();

        $this->recordFailures($path, 'admin', '203.0.113.7', 5);
        $this->assertFalse(
            recovery_policy_fallback_count($path, 'admin', '203.0.113.7', self::NOW)['throttled'],
            'five failures are still inside the budget'
        );

        $this->recordFailures($path, 'admin', '203.0.113.7', 1, self::NOW + 1);

        $consult = recovery_policy_fallback_count($path, 'admin', '203.0.113.7', self::NOW + 2);
        $this->assertTrue($consult['throttled']);
        $this->assertSame(6, $consult['attempts']);
    }

    #[Test]
    public function aDifferentAddressIsNotThrottledByThatBudget(): void
    {
        $path = $this->counterPath();
        $this->recordFailures($path, 'admin', '203.0.113.7', 6);

        $this->assertFalse(
            recovery_policy_fallback_count($path, 'admin', '198.51.100.9', self::NOW)['throttled'],
            'the per-address budget must not become a global one'
        );
    }

    #[Test]
    public function twentyFailuresAcrossAddressesCloseTheAccountBudget(): void
    {
        $path = $this->counterPath();

        for ($i = 0; $i < RECOVERY_POLICY_ACCOUNT_MAX_ATTEMPTS; $i++) {
            recovery_policy_fallback_record($path, 'admin', '203.0.113.' . $i, self::NOW);
        }

        $consult = recovery_policy_fallback_count($path, 'admin', '198.51.100.9', self::NOW);
        $this->assertTrue($consult['throttled'], 'rotating addresses must not reset the account budget');
        $this->assertSame(RECOVERY_POLICY_ACCOUNT_MAX_ATTEMPTS, $consult['account_attempts']);
        $this->assertSame(0, $consult['attempts'], 'this address itself has no recorded failures');
    }

    #[Test]
    public function anElapsedWindowReopensTheBudget(): void
    {
        $path = $this->counterPath();
        $this->recordFailures($path, 'admin', '203.0.113.7', 6, self::NOW);

        $this->assertTrue(recovery_policy_fallback_count($path, 'admin', '203.0.113.7', self::NOW)['throttled']);
        $this->assertFalse(
            recovery_policy_fallback_count(
                $path,
                'admin',
                '203.0.113.7',
                self::NOW + RECOVERY_POLICY_THROTTLE_WINDOW
            )['throttled'],
            'the window is a budget, not a permanent ban'
        );
    }

    #[Test]
    public function theAccountWindowOutlivesTheAddressWindow(): void
    {
        $path = $this->counterPath();

        for ($i = 0; $i < RECOVERY_POLICY_ACCOUNT_MAX_ATTEMPTS; $i++) {
            recovery_policy_fallback_record($path, 'admin', '203.0.113.' . $i, self::NOW);
        }

        $afterAddressWindow = self::NOW + RECOVERY_POLICY_THROTTLE_WINDOW + 1;
        $this->assertTrue(
            recovery_policy_fallback_count($path, 'admin', '198.51.100.9', $afterAddressWindow)['throttled'],
            'twenty failures for one account stay counted for the longer account window'
        );

        $this->assertFalse(
            recovery_policy_fallback_count(
                $path,
                'admin',
                '198.51.100.9',
                self::NOW + RECOVERY_POLICY_ACCOUNT_WINDOW
            )['throttled']
        );
    }

    #[Test]
    public function aSuccessClearsTheCounterItRecordedIn(): void
    {
        $path = $this->counterPath();
        $this->recordFailures($path, 'admin', '203.0.113.7', 3);

        $this->assertSame(3, recovery_policy_fallback_count($path, 'admin', '203.0.113.7', self::NOW)['attempts']);
        $this->assertTrue(recovery_policy_fallback_clear($path, 'admin', '203.0.113.7'));

        $consult = recovery_policy_fallback_count($path, 'admin', '203.0.113.7', self::NOW);
        $this->assertSame(0, $consult['attempts']);
        $this->assertSame(0, $consult['account_attempts'], 'the account budget is cleared too');
        $this->assertFalse($consult['throttled']);
    }

    /**
     * One account, one budget, however the nick is spelled.
     *
     * `fs_users.nick` is compared under `utf8mb4_unicode_ci`, so `admin`,
     * `Admin`, `ÁDMIN` and `ádmin` answer the same row and reach the same
     * verifier. The counter is keyed by a digest of the submitted string, so a
     * spelling the database treats as equal must not open a budget of its own:
     * that would multiply REC-04's ceiling by the number of spellings an
     * attacker cared to type, on the one surface REC-04 protects.
     *
     * The assertion is on the counter, not on a message. The file must hold
     * exactly the two keys the whole budget occupies, the sixth charge must land
     * on the shared key, and the seventh spelling must be the one refused.
     */
    #[Test]
    public function everySpellingOfOneAccountSharesOneBudget(): void
    {
        $path = $this->counterPath();
        $variants = ['admin', 'Admin', 'ADMIN', 'aDmin', 'Ádmin', 'ádmin', 'áDmín'];

        $admitted = [];
        foreach ($variants as $variant) {
            $reservation = recovery_policy_fallback_reserve($path, $variant, '203.0.113.7', self::NOW);
            if ($reservation['reserved'] === true) {
                $admitted[] = $variant;
            }
        }

        $this->assertSame(
            array_slice($variants, 0, RECOVERY_POLICY_MAX_ATTEMPTS),
            $admitted,
            'six submissions consume the single budget; the seventh spelling is refused, whatever it is'
        );

        $state = recovery_policy_fallback_state($path);
        $this->assertSame(
            ['pair_' . md5('admin|203.0.113.7'), 'account_' . md5('admin')],
            array_keys($state),
            'one nick+address key and one account key, not one pair per spelling'
        );
        $this->assertSame(
            RECOVERY_POLICY_MAX_ATTEMPTS,
            $state['pair_' . md5('admin|203.0.113.7')]['count'],
            'the sixth charge landed on the shared key instead of opening a bucket of its own'
        );
    }

    /**
     * The success-clear names the same subject the charge did.
     *
     * A clear keyed by the typed spelling would release a bucket nobody charged
     * and leave the real one full, so a legitimate login would be measured
     * against an exhausted budget on its next attempt.
     */
    #[Test]
    public function aSuccessClearsTheBudgetWhateverSpellingAsksForIt(): void
    {
        $path = $this->counterPath();
        $this->recordFailures($path, 'admin', '203.0.113.7', 2);

        $this->assertSame(
            2,
            recovery_policy_fallback_count($path, 'ÁDMIN', '203.0.113.7', self::NOW)['attempts'],
            'the accented spelling is measured against the budget the plain one consumed'
        );

        $this->assertTrue(recovery_policy_fallback_clear($path, 'ÁDMIN', '203.0.113.7'));

        $this->assertSame(
            [],
            recovery_policy_fallback_state($path),
            'the clear released the bucket the failures were recorded in'
        );
        $this->assertSame(
            0,
            recovery_policy_fallback_count($path, 'admin', '203.0.113.7', self::NOW)['attempts'],
            'and the plain spelling finds it empty too'
        );
    }

    #[Test]
    public function theCounterIsBoundedRatherThanAppendedForever(): void
    {
        $path = $this->counterPath();
        $this->recordFailures($path, 'admin', '203.0.113.7', 2, self::NOW);

        $later = self::NOW + RECOVERY_POLICY_ACCOUNT_WINDOW + 1;
        recovery_policy_fallback_record($path, 'otro', '198.51.100.9', $later);

        $state = recovery_policy_fallback_state($path);
        $this->assertCount(2, $state, 'the expired pair and account entries are pruned on write, not accumulated');
        $this->assertSame(0, recovery_policy_fallback_count($path, 'admin', '203.0.113.7', $later)['attempts']);
        $this->assertSame(1, recovery_policy_fallback_count($path, 'otro', '198.51.100.9', $later)['attempts']);
    }

    /**
     * The counter lives in the installation, not in a directory every local user
     * can write to.
     *
     * `sys_get_temp_dir()` is world-writable and the file name is predictable
     * from the install root, so on a shared host another account can pre-create
     * the counter `0666` and then delete it (disabling the limiter), inflate it
     * (locking the administrator out) or plant a symlink for the next write to
     * follow. `FS_FOLDER/tmp/` already belongs to the installation, already
     * exists, and already holds `ip.log`; nothing in the core is needed to use
     * it. The per-install digest stays, because two installations under one
     * document root must not share a budget.
     */
    #[Test]
    public function theCounterFileLivesInsideTheInstallation(): void
    {
        $root = $this->tempDir();
        $path = recovery_policy_fallback_path($root);

        $this->assertSame($root . '/tmp', dirname($path), 'the installation own tmp directory, not the host one');
        $this->assertStringContainsString(FS_TMP_NAME, $path, 'the suite namespaces its own tmp prefix');
        $this->assertStringStartsWith(
            FS_TMP_NAME . 'system_updater_recovery_throttle_',
            basename($path),
            'one predictable file name per installation, inside a directory the installation controls'
        );

        $this->assertNotSame(
            recovery_policy_fallback_path('/tmp/install-a'),
            recovery_policy_fallback_path('/tmp/install-b'),
            'two installations must not share a budget'
        );
    }

    /**
     * A planted link must not become the counter.
     *
     * Following it would let whoever created it choose where the next write
     * lands, and reading through it would let them feed the limiter forged
     * counts. The answer is the same one an unreadable file gets: no state, and
     * a fail-open refusal to write.
     */
    #[Test]
    public function aSymlinkedCounterFileIsRefused(): void
    {
        $dir = $this->tempDir();
        $path = $dir . '/counter.json';
        $target = $dir . '/target.json';
        file_put_contents($target, json_encode([
            'pair_' . md5('admin|203.0.113.7') => ['count' => 99, 'updated' => self::NOW],
        ]));
        symlink($target, $path);

        $this->assertSame([], recovery_policy_fallback_state($path), 'a link is not the counter');
        $this->assertFalse(recovery_policy_fallback_record($path, 'admin', '203.0.113.7', self::NOW));

        $reservation = recovery_policy_fallback_reserve($path, 'admin', '203.0.113.7', self::NOW);
        $this->assertFalse($reservation['reserved'], 'nothing was charged through the link');
        $this->assertFalse($reservation['throttled'], 'an unusable counter fails open, it does not lock the door');

        $this->assertSame(
            json_encode([
                'pair_' . md5('admin|203.0.113.7') => ['count' => 99, 'updated' => self::NOW],
            ]),
            file_get_contents($target),
            'the link target is never written'
        );
    }

    /**
     * The counter holds one installation's attempt counts, so nobody else needs
     * to read it. A file created by the console - or pre-created by someone else
     * with loose bits - must end up owner-only after the first write.
     */
    #[Test]
    public function theCounterFileIsOwnerOnly(): void
    {
        $dir = $this->tempDir();
        $path = $dir . '/fresh.json';

        $this->assertTrue(recovery_policy_fallback_record($path, 'admin', '203.0.113.7', self::NOW));
        clearstatcache(true, $path);
        $this->assertSame(0, fileperms($path) & 0077, 'a new counter is created owner-only');
    }

    #[Test]
    public function aWorldWritableCounterFileIsTightenedOnWrite(): void
    {
        $dir = $this->tempDir();
        $path = $dir . '/planted.json';
        file_put_contents($path, '{}');
        chmod($path, 0666);

        $this->assertTrue(recovery_policy_fallback_record($path, 'admin', '203.0.113.7', self::NOW));
        clearstatcache(true, $path);
        $this->assertSame(0, fileperms($path) & 0077, 'a pre-created 0666 counter is narrowed, not trusted');
    }

    #[Test]
    public function anUnusableCounterDirectoryFailsOpen(): void
    {
        $path = $this->tempDir() . '/missing/nested/counter.json';

        $this->assertFalse(recovery_policy_fallback_record($path, 'admin', '203.0.113.7', self::NOW));

        $reservation = recovery_policy_fallback_reserve($path, 'admin', '203.0.113.7', self::NOW);
        $this->assertFalse($reservation['reserved']);
        $this->assertFalse($reservation['throttled'], 'a counter that cannot be written must not lock the operator out');
    }

    #[Test]
    public function anAbsentCounterFileThrottlesNobody(): void
    {
        $path = $this->counterPath();

        $this->assertSame([], recovery_policy_fallback_state($path));
        $consult = recovery_policy_fallback_count($path, 'admin', '203.0.113.7', self::NOW);
        $this->assertFalse($consult['throttled']);
        $this->assertSame(0, $consult['attempts']);
        $this->assertFileDoesNotExist($path, 'a consult must not create the counter');
    }

    #[Test]
    public function aThrottledNickIsRefusedBeforeEvaluation(): void
    {
        $path = $this->counterPath();
        $this->recordFailures($path, 'admin', '203.0.113.7', RECOVERY_POLICY_MAX_ATTEMPTS);

        $consult = recovery_policy_fallback_count($path, 'admin', '203.0.113.7', self::NOW);
        $calls = 0;

        $result = recovery_policy_evaluate([
            'token_valid' => true,
            'ip_banned' => false,
            'throttled' => $consult['throttled'],
            'nick' => 'admin',
            'row' => ['nick' => 'admin', 'password' => 'bcrypt', 'admin' => 1],
            'plain' => 'correct',
        ], function (?array $row, string $plain) use (&$calls): string {
            $calls++;

            return 'ok';
        }, false);

        $this->assertSame('deny', $result['outcome']);
        $this->assertSame('throttled', $result['phase']);
        $this->assertSame('none', $result['bucket']);
        $this->assertSame(0, $calls, 'a throttled submission never evaluates the password');
    }

    /**
     * REC-02 in all the buckets at once. The console acts on the pipeline's
     * answer, so this models its two steps: decide, then act on the decision.
     * A rejected form token must leave the local counter, the account counter
     * and the ban bucket exactly as they were.
     */
    #[Test]
    public function aRejectedTokenBurnsNeitherTheFallbackCounterNorTheBanBucket(): void
    {
        $path = $this->counterPath();
        $this->recordFailures($path, 'admin', '203.0.113.7', 2);

        $installRoot = $this->tempDir();
        mkdir($installRoot . '/tmp', 0777, true);
        $banFile = recovery_policy_ip_log_path($installRoot);
        file_put_contents($banFile, "203.0.113.7;2;" . (self::NOW + 3600) . "\n");
        $banBefore = md5_file($banFile);

        $before = recovery_policy_fallback_state($path);
        $calls = 0;

        $result = recovery_policy_evaluate([
            'token_valid' => false,
            'row' => ['nick' => 'admin', 'password' => 'bcrypt', 'admin' => 1],
            'plain' => 'correct',
        ], function (?array $row, string $plain) use (&$calls): string {
            $calls++;

            return 'ok';
        }, false);

        // Exactly what the console is allowed to do with the answer.
        if ($result['record_failure']) {
            recovery_policy_fallback_record($path, 'admin', '203.0.113.7', self::NOW);
        }

        $this->assertSame('token_rejected', $result['phase']);
        $this->assertSame(0, $calls);
        $this->assertSame($before, recovery_policy_fallback_state($path), 'no attempt was recorded');
        clearstatcache(true, $banFile);
        $this->assertSame($banBefore, md5_file($banFile), 'the ban bucket is never written by this endpoint');
    }

    /**
     * REC-04, the concurrency half: a budget that is read now and charged later
     * is not a budget.
     *
     * `count()` consulting and `record()` incrementing are two separate
     * `flock()`s, so N simultaneous submissions for one nick all read a count
     * below the threshold, all reach `password_verify()`, and the ceiling
     * becomes a function of how many requests the attacker can open. The
     * reservation is the same decision and the same increment under one lock.
     */
    #[Test]
    public function aReservationChargesBothDimensionsInOneStep(): void
    {
        $path = $this->counterPath();

        $first = recovery_policy_fallback_reserve($path, 'admin', '203.0.113.7', self::NOW);

        $this->assertTrue($first['reserved']);
        $this->assertFalse($first['throttled']);
        $this->assertSame(1, $first['attempts']);
        $this->assertSame(1, $first['account_attempts']);

        $second = recovery_policy_fallback_reserve($path, 'admin', '198.51.100.9', self::NOW);
        $this->assertTrue($second['reserved'], 'a second address is still inside the per-address budget');
        $this->assertSame(1, $second['attempts'], 'its own pair dimension');
        $this->assertSame(2, $second['account_attempts'], 'but the account dimension is shared');
    }

    /**
     * The interleaved double the review describes, without needing threads.
     *
     * Both requests arrive when one slot is left. Under check-then-record both
     * would read five and both would proceed, taking the count to seven. Under
     * the reservation the first one's increment is already on disk when the
     * second one looks, so the second is refused and the count stops at six.
     */
    #[Test]
    public function anInterleavedDoubleCannotTakeTheLastSlotTwice(): void
    {
        $path = $this->counterPath();
        $this->recordFailures($path, 'admin', '203.0.113.7', RECOVERY_POLICY_MAX_ATTEMPTS - 1);

        $first = recovery_policy_fallback_reserve($path, 'admin', '203.0.113.7', self::NOW);
        $second = recovery_policy_fallback_reserve($path, 'admin', '203.0.113.7', self::NOW);

        $this->assertTrue($first['reserved'], 'the last slot is available to whoever takes it first');
        $this->assertFalse($first['throttled']);

        $this->assertFalse($second['reserved'], 'the second request must find the slot already taken');
        $this->assertTrue($second['throttled']);

        $this->assertSame(
            RECOVERY_POLICY_MAX_ATTEMPTS,
            recovery_policy_fallback_count($path, 'admin', '203.0.113.7', self::NOW)['attempts'],
            'the budget is never exceeded, only consumed'
        );
    }

    /**
     * A refusal costs the operator nothing, so it must not touch the file: an
     * endless stream of refused submissions must not rewrite the counter into a
     * denial of service for everyone else on the host.
     */
    #[Test]
    public function aRefusedReservationLeavesTheCounterFileUntouched(): void
    {
        $path = $this->counterPath();
        $this->recordFailures($path, 'admin', '203.0.113.7', RECOVERY_POLICY_MAX_ATTEMPTS);
        $before = (string) md5_file($path);

        $refused = recovery_policy_fallback_reserve($path, 'admin', '203.0.113.7', self::NOW);

        $this->assertFalse($refused['reserved']);
        $this->assertTrue($refused['throttled']);
        clearstatcache(true, $path);
        $this->assertSame($before, md5_file($path));
    }

    /**
     * The order the review asks for, step by step, on the submission the
     * budget exists to stop: the charge is already visible to the database of
     * record when the credential is evaluated.
     */
    #[Test]
    public function theReservationIsOnDiskBeforeTheCredentialIsEvaluated(): void
    {
        $path = $this->counterPath();
        $order = [];
        $chargedAtVerdict = null;

        $result = $this->evaluateWithBudget(
            $path,
            ['row' => null, 'plain' => 'wrong-password'],
            function () use (&$order, &$chargedAtVerdict, $path): string {
                $order[] = 'verdict';
                $chargedAtVerdict = recovery_policy_fallback_count($path, 'admin', '203.0.113.7', self::NOW);

                return 'deny';
            },
            $order
        );

        $this->assertSame(['reserve', 'verdict'], $order, 'reserve, then evaluate - never the reverse');
        $this->assertSame('credential_failed', $result['phase']);
        $this->assertTrue($result['reserved']);
        $this->assertFalse($result['record_failure'], 'the reservation already counted this attempt');
        $this->assertFalse($result['clear']);
        $this->assertIsArray($chargedAtVerdict);
        $this->assertSame(1, $chargedAtVerdict['attempts'], 'the charge is visible during verification');
        $this->assertSame(1, $chargedAtVerdict['account_attempts']);
    }

    /**
     * REC-04's success-clear, applied to the reservation: a legitimate login
     * must not leave the attempt it charged behind, and the release happens in
     * the same order - after the verdict, as its consequence.
     */
    #[Test]
    public function aSuccessfulCredentialReleasesTheReservationItTook(): void
    {
        $path = $this->counterPath();
        $order = [];

        $result = $this->evaluateWithBudget(
            $path,
            ['row' => ['nick' => 'admin', 'password' => 'bcrypt', 'admin' => 1], 'plain' => 'correct'],
            function () use (&$order): string {
                $order[] = 'verdict';

                return 'ok';
            },
            $order
        );

        $this->assertSame(['reserve', 'verdict', 'release'], $order);
        $this->assertSame('credential_ok', $result['phase']);
        $this->assertSame('ok', $result['outcome']);
        $this->assertTrue($result['reserved'], 'the pipeline charged the budget for this submission');
        $this->assertTrue($result['clear'], 'and REC-04 still clears the bucket it was measured against');
        $this->assertFalse($result['record_failure']);

        $after = recovery_policy_fallback_count($path, 'admin', '203.0.113.7', self::NOW);
        $this->assertSame(0, $after['attempts'], 'a successful login leaves no charge behind');
        $this->assertSame(0, $after['account_attempts']);
    }

    /**
     * The gates that close before the budget is ever consulted must not reserve:
     * a reservation is a write, and a rejected token, an invalid submission and
     * a banned address are all refusals that REC-02 says cost nothing.
     */
    #[Test]
    public function noReservationIsTakenWhenAGateClosesFirst(): void
    {
        $path = $this->counterPath();
        $cases = [
            'rejected token' => [
                ['token_valid' => false, 'ip_banned' => false],
                'token_rejected',
            ],
            'invalid input' => [
                ['token_valid' => true, 'input_valid' => false, 'ip_banned' => false],
                'input_rejected',
            ],
            'empty password' => [
                ['token_valid' => true, 'ip_banned' => false, 'plain' => ''],
                'input_rejected',
            ],
            'empty nick' => [
                ['token_valid' => true, 'ip_banned' => false, 'nick' => '', 'plain' => 'whatever'],
                'input_rejected',
            ],
            'banned address' => [
                ['token_valid' => true, 'ip_banned' => true],
                'banned',
            ],
        ];

        foreach ($cases as $label => [$facts, $expectedPhase]) {
            $order = [];
            $result = $this->evaluateWithBudget($path, $facts, static fn (): string => 'ok', $order);

            $this->assertSame([], $order, $label . ': no reservation and no evaluation');
            $this->assertSame($expectedPhase, $result['phase'], $label);
            $this->assertFalse($result['record_failure'], $label . ': nothing to record');
            $this->assertFalse($result['reserved'], $label);
            $this->assertSame('none', $result['bucket'], $label);
        }

        $this->assertFileDoesNotExist($path, 'a gate that closed before the budget never writes the counter');
    }

    /**
     * A refused reservation is the throttled phase, and it must be refused
     * before `password_verify()` rather than after - the whole point of a
     * budget is to not spend the expensive step.
     */
    #[Test]
    public function aRefusedReservationRefusesTheSubmissionWithoutEvaluating(): void
    {
        $path = $this->counterPath();
        $this->recordFailures($path, 'admin', '203.0.113.7', RECOVERY_POLICY_MAX_ATTEMPTS);
        $before = (string) md5_file($path);
        $order = [];

        $result = $this->evaluateWithBudget(
            $path,
            ['row' => ['nick' => 'admin', 'password' => 'bcrypt', 'admin' => 1], 'plain' => 'correct'],
            function () use (&$order): string {
                $order[] = 'verdict';

                return 'ok';
            },
            $order
        );

        $this->assertSame(['reserve'], $order, 'the verdict is never reached');
        $this->assertSame('deny', $result['outcome']);
        $this->assertSame('throttled', $result['phase']);
        $this->assertFalse($result['reserved']);
        $this->assertFalse($result['record_failure']);
        clearstatcache(true, $path);
        $this->assertSame($before, md5_file($path));
    }

    /**
     * The same race across real processes.
     *
     * Ten separate PHP processes reserve one slot each against one counter file
     * at the same time. `flock()` serialises them, so exactly the six slots the
     * budget declares are handed out, no more, and each reservation observes a
     * distinct count - a reservation that lost the lock cannot also report that
     * it was the first. Without the atomic primitive the shape is "read, then
     * later write", and all ten would have read a count below the threshold.
     *
     * This proves the invariant, not a detector: a run in which the kernel
     * happens to serialise the children perfectly passes too. What it forbids is
     * any interleaving that exceeds the budget.
     */
    #[Test]
    public function theBudgetCannotBeExceededByConcurrentReservations(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled, so no concurrent reservation can be driven from here.');
        }

        $path = $this->counterPath();
        $attempts = [];
        $reserved = 0;

        foreach ($this->startConcurrentReservations($path, 10) as $child) {
            $this->assertSame(0, $child['exit'], 'a reservation child must not fail: ' . $child['stderr']);
            $answer = json_decode($child['stdout'], true);
            $this->assertIsArray($answer, 'unreadable answer: ' . $child['stdout'] . $child['stderr']);

            if ($answer['reserved'] === true) {
                $reserved++;
                $attempts[] = $answer['attempts'];
            }
        }

        $this->assertSame(
            RECOVERY_POLICY_MAX_ATTEMPTS,
            $reserved,
            'ten simultaneous submissions may only consume the six slots the budget declares'
        );
        $this->assertSame(4, 10 - $reserved, 'the rest are refused');

        sort($attempts);
        $this->assertSame(
            range(1, RECOVERY_POLICY_MAX_ATTEMPTS),
            $attempts,
            'each reserved slot saw a distinct count, so every increment happened under the lock'
        );

        $final = recovery_policy_fallback_count($path, 'admin', '203.0.113.7', self::NOW);
        $this->assertSame(RECOVERY_POLICY_MAX_ATTEMPTS, $final['attempts']);
        $this->assertTrue($final['throttled']);
    }

    /**
     * Drive the pipeline exactly as W7 will: the fallback budget as the
     * `reserve`/`release` seam, one nick and one address, one injected clock.
     *
     * @param array<string, mixed> $facts
     * @param list<string> $order
     *
     * @return array<string, mixed>
     */
    private function evaluateWithBudget(string $path, array $facts, callable $verdict, array &$order): array
    {
        $facts = array_merge([
            'token_valid' => true,
            'ip_banned' => false,
            'nick' => 'admin',
            'ip' => '203.0.113.7',
            'now' => self::NOW,
            'row' => null,
            'plain' => 'wrong',
        ], $facts);

        return recovery_policy_evaluate(
            $facts,
            $verdict,
            false,
            function (string $nick, string $ip, int $now) use (&$order, $path): array {
                $order[] = 'reserve';

                return recovery_policy_fallback_reserve($path, $nick, $ip, $now);
            },
            function (string $nick, string $ip) use (&$order, $path): bool {
                $order[] = 'release';

                return recovery_policy_fallback_clear($path, $nick, $ip);
            }
        );
    }

    /**
     * Start `$count` child processes that each take one reservation, then read
     * every answer. The children are started before any is awaited, which is as
     * close to "at the same time" as a test can get without threads.
     *
     * @return list<array{stdout: string, stderr: string, exit: int}>
     */
    private function startConcurrentReservations(string $path, int $count): array
    {
        $script = rtrim(sys_get_temp_dir(), '/') . '/su_reserve_probe_' . bin2hex(random_bytes(6)) . '.php';
        $source = "<?php\ndeclare(strict_types=1);\n"
            . 'require_once ' . var_export(dirname(__DIR__) . '/lib/recovery_policy.php', true) . ";\n"
            . 'echo json_encode(recovery_policy_fallback_reserve('
            . '$argv[1], "admin", "203.0.113.7", ' . self::NOW . "));\n";
        file_put_contents($script, $source);

        $children = [];

        try {
            for ($i = 0; $i < $count; $i++) {
                $process = proc_open(
                    [PHP_BINARY, $script, $path],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes
                );

                $this->assertTrue(is_resource($process), 'could not start a reservation child');
                $children[] = ['process' => $process, 'pipes' => $pipes];
            }

            $answers = [];

            foreach ($children as $child) {
                $stdout = (string) stream_get_contents($child['pipes'][1]);
                $stderr = (string) stream_get_contents($child['pipes'][2]);
                fclose($child['pipes'][1]);
                fclose($child['pipes'][2]);

                $answers[] = [
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                    'exit' => proc_close($child['process']),
                ];
            }

            return $answers;
        } finally {
            @unlink($script);
        }
    }
}
