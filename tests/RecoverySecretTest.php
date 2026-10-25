<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;
use Tests\SystemUpdater\Support\RecoverySecretFixture;

require_once dirname(__DIR__) . '/lib/recovery_secret.php';
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
     */
    public function testTheSecretIsStoredOutsideTheInstallRootWhenASiblingIsWritable(): void
    {
        $fixture = $this->newFixture();

        $this->assertNotNull(recovery_secret_ensure($fixture->root));

        $path = recovery_secret_path($fixture->root);
        $webroot = $fixture->root . '/';

        $this->assertStringStartsWith($fixture->siblingDirectory() . '/', $path);
        $this->assertStringNotContainsString(
            $webroot,
            $path,
            'the secret must not live inside the webroot while a writable sibling exists'
        );
        $this->assertSame(RecoverySecretFixture::SECRET_FILENAME, basename($path));
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
     * RACC-06 escape 1: `php recovery.php --secret` prints the current value
     * without loading `config.php` and without opening the database. The
     * sentinel proves the second half: a DB connection needs `FS_DB_*`, which
     * only `config.php` defines, so an unloaded config is an untouched DB.
     */
    public function testCliReadPrintsTheCurrentSecretWithoutLoadingConfig(): void
    {
        $fixture = $this->newFixture();
        $fixture->installConsole();
        $fixture->writeSentinelConfig();

        $value = recovery_secret_generate();
        $this->assertTrue(recovery_secret_store($value, $fixture->siblingSecretPath()));

        $result = $fixture->runConsole('--secret');

        $this->assertSame(0, $result['code'], 'the read escape must succeed: ' . $result['stderr']);
        $this->assertSame($value, trim($result['stdout']));
        $this->assertFalse($fixture->configWasLoaded(), 'the read escape must not need config.php');
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
        $this->assertFalse($fixture->configWasLoaded());

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
        $this->assertFalse($fixture->configWasLoaded());

        $this->assertSame($rotated, recovery_secret_read($fixture->root), 'the new value is persisted');
        $this->assertNotSame($previous, recovery_secret_read($fixture->root), 'the previous value is retired');
        $this->assertSame($rotated, trim($fixture->runConsole('--secret')['stdout']));

        $fresh = $fixture->runSnippet('echo recovery_secret_resolve(__DIR__);');
        $this->assertSame($rotated, trim($fresh['stdout']), 'a fresh process sees only the rotated value');

        $this->assertSame(1, substr_count($fixture->errorLogContents(), $rotated));
    }

    /**
     * RACC-06 "the CLI flags MUST be ignored for non-CLI SAPIs".
     *
     * `php-cgi` is not installed in this container and `php -S` belongs to the
     * next slice's harness, so the web half is pinned at its mechanism rather
     * than over HTTP: the console's only secret branch is guarded by
     * `PHP_SAPI === 'cli'`, it runs *before* `config.php` is loaded, nothing in
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
        $this->assertLessThan($config, $handler, 'the CLI escapes must run before config.php is loaded');

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
}
