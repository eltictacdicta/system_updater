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

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/recovery_secret.php';

/**
 * RACC-02's "the secret differs between installations", in the layout where the
 * previous derivation could not keep that promise: **two installations under one
 * parent directory**.
 *
 * That is not an exotic layout. `public_html` next to `staging`, or two vhosts
 * under `/var/www`, is the ordinary way to run a production site and a copy of
 * it. The outside-webroot candidate used to be `dirname($root)` plus a fixed
 * file name, so those two installations resolved the *same file*: one secret,
 * shared, and each one's gate accepted the other's value. The defect was
 * invisible to the uniqueness case in `RecoverySecretTest`, because each of its
 * fixtures allocates its own parent by construction.
 *
 * So every case here builds `<parent>/vhostA` and `<parent>/vhostB` and asserts
 * the property that was actually false: the two installations own *different*
 * files, hold *different* values, and neither gate answers for the other's
 * secret.
 *
 * Deliberately fixture-free: the candidate locations are the library's own
 * answer (`recovery_secret_path()`), so nothing but the derivation under test
 * can decide where the file goes. A harness that pre-computed the path could
 * agree with a wrong derivation and hide the defect again.
 */
final class RecoverySecretSharedParentTest extends TestCase
{
    /** The parent both installations share — the location of the candidates. */
    private string $parent = '';

    /** @var string[] */
    private array $roots = [];

    private string|false $previousErrorLog = false;

    private string $errorLogFile = '';

    /**
     * A first resolution writes a throwaway value to the PHP error log on
     * purpose (RACC-06). In the suite that sink is stderr, so it is redirected
     * to a per-test file: these cases generate one secret per installation and
     * would otherwise print them into the test output.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousErrorLog = ini_get('error_log');
        $this->errorLogFile = rtrim(sys_get_temp_dir(), '/') . '/su_shared_log_' . bin2hex(random_bytes(6));
        ini_set('error_log', $this->errorLogFile);

        $this->parent = rtrim(sys_get_temp_dir(), '/') . '/su_shared_parent_' . bin2hex(random_bytes(6));
        if (!mkdir($this->parent, 0777, true) && !is_dir($this->parent)) {
            $this->fail('cannot create the shared parent at ' . $this->parent);
        }
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousErrorLog);
        @unlink($this->errorLogFile);

        // The candidates live beside the roots, so the shared parent has to be
        // walked; the roots go with it.
        self::removeTree($this->parent);
        $this->roots = [];

        parent::tearDown();
    }

    /**
     * The defect measured directly: one parent, two installations, two files.
     *
     * Asserted on `recovery_secret_path()` — "where the secret is, or would be"
     * — so the case does not depend on which installation was resolved first.
     */
    public function testTwoInstallationsUnderOneParentResolveDifferentSecretFiles(): void
    {
        $first = $this->install('vhostA');
        $second = $this->install('vhostB');

        $firstPath = recovery_secret_path($first);
        $secondPath = recovery_secret_path($second);

        $this->assertNotSame(
            $firstPath,
            $secondPath,
            'two installations sharing a parent must not resolve the same secret file: both got ' . $firstPath
        );

        foreach ([$firstPath, $secondPath] as $path) {
            $this->assertStringStartsWith(
                $this->parent . '/',
                $path,
                'and both candidates still sit outside the installation, beside it'
            );
            $this->assertSame(
                recovery_secret_filename(),
                basename($path),
                'the operator-facing file name is unchanged; only the directory is per installation'
            );
        }
    }

    /**
     * The value, not only the path: resolving both installations must produce
     * two independent secrets. With one shared file the second resolution
     * *reads* the first installation's secret instead of generating its own.
     */
    public function testTwoInstallationsUnderOneParentNeverShareASecretValue(): void
    {
        $first = $this->install('vhostA');
        $second = $this->install('vhostB');

        $firstValue = (string) recovery_secret_ensure($first);
        $secondValue = (string) recovery_secret_ensure($second);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $firstValue);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secondValue);
        $this->assertNotSame(
            $firstValue,
            $secondValue,
            'a shared parent made the two installations share one secret'
        );

        $this->assertSame($firstValue, recovery_secret_read($first), 'each installation reads back its own value');
        $this->assertSame($secondValue, recovery_secret_read($second));

        // The value is still not derived from the path: two installations can
        // share every path component except the last and still need unrelated
        // secrets.
        foreach (['md5', 'sha1', 'sha256'] as $algorithm) {
            $this->assertStringNotContainsString(
                hash($algorithm, $second),
                $firstValue,
                'the secret is not the ' . $algorithm . ' of the install root'
            );
        }
    }

    /**
     * The security half, stated as the defect's consequence: install B's gate
     * must reject install A's secret, and accept its own.
     *
     * This is the measured failure in `verify-report.md` (C-1): with one shared
     * file, B's gate answered `granted: YES` for A's value, so a secret leaked
     * from the staging installation opened production's console.
     */
    public function testOneInstallationsGateRejectsTheOtherSecretDespiteTheSharedParent(): void
    {
        $first = $this->install('vhostA');
        $second = $this->install('vhostB');

        $firstValue = (string) recovery_secret_ensure($first);
        $secondValue = (string) recovery_secret_ensure($second);

        $configFile = $second . '/config.php';
        $this->assertFileDoesNotExist($configFile, 'premise: no operator pin is involved in this case');

        // The security assertion comes first, before the "premise" one, so the
        // case fails on the property that matters and not on its setup.
        $foreign = recovery_secret_gate($firstValue, $second, $configFile);
        $this->assertFalse(
            $foreign['granted'],
            "install B's gate must reject install A's secret, even though both share a parent directory"
        );

        $this->assertNotSame($firstValue, $secondValue, 'and the two installations hold different secrets');

        $own = recovery_secret_gate($secondValue, $second, $configFile);
        $this->assertTrue($own['granted'], "install B's gate must still accept its own secret");
        $this->assertSame('persisted', $own['source']);

        // And the mirror: A refuses B's value too, so the isolation is not
        // one-directional.
        $this->assertFalse(recovery_secret_gate($secondValue, $first, $first . '/config.php')['granted']);
        $this->assertTrue(recovery_secret_gate($firstValue, $first, $first . '/config.php')['granted']);
    }

    /**
     * The fix must not buy uniqueness by moving the file into the installation:
     * the first candidate is the one that survives a damaged core (RACC-01), so
     * it stays outside both webroots.
     */
    public function testThePerInstallationLocationStaysOutsideBothWebroots(): void
    {
        $first = $this->install('vhostA');
        $second = $this->install('vhostB');

        foreach ([$first, $second] as $root) {
            $path = recovery_secret_path($root);

            $this->assertStringStartsWith($this->parent . '/', $path, 'the candidate is a sibling of the installation');
            $this->assertStringNotContainsString(
                $root . '/',
                $path,
                'the candidate must not live inside the webroot it belongs to'
            );
            $this->assertNotSame(
                $root . '/tmp/' . recovery_secret_filename(),
                $path,
                'the last-resort inside-webroot candidate is not the one taken here'
            );
        }
    }

    /** One install root under the shared parent, with no other content. */
    private function install(string $name): string
    {
        $root = $this->parent . '/' . $name;

        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            $this->fail('cannot create the install root at ' . $root);
        }

        $this->roots[] = $root;

        return $root;
    }

    private static function removeTree(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @chmod($path, 0600);
            @unlink($path);

            return;
        }

        @chmod($path, 0700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
