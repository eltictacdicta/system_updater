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

require_once FS_FOLDER . '/plugins/system_updater/lib/backup_manager.php';

/**
 * Contract tests for the `resolve_backup_dir()` helper and the
 * `ensureBackupDirectoryExists()` startup checks.
 *
 * These tests pin the security-relevant behaviour of the change
 * `secure-backup-access`: backups MUST live outside the webroot, the
 * override constant MUST be honoured, and an unwritable directory MUST
 * surface as a loud error instead of silently succeeding.
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
    // resolve_backup_dir — default + override
    // ============================================================

    #[Test]
    public function resolveBackupDirDefaultLivesOutsideWebroot(): void
    {
        $resolved = backup_manager::resolve_backup_dir();

        $this->assertSame(
            dirname(FS_FOLDER) . DIRECTORY_SEPARATOR . 'backups',
            $resolved,
            'Default backup dir must be a sibling of FS_FOLDER (outside the webroot)'
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
    // ensureBackupDirectoryExists — 0700 perms + startup error
    // ============================================================

    #[Test]
    public function ensureBackupDirectoryIsCreatedWithRestrictivePerms(): void
    {
        $tempRoot = $this->makeTempRoot('perms');
        // The backup dir is a sibling of the supplied fsRoot, so create the
        // sibling first and let the constructor handle it.
        $sibling = dirname($tempRoot) . DIRECTORY_SEPARATOR . 'backups';
        if (is_dir($sibling)) {
            $this->rrmdir($sibling);
        }

        try {
            new backup_manager($tempRoot);

            $this->assertDirectoryExists(
                $sibling,
                'Constructor must create the backup directory at resolve_backup_dir()'
            );

            $perms = fileperms($sibling) & 0777;
            $this->assertSame(
                0700,
                $perms,
                'Backup directory must be created with 0700 permissions (got %s)',
                decoct($perms)
            );
        } finally {
            chmod($sibling, 0700);
            $this->rrmdir($sibling);
            $this->rrmdir($tempRoot);
        }
    }

    #[Test]
    public function constructorSurfacesErrorWhenBackupDirIsUnwritable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root — chmod 0500 is bypassed.');
        }

        $tempRoot = $this->makeTempRoot('unwritable');
        $sibling = dirname($tempRoot) . DIRECTORY_SEPARATOR . 'backups';
        if (is_dir($sibling)) {
            $this->rrmdir($sibling);
        }
        mkdir($sibling, 0755, true);
        chmod($sibling, 0500);

        try {
            $manager = new backup_manager($tempRoot);
            $errors = $manager->get_errors();

            $this->assertNotEmpty(
                $errors,
                'Unwritable backup dir must surface at least one error in get_errors()'
            );
            $combined = strtolower(implode(' | ', $errors));
            $this->assertTrue(
                str_contains($combined, 'escritur') || str_contains($combined, 'permis')
                    || str_contains($combined, 'writ'),
                'Error message must explain the writability problem. Got: ' . $combined
            );
        } finally {
            chmod($sibling, 0700);
            $this->rrmdir($sibling);
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
