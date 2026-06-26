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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once FS_FOLDER . '/plugins/system_updater/lib/backup_manager.php';

/**
 * Contract tests for the hostname-aware backup filename feature.
 *
 * Covers three concerns:
 *  1. The pure helpers `slugify_host` and `resolve_host_slug` (via reflection).
 *  2. The default name of `create_backup_with_progress`, `create_database_backup`
 *     and `create_files_backup` (default = `<host-slug>_<timestamp>`).
 *  3. Regression: legacy `backup_YYYY-MM-DD_*` files remain listed and
 *     groupable by `list_backups_grouped()`.
 */
#[CoversClass(backup_manager::class)]
class BackupManagerFilenameTest extends TestCase
{
    /**
     * @var array<string, string|null>
     */
    private array $serverBackup = [];

    /**
     * @var string
     */
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = [
            'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? null,
            'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? null,
        ];
        unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME']);

        $this->tempDir = sys_get_temp_dir() . '/fs_filename_test_' . uniqid('', true);
        if (!mkdir($this->tempDir, 0755, true) && !is_dir($this->tempDir)) {
            $this->fail('No se pudo crear el directorio temporal: ' . $this->tempDir);
        }
        // Seed at least one file so create_files_backup has something to include.
        file_put_contents($this->tempDir . '/seed.txt', 'seed');
        if (!is_dir($this->tempDir . '/sub')) {
            mkdir($this->tempDir . '/sub', 0755, true);
        }
        file_put_contents($this->tempDir . '/sub/file.txt', 'content');
    }

    protected function tearDown(): void
    {
        foreach (['HTTP_HOST', 'SERVER_NAME'] as $key) {
            if ($this->serverBackup[$key] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $this->serverBackup[$key];
            }
        }

        if (is_dir($this->tempDir)) {
            // Clean any backup artifacts the tests may have left behind.
            $backupPath = $this->tempDir . '/backups';
            if (is_dir($backupPath)) {
                foreach (array_diff(scandir($backupPath), ['.', '..']) as $entry) {
                    $entryPath = $backupPath . '/' . $entry;
                    if (is_dir($entryPath)) {
                        $this->rrmdir($entryPath);
                    } else {
                        @unlink($entryPath);
                    }
                }
                @rmdir($backupPath);
            }
            $this->rrmdir($this->tempDir);
        }

        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * @param array<int, mixed> $args
     */
    private function callPrivate(object $obj, string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    /**
     * @param array<int, mixed> $args
     */
    private function callPrivateStatic(string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod(backup_manager::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    // ============================================================
    // slugify_host — pure helper, exercised via reflection
    // ============================================================

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function slugifyHostProvider(): array
    {
        $longInput = str_repeat('a', 70);
        $longInputWithLeadingDashes = '---' . str_repeat('a', 70);
        return [
            'normal domain becomes lowercase with dashes' => ['Example.COM', 'example-com'],
            'subdomain collapses dots to dashes' => ['sub.example.com', 'sub-example-com'],
            'ip literal keeps digits' => ['192.168.1.1', '192-168-1-1'],
            'host with port keeps colon as separator' => ['example.com:8080', 'example-com-8080'],
            'uppercase letters become lowercase' => ['MyHost', 'myhost'],
            'mixed multi-level subdomain' => ['Sub.Example.COM', 'sub-example-com'],
            'consecutive dashes collapse' => ['a---b', 'a-b'],
            'leading dashes trimmed' => ['---foo', 'foo'],
            'trailing dashes trimmed' => ['foo---', 'foo'],
            'leading and trailing dashes trimmed' => ['-foo-', 'foo'],
            'empty input becomes unknown' => ['', 'unknown'],
            'only special chars becomes unknown' => ['!!!@@@###', 'unknown'],
            'special chars replaced with dash and trimmed' => ['foo@bar!', 'foo-bar'],
            'cap 63 chars after trim' => [$longInput, str_repeat('a', 63)],
            'cap 63 after trim of leading dashes' => [$longInputWithLeadingDashes, str_repeat('a', 63)],
            'localhost preserved' => ['localhost', 'localhost'],
            'underscore replaced with dash' => ['my_host', 'my-host'],
        ];
    }

    #[Test]
    #[DataProvider('slugifyHostProvider')]
    public function slugifyHostProducesExpectedSlug(string $raw, string $expected): void
    {
        $this->assertSame(
            $expected,
            $this->callPrivateStatic('slugify_host', [$raw]),
            "slugify_host(" . var_export($raw, true) . ") should produce the expected slug"
        );
    }

    // ============================================================
    // resolve_host_slug — chain HTTP_HOST → SERVER_NAME → gethostname() → unknown
    // ============================================================

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: string}>
     */
    public static function resolveHostSlugProvider(): array
    {
        return [
            'http_host wins' => ['example.com', null, 'example-com'],
            'falls back to server_name when http_host missing' => [null, 'example.org', 'example-org'],
            'falls back to server_name when http_host invalid' => ['', 'example.org', 'example-org'],
            'rejects host injection falls through to server_name' => [
                '../../etc/passwd',
                'backup.example.com',
                'backup-example-com',
            ],
            'rejects too-long host (>253)' => [str_repeat('a', 254), 'fallback.example.com', 'fallback-example-com'],
            'rejects host with slash' => ['foo/bar', 'clean.example.com', 'clean-example-com'],
            'rejects empty http_host' => ['', 'srv.example.com', 'srv-example-com'],
            'localhost passthrough' => ['localhost', null, 'localhost'],
            'http_host with port is sanitized' => ['example.com:8080', null, 'example-com-8080'],
            'http_host subdomain multi-level' => ['a.b.c.example.com', null, 'a-b-c-example-com'],
            'ip literal in http_host' => ['192.168.1.1', null, '192-168-1-1'],
            'rejects host with space' => ['foo bar', 'clean.example.com', 'clean-example-com'],
            'rejects host with unsafe chars falls through' => [
                'evil<host>',
                'clean.example.com',
                'clean-example-com',
            ],
        ];
    }

    #[Test]
    #[DataProvider('resolveHostSlugProvider')]
    public function resolveHostSlugResolvesChain(?string $httpHost, ?string $serverName, string $expected): void
    {
        if ($httpHost !== null) {
            $_SERVER['HTTP_HOST'] = $httpHost;
        }
        if ($serverName !== null) {
            $_SERVER['SERVER_NAME'] = $serverName;
        }
        $manager = new backup_manager($this->tempDir);
        $this->assertSame(
            $expected,
            $this->callPrivate($manager, 'resolve_host_slug'),
            'resolve_host_slug should honour the chain HTTP_HOST -> SERVER_NAME -> gethostname() -> unknown'
        );
    }

    #[Test]
    public function resolveHostSlugFallsBackToSystemHostnameWhenNoServerVars(): void
    {
        // HTTP_HOST and SERVER_NAME both empty (fail the {1,253} regex).
        $_SERVER['HTTP_HOST'] = '';
        $_SERVER['SERVER_NAME'] = '';
        $manager = new backup_manager($this->tempDir);
        $result = $this->callPrivate($manager, 'resolve_host_slug');
        $systemHostname = gethostname();
        $expected = $this->callPrivateStatic('slugify_host', [$systemHostname === false ? '' : $systemHostname]);
        $this->assertSame($expected, $result);
        $this->assertNotSame('', $result, 'fallback chain should not return an empty slug');
    }

    // ============================================================
    // create_database_backup default name
    // ============================================================

    #[Test]
    public function createDatabaseBackupDefaultUsesHostSlug(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $manager = new backup_manager($this->tempDir);
        $result = $manager->create_database_backup('');
        $this->assertTrue(
            $result['success'] ?? false,
            'create_database_backup should succeed; errors: ' . implode(' | ', $manager->get_errors())
        );
        $this->assertMatchesRegularExpression(
            '/^example-com_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql\.gz$/',
            $result['file'] ?? '',
            'default database backup filename should be <host-slug>_<timestamp>.sql.gz'
        );
    }

    // ============================================================
    // create_files_backup default name
    // ============================================================

    #[Test]
    public function createFilesBackupDefaultUsesHostSlug(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $manager = new backup_manager($this->tempDir);
        $result = $manager->create_files_backup('');
        $this->assertTrue(
            $result['success'] ?? false,
            'create_files_backup should succeed; errors: ' . implode(' | ', $manager->get_errors())
        );
        $this->assertMatchesRegularExpression(
            '/^example-com_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/',
            $result['file'] ?? '',
            'default files backup filename should be <host-slug>_<timestamp>.zip'
        );
    }

    // ============================================================
    // create_backup_with_progress default name + custom override
    // ============================================================

    #[Test]
    public function createBackupWithProgressDefaultUsesHostSlug(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $manager = new backup_manager($this->tempDir);
        $result = $manager->create_backup_with_progress('');
        $this->assertNotNull(
            $result['complete'] ?? null,
            'create_backup_with_progress should always return a complete key; errors: '
                . implode(' | ', $manager->get_errors())
        );
        $this->assertMatchesRegularExpression(
            '/^example-com_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/',
            $result['complete']['backup_name'] ?? '',
            'unified base name should be <host-slug>_<timestamp>'
        );
    }

    #[Test]
    public function createBackupWithProgressCustomNameIsPreservedVerbatim(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $manager = new backup_manager($this->tempDir);
        $result = $manager->create_backup_with_progress('manual-backup');
        $this->assertSame(
            'manual-backup',
            $result['complete']['backup_name'] ?? null,
            'non-empty customName must be preserved verbatim (no slug, no timestamp suffix)'
        );
    }

    // ============================================================
    // Regression: legacy backup_YYYY-MM-DD_* files remain listed
    // ============================================================

    #[Test]
    public function listBackupsGroupedIncludesLegacyBackupCompleteFiles(): void
    {
        $backupPath = $this->tempDir . '/backups';
        mkdir($backupPath, 0755, true);
        $legacyComplete = 'backup_2024-01-15_10-30-00_complete.zip';
        $legacyDb = 'backup_2024-01-15_10-30-00_db.sql.gz';
        $legacyFiles = 'backup_2024-01-15_10-30-00_files.zip';
        file_put_contents($backupPath . '/' . $legacyComplete, 'fake complete zip');
        file_put_contents($backupPath . '/' . $legacyDb, 'fake db dump');
        file_put_contents($backupPath . '/' . $legacyFiles, 'fake files zip');

        $manager = new backup_manager($this->tempDir);
        $grouped = $manager->list_backups_grouped();

        $foundGroup = null;
        foreach ($grouped as $group) {
            if (($group['base_name'] ?? '') === 'backup_2024-01-15_10-30-00') {
                $foundGroup = $group;
                break;
            }
        }
        $this->assertNotNull($foundGroup, 'legacy backup group should be present in list_backups_grouped()');
        $this->assertNotNull($foundGroup['complete'] ?? null, 'legacy complete file should be linked to its group');
        $this->assertSame($legacyComplete, $foundGroup['complete']['name']);
        $this->assertNotNull($foundGroup['database'] ?? null, 'legacy db file should be linked to its group');
        $this->assertSame($legacyDb, $foundGroup['database']['name']);
        $this->assertNotNull($foundGroup['files'] ?? null, 'legacy files file should be linked to its group');
        $this->assertSame($legacyFiles, $foundGroup['files']['name']);
        $this->assertTrue($foundGroup['can_restore_complete'] ?? false);
    }

    // ============================================================
    // Class constants: DNS label and RFC 1035 host limits
    // ============================================================

    #[Test]
    public function hostSlugTypeConstantsHaveExpectedValues(): void
    {
        $this->assertSame(
            63,
            backup_manager::HOST_SLUG_MAX_LEN,
            'HOST_SLUG_MAX_LEN must equal 63 (DNS label limit)'
        );
        $this->assertSame(
            253,
            backup_manager::HOST_HEADER_MAX_LEN,
            'HOST_HEADER_MAX_LEN must equal 253 (RFC 1035 total host length)'
        );
    }
}
