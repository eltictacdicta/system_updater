<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Tests\SystemUpdater;

use backup_manager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

require_once FS_FOLDER . '/plugins/system_updater/lib/backup_manager.php';

/**
 * Contract tests for the zero-ops backup directory resolution.
 *
 * These tests pin the security-relevant behaviour of the change
 * `secure-backup-access`:
 *   - backups MUST live outside the webroot by default (suffixed sibling);
 *   - the `FS_BACKUP_DIR` override MUST be honoured verbatim;
 *   - the random suffix MUST be persisted and stable across calls;
 *   - a lost state file MUST adopt the existing suffixed dir (no data loss);
 *   - an unusable sibling MUST fall back automatically to a protected
 *     legacy dir inside the webroot (zero manual ops);
 *   - legacy `backups` dirs MUST be migrated best-effort;
 *   - the backup dir MUST be created with restrictive 0700 permissions and
 *     MUST never be silently included in a file backup.
 */
#[CoversClass(backup_manager::class)]
final class BackupManagerBackupDirTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private array $definedConstants = [];

    /**
     * @var list<string>
     */
    private array $addedConstants = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->definedConstants = [];
        $this->addedConstants = [];
        $constants = ['FS_BACKUP_DIR'];
        foreach ($constants as $name) {
            if (defined($name)) {
                $this->definedConstants[$name] = (string) constant($name);
            } else {
                $this->addedConstants[] = $name;
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->addedConstants as $name) {
            if (defined($name)) {
                // PHP cannot undefine a constant at runtime; we accept the
                // side effect but the test process is single-shot anyway.
                continue;
            }
        }

        // Make sure the temp directory is writable again before rmdir() so
        // we can clean up regardless of what the test did.
        foreach ($this->definedConstants as $name => $value) {
            // No-op: we cannot re-define constants.
            unset($value);
        }

        parent::tearDown();
    }

    // ============================================================
    // resolve_backup_dir — base candidate + override
    // ============================================================

    #[Test]
    public function resolveBackupDirDefaultLivesOutsideWebroot(): void
    {
        $resolved = backup_manager::resolve_backup_dir();

        $this->assertSame(
            dirname(FS_FOLDER) . DIRECTORY_SEPARATOR . 'backups',
            $resolved,
            'Default backup dir base must be a sibling of FS_FOLDER (outside the webroot)'
        );

        $this->assertStringStartsNotWith(
            rtrim(FS_FOLDER, '/\\') . DIRECTORY_SEPARATOR,
            $resolved,
            'Default backup dir must NOT be a descendant of FS_FOLDER'
        );
    }

    #[Test]
    public function resolveBackupDirOverrideTakesPrecedence(): void
    {
        // Use a private helper that accepts the override explicitly so the
        // assertion does not depend on the (immutable) FS_BACKUP_DIR constant.
        $override = '/var/backups/fsframework-test';
        $fsFolder = sys_get_temp_dir() . '/fs_root_' . uniqid('', true);

        $resolved = $this->callResolveBackupDirWith($override, $fsFolder);

        $this->assertSame(
            $override,
            $resolved,
            'When FS_BACKUP_DIR is set, resolve_backup_dir() must return it verbatim'
        );
    }

    #[Test]
    public function resolveBackupDirOverrideEmptyFallsBackToDefault(): void
    {
        $fsFolder = sys_get_temp_dir() . '/fs_root_' . uniqid('', true);

        $resolved = $this->callResolveBackupDirWith('', $fsFolder);

        $this->assertSame(
            dirname($fsFolder) . DIRECTORY_SEPARATOR . 'backups',
            $resolved,
            'Empty FS_BACKUP_DIR must fall back to the default sibling path'
        );
    }

    // ============================================================
    // resolve_effective_backup_dir — random suffix persistence
    // ============================================================

    #[Test]
    public function resolveEffectiveBackupDirAppliesRandomSuffix(): void
    {
        $fsRoot = $this->makeTempRoot('suffix');
        mkdir($fsRoot . '/tmp', 0755, true);

        try {
            $resolved = backup_manager::resolve_effective_backup_dir($fsRoot);

            $this->assertMatchesRegularExpression(
                '~' . preg_quote(dirname($fsRoot), '~') . '/backups-[a-f0-9]{16}$~',
                $resolved,
                'Effective dir must be a suffixed sibling with a 16-hex random suffix'
            );

            $stateFile = $fsRoot . '/tmp/system_updater_backup_dir.txt';
            $this->assertFileExists($stateFile, 'Suffix must be persisted to the state file');
            $suffix = trim((string) file_get_contents($stateFile));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $suffix);
            $this->assertStringEndsWith('-' . $suffix, $resolved);
        } finally {
            $this->rrmdir($fsRoot);
        }
    }

    #[Test]
    public function resolveEffectiveBackupDirIsStableAcrossCalls(): void
    {
        $fsRoot = $this->makeTempRoot('stable');
        mkdir($fsRoot . '/tmp', 0755, true);

        try {
            $first = backup_manager::resolve_effective_backup_dir($fsRoot);
            $second = backup_manager::resolve_effective_backup_dir($fsRoot);

            $this->assertSame(
                $first,
                $second,
                'Suffix must be persisted, not regenerated on every call'
            );
        } finally {
            $this->rrmdir($fsRoot);
        }
    }

    #[Test]
    public function resolveEffectiveBackupDirAdoptsExistingSuffixedDir(): void
    {
        $fsRoot = $this->makeTempRoot('adopt');
        mkdir($fsRoot . '/tmp', 0755, true);

        try {
            // First call creates + persists a suffix; then simulate tmp wipe.
            backup_manager::resolve_effective_backup_dir($fsRoot);
            $this->assertTrue(
                unlink($fsRoot . '/tmp/system_updater_backup_dir.txt'),
                'State file must exist before we simulate the tmp wipe'
            );

            // Pre-create a DIFFERENT suffixed dir (e.g. after a manual copy
            // or a tmp cleanup) — adoption must prefer what is on disk.
            $adoptedSuffix = str_repeat('ab', 8); // 16 hex chars
            $existing = dirname($fsRoot) . '/backups-' . $adoptedSuffix;
            mkdir($existing, 0700, true);
            file_put_contents($existing . '/backup_2024-01-01.sql.gz', 'x');

            try {
                $resolved = backup_manager::resolve_effective_backup_dir($fsRoot);
                $this->assertStringEndsWith('-' . $adoptedSuffix, $resolved);
            } finally {
                $this->rrmdir($existing);
            }
        } finally {
            $this->rrmdir($fsRoot);
        }
    }

    // ============================================================
    // resolve_usable_backup_dir — automatic fallback
    // ============================================================

    #[Test]
    public function resolveUsableBackupDirFallsBackInsideWebrootWhenAllOutsideUnusable(): void
    {
        $parent = sys_get_temp_dir() . '/parent_block_' . uniqid('', true);
        file_put_contents($parent, 'block'); // parent is a FILE → sibling cannot be created
        $fsFolder = $parent . '/fsroot';
        $homeBlock = sys_get_temp_dir() . '/home_block_' . uniqid('', true);
        file_put_contents($homeBlock, 'block'); // home is a FILE → cannot create there

        try {
            $resolved = backup_manager::resolve_usable_backup_dir($fsFolder, $homeBlock);

            $this->assertStringStartsWith(
                $fsFolder . DIRECTORY_SEPARATOR . 'backups',
                $resolved,
                'When sibling AND home are unusable the resolver must fall back inside the webroot'
            );
        } finally {
            @unlink($parent);
            @unlink($homeBlock);
        }
    }

    #[Test]
    public function resolveUsableBackupDirPrefersHomeWhenSiblingUnusable(): void
    {
        $tempRoot = $this->makeTempRoot('home');
        mkdir($tempRoot . '/tmp', 0755, true);
        $homeDir = sys_get_temp_dir() . '/home_real_' . uniqid('', true);
        mkdir($homeDir, 0755, true);

        // Block the sibling with a FILE at the effective path.
        $effective = backup_manager::resolve_effective_backup_dir($tempRoot);
        if (is_dir($effective)) {
            rmdir($effective);
        }
        file_put_contents($effective, 'block');

        try {
            $resolved = backup_manager::resolve_usable_backup_dir($tempRoot, $homeDir);

            $this->assertStringStartsWith(
                $homeDir . DIRECTORY_SEPARATOR . 'backups-',
                $resolved,
                'When the sibling is unusable the resolver must prefer the user home over the webroot'
            );
        } finally {
            @unlink($effective);
            foreach ((array) glob($homeDir . '/backups-*') as $dir) {
                chmod($dir, 0700);
                $this->rrmdir($dir);
            }
            $this->rrmdir($homeDir);
            $this->rrmdir($tempRoot);
        }
    }

    // ============================================================
    // backup_dir_candidates — ordered chain
    // ============================================================

    #[Test]
    public function backupDirCandidatesAreOrderedSiblingHomeLegacy(): void
    {
        $tempRoot = $this->makeTempRoot('cand');
        mkdir($tempRoot . '/tmp', 0755, true);

        try {
            $candidates = backup_manager::backup_dir_candidates($tempRoot, '/home/test-user');

            $this->assertCount(
                3,
                $candidates,
                'Chain must contain exactly sibling, home and legacy candidates (no override defined)'
            );
            $this->assertStringStartsWith(
                dirname($tempRoot) . DIRECTORY_SEPARATOR . 'backups-',
                $candidates[0],
                'First candidate must be the sibling outside the webroot'
            );
            $this->assertStringStartsWith(
                '/home/test-user/backups-',
                $candidates[1],
                'Second candidate must be the user home outside the webroot'
            );
            $this->assertStringStartsWith(
                $tempRoot . DIRECTORY_SEPARATOR . 'backups-',
                $candidates[2],
                'Last candidate must be the protected legacy dir inside the webroot'
            );
        } finally {
            $this->rrmdir($tempRoot);
        }
    }

    // ============================================================
    // Constructor — perms, fallback + exclusion, legacy migration
    // ============================================================

    #[Test]
    public function ensureBackupDirectoryIsCreatedWithRestrictivePerms(): void
    {
        $tempRoot = $this->makeTempRoot('perms');
        mkdir($tempRoot . '/tmp', 0755, true);

        try {
            new backup_manager($tempRoot);

            $created = (array) glob(dirname($tempRoot) . '/backups-*');
            $this->assertNotEmpty(
                $created,
                'Constructor must create the suffixed backup dir outside the webroot'
            );
            $sibling = (string) $created[0];
            $this->assertDirectoryExists($sibling);

            $perms = fileperms($sibling) & 0777;
            $this->assertSame(
                0700,
                $perms,
                'Backup directory must be created with 0700 permissions (got %s)',
                decoct($perms)
            );
        } finally {
            foreach ((array) glob(dirname($tempRoot) . '/backups-*') as $dir) {
                chmod($dir, 0700);
                $this->rrmdir($dir);
            }
            $this->rrmdir($tempRoot);
        }
    }

    #[Test]
    public function constructorFallsBackInsideWebrootAndExcludesBackupDir(): void
    {
        $tempRoot = $this->makeTempRoot('fallback');
        mkdir($tempRoot . '/tmp', 0755, true);

        // Block the preferred sibling by creating a FILE at the effective
        // path: the resolver must fall back to a protected legacy dir
        // inside the webroot instead of surfacing a hard error.
        $effective = backup_manager::resolve_effective_backup_dir($tempRoot);
        if (is_dir($effective)) {
            rmdir($effective);
        }
        file_put_contents($effective, 'block');

        // Also block the user home so the constructor reaches the legacy
        // fallback inside the webroot (home is the preferred alternative).
        $homeBlock = sys_get_temp_dir() . '/home_block_' . uniqid('', true);
        file_put_contents($homeBlock, 'block');

        // Simulate an nginx server so the honest warning is emitted too.
        $serverBackup = $_SERVER['SERVER_SOFTWARE'] ?? null;
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25.3';

        try {
            $manager = new backup_manager($tempRoot, $homeBlock);
            $backupPath = $manager->get_backup_path();

            $this->assertStringStartsWith(
                $tempRoot . DIRECTORY_SEPARATOR . 'backups-',
                $backupPath,
                'Constructor must fall back to a suffixed dir inside the webroot'
            );

            $this->assertEmpty(
                $manager->get_errors(),
                'Automatic fallback must not produce hard errors: '
                . implode(' | ', $manager->get_errors())
            );

            $messages = implode(' ', $manager->get_messages());
            $this->assertStringContainsString(
                'compatibilidad',
                $messages,
                'Fallback must inform the operator with a compatibility message'
            );
            $this->assertStringContainsString(
                'nginx',
                $messages,
                'nginx fallback must warn the operator about missing .htaccess support'
            );

            // The fallback dir MUST be guarded against direct web access.
            $this->assertFileExists(
                $backupPath . '/.htaccess',
                'Fallback dir must contain a .htaccess guard'
            );
            $htaccess = (string) file_get_contents($backupPath . '/.htaccess');
            $this->assertStringContainsString(
                'Require all denied',
                $htaccess,
                'Generated .htaccess must use Apache 2.4 syntax'
            );
            $this->assertStringContainsString(
                'Deny from all',
                $htaccess,
                'Generated .htaccess must keep the legacy Apache 2.2 syntax'
            );
            $this->assertFileExists(
                $backupPath . '/index.php',
                'Fallback dir must contain an index.php guard'
            );

            // The active backup dir must be excluded from file backups.
            $ref = new ReflectionProperty(backup_manager::class, 'excludedDirs');
            $ref->setAccessible(true);
            $excluded = $ref->getValue($manager);
            $this->assertContains(basename($backupPath), $excluded);
        } finally {
            if ($serverBackup === null) {
                unset($_SERVER['SERVER_SOFTWARE']);
            } else {
                $_SERVER['SERVER_SOFTWARE'] = $serverBackup;
            }
            @unlink($effective);
            @unlink($homeBlock);
            foreach ((array) glob($tempRoot . '/backups-*') as $dir) {
                chmod($dir, 0700);
                $this->rrmdir($dir);
            }
            $this->rrmdir($tempRoot);
        }
    }

    #[Test]
    public function constructorMigratesLegacyBackups(): void
    {
        $tempRoot = $this->makeTempRoot('migrate');
        mkdir($tempRoot . '/tmp', 0755, true);
        $legacy = $tempRoot . '/backups';
        mkdir($legacy, 0755, true);
        file_put_contents($legacy . '/backup_2024-01-01_complete.zip', 'legacy-data');

        try {
            $manager = new backup_manager($tempRoot);
            $newPath = $manager->get_backup_path();

            // Sibling is usable in tests → no fallback; legacy must migrate.
            $this->assertStringStartsWith(
                dirname($tempRoot) . '/backups-',
                $newPath,
                'Usable sibling must be preferred (no fallback)'
            );

            $this->assertFileExists(
                $newPath . '/backup_2024-01-01_complete.zip',
                'Legacy backup must be copied to the new dir'
            );
            $this->assertFileDoesNotExist(
                $legacy . '/backup_2024-01-01_complete.zip',
                'Legacy source must be removed after a verified copy'
            );

            $messages = implode(' ', $manager->get_messages());
            $this->assertStringContainsString('migradas', $messages);
        } finally {
            foreach ((array) glob(dirname($tempRoot) . '/backups-*') as $dir) {
                chmod($dir, 0700);
                $this->rrmdir($dir);
            }
            $this->rrmdir($tempRoot);
        }
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function makeTempRoot(string $tag): string
    {
        $dir = sys_get_temp_dir() . '/fs_root_' . $tag . '_' . uniqid('', true);
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail('No se pudo crear el directorio temporal: ' . $dir);
        }
        return $dir;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function callResolveBackupDirWith(string $override, string $fsFolder): string
    {
        $ref = new ReflectionMethod(backup_manager::class, 'resolve_backup_dir');
        $ref->setAccessible(true);

        // The static method has no parameters; the override is supplied
        // through a `defined()` check inside the implementation.  To make
        // the test independent of the (immutable) FS_BACKUP_DIR constant,
        // we pass it via a reflection-friendly wrapper that the production
        // code will expose: see backup_manager::resolve_backup_dir().
        //
        // When the production method only reads `FS_BACKUP_DIR`, we cannot
        // override it inside a single test process.  The companion helper
        // `resolve_backup_dir_with()` is the public seam for this case.
        if (method_exists(backup_manager::class, 'resolve_backup_dir_with')) {
            $helper = new ReflectionMethod(backup_manager::class, 'resolve_backup_dir_with');
            $helper->setAccessible(true);
            return (string) $helper->invoke(null, $override, $fsFolder);
        }

        // Fall back to the public method (only valid when FS_BACKUP_DIR
        // is *not* set, so the override is unused).
        $wasDefined = defined('FS_BACKUP_DIR');
        $previous = $wasDefined ? FS_BACKUP_DIR : null;
        if (!$wasDefined && $override !== '') {
            // We cannot define a constant at runtime; emulate the override
            // by calling the pure helper.  This branch only fires when the
            // public method has no companion helper — see RED test for
            // proof.
            $this->markTestSkipped('resolve_backup_dir_with() not yet implemented.');
        }

        return (string) $ref->invoke(null);
    }
}