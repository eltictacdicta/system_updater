<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/session_auth.php';

/**
 * The guard's database backend contract.
 *
 * `system_updater_resolve_admin_verdict()` reads `fs_users` through `mysqli`.
 * That is the only client the plugin ships: `recovery.php` and the PHP-native
 * backup path in `lib/backup_manager.php` connect the same way. The PostgreSQL
 * branches of `backup_manager` shell out to `pg_dump`/`psql`; they never give
 * PHP a PostgreSQL reader.
 *
 * So on a PostgreSQL install the guard cannot verify anybody. Before this
 * contract it failed *silently*: `null` from the resolver collapsed into the
 * uniform "unauthenticated" denial and an operator saw every administrator
 * refused with no cause named anywhere. Fail-closed is correct; mute is not.
 *
 * These cases pin the loud half without weakening the closed half:
 *  - the backend is classified before any connection is attempted;
 *  - a non-MySQL backend is refused, never accepted;
 *  - the refusal is recorded once per process, naming the backend and the
 *    limitation, where an operator reads errors;
 *  - the decision the *caller* receives stays byte-identical to the anonymous
 *    denial, because AUTH-04 forbids disclosing the cause in the response.
 */
final class AdminBackendSupportTest extends TestCase
{
    private string $errorLogFile = '';

    /** @var string|false */
    private $previousErrorLog = false;

    protected function setUp(): void
    {
        parent::setUp();

        system_updater_reset_admin_read_diagnostics();

        $logFile = tempnam(sys_get_temp_dir(), 'su_admin_guard_log_');
        $this->assertIsString($logFile, 'premise: a writable error_log target');
        $this->errorLogFile = $logFile;

        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->errorLogFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', is_string($this->previousErrorLog) ? $this->previousErrorLog : '');

        if ($this->errorLogFile !== '' && file_exists($this->errorLogFile)) {
            @unlink($this->errorLogFile);
        }

        system_updater_reset_admin_read_diagnostics();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function supportedBackendProvider(): array
    {
        return [
            'mysql upper case' => ['MYSQL', 'MYSQL'],
            'mysql lower case' => ['mysql', 'MYSQL'],
            'mysql with padding' => ['  MySql  ', 'MYSQL'],
            'mariadb is a mysql protocol' => ['MARIADB', 'MYSQL'],
            'empty falls back to the mysql default' => ['', 'MYSQL'],
        ];
    }

    #[DataProvider('supportedBackendProvider')]
    public function testMysqlFamilyBackendsAreSupported(string $dbType, string $expectedBackend): void
    {
        $support = system_updater_admin_backend_support($dbType);

        $this->assertTrue($support['supported'], $dbType . ' must be a supported backend');
        $this->assertSame($expectedBackend, $support['backend']);
        $this->assertSame('', $support['diagnostic'], 'a supported backend has no diagnostic');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsupportedBackendProvider(): array
    {
        return [
            'postgresql canonical' => ['POSTGRESQL', 'POSTGRESQL'],
            'postgresql lower case' => ['postgresql', 'POSTGRESQL'],
            'postgres alias' => ['POSTGRES', 'POSTGRESQL'],
            'pgsql alias' => ['PGSQL', 'POSTGRESQL'],
            'sqlite' => ['SQLITE', 'SQLITE'],
            'oracle' => ['oracle', 'ORACLE'],
        ];
    }

    #[DataProvider('unsupportedBackendProvider')]
    public function testNonMysqlBackendsAreUnsupportedAndNamed(string $dbType, string $expectedBackend): void
    {
        $support = system_updater_admin_backend_support($dbType);

        $this->assertFalse($support['supported'], $dbType . ' must never be reported as supported');
        $this->assertSame($expectedBackend, $support['backend'], 'the operator needs the exact backend name');
        $this->assertSame(
            SYSTEM_UPDATER_GUARD_DIAGNOSTIC_BACKEND_UNSUPPORTED,
            $support['diagnostic'],
            'an unsupported backend must carry its own diagnostic, not an empty one'
        );
    }

    public function testTheBackendDefaultsToTheConfiguredDatabaseType(): void
    {
        $support = system_updater_admin_backend_support();

        $this->assertSame(
            system_updater_normalize_database_backend(defined('FS_DB_TYPE') ? (string) FS_DB_TYPE : 'MYSQL'),
            $support['backend'],
            'no argument means "whatever this installation is configured with"'
        );
    }

    public function testTheDiagnosticNamesTheBackendAndTheLimitation(): void
    {
        $message = system_updater_admin_read_diagnostic_message(
            SYSTEM_UPDATER_GUARD_DIAGNOSTIC_BACKEND_UNSUPPORTED,
            'POSTGRESQL'
        );

        $this->assertStringContainsString('system_updater', $message, 'the line must name its origin');
        $this->assertStringContainsString('POSTGRESQL', $message, 'the operator must know which backend');
        $this->assertStringContainsString('mysqli', $message, 'the operator must know what is missing');
        $this->assertStringNotContainsString(
            SYSTEM_UPDATER_GUARD_REASON_UNAUTHENTICATED,
            $message,
            'the diagnostic must not reuse the uniform reason: that is the bug this contract closes'
        );
    }

    /**
     * The load-bearing case: a PostgreSQL install loses every administrator,
     * and now says so instead of pretending nobody is logged in.
     */
    public function testResolverRefusesAndLogsOnceOnAnUnsupportedBackend(): void
    {
        $support = system_updater_admin_backend_support('POSTGRESQL');
        $this->assertFalse($support['supported'], 'premise: PostgreSQL is not readable by this guard');

        $first = system_updater_resolve_admin_verdict('admin', 'POSTGRESQL');
        $second = system_updater_resolve_admin_verdict('admin', 'POSTGRESQL');

        $this->assertNull($first, 'an unreadable backend is "no verdict", never an accept');
        $this->assertNull($second, 'repeat polls stay closed');
        $this->assertNotTrue($first, 'fail-closed: the resolver must never answer true here');

        $lines = $this->diagnosticLines();
        $this->assertCount(1, $lines, 'one line per process, not one per poll: ' . implode("\n", $lines));
        $this->assertStringContainsString('POSTGRESQL', $lines[0]);
        $this->assertStringContainsString('mysqli', $lines[0]);
    }

    public function testASupportedBackendLogsNoBackendDiagnostic(): void
    {
        $support = system_updater_admin_backend_support('MYSQL');
        $this->assertTrue($support['supported'], 'premise: MySQL is the supported backend');

        $this->assertSame([], $this->diagnosticLines(), 'a healthy install must stay quiet');
    }

    /**
     * AUTH-04 holds: the caller cannot tell a backend outage from anonymity.
     * The operator-only signal must not leak into the decision.
     */
    public function testTheCallersDenialStaysUniformWhenTheBackendIsUnreadable(): void
    {
        $anonymous = system_updater_guard_decision([], [], static fn (string $nick): ?bool => null);

        $unreadableBackend = system_updater_guard_decision(
            ['FSSESS_test' => [
                'user_nick' => 'admin',
                'login_time' => time(),
                'last_activity' => time(),
            ]],
            [],
            static fn (string $nick): ?bool => system_updater_resolve_admin_verdict($nick, 'POSTGRESQL')
        );

        $this->assertSame(
            json_encode($anonymous),
            json_encode($unreadableBackend),
            'the refusal must be byte-identical to the anonymous one'
        );
        $this->assertSame(SYSTEM_UPDATER_GUARD_REASON_UNAUTHENTICATED, $unreadableBackend['reason']);
    }

    /**
     * The backend must be classified *before* a connection is opened. Asserted
     * on the body because the suite has no PostgreSQL server to fail against;
     * the alternative would be a test that depends on a socket error.
     */
    public function testTheResolverClassifiesTheBackendBeforeConnecting(): void
    {
        $body = $this->functionBody($this->guardSource(), 'system_updater_resolve_admin_verdict');

        $this->assertNotSame('', $body, 'premise: the resolver exists');

        $classifyAt = strpos($body, 'system_updater_admin_backend_support(');
        $connectAt = strpos($body, 'new \\mysqli(');

        $this->assertNotFalse($classifyAt, 'the resolver must consult the backend support contract');
        $this->assertNotFalse($connectAt, 'premise: the resolver still reads the row through mysqli');
        $this->assertLessThan(
            $connectAt,
            $classifyAt,
            'the backend must be classified before any connection attempt'
        );

        $availabilityAt = strpos($body, 'system_updater_admin_read_availability(');
        $this->assertNotFalse(
            $availabilityAt,
            'the reader environment must be checked, not discovered by a failed connect'
        );
        $this->assertLessThan(
            $connectAt,
            $availabilityAt,
            'a missing extension or missing constants must be diagnosed before a connection is attempted'
        );
    }

    // ============================================================
    // The two silent denies: no mysqli, no connection constants
    // ============================================================

    /**
     * A MySQL install whose PHP has no `mysqli` extension.
     *
     * Same failure class as an unsupported backend, and it used to be just as
     * mute: `null` from the resolver collapsed into the anonymous denial and
     * nothing anywhere said why every administrator was being refused.
     */
    public function testAMissingMysqliExtensionIsReportedAndRefused(): void
    {
        $constants = ['FS_DB_HOST', 'FS_DB_USER', 'FS_DB_NAME'];

        $availability = system_updater_admin_read_availability(false, $constants);

        $this->assertFalse($availability['available'], 'no client means no verdict');
        $this->assertSame(SYSTEM_UPDATER_GUARD_DIAGNOSTIC_MYSQLI_MISSING, $availability['diagnostic']);
        $this->assertSame(['mysqli'], $availability['missing'], 'the operator needs to know what to install');

        $verdict = system_updater_resolve_admin_verdict('admin', 'MYSQL', false, $constants);

        $this->assertNull($verdict, 'fail closed: never an accept');
        $this->assertNotTrue($verdict, 'fail closed: never a truthy answer');

        $lines = $this->diagnosticLines();
        $this->assertCount(1, $lines, 'one line, once per process');
        $this->assertStringContainsString('mysqli', $lines[0]);
        $this->assertStringNotContainsString(
            SYSTEM_UPDATER_GUARD_REASON_UNAUTHENTICATED,
            $lines[0],
            'the operator line must not be the caller-facing reason'
        );
    }

    /**
     * A `config.php` that never defines the connection constants — a damaged
     * or half-restored installation is the realistic case.
     */
    public function testAMissingConnectionConstantIsReportedAndRefused(): void
    {
        $availability = system_updater_admin_read_availability(true, ['FS_DB_HOST']);

        $this->assertFalse($availability['available']);
        $this->assertSame(SYSTEM_UPDATER_GUARD_DIAGNOSTIC_DB_CONFIG_MISSING, $availability['diagnostic']);
        $this->assertSame(
            ['FS_DB_USER', 'FS_DB_NAME'],
            $availability['missing'],
            'the diagnostic must name exactly the constants that are missing, in a stable order'
        );

        $verdict = system_updater_resolve_admin_verdict('admin', 'MYSQL', true, ['FS_DB_HOST']);

        $this->assertNull($verdict);

        $lines = $this->diagnosticLines();
        $this->assertCount(1, $lines, 'one line, once per process');
        $this->assertStringContainsString('FS_DB_USER', $lines[0]);
        $this->assertStringContainsString('FS_DB_NAME', $lines[0]);
        $this->assertStringNotContainsString('FS_DB_HOST', $lines[0], 'name what is missing, not what is present');
    }

    /**
     * `status` and `progress` poll every couple of seconds, so each distinct
     * cause gets exactly one line — two causes, two lines, not four.
     */
    public function testEachUnreadableEnvironmentCauseIsReportedOncePerProcess(): void
    {
        $constants = ['FS_DB_HOST', 'FS_DB_USER', 'FS_DB_NAME'];

        for ($poll = 0; $poll < 3; $poll++) {
            system_updater_resolve_admin_verdict('admin', 'MYSQL', false, $constants);
            system_updater_resolve_admin_verdict('admin', 'MYSQL', true, ['FS_DB_HOST']);
        }

        $lines = $this->diagnosticLines();

        $this->assertCount(2, $lines, 'one line per distinct cause: ' . implode("\n", $lines));
        $this->assertCount(1, array_filter($lines, static fn (string $line): bool => str_contains($line, 'mysqli')));
        $this->assertCount(1, array_filter($lines, static fn (string $line): bool => str_contains($line, 'FS_DB_USER')));
    }

    public function testAHealthyEnvironmentIsNeitherRefusedNorReported(): void
    {
        $availability = system_updater_admin_read_availability(
            true,
            ['FS_DB_HOST', 'FS_DB_USER', 'FS_DB_NAME']
        );

        $this->assertTrue($availability['available'], 'premise: a complete environment is readable');
        $this->assertSame('', $availability['diagnostic']);
        $this->assertSame([], $availability['missing']);
        $this->assertSame([], $this->diagnosticLines(), 'a healthy install stays quiet');
    }

    public function testTheEnvironmentDiagnosticsNameWhatIsMissing(): void
    {
        $extension = system_updater_admin_read_diagnostic_message(
            SYSTEM_UPDATER_GUARD_DIAGNOSTIC_MYSQLI_MISSING,
            'MYSQL'
        );

        $this->assertStringContainsString('system_updater', $extension);
        $this->assertStringContainsString('mysqli', $extension);
        $this->assertStringNotContainsString(SYSTEM_UPDATER_GUARD_REASON_UNAUTHENTICATED, $extension);

        $configuration = system_updater_admin_read_diagnostic_message(
            SYSTEM_UPDATER_GUARD_DIAGNOSTIC_DB_CONFIG_MISSING,
            'MYSQL',
            ['FS_DB_USER', 'FS_DB_NAME']
        );

        $this->assertStringContainsString('system_updater', $configuration);
        $this->assertStringContainsString('FS_DB_USER', $configuration);
        $this->assertStringContainsString('FS_DB_NAME', $configuration);
        $this->assertStringNotContainsString(SYSTEM_UPDATER_GUARD_REASON_UNAUTHENTICATED, $configuration);
    }

    /**
     * AUTH-04 holds here too: an unreadable environment must be indistinguish-
     * able from anonymity for the caller. Only the operator sees the cause.
     */
    public function testTheCallersDenialStaysUniformWhenTheEnvironmentIsUnusable(): void
    {
        $anonymous = system_updater_guard_decision([], [], static fn (string $nick): ?bool => null);

        $unusable = system_updater_guard_decision(
            ['FSSESS_test' => [
                'user_nick' => 'admin',
                'login_time' => time(),
                'last_activity' => time(),
            ]],
            [],
            static function (string $nick): ?bool {
                return system_updater_resolve_admin_verdict('admin', 'MYSQL', false, ['FS_DB_HOST', 'FS_DB_USER', 'FS_DB_NAME']);
            }
        );

        $this->assertSame(
            json_encode($anonymous),
            json_encode($unusable),
            'the refusal must be byte-identical to the anonymous one'
        );
    }

    /**
     * @return array<int, string>
     */
    private function diagnosticLines(): array
    {
        if (!is_file($this->errorLogFile)) {
            return [];
        }

        $lines = file($this->errorLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return is_array($lines) ? array_values($lines) : [];
    }

    private function guardSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/lib/session_auth.php');
    }

    /**
     * Body of a top-level function. The file closes every function with `}` in
     * the first column, so that brace is the boundary.
     */
    private function functionBody(string $source, string $function): string
    {
        $start = strpos($source, 'function ' . $function . '(');
        if ($start === false) {
            return '';
        }

        $end = strpos($source, "\n}", $start);
        if ($end === false) {
            return '';
        }

        return substr($source, $start, $end - $start);
    }
}
