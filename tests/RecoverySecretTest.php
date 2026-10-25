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
 * Covers the half of RACC-02 that says the value *exists and survives*:
 * generated from a CSPRNG, persisted, stable across resolutions and across
 * processes, unique per installation rather than derived from a shared input,
 * readable by its owner alone, stored outside the webroot when a sibling is
 * writable, and harmless when the last-resort file is requested over HTTP.
 *
 * The suite has no database and never touches the real installation: every
 * case builds a temp install root through {@see RecoverySecretFixture} and
 * asserts against that tree.
 */
final class RecoverySecretTest extends TestCase
{
    /** @var RecoverySecretFixture[] */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }
        $this->fixtures = [];

        parent::tearDown();
    }

    private function newFixture(): RecoverySecretFixture
    {
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

        $result = $fixture->runSnippet('echo recovery_secret_ensure(__DIR__ . "/root");');

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
}
