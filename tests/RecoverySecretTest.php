<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;
use Tests\SystemUpdater\Support\PartialWriteStream;
use Tests\SystemUpdater\Support\RecoverySecretFixture;

require_once dirname(__DIR__) . '/lib/recovery_secret.php';
require_once __DIR__ . '/Support/PartialWriteStream.php';
require_once __DIR__ . '/Support/RecoverySecretFixture.php';

/**
 * Contract of the console's per-installation secret.
 *
 * Covers RACC-02 (the value exists, persists, is unique per installation, is
 * owner-only and is harmless to request directly), RACC-03 (the documented
 * resolution order, reachable without `config.php` and without loading a
 * class) and RACC-06 (the three lost-secret escapes: CLI read, CLI rotation and
 * the operator pin, plus the one-time generation line in the PHP error log).
 *
 * The suite has no database and never touches the real installation: every
 * case builds a temp install root through {@see RecoverySecretFixture} and
 * asserts against that tree.
 */
final class RecoverySecretTest extends TestCase
{
    private const RECOVERY_SCRIPT = '/recovery.php';

    /** @var RecoverySecretFixture[] */
    private array $fixtures = [];

    private string|false $previousErrorLog = false;

    private string $errorLogFile = '';

    /**
     * A first generation writes the value to the PHP error log on purpose
     * (RACC-06). In the PHPUnit process that sink is stderr, which would print
     * a throwaway secret per fixture into the suite output; it is redirected to
     * a per-test file and restored afterwards. The tests that assert the log
     * still read a real file — this is hygiene, not a stub.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousErrorLog = ini_get('error_log');
        $this->errorLogFile = rtrim(sys_get_temp_dir(), '/') . '/su_recsec_log_' . bin2hex(random_bytes(6));
        ini_set('error_log', $this->errorLogFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousErrorLog);
        @unlink($this->errorLogFile);

        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }
        $this->fixtures = [];

        parent::tearDown();
    }

    /** Everything `error_log()` received from this PHPUnit process. */
    private function processErrorLog(): string
    {
        clearstatcache(true, $this->errorLogFile);

        return is_file($this->errorLogFile) ? (string) file_get_contents($this->errorLogFile) : '';
    }

    private function newFixture(): RecoverySecretFixture    {
        $fixture = new RecoverySecretFixture();
        $this->fixtures[] = $fixture;

        return $fixture;
    }

    /**
     * RACC-02's loud-error state, only where the host can actually produce it.
     *
     * `chmod 0555` is enforced by the kernel's permission check, and a
     * privileged process (root — what a good many CI containers give PHPUnit)
     * skips that check: the directory stays writable, a secret gets generated
     * and the cases below would fail for a reason that has nothing to do with
     * the library. The precondition is therefore *observed* instead of assumed
     * — the modes are applied and then checked. When the observation says the
     * state was not reached, the case is skipped, visibly, rather than passing
     * without exercising anything; on a host where `0555` means something it
     * runs exactly as before.
     */
    private function withoutWritableSecretLocations(RecoverySecretFixture $fixture): void
    {
        if ($fixture->makeSecretLocationsUnwritable()) {
            return;
        }

        $this->markTestSkipped(
            'cannot make the secret locations unwritable for uid '
            . (function_exists('posix_geteuid') ? (string) posix_geteuid() : 'unknown')
            . ': chmod 0555 does not restrict a privileged process, so RACC-02\'s'
            . ' loud-error branch is unreachable from this host'
        );
    }

    /**
     * RACC-02: the value is `random_bytes(32)` rendered as hex, so it has a
     * fixed, checkable shape — 64 characters, all of them hex digits.
     */
    public function testTheGeneratedSecretIsSixtyFourHexCharacters(): void
    {
        $generated = recovery_secret_generate();

        $this->assertSame(64, strlen($generated), 'the secret is random_bytes(32) in hex');
        $this->assertTrue(ctype_xdigit($generated), 'the secret is hex, not raw bytes');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $generated);

        $fixture = $this->newFixture();
        $promoted = recovery_secret_ensure($fixture->root);

        $this->assertNotNull($promoted, 'a writable install root always yields a secret');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            (string) $promoted,
            'the value the console promotes keeps the generated shape'
        );
    }

    /**
     * RACC-02 "the secret survives a restart": a later resolution returns the
     * same value and does not rewrite the file. The mtime is pinned to an
     * obviously-old value first, so a rewrite cannot hide behind a same-second
     * timestamp.
     */
    public function testASecondResolutionReturnsTheSameValueAndDoesNotRewriteTheFile(): void
    {
        $fixture = $this->newFixture();

        $first = recovery_secret_ensure($fixture->root);
        $this->assertNotNull($first);

        $path = recovery_secret_path($fixture->root);
        $this->assertFileExists($path);

        $contents = (string) file_get_contents($path);
        touch($path, 1000000000);

        $second = recovery_secret_ensure($fixture->root);

        $this->assertSame($first, $second, 'a second resolution returns the stored value, not a new one');
        $this->assertSame($contents, (string) file_get_contents($path), 'the stored bytes are untouched');
        $this->assertSame(
            1000000000,
            $fixture->mtimeOf($path),
            'resolving an existing secret must not rewrite the file'
        );
    }

    /**
     * RACC-02 "the secret survives a restart" taken literally: a *different
     * process* resolves the same value. In-process resolution could pass while
     * the value only ever lived in a static, so the assertion runs in a fresh
     * PHP process against the same install root.
     */
    public function testAFreshProcessResolvesTheSameValue(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();

        $written = recovery_secret_ensure($fixture->root);
        $this->assertNotNull($written);

        $result = $fixture->runSnippet('echo recovery_secret_ensure(__DIR__);');

        $this->assertSame(0, $result['code'], 'resolving a persisted secret must succeed: ' . $result['stderr']);
        $this->assertSame($written, trim($result['stdout']), 'the value survives a process boundary');
    }

    /**
     * RACC-02: two installations never share a secret, and the value is not
     * derived from a shared input.
     *
     * The literal requirement is about derivation; what is observable here is
     * the falsifiable subset — two roots with different paths produce
     * different values, and neither value is the digest of its own install
     * path (nor contains one), which is the "guessable shared input" the
     * requirement forbids using as the sole seed.
     */
    public function testTwoInstallationsNeverShareASecretAndItIsNotADigestOfTheSharedInputs(): void
    {
        $first = $this->newFixture();
        $second = $this->newFixture();

        $firstValue = (string) recovery_secret_ensure($first->root);
        $secondValue = (string) recovery_secret_ensure($second->root);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $firstValue);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secondValue);
        $this->assertNotSame($firstValue, $secondValue, 'two installations must never share a secret');

        $sharedInputs = [$first->root, $second->root, $first->base, $second->base];
        foreach ($sharedInputs as $input) {
            foreach (['md5', 'sha1', 'sha256'] as $algorithm) {
                $digest = hash($algorithm, $input);
                $this->assertStringNotContainsString(
                    $digest,
                    $firstValue . $secondValue,
                    'the secret is not the ' . $algorithm . ' of an install path'
                );
                $this->assertStringNotContainsString(
                    substr($digest, 0, 16),
                    $firstValue . $secondValue,
                    'the secret does not embed a digest of an install path'
                );
            }
        }
    }

    /**
     * RACC-02: the persisted secret is readable and writable by the PHP user
     * alone. `0600` is the whole claim, so it is asserted on the real mode.
     */
    public function testThePersistedSecretIsReadableByItsOwnerAlone(): void
    {
        $fixture = $this->newFixture();

        $this->assertNotNull(recovery_secret_ensure($fixture->root));

        $path = recovery_secret_path($fixture->root);

        $this->assertSame(0600, $fixture->modeOf($path), 'the secret file must be 0600');
    }

    /**
     * RACC-02: outside the webroot when a suitable location is writable. The
     * global `0600` alone is not enough — a world-readable parent would still
     * leak the name and the bytes to a sibling tenant — so the location itself
     * is part of the contract, and the last-resort path is only taken when the
     * sibling is not writable (asserted separately).
     *
     * The location is the *per-installation* directory beside the installation
     * (C-1): a fixed file name in the parent made two installations that share
     * a parent resolve one file. The file name stays fixed, because that is what
     * an operator greps for.
     */
    public function testTheSecretIsStoredOutsideTheInstallRootWhenASiblingIsWritable(): void
    {
        $fixture = $this->newFixture();

        $this->assertNotNull(recovery_secret_ensure($fixture->root));

        $path = recovery_secret_path($fixture->root);
        $webroot = $fixture->root . '/';

        $this->assertStringStartsWith($fixture->parentDirectory() . '/', $path);
        $this->assertStringNotContainsString(
            $webroot,
            $path,
            'the secret must not live inside the webroot while a writable sibling exists'
        );
        $this->assertSame(RecoverySecretFixture::SECRET_FILENAME, basename($path));
        $this->assertSame(
            $fixture->siblingDirectory(),
            dirname($path),
            'the file sits in the directory derived from the installation, not directly in the shared parent'
        );
        $this->assertNotSame(
            $fixture->parentDirectory(),
            dirname($path),
            'a fixed file name directly in the parent is the shared-secret defect (C-1)'
        );
        $this->assertSame(0700, $fixture->modeOf(dirname($path)), 'the per-installation directory is owner-only');
    }

    /**
     * RACC-02 "fetching the secret over HTTP discloses nothing", narrowed to
     * what a DB-free suite can observe: the persisted file is a PHP file whose
     * *execution* emits no bytes at all, so a PHP-enabled server answers it
     * with an empty 404 body and never with the hex value or the source. (A
     * server that serves `.php` as a static file is out of this suite's reach;
     * it is documented in the library's docblock instead.)
     */
    public function testThePersistedFileDisclosesNothingWhenItIsExecutedDirectly(): void
    {
        $fixture = $this->newFixture();

        $value = (string) recovery_secret_ensure($fixture->root);
        $path = recovery_secret_path($fixture->root);

        $this->assertStringStartsWith(
            '<?php',
            (string) file_get_contents($path),
            'the persisted file must execute as PHP, not be served as static text'
        );

        $result = $fixture->runPhpFile($path);

        $this->assertSame('', trim($result['stdout']), 'a direct request must disclose no bytes');
        $this->assertStringNotContainsString($value, $result['stdout']);
        $this->assertStringNotContainsString($value, $result['stderr']);
    }

    // ---------------------------------------------------------------------
    // RACC-03 — resolution order, without core code
    // ---------------------------------------------------------------------

    /**
     * RACC-03 branch 2 is the one the gate takes on every ordinary request, so
     * it must answer with only this library loaded: no `config.php` execution
     * (the sentinel would exist), no `FSFramework` class, no autoloader.
     *
     * The snippet runs in a fresh process against an install root that has no
     * `vendor/`, no `base/` and no `src/` — the damaged-core installation
     * RACC-01 exists for.
     */
    public function testThePersistedValueResolvesWithoutLoadingConfigOrAClass(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();
        $fixture->writeSentinelConfig();

        $value = recovery_secret_generate();
        $this->assertTrue(recovery_secret_store($value, $fixture->siblingSecretPath()));

        $result = $fixture->runSnippet(<<<'PHP'
$classes = array_values(array_filter(
    get_declared_classes(),
    static fn(string $name): bool => str_starts_with($name, 'FSFramework\\')
));
echo json_encode([
    'value' => recovery_secret_resolve(__DIR__),
    'framework_classes' => $classes,
    'autoloaders' => count(spl_autoload_functions() ?: []),
], JSON_UNESCAPED_SLASHES);
PHP);

        $this->assertSame(0, $result['code'], 'resolving the persisted value must not fail: ' . $result['stderr']);
        $this->assertSame(
            ['value' => $value, 'framework_classes' => [], 'autoloaders' => 0],
            json_decode(trim($result['stdout']), true),
            'branch 2 resolves with no config.php, no core class and no autoloader'
        );
        $this->assertFalse(
            $fixture->configWasLoaded(),
            'taking the persisted branch must never load config.php'
        );
        $this->assertDirectoryDoesNotExist($fixture->root . '/vendor');
        $this->assertDirectoryDoesNotExist($fixture->root . '/base');
        $this->assertDirectoryDoesNotExist($fixture->root . '/src');
    }

    /**
     * RACC-03 "the gate answers before `config.php` is loaded": a `config.php`
     * that would fatal if executed cannot affect the persisted branch, and no
     * parse error escapes.
     */
    public function testAParseErrorInConfigCannotBreakThePersistedBranch(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();
        $fixture->writeConfig("<?php\nthis is not valid php\n");

        $value = recovery_secret_generate();
        $this->assertTrue(recovery_secret_store($value, $fixture->siblingSecretPath()));

        $result = $fixture->runSnippet('echo recovery_secret_resolve(__DIR__);');

        $this->assertSame(0, $result['code'], 'a damaged config.php is not on this path at all');
        $this->assertSame($value, trim($result['stdout']), 'nothing but the secret reaches stdout');
        $this->assertStringNotContainsString('Parse error', $result['stdout']);
        $this->assertStringNotContainsString('Parse error', $result['stderr']);
    }

    /**
     * RACC-03 "the operator pin wins". The pin is read by the caller (in the
     * console: the lost-secret fallback), so the snippet loads it explicitly
     * before resolving — exactly the order the gate must use. A defined pin
     * must win over a *different* persisted value, and must leave that file
     * alone: neither read into the answer nor rewritten.
     */
    public function testTheOperatorPinWinsAndThePersistedFileIsNeitherReadNorRewritten(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();

        $persisted = recovery_secret_generate();
        $this->assertTrue(recovery_secret_store($persisted, $fixture->siblingSecretPath()));
        $path = $fixture->siblingSecretPath();
        $contents = (string) file_get_contents($path);
        touch($path, 1000000000);

        // Deliberately not 64 hex: an operator may pin any value they choose.
        $pin = 'operator-pin-' . bin2hex(random_bytes(8));
        $fixture->writeSentinelConfig('define(' . var_export('SU_RECOVERY_SECRET', true) . ', ' . var_export($pin, true) . ");\n");

        $result = $fixture->runSnippet(<<<'PHP'
echo json_encode([
    'pin' => recovery_secret_operator_pin(__DIR__ . '/config.php'),
    'resolved' => recovery_secret_resolve(__DIR__),
]);
PHP);

        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertSame(
            ['pin' => $pin, 'resolved' => $pin],
            json_decode(trim($result['stdout']), true),
            'the pin outranks the persisted value'
        );
        $this->assertSame($contents, (string) file_get_contents($path), 'the persisted value is not rewritten');
        $this->assertSame(1000000000, $fixture->mtimeOf($path), 'the persisted value is not even touched');
    }

    /**
     * RACC-06 escape 3 must survive the state it is meant for: a `config.php`
     * that is damaged must yield "no pin", never a fatal error page on the
     * path that is supposed to rescue the installation.
     */
    public function testABrokenConfigYieldsNoPinInsteadOfAFatalError(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();
        $fixture->writeConfig("<?php\nfunction broken( { }\n");

        $result = $fixture->runSnippet(<<<'PHP'
echo json_encode(['pin' => recovery_secret_operator_pin(__DIR__ . '/config.php')]);
PHP);

        $this->assertSame(0, $result['code'], 'a parse error must not abort the reader: ' . $result['stderr']);
        $this->assertSame(['pin' => null], json_decode(trim($result['stdout']), true));
        $this->assertStringNotContainsString('Parse error', $result['stdout']);
        $this->assertStringNotContainsString('Parse error', $result['stderr']);
    }

    // ---------------------------------------------------------------------
    // RACC-06 — the lost-secret escapes
    // ---------------------------------------------------------------------

    /**
     * RACC-06 "recovering from the log": an operator with log access but no
     * shell has exactly one place to look, and it must hold the value exactly
     * once — not on every resolution (which would drown the log and disclose
     * the secret repeatedly), and never in a truncated form they cannot use.
     */
    public function testTheFirstGenerationIsLoggedExactlyOnce(): void
    {
        $fixture = $this->newFixture();

        $value = (string) recovery_secret_ensure($fixture->root);
        $log = $this->processErrorLog();

        $this->assertSame(1, substr_count($log, $value), 'the generated value is logged exactly once');
        $this->assertStringContainsString(
            '[system_updater] recovery console secret generated',
            $log,
            'the line carries the documented, greppable prefix'
        );

        recovery_secret_ensure($fixture->root);
        recovery_secret_ensure($fixture->root);

        $this->assertSame(1, substr_count($this->processErrorLog(), $value), 'resolving again must not log again');
    }

    /**
     * RACC-06 escape 1, and the part of it that survives the C-2 decision: the
     * read escape answers with no database and, when `config.php` is damaged or
     * absent, without it too.
     *
     * The original wording was "prints the current secret without loading
     * `config.php`". That promise cannot survive a pin that outranks the
     * persisted value: "is a pin defined?" is only answerable by executing the
     * file, which is why the gate does it on every evaluation. The escape
     * therefore consults it with the same suppression — that is half one, the
     * sentinel proving the file really ran — and still answers from the
     * persisted branch when the file is gone, which is half two and the half
     * `RACC-03` still requires of branch 2.
     */
    public function testCliReadConsultsConfigForThePinAndStillAnswersWithoutIt(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();
        $fixture->writeSentinelConfig();

        $value = recovery_secret_generate();
        $this->assertTrue(recovery_secret_store($value, $fixture->siblingSecretPath()));

        $result = $fixture->runConsole('--secret');

        $this->assertSame(0, $result['code'], 'the read escape must succeed: ' . $result['stderr']);
        $this->assertSame($value, trim($result['stdout']));
        $this->assertTrue(
            $fixture->configWasLoaded(),
            'the escape consults config.php so the pin cannot be silently outranked'
        );

        unlink($fixture->root . '/config.php');
        $this->assertFileDoesNotExist($fixture->root . '/config.php');

        $without = $fixture->runConsole('--secret');

        $this->assertSame(0, $without['code'], 'a missing config.php is not a failure for the escape: ' . $without['stderr']);
        $this->assertSame($value, trim($without['stdout']), 'branch 2 still needs no config.php');
    }

    /**
     * RACC-06 escapes 1 and 4 together: an installation with no secret yet
     * answers `--secret` by generating one, persisting it and writing the
     * single documented log line, so the operator can read it later from
     * either sink. The second run must be a pure read.
     */
    public function testCliReadGeneratesOnceAndLogsOnceOnFirstUse(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();
        $fixture->writeSentinelConfig();

        $first = $fixture->runConsole('--secret');

        $this->assertSame(0, $first['code'], 'first use must succeed: ' . $first['stderr']);
        $value = trim($first['stdout']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $value);
        $this->assertSame($value, recovery_secret_read($fixture->root), 'the printed value is the persisted one');
        $this->assertTrue(
            $fixture->configWasLoaded(),
            'the escape looks for the pin first, exactly as the gate does'
        );

        $second = $fixture->runConsole('--secret');

        $this->assertSame($value, trim($second['stdout']), 'a second read returns the same value');
        $log = $fixture->errorLogContents();
        $this->assertSame(1, substr_count($log, $value), 'the value reaches the log exactly once');
        $this->assertStringContainsString('[system_updater] recovery console secret generated', $log);
    }

    /**
     * RACC-06 escape 2: rotation prints and persists a new value and retires
     * the previous one.
     *
     * The full scenario ends with "a request carrying the previous secret is
     * refused with the 404 of RACC-04", which needs the gate (the next slice).
     * What is pinned here is the half rotation owns: the old value is no longer
     * the installation's secret, in this process and in a fresh one.
     */
    public function testCliRotatePrintsAndPersistsANewValueAndRetiresTheOld(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();
        $fixture->writeSentinelConfig();

        $previous = recovery_secret_generate();
        $this->assertTrue(recovery_secret_store($previous, $fixture->siblingSecretPath()));

        $result = $fixture->runConsole('--rotate-secret');

        $this->assertSame(0, $result['code'], 'rotation must succeed: ' . $result['stderr']);
        $rotated = trim(explode("\n", trim($result['stdout']))[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $rotated);
        $this->assertNotSame($previous, $rotated, 'rotation must produce a different value');
        $this->assertTrue(
            $fixture->configWasLoaded(),
            'rotation reads the pin too, so its advisory note can name the state it is in'
        );

        $this->assertSame($rotated, recovery_secret_read($fixture->root), 'the new value is persisted');
        $this->assertNotSame($previous, recovery_secret_read($fixture->root), 'the previous value is retired');
        $this->assertSame($rotated, trim($fixture->runConsole('--secret')['stdout']));

        $fresh = $fixture->runSnippet('echo recovery_secret_resolve(__DIR__);');
        $this->assertSame($rotated, trim($fresh['stdout']), 'a fresh process sees only the rotated value');

        $this->assertSame(1, substr_count($fixture->errorLogContents(), $rotated));
    }

    /**
     * RACC-03 "identical in every consumer — the gate, the marker's fingerprint
     * check and the CLI", measured at the CLI (N-1 of the re-verification).
     *
     * The defect this forbids is precise and was shipped: the read escape used to
     * resolve before `config.php` was ever executed, so in a pinned installation
     * it printed the **persisted** value — the one the gate answers `404` for —
     * while the pin was the credential the gate accepted. An operator acting on
     * that output was refused, which makes `RACC-06` escape 1 ("prints the
     * current secret") false in exactly the state the pin exists for.
     *
     * So the assertion is not "the output mentions the pin". It is the two
     * properties the operator depends on: the accepted value is what comes out,
     * and the refused value does not come out at all, so the two cannot be
     * confused. The gate verdicts are measured in a fresh process, so "the value
     * the CLI reports is one the gate accepts" is not taken on trust.
     */
    public function testCliReadUnderAnOperatorPinReportsTheValueTheGateAccepts(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();

        $pin = 'operator-pin-' . bin2hex(random_bytes(12));
        $persisted = recovery_secret_generate();

        $fixture->writeSentinelConfig("define('SU_RECOVERY_SECRET', " . var_export($pin, true) . ");\n");
        $this->assertTrue(recovery_secret_store($persisted, $fixture->siblingSecretPath()));

        $result = $fixture->runConsole('--secret');

        $this->assertSame(0, $result['code'], 'the read escape must succeed: ' . $result['stderr']);
        $this->assertSame(
            $pin,
            $this->firstLine($result['stdout']),
            'the first line is the credential the gate compares against, not the one it refuses'
        );
        $this->assertNotSame($persisted, $this->firstLine($result['stdout']));
        $this->assertStringNotContainsString(
            $persisted,
            $result['stdout'],
            'the refused value is never printed, so it cannot be mistaken for the accepted one'
        );
        $this->assertStringNotContainsString($persisted, $result['stderr']);
        $this->assertTrue($fixture->configWasLoaded(), 'the pin can only be honoured by executing config.php');

        $verdicts = $fixture->runSnippet(
            'echo json_encode(['
            . "'reported' => recovery_secret_gate(" . var_export($pin, true) . ', __DIR__),'
            . "'persisted' => recovery_secret_gate(" . var_export($persisted, true) . ', __DIR__),'
            . ']);'
        );

        $this->assertSame(0, $verdicts['code'], $verdicts['stderr']);
        $gate = json_decode(trim($verdicts['stdout']), true);

        $this->assertTrue($gate['reported']['granted'], 'the value the CLI reports opens the gate');
        $this->assertSame('operator_pin', $gate['reported']['source']);
        $this->assertFalse($gate['persisted']['granted'], 'and the value it withholds is the refused one');
        $this->assertSame('operator_pin', $gate['persisted']['source']);
    }

    /**
     * The other half of the same contract: a non-pinned installation is
     * unchanged, line for line.
     *
     * The advisory line is what tells an operator that a pin is in force, so it
     * must be conditional. If it leaked into the ordinary path, every script that
     * composes `php recovery.php --secret` into a URL would carry a stray `#`
     * line it never asked for — a regression dressed up as a fix.
     */
    public function testCliReadWithoutAPinPrintsThePersistedValueAndNothingElse(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();
        $fixture->writeSentinelConfig();

        $value = recovery_secret_generate();
        $this->assertTrue(recovery_secret_store($value, $fixture->siblingSecretPath()));

        $result = $fixture->runConsole('--secret');

        $this->assertSame(0, $result['code'], 'the read escape must succeed: ' . $result['stderr']);
        $this->assertSame($value . "\n", $result['stdout'], 'the non-pinned output is the persisted value alone');
        $this->assertSame('', $result['stderr']);
    }

    /**
     * RACC-06 escape 2 under a pin: rotation still does what it owns, and stops
     * implying something it cannot deliver.
     *
     * Rotation regenerates the persisted file — that half is unchanged and still
     * matters, because it retires the old persisted value and prepares the
     * installation for the day the pin is removed. What it cannot do while a pin
     * is defined is change what the gate accepts, so printing a fresh value with
     * only the old "when defined, overrides this value" note still leaves the
     * operator holding a credential that is refused. The note is therefore kept
     * verbatim (it is the documented contract) and the pinned state says the rest
     * out loud.
     */
    public function testCliRotateUnderAnOperatorPinSaysTheGateStillAnswersToThePin(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();

        $pin = 'operator-pin-' . bin2hex(random_bytes(12));
        $previous = recovery_secret_generate();

        $fixture->writeSentinelConfig("define('SU_RECOVERY_SECRET', " . var_export($pin, true) . ");\n");
        $this->assertTrue(recovery_secret_store($previous, $fixture->siblingSecretPath()));

        $result = $fixture->runConsole('--rotate-secret');

        $this->assertSame(0, $result['code'], 'rotation must still succeed under a pin: ' . $result['stderr']);

        $rotated = $this->firstLine($result['stdout']);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $rotated);
        $this->assertNotSame($pin, $rotated, 'rotation produces a persisted value, never the pin');
        $this->assertNotSame($previous, $rotated, 'and it still retires the previous persisted value');
        $this->assertSame($rotated, recovery_secret_read($fixture->root), 'the new persisted value is on disk');

        $this->assertStringContainsString(
            'SU_RECOVERY_SECRET in config.php, when defined, overrides this value.',
            $result['stdout'],
            'the pre-existing override note is the documented contract and is kept'
        );
        $this->assertStringContainsString(
            'the gate does not accept it while the pin is defined',
            $result['stdout'],
            'and the pinned state says out loud what rotation cannot change'
        );

        $verdicts = $fixture->runSnippet(
            'echo json_encode(['
            . "'pin' => recovery_secret_gate(" . var_export($pin, true) . ', __DIR__),'
            . "'rotated' => recovery_secret_gate(" . var_export($rotated, true) . ', __DIR__),'
            . ']);'
        );

        $gate = json_decode(trim($verdicts['stdout']), true);

        $this->assertTrue($gate['pin']['granted'], 'the pin is still what the gate accepts');
        $this->assertFalse($gate['rotated']['granted'], 'and the freshly printed value is still refused');
        $this->assertSame(
            $pin,
            $this->firstLine($fixture->runConsole('--secret')['stdout']),
            'a later read escape reports the pin, never the rotated value'
        );
    }

    /**
     * RACC-01's suppression, now that the CLI is a `config.php` consumer too: a
     * damaged file yields "no pin" and the persisted branch answers, with no
     * parse error on stdout, on stderr, or in the server log — the same four
     * properties the gate's read already carries.
     */
    public function testCliReadFallsBackSilentlyOnADamagedConfig(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();
        $fixture->writeConfig("<?php\nthis is not valid php\n");

        $value = recovery_secret_generate();
        $this->assertTrue(recovery_secret_store($value, $fixture->siblingSecretPath()));

        $result = $fixture->runConsole('--secret');

        $this->assertSame(0, $result['code'], 'a damaged config.php must not break the read escape: ' . $result['stderr']);
        $this->assertSame(
            $value . "\n",
            $result['stdout'],
            'with no pin visible, the persisted value is printed and nothing else'
        );
        $this->assertSame('', $result['stderr'], 'no parse error on stderr');

        foreach (['Parse error', 'syntax error', 'PHP '] as $noise) {
            $this->assertStringNotContainsString($noise, $result['stdout']);
            $this->assertStringNotContainsString($noise, $result['stderr']);
            $this->assertStringNotContainsString($noise, $fixture->errorLogContents(), 'nor in the server log');
        }
    }

    /** The first stdout line: the documented position of the secret. */
    private function firstLine(string $stdout): string
    {
        return trim(explode("\n", trim($stdout))[0]);
    }

    /**
     * RACC-06 "the CLI flags MUST be ignored for non-CLI SAPIs".
     *
     * `php-cgi` is not installed in this container and `php -S` belongs to the
     * next slice's harness, so the web half is pinned at its mechanism rather
     * than over HTTP: the console's only secret branch is guarded by
     * `PHP_SAPI === 'cli'`, it runs *before* the console's own `require_once` of
     * `config.php` — it reads that file for the pin, but only through the
     * library, never by reaching the console's normal path — nothing in
     * the library reads a request superglobal, and the flag parser accepts the
     * two documented tokens and nothing else. A query parameter therefore has
     * no path to the secret at all.
     */
    public function testTheCliEscapesAreUnreachableFromAWebRequest(): void
    {
        $plugin = dirname(__DIR__);
        $console = (string) file_get_contents($plugin . self::RECOVERY_SCRIPT);
        $library = (string) file_get_contents($plugin . '/lib/recovery_secret.php');

        $guard = strpos($console, "PHP_SAPI === 'cli'");
        $handler = strpos($console, 'recovery_secret_cli_run(');
        $config = strpos($console, "FS_FOLDER . '/config.php'");

        $this->assertNotFalse($guard, 'the CLI branches must be guarded by the SAPI');
        $this->assertNotFalse($handler, 'the console must wire the CLI escapes');
        $this->assertNotFalse($config, 'the console must still load config.php for its normal path');
        $this->assertSame(1, substr_count($console, 'recovery_secret_cli_run('), 'exactly one call site');
        $this->assertLessThan($handler, $guard, 'the guard must wrap the handler');
        $this->assertLessThan($config, $handler, 'the CLI escapes must run before the console loads config.php for its normal path');

        $this->assertDoesNotMatchRegularExpression(
            '/\$_(GET|POST|REQUEST|COOKIE|SERVER\[.argv)/',
            $library,
            'the secret library must never read the request'
        );
        // argv is the one superglobal the CLI parser may see, and it is read by
        // the console, under the SAPI guard — never by the library.
        $this->assertStringNotContainsString('filter_input', $library);

        $this->assertNull(recovery_secret_cli_command(['recovery.php']));
        $this->assertNull(recovery_secret_cli_command(['recovery.php', '--secret=abc']));
        $this->assertNull(recovery_secret_cli_command(['recovery.php', 'secret']));
        $this->assertNull(recovery_secret_cli_command(['recovery.php', '-s']));
        $this->assertNull(recovery_secret_cli_command(['recovery.php', '--secrets']));
        $this->assertNull(recovery_secret_cli_command(['recovery.php', '--help', '--secret']));
        $this->assertSame('read', recovery_secret_cli_command(['recovery.php', '--secret']));
        $this->assertSame('rotate', recovery_secret_cli_command(['recovery.php', '--rotate-secret']));
    }

    // ---------------------------------------------------------------------
    // RACC-02 — an unwritable location is a loud error, never a regeneration
    // ---------------------------------------------------------------------

    /**
     * RACC-02 "an unwritable secret location is a loud error".
     *
     * The trap this forbids is precise: generating a value per request looks
     * like the console works, while every request gets a different secret and
     * the installation is effectively locked out. So there is no value at all,
     * the failure names where it tried, and a second request produces the same
     * failure rather than a new attempt with a new value.
     */
    public function testAnUnwritableLocationIsALoudErrorAndNeverRegeneratesPerRequest(): void
    {
        $fixture = $this->newFixture();
        $this->withoutWritableSecretLocations($fixture);
        recovery_secret_last_error('');

        $first = recovery_secret_ensure($fixture->root);
        $error = recovery_secret_last_error();

        $this->assertNull($first, 'no writable location means no value, never a throwaway one');
        $this->assertStringContainsString('No writable location', $error);
        $this->assertStringContainsString(
            $fixture->siblingDirectory(),
            $error,
            'the error names the location it tried'
        );
        $this->assertStringContainsString('SU_RECOVERY_SECRET', $error, 'and names the escape that still works');
        $this->assertFileDoesNotExist($fixture->siblingSecretPath());
        $this->assertFileDoesNotExist($fixture->nestedSecretPath());
        $this->assertSame('', $this->processErrorLog(), 'a failed resolution logs no value');

        $second = recovery_secret_ensure($fixture->root);

        $this->assertNull($second);
        $this->assertSame($error, recovery_secret_last_error(), 'the second request repeats the error, not the attempt');
        $this->assertFileDoesNotExist($fixture->siblingSecretPath());
    }

    /**
     * The other half of the same requirement: the directory accepts a write but
     * the file cannot be put in place (a directory already sits on the path).
     * Nothing is handed back, and the diagnostic names the path — an operator
     * should not have to guess which write failed.
     */
    public function testAFailedWriteIsReportedAndLeavesNoValueBehind(): void
    {
        $fixture = $this->newFixture();
        mkdir($fixture->siblingSecretPath(), 0777, true);
        recovery_secret_last_error('');

        $this->assertNull(recovery_secret_ensure($fixture->root));

        $error = recovery_secret_last_error();

        $this->assertStringContainsString($fixture->siblingSecretPath(), $error, 'the error names the path');
        $this->assertStringContainsString('write', $error);
        $this->assertNull(recovery_secret_read($fixture->root), 'a failed write persists no secret');
        $this->assertSame('', $this->processErrorLog(), 'a failed write logs no value');
    }

    /**
     * The operator-visible surface of the state above is the CLI: exit
     * non-zero, no secret on stdout (a value that was not persisted must never
     * escape), the diagnostic on stderr.
     *
     * `config.php` is executed here too now, for the pin. This case is also the
     * one where that read must stay invisible: the installation and its parent
     * are `0555`, so the sentinel `config.php` itself cannot complete its write.
     * The suppression, not the file's success, is what keeps stdout empty and
     * stderr limited to the one diagnostic — a failure from the pin read leaking
     * into either stream would be indistinguishable from the secret failure and
     * would break the non-zero/no-secret contract.
     */
    public function testCliReportsTheUnwritableLocationOnStderrAndPrintsNoSecret(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();
        $fixture->writeSentinelConfig();
        $this->withoutWritableSecretLocations($fixture);

        $read = $fixture->runConsole('--secret');

        $this->assertSame(1, $read['code'], 'a failed resolution must be a non-zero exit');
        $this->assertSame('', trim($read['stdout']), 'the console never prints a secret it could not persist');
        $this->assertStringContainsString(
            $fixture->siblingDirectory(),
            $read['stderr'],
            'the diagnostic names the location it tried, not a generic excuse'
        );
        $this->assertStringContainsString('SU_RECOVERY_SECRET', $read['stderr'], 'and the escape that still works');
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $read['stderr']);

        $rotate = $fixture->runConsole('--rotate-secret');

        $this->assertSame(1, $rotate['code']);
        $this->assertSame('', trim($rotate['stdout']));
        $this->assertSame(
            $read['stderr'],
            $rotate['stderr'],
            'two consecutive requests produce the same error, so nothing is being regenerated'
        );
    }

    // ---------------------------------------------------------------------
    // RACC-02 — the install root nobody passed in
    // ---------------------------------------------------------------------

    /**
     * The other half of the CLI contract, at the CLI entry point itself: an
     * installation whose root cannot be verified has no candidate, so the
     * escape must exit non-zero and print no secret — never a value it could not
     * persist, and never one derived from a guessed path.
     *
     * This is also the case that proves the pin read added for N-1 did not widen
     * the surface: with no verified root there is no `config.php` path to
     * consult either, so the refusal is the same one the library documents and
     * the escape stays silent about why beyond the diagnostic.
     */
    public function testCliAtAnUnverifiableInstallationExitsNonZeroAndPrintsNoSecret(): void
    {
        $fixture = $this->newFixture();
        $library = $fixture->installLibraryAt('lib');

        $probe = $fixture->root . '/cli_probe.php';
        file_put_contents(
            $probe,
            "<?php\ndeclare(strict_types=1);\n"
            . 'require_once ' . var_export($library, true) . ";\n"
            . "exit(recovery_secret_cli_run('read'));\n"
        );

        $result = $fixture->runPhpFile($probe);

        $this->assertSame(1, $result['code'], 'no verified root is a non-zero exit, not a value');
        $this->assertSame('', $result['stdout'], 'and no secret reaches stdout');
        $this->assertStringContainsString('SU_RECOVERY_SECRET', $result['stderr'], 'the escape that still works is named');
        $this->assertStringContainsString('could not be verified', $result['stderr'], 'and the reason is the real one');
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $result['stdout'] . $result['stderr']);
    }

    /**
     * RACC-02's "outside the webroot" has to hold for the one caller that
     * passes no install root at all.
     *
     * The library lives at `<root>/plugins/<plugin>/lib`, so the installation
     * root is three levels up, not two. Two levels names `<root>/plugins`, and
     * `dirname()` of *that* is `<root>` — the webroot itself — so the candidate
     * that is supposed to sit beside the installation lands inside it and the
     * guarantee is silently gone. This is the branch the rest of the suite never
     * took, because `FS_FOLDER` is defined in the PHPUnit process and every
     * other case passes an explicit root; the snippet runs in a fresh process
     * where neither holds.
     */
    public function testTheFallbackInstallRootIsTheInstallationAndNotTheWebroot(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();

        $result = $fixture->runSnippet(<<<'PHP'
echo json_encode([
    'root' => recovery_secret_install_root(),
    'candidates' => recovery_secret_candidates(),
], JSON_UNESCAPED_SLASHES);
PHP);

        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertSame(
            [
                'root' => $fixture->root,
                'candidates' => [$fixture->siblingSecretPath(), $fixture->nestedSecretPath()],
            ],
            json_decode(trim($result['stdout']), true),
            'the outside-webroot candidate is the sibling of the installation, not of <root>/plugins'
        );
    }

    /**
     * The same branch observed as behaviour instead of arithmetic: a first use
     * with no install root and no `FS_FOLDER` must persist the secret beside
     * the installation and leave no copy inside the webroot.
     */
    public function testTheFallbackFirstUsePersistsOutsideTheWebroot(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();

        $result = $fixture->runSnippet(<<<'PHP'
$value = recovery_secret_ensure();
echo json_encode([
    'path' => recovery_secret_path(),
    'written' => recovery_secret_existing_path(),
    'length' => $value === null ? 0 : strlen($value),
    'error' => recovery_secret_last_error(),
], JSON_UNESCAPED_SLASHES);
PHP);

        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertSame(
            [
                'path' => $fixture->siblingSecretPath(),
                'written' => $fixture->siblingSecretPath(),
                'length' => 64,
                'error' => '',
            ],
            json_decode(trim($result['stdout']), true),
            'the fallback resolves a location beside the installation'
        );
        $this->assertFileExists($fixture->siblingSecretPath(), 'the secret is persisted outside the webroot');
        $this->assertFileDoesNotExist(
            $fixture->root . '/' . RecoverySecretFixture::SECRET_FILENAME,
            'and no copy is left inside it'
        );
        $this->assertFileDoesNotExist($fixture->nestedSecretPath());
    }

    /**
     * Where the arithmetic cannot be trusted, the fallback must refuse.
     *
     * When the library is not at `<root>/plugins/<plugin>/lib`, the three-level
     * guess names a directory that is not the installation, and there is no way
     * to prove that a candidate built from it is outside the webroot. Guessing
     * anyway is exactly how the secret ends up in it, so the contract is the
     * loud error RACC-02 already defines: no candidate, no value, nothing
     * written below the installation and the operator escape named.
     */
    public function testTheFallbackRefusesAWebrootItCannotVerify(): void
    {
        $fixture = $this->newFixture();
        $library = $fixture->installLibraryAt('lib');

        $probe = $fixture->root . '/probe.php';
        file_put_contents(
            $probe,
            "<?php\ndeclare(strict_types=1);\n"
            . 'require_once ' . var_export($library, true) . ";\n"
            . "\$value = recovery_secret_ensure();\n"
            . "echo json_encode([\n"
            . "    'root' => recovery_secret_install_root(),\n"
            . "    'candidates' => recovery_secret_candidates(),\n"
            . "    'value' => \$value === null ? null : 'present',\n"
            . "    'error' => recovery_secret_last_error(),\n"
            . '], JSON_UNESCAPED_SLASHES);' . "\n"
        );

        $result = $fixture->runPhpFile($probe);

        $this->assertSame(0, $result['code'], $result['stderr']);
        $data = json_decode(trim($result['stdout']), true);

        $this->assertSame('', $data['root'], 'a tree that does not verify yields no root at all');
        $this->assertSame([], $data['candidates'], 'and therefore no candidate that could land in the webroot');
        $this->assertNull($data['value'], 'nothing is generated without a location that can be proven safe');
        $this->assertStringContainsString('SU_RECOVERY_SECRET', $data['error'], 'the escape is still named');
        $this->assertDoesNotMatchRegularExpression(
            '/[0-9a-f]{64}/',
            $result['stdout'] . $result['stderr'],
            'a refusal never discloses a value'
        );

        foreach (['/' . RecoverySecretFixture::SECRET_FILENAME, '/lib/' . RecoverySecretFixture::SECRET_FILENAME, '/tmp'] as $relative) {
            $this->assertFileDoesNotExist($fixture->root . $relative, 'nothing is written inside the webroot');
        }
    }

    // ---------------------------------------------------------------------
    // RACC-02 — the write itself: owner-only from creation, atomic, clean
    // ---------------------------------------------------------------------

    /**
     * The file must be owner-only *from the moment it exists*.
     *
     * `file_put_contents()` creates with the process umask, and the `chmod()`
     * that follows is the fix for exactly that: it lands after the bytes are
     * already readable, and it never lands at all if the process dies inside
     * the window. So the claim cannot be "the file ends up `0600`" — the
     * existing permissions case covers that and a permissive umask satisfies
     * it. The claim is the umask in force at creation, which
     * {@see PartialWriteStream} is the only place to observe without racing
     * the writer.
     *
     * The interceptor is configured to refuse the write, so the same case also
     * pins that the caller's umask is restored on the failure path: a library
     * that narrowed the process umask for everyone else would be its own bug.
     */
    public function testTheSecretFileIsCreatedUnderAnOwnerOnlyUmask(): void
    {
        PartialWriteStream::reset();

        $previousUmask = umask(0022);

        try {
            $stored = recovery_secret_store(recovery_secret_generate(), PartialWriteStream::target());
            $umaskAfterStore = umask();
        } finally {
            umask($previousUmask);
        }

        $this->assertFalse($stored, 'the interceptor refuses the write, so nothing is published');
        $this->assertSame(
            0077,
            PartialWriteStream::$umaskAtCreation,
            'the file must be owner-only at creation, not only after the chmod() that follows'
        );
        $this->assertSame(0022, $umaskAfterStore, 'the caller umask is restored, including on the failure path');
    }

    /**
     * A write that stopped short must leave nothing behind.
     *
     * `file_put_contents()` reports a partial write as `false`, with the
     * accepted prefix already on disk. Returning on that `false` without
     * removing the temp file leaves the first characters of a secret sitting
     * beside the real one — readable by whoever can read the directory, and
     * invisible to the code that later rotates the value as if it had never
     * been written.
     */
    public function testAPartialWriteIsRejectedAndLeavesNoTemporaryFileBehind(): void
    {
        PartialWriteStream::reset();
        PartialWriteStream::$acceptedBytes = 40;

        $target = PartialWriteStream::target();
        $value = recovery_secret_generate();

        $this->assertFalse(
            recovery_secret_store($value, $target),
            'a write that stopped short must never report success'
        );

        $this->assertSame(40, PartialWriteStream::$bytesWritten, 'the failure under test is a partial write');
        $this->assertLessThan(
            strlen(recovery_secret_payload($value)),
            PartialWriteStream::$bytesWritten,
            'and it really is a prefix, not the whole payload'
        );
        $this->assertFalse(
            PartialWriteStream::exists(PartialWriteStream::$openedPath),
            'the temp file holding the prefix must be removed'
        );
        $this->assertFalse(PartialWriteStream::exists($target), 'and nothing may be published at the target');
    }

    // ---------------------------------------------------------------------
    // RACC-04 — the gate's comparison (the console's reachability check)
    // ---------------------------------------------------------------------

    /**
     * RACC-03 "the operator pin wins", made the gate's order by product-owner
     * decision: the pin outranks a different persisted value, in the gate, not
     * only in `recovery_secret_resolve()`.
     *
     * `config.php` is therefore consulted on every gate evaluation — that is the
     * accepted cost of the decision, and it is why the sentinel is asserted. The
     * two properties that must survive it are pinned here: the persisted value
     * is neither the credential that grants nor a file that gets rewritten, and
     * a wrong candidate is still refused.
     */
    public function testTheOperatorPinWinsOverADifferentPersistedValue(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();

        $persisted = recovery_secret_generate();
        $this->assertTrue(recovery_secret_store($persisted, $fixture->siblingSecretPath()));
        $path = $fixture->siblingSecretPath();
        $contents = (string) file_get_contents($path);
        touch($path, 1000000000);

        // Deliberately not 64 hex: an operator may pin any value they choose.
        $pin = 'operator-pin-' . bin2hex(random_bytes(8));
        $fixture->writeSentinelConfig(
            'define(' . var_export('SU_RECOVERY_SECRET', true) . ', ' . var_export($pin, true) . ");\n"
        );

        $result = $fixture->runSnippet(
            'echo json_encode(['
            . "'pin' => recovery_secret_gate(" . var_export($pin, true) . ", __DIR__, __DIR__ . '/config.php'),"
            . "'persisted' => recovery_secret_gate(" . var_export($persisted, true) . ", __DIR__, __DIR__ . '/config.php'),"
            . "'wrong' => recovery_secret_gate('f" . str_repeat('0', 63) . "', __DIR__, __DIR__ . '/config.php'),"
            . "'empty' => recovery_secret_gate('', __DIR__, __DIR__ . '/config.php'),"
            . ']);'
        );

        $this->assertSame(0, $result['code'], 'the gate must not fail: ' . $result['stderr']);
        $decoded = json_decode(trim($result['stdout']), true);
        $this->assertIsArray($decoded, 'the snippet answered: ' . $result['stdout'] . $result['stderr']);

        $this->assertTrue($decoded['pin']['granted'], 'the operator pin is what the gate answers against');
        $this->assertSame('operator_pin', $decoded['pin']['source']);
        $this->assertFalse(
            $decoded['persisted']['granted'],
            'the persisted value must lose to a different pin, not win over it'
        );
        $this->assertSame('operator_pin', $decoded['persisted']['source'], 'and the branch that decided is the pin');
        $this->assertFalse($decoded['wrong']['granted']);
        $this->assertFalse($decoded['empty']['granted']);
        $this->assertSame('operator_pin', $decoded['wrong']['source'], 'the refusal takes the pin branch too');

        $this->assertTrue(
            $fixture->configWasLoaded(),
            'the pin lives in config.php, so the gate must consult the file to find it'
        );
        $this->assertSame($contents, (string) file_get_contents($path), 'the persisted value is not rewritten');
        $this->assertSame(1000000000, $fixture->mtimeOf($path), 'the persisted value is not even touched');
    }

    /**
     * The escape of RACC-06 must not become a credential the console stores: the
     * pin is compared, never persisted, and it keeps working while the file it
     * lives in stays valid.
     */
    public function testTheOperatorPinIsNeverPersistedBehindTheOperatorsBack(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();

        $pin = 'operator-pin-' . bin2hex(random_bytes(8));
        $fixture->writeSentinelConfig(
            'define(' . var_export('SU_RECOVERY_SECRET', true) . ', ' . var_export($pin, true) . ");\n"
        );

        $result = $fixture->runSnippet(
            'echo json_encode(['
            . "'correct' => recovery_secret_gate(" . var_export($pin, true) . ", __DIR__, __DIR__ . '/config.php'),"
            . "'wrong' => recovery_secret_gate('f" . str_repeat('0', 63) . "', __DIR__, __DIR__ . '/config.php'),"
            . ']);'
        );

        $this->assertSame(0, $result['code'], $result['stderr']);
        $decoded = json_decode(trim($result['stdout']), true);
        $this->assertIsArray($decoded, 'the snippet answered: ' . $result['stdout'] . $result['stderr']);

        $this->assertTrue($decoded['correct']['granted'], 'the operator pin is the lost-secret escape');
        $this->assertSame('operator_pin', $decoded['correct']['source']);
        $this->assertFalse($decoded['wrong']['granted']);
        $this->assertSame('operator_pin', $decoded['wrong']['source']);

        $this->assertTrue($fixture->configWasLoaded(), 'premise: the pin branch is the documented config load');
        $this->assertFileDoesNotExist(
            $fixture->siblingSecretPath(),
            'the pin is not the installation secret, so it is never persisted'
        );
    }

    /**
     * The other half of "the pin wins" is what has to happen when the file that
     * carries it is broken: fall back to the persisted value, silently.
     *
     * RACC-01 already requires that a damaged `config.php` never paints the
     * response, and the pin decision does not relax it: the include is
     * error-suppressed, output-buffered and `Throwable`-caught, so a parse error
     * yields "no pin" — no body, no warning, no error_log line — and the
     * installation's own persisted secret still opens the console.
     */
    public function testADamagedConfigFallsBackToThePersistedValueSilently(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();
        $fixture->writeConfig("<?php\nfunction broken( { }\n");

        $persisted = recovery_secret_generate();
        $this->assertTrue(recovery_secret_store($persisted, $fixture->siblingSecretPath()));

        $result = $fixture->runSnippet(
            'echo json_encode(['
            . "'persisted' => recovery_secret_gate(" . var_export($persisted, true) . ", __DIR__, __DIR__ . '/config.php'),"
            . "'pinish' => recovery_secret_gate('operator-pin-' . bin2hex('x'), __DIR__, __DIR__ . '/config.php'),"
            . ']);'
        );

        $this->assertSame(0, $result['code'], 'a damaged config must not abort the gate: ' . $result['stderr']);
        $decoded = json_decode(trim($result['stdout']), true);
        $this->assertIsArray($decoded, 'the snippet answered: ' . $result['stdout'] . $result['stderr']);

        $this->assertTrue($decoded['persisted']['granted'], 'with no readable pin the persisted value still grants');
        $this->assertSame('persisted', $decoded['persisted']['source']);
        $this->assertFalse($decoded['pinish']['granted'], 'and nothing is invented from the broken file');

        foreach (['Parse error', 'syntax error', 'Warning', 'Fatal error'] as $noise) {
            $this->assertStringNotContainsString($noise, $result['stdout'], 'a damaged config must not be echoed');
            $this->assertStringNotContainsString($noise, $result['stderr'], 'nor warned about');
        }
        $this->assertStringStartsWith('{', trim($result['stdout']), 'nothing precedes the gate answer');
        $this->assertStringEndsWith('}', trim($result['stdout']), 'and nothing follows it');
    }

    /**
     * "Must not warn" is part of the C-2 decision, and it is stricter than "must
     * not echo": a warning from `config.php` is invisible in the response when
     * `display_errors` is off (production) but lands in the error log, where a
     * refusal is supposed to leave nothing at all.
     *
     * So the include is error-suppressed as well as buffered. The gate still
     * falls back to the persisted value, and the log stays empty — which is also
     * how the refusal keeps from advertising that this file was ever read.
     */
    public function testAConfigThatWarnsLeavesNothingInTheResponseOrTheLog(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();
        $fixture->writeConfig("<?php\n\$broken = \$undefined_variable_in_config;\n");

        $persisted = recovery_secret_generate();
        $this->assertTrue(recovery_secret_store($persisted, $fixture->siblingSecretPath()));

        $result = $fixture->runSnippet(
            'echo json_encode(['
            . "'persisted' => recovery_secret_gate(" . var_export($persisted, true) . ", __DIR__, __DIR__ . '/config.php'),"
            . ']);',
            ['display_errors' => '0']
        );

        $this->assertSame(0, $result['code'], $result['stderr']);
        $decoded = json_decode(trim($result['stdout']), true);
        $this->assertIsArray($decoded, 'the snippet answered: ' . $result['stdout'] . $result['stderr']);
        $this->assertTrue($decoded['persisted']['granted'], 'the persisted value still decides');

        $this->assertStringNotContainsString('Warning', $result['stdout'], 'the response body stays clean');
        $this->assertStringNotContainsString('Warning', $result['stderr'], 'and the process says nothing either');
        $this->assertSame(
            '',
            $fixture->errorLogContents(),
            'a warning from config.php must not reach the error log either'
        );
    }

    /**
     * The three failure inputs take **one** code path (RACC-04: an early return
     * for "no parameter" is forbidden), so absent, empty and wrong must agree on
     * everything observable.
     */
    public function testAbsentEmptyAndWrongCandidatesTakeTheSameComparison(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();

        $secret = recovery_secret_generate();
        $this->assertTrue(recovery_secret_store($secret, $fixture->siblingSecretPath()));

        $wrong = 'a' . str_repeat('0', 63);
        $this->assertNotSame($secret, $wrong);

        $empty = recovery_secret_gate('', $fixture->root);
        $absent = recovery_secret_gate('', $fixture->root);
        $bad = recovery_secret_gate($wrong, $fixture->root);
        $good = recovery_secret_gate($secret, $fixture->root);

        $this->assertFalse($empty['granted'], 'an empty candidate never grants');
        $this->assertSame($empty, $absent, 'absent and empty are the same call, not two paths');
        $this->assertSame($empty, $bad, 'empty and wrong differ only in the candidate, not in the branch');
        $this->assertTrue($good['granted'], 'and the installation secret still grants');
    }

    /**
     * The comparison itself: `hash_equals()`-equivalent, on the whole value.
     *
     * Asserted on the function that does the comparing instead of by timing:
     * `===` short-circuits at the first differing byte, which is the property
     * RACC-04 requires the gate not to have.
     *
     * The order assertion flipped with the product-owner decision behind C-2:
     * the gate consults the operator pin first and only then the persisted
     * value, and `recovery_secret_gate_value()` implements the same order for the
     * marker's fingerprint check.
     */
    public function testTheComparisonIsConstantTimeAndCoversTheWholeValue(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/lib/recovery_secret.php');
        $body = $this->functionBody($source, 'recovery_secret_gate');
        $valueBody = $this->functionBody($source, 'recovery_secret_gate_value');

        $this->assertStringContainsString('hash_equals(', $body, 'the gate must compare with hash_equals()');
        $this->assertStringNotContainsString('strcmp(', $body);
        $this->assertStringNotContainsString('substr_compare(', $body);
        $this->assertDoesNotMatchRegularExpression(
            '/\$[a-z]*candidate\w*\s*===?/i',
            $body,
            'a short-circuiting comparison against the candidate is the defect, not the fix'
        );

        $this->assertLessThan(
            (int) strpos($body, 'recovery_secret_gate_value('),
            (int) strpos($body, 'recovery_secret_operator_pin('),
            'the operator pin is consulted before the persisted branch'
        );
        $this->assertLessThan(
            (int) strpos($valueBody, 'recovery_secret_read('),
            (int) strpos($valueBody, 'SU_RECOVERY_SECRET'),
            'and the value order the marker consumes puts the pin first as well'
        );
    }

    private function functionBody(string $source, string $function): string
    {
        $pattern = '/function\s+' . preg_quote($function, '/') . '\s*\([^)]*\)[^{]*\{(.*?)\n\}/s';
        $this->assertSame(1, preg_match($pattern, $source, $matches), 'premise: ' . $function . '() exists');

        return $matches[1];
    }
}
