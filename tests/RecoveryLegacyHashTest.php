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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/recovery_legacy_hash.php';

/**
 * REC-07 — the legacy hash bridge survives A1, locally.
 *
 * The console exists for the installation whose core no longer boots, and the
 * administrator it exists for is frequently the one whose row still holds a
 * pre-bcrypt digest. The plugin-local verifier is therefore load-bearing for
 * the autoloader removal that follows it: if it is wrong, that administrator is
 * locked out of the only script that exists for the state where nobody can get
 * in.
 *
 * What is asserted, and why it is enough
 * --------------------------------------
 * The comparison set and its order are the core's, copied deliberately:
 * `password_verify()` first, then `sha1($salt . $plain)` when the row supplies a
 * salt, then `sha1($plain)`, then `sha1(mb_strtolower($plain, 'UTF8'))`, then
 * `md5($plain)`. Every comparison is `hash_equals()`.
 *
 * Two copies of that algorithm now exist by mandate, so the drift is not left to
 * prose: {@see testTheComparisonSetMatchesTheCoreAlgorithm()} measures this
 * module against `PasswordHasherService::verifyLegacyHash()` on the same inputs
 * whenever the core is loadable in the test run, and reports the class it could
 * not find when the plugin is checked out standalone. That test is the tripwire:
 * a core change to the legacy algorithm turns it red here.
 *
 * "The legacy comparisons are not attempted against a modern hash" is a **cost**
 * property, not an outcome one, and this file says so instead of pretending
 * otherwise: a modern hash is 60+ characters beginning with `$`, and no
 * `sha1()`/`md5()` digest is, so the legacy comparisons could never match one.
 * What the requirement forbids is the wasted work, so the ordering is pinned on
 * the source ({@see testTheLegacyComparisonsRunAfterTheModernCheck()}) and the
 * format detector is asserted variant by variant.
 *
 * The module must load with nothing defined and no autoloader. That is proven by
 * the absence of the machinery rather than by a mock: the file declares no
 * namespace, imports nothing, includes nothing and names no core symbol, which
 * PHP semantics make equivalent to "requires nothing".
 */
final class RecoveryLegacyHashTest extends TestCase
{
    private const PLAIN = 'correct-horse-battery-staple';

    private const SALT = 'a-per-row-legacy-salt';

    /**
     * A hash every request must be able to compute without the core, spelled out
     * so the module is never compared against itself.
     */
    private static function digest(string $algorithm, string $plain, ?string $salt = null): string
    {
        return match ($algorithm) {
            'sha1_salted' => sha1((string) $salt . $plain),
            'sha1' => sha1($plain),
            'sha1_lower' => sha1(mb_strtolower($plain, 'UTF8')),
            'md5' => md5($plain),
            default => self::fail('premise: unknown algorithm ' . $algorithm),
        };
    }

    #[Test]
    public function aSaltedSha1RowFromALegacySchemaIsAccepted(): void
    {
        $stored = self::digest('sha1_salted', self::PLAIN, self::SALT);

        $this->assertTrue(
            recovery_legacy_hash_verify($stored, self::PLAIN, self::SALT),
            'the salted variant is the first comparison of the core set'
        );
        $this->assertFalse(
            recovery_legacy_hash_verify($stored, self::PLAIN . 'x', self::SALT),
            'a different password does not verify'
        );
    }

    /**
     * The current `fs_users` schema has no `salt` column, so a row read from it
     * passes `null` and the salted comparison is unreachable. It is reproduced
     * because a legacy schema that does have the column can still be running,
     * and because a row whose salt was dropped must not start authenticating on
     * the unsalted variant of the same digest.
     */
    #[Test]
    public function theSaltedVariantIsOnlyReachableWithTheSaltOfTheRow(): void
    {
        $stored = self::digest('sha1_salted', self::PLAIN, self::SALT);

        $this->assertFalse(
            recovery_legacy_hash_verify($stored, self::PLAIN, null),
            'without the salt from the row the salted digest must not verify'
        );
        $this->assertFalse(
            recovery_legacy_hash_verify($stored, self::PLAIN, 'another-salt'),
            'and a different salt must not verify either'
        );
    }

    #[Test]
    public function aPlainSha1RowIsAccepted(): void
    {
        $this->assertTrue(
            recovery_legacy_hash_verify(self::digest('sha1', self::PLAIN), self::PLAIN, null)
        );
    }

    /**
     * FacturaScripts 2017 also stored the digest of the lower-cased password, so
     * a row entered as `Único` verifies when the operator types `único`.
     */
    #[Test]
    public function theLowerCasedSha1VariantIsAccepted(): void
    {
        $stored = self::digest('sha1_lower', 'Único-password');

        $this->assertNotSame(
            self::digest('sha1', 'Único-password'),
            $stored,
            'premise: the two variants differ, so the assertion below is not a restatement'
        );
        $this->assertTrue(recovery_legacy_hash_verify($stored, 'Único-password', null));
        $this->assertTrue(
            recovery_legacy_hash_verify($stored, 'único-PASSWORD', null),
            'the lower-cased variant is matched on the folded form, not the typed one'
        );
        $this->assertFalse(recovery_legacy_hash_verify($stored, 'Único-password!', null));
    }

    #[Test]
    public function anMd5RowIsAccepted(): void
    {
        $this->assertTrue(
            recovery_legacy_hash_verify(self::digest('md5', self::PLAIN), self::PLAIN, null)
        );
    }

    #[Test]
    public function aBcryptRowIsVerifiedByPasswordVerify(): void
    {
        $stored = password_hash(self::PLAIN, PASSWORD_BCRYPT);

        $this->assertTrue(recovery_legacy_hash_verify($stored, self::PLAIN, null));
        $this->assertFalse(recovery_legacy_hash_verify($stored, self::PLAIN . 'x', null));
        $this->assertTrue(
            recovery_legacy_hash_verify($stored, self::PLAIN, self::SALT),
            'a supplied salt changes nothing on the modern path'
        );
    }

    /**
     * Every spelling `password_verify()` treats as its own format.
     *
     * `$2a$`, `$2b$` and `$2x$` are the same blowfish hash as `$2y$` and
     * `password_verify()` accepts all four, while `password_get_info()` reports
     * the last three as `unknown` (measured on PHP 8.3.33). The detector
     * therefore cannot be `password_get_info()` alone: those spellings must not
     * be handed to the legacy comparisons.
     *
     * @return array<string, array{0: string}>
     */
    public static function modernSpellings(): array
    {
        $bcrypt = password_hash(self::PLAIN, PASSWORD_BCRYPT);
        $body = substr($bcrypt, 4);

        return [
            'bcrypt $2y$' => ['$2y$' . $body],
            'blowfish $2a$' => ['$2a$' . $body],
            'blowfish $2b$' => ['$2b$' . $body],
            'blowfish $2x$' => ['$2x$' . $body],
            'argon2i' => [password_hash(self::PLAIN, PASSWORD_ARGON2I)],
            'argon2id' => [password_hash(self::PLAIN, PASSWORD_ARGON2ID)],
        ];
    }

    #[Test]
    #[DataProvider('modernSpellings')]
    public function aModernHashIsRecognisedAsModern(string $stored): void
    {
        $this->assertTrue(
            recovery_legacy_hash_is_modern($stored),
            'the legacy comparisons must not be attempted against this format'
        );
    }

    /**
     * Everything else is legacy-schema territory. A legacy digest is hex, so the
     * detector cannot accidentally claim one as modern.
     *
     * @return array<string, array{0: string}>
     */
    public static function legacyValues(): array
    {
        return [
            'sha1' => [sha1(self::PLAIN)],
            'md5' => [md5(self::PLAIN)],
            'salted sha1' => [sha1(self::SALT . self::PLAIN)],
            'md5-crypt $1$' => ['$1$abc$def'],
            'sha256-crypt $5$' => ['$5$rounds=5000$abc$def'],
            'sha512-crypt $6$' => ['$6$rounds=5000$abc$def'],
            'empty' => [''],
            'not a hash' => ['not-a-hash-at-all'],
            'a bare dollar' => ['$'],
        ];
    }

    #[Test]
    #[DataProvider('legacyValues')]
    public function aLegacyValueIsNotMistakenForAModernHash(string $stored): void
    {
        $this->assertFalse(
            recovery_legacy_hash_is_modern($stored),
            'only the formats password_verify() owns may skip the legacy set'
        );
    }

    /**
     * REC-07: "The modern path MUST run first ... and the legacy comparisons MUST
     * NOT be attempted against a hash that `password_verify()` recognizes as its
     * own format."
     *
     * Asserted on the source because "not attempted" is a cost property with no
     * observable outcome: `sha1()`/`md5()` produce 40/32 hex characters and a
     * modern hash is 60+ characters beginning with `$`, so a legacy comparison
     * against a modern hash is false by construction. An assertion on a returned
     * value would pass whether the code skipped the work or not.
     *
     * The order inside the function is itself load-bearing, in the direction that
     * cannot lock anybody out: `password_verify()` first, and the format detector
     * second as the gate that closes the legacy block. A detector-first shape
     * would deny a format PHP accepts but the detector has not learned yet — the
     * exact lockout this requirement exists to prevent — while the shipped shape
     * only ever costs four comparisons on a value that cannot match anyway.
     */
    #[Test]
    public function theLegacyComparisonsRunAfterTheModernCheck(): void
    {
        $body = $this->functionBody($this->source(), 'recovery_legacy_hash_verify');

        $modern = strpos($body, 'password_verify(');
        $format = strpos($body, 'recovery_legacy_hash_is_modern(');
        $firstDigest = strpos($body, 'sha1(');

        $this->assertIsInt($modern, 'the verifier must call password_verify()');
        $this->assertIsInt($format, 'the verifier must consult the format detector');
        $this->assertIsInt($firstDigest, 'the verifier must compare a legacy digest');

        $this->assertLessThan($firstDigest, $modern, 'password_verify() runs before the legacy block');
        $this->assertLessThan(
            $format,
            $modern,
            'the modern verification runs first, and the detector only decides what follows it'
        );
        $this->assertLessThan(
            $firstDigest,
            $format,
            'the modern format is excluded before the first legacy comparison'
        );

        $this->assertSame(
            4,
            substr_count($body, 'hash_equals('),
            'one hash_equals() per legacy variant: salted sha1, sha1, folded sha1, md5'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function wrongPasswords(): array
    {
        $plain = self::PLAIN;

        return [
            'salted sha1' => [sha1(self::SALT . $plain), $plain . 'x', self::SALT],
            'sha1' => [sha1($plain), $plain . 'x', ''],
            'folded sha1' => [sha1(mb_strtolower($plain, 'UTF8')), $plain . 'x', ''],
            'md5' => [md5($plain), $plain . 'x', ''],
            'bcrypt' => [password_hash($plain, PASSWORD_BCRYPT), $plain . 'x', ''],
        ];
    }

    #[Test]
    #[DataProvider('wrongPasswords')]
    public function aRecognisableDigestThatDoesNotMatchIsAPlainFailure(
        string $stored,
        string $plain,
        string $salt
    ): void {
        $this->assertFalse(recovery_legacy_hash_verify($stored, $plain, $salt === '' ? null : $salt));
    }

    /**
     * REC-07: "If no verification is available at all ... the outcome MUST be the
     * uniform failure. It MUST NOT be a bypass ("unknown format accepted")."
     *
     * The verifier answers a boolean and nothing else, so the only bypass it
     * could offer is `true`. Every unrecognisable shape answers `false`; the
     * uniform body is the console's job and belongs to REC-05.
     *
     * @return array<string, array{0: string}>
     */
    public static function unrecognisableValues(): array
    {
        return [
            'empty' => [''],
            'prose' => ['not-a-hash-at-all'],
            'a bare dollar' => ['$'],
            'a truncated sha1' => [substr(sha1(self::PLAIN), 0, 39)],
            'a truncated md5' => [substr(md5(self::PLAIN), 0, 31)],
            'uppercase sha1' => [strtoupper(sha1(self::PLAIN))],
            'sha256 of the password' => [hash('sha256', self::PLAIN)],
            'a modern hash of another password' => [password_hash('something-else', PASSWORD_BCRYPT)],
        ];
    }

    #[Test]
    #[DataProvider('unrecognisableValues')]
    public function anUnrecognisableStoredValueIsAPlainFailure(string $stored): void
    {
        $this->assertFalse(recovery_legacy_hash_verify($stored, self::PLAIN, null));
        $this->assertFalse(recovery_legacy_hash_verify($stored, self::PLAIN, self::SALT));
    }

    /**
     * `sha1('')` is a perfectly valid digest, so a row that was emptied would
     * authenticate every empty submission on the console — and an empty
     * submission is the shape a cross-site POST can produce for free. The
     * console's policy layer already refuses an empty password before evaluation
     * (REC-02), and this guard makes the verifier refuse it on its own.
     */
    #[Test]
    public function anEmptyPasswordNeverVerifies(): void
    {
        $this->assertFalse(recovery_legacy_hash_verify(sha1(''), '', null));
        $this->assertFalse(recovery_legacy_hash_verify(md5(''), '', null));
        $this->assertFalse(recovery_legacy_hash_verify(sha1(self::SALT), '', self::SALT));
    }

    /**
     * The tripwire for the two copies A1 forces us to keep (§ Failure-mode
     * inventory 5, `plugin-recovery-login`).
     *
     * Same inputs, both implementations, same answers. When the core is present —
     * it is, in this repository's own test run — a change to the core algorithm
     * turns this red instead of silently locking a legacy administrator out of
     * the console. When the plugin is checked out standalone there is no core to
     * compare against, and the skip names what it looked for rather than passing
     * quietly.
     */
    #[Test]
    public function theComparisonSetMatchesTheCoreAlgorithm(): void
    {
        $class = 'FSFramework\\Security\\PasswordHasherService';

        if (!class_exists($class)) {
            $this->markTestSkipped(
                'The core class ' . $class . ' is not loadable: this plugin is checked out without '
                . 'the framework, so there is no core copy to measure the plugin-local one against.'
            );
        }

        $hasher = new $class();

        if (!is_callable([$hasher, 'verifyLegacyHash'])) {
            $this->markTestSkipped(
                'The core class ' . $class . ' has no callable verifyLegacyHash(): the local comparison '
                . 'set cannot be measured against an API this core version does not publish.'
            );
        }

        foreach ($this->driftMatrix() as $label => [$stored, $plain, $salt]) {
            $this->assertSame(
                $hasher->verifyLegacyHash($stored, $plain, $salt),
                recovery_legacy_hash_verify($stored, $plain, $salt),
                'the local verifier disagrees with the core algorithm on: ' . $label
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    private function driftMatrix(): array
    {
        $plain = self::PLAIN;
        $salt = self::SALT;

        return [
            'salted sha1, salt supplied' => [sha1($salt . $plain), $plain, $salt],
            'salted sha1, salt withheld' => [sha1($salt . $plain), $plain, null],
            'salted sha1, wrong password' => [sha1($salt . $plain), $plain . 'x', $salt],
            'sha1' => [sha1($plain), $plain, null],
            'sha1, wrong password' => [sha1($plain), $plain . 'x', null],
            'folded sha1' => [sha1(mb_strtolower($plain, 'UTF8')), mb_strtoupper($plain), null],
            'md5' => [md5($plain), $plain, null],
            'md5, wrong password' => [md5($plain), $plain . 'x', null],
        ];
    }

    /**
     * The dependency contract (RACC-01), asserted on the file that has to survive
     * the autoloader removal.
     *
     * PHP makes this equivalent to "loads with nothing defined": a file with no
     * include, no namespace and no import cannot reach another file, and one that
     * names no fully-qualified symbol cannot reach a core class.
     */
    #[Test]
    public function theVerifierLoadsWithoutAnyCoreClassOrAutoloader(): void
    {
        $source = $this->source();

        $this->assertDoesNotMatchRegularExpression('/^\s*(namespace|use)\s/m', $source);
        $this->assertDoesNotMatchRegularExpression('/^\s*(require|include)(_once)?\b/m', $source);
        $this->assertStringNotContainsString('class_exists(', $source);
        $this->assertStringNotContainsString('\\FSFramework', $source);
        $this->assertStringNotContainsString('new \\', $source);
        $this->assertStringNotContainsString('vendor/autoload', $source);
        $this->assertStringNotContainsString('SessionManager', $source);
        $this->assertStringNotContainsString('$GLOBALS', $source);
    }

    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/lib/recovery_legacy_hash.php');
    }

    private function functionBody(string $source, string $function): string
    {
        $pattern = '/function\s+' . preg_quote($function, '/') . '\s*\([^)]*\)[^{]*\{(.*?)\n\}/s';
        $this->assertSame(1, preg_match($pattern, $source, $matches), 'premise: ' . $function . '() exists');

        return $matches[1];
    }
}
