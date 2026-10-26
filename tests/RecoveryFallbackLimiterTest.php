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

    #[Test]
    public function theCounterFileBelongsToTheInstallationNotTheWholeHost(): void
    {
        $first = recovery_policy_fallback_path('/tmp/install-a');
        $second = recovery_policy_fallback_path('/tmp/install-b');

        $this->assertNotSame($first, $second, 'two installations sharing /tmp must not share a budget');
        $this->assertStringStartsWith(rtrim(sys_get_temp_dir(), '/') . '/', $first);
        $this->assertStringContainsString(FS_TMP_NAME, $first, 'the suite namespaces its own tmp prefix');
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
}
