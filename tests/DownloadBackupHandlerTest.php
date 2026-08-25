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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/system_updater/lib/download_backup_handler.php';
require_once FS_FOLDER . '/plugins/system_updater/lib/backup_manager.php';

/**
 * Unit tests for the pure helper functions that power `download_backup.php`.
 *
 * The endpoint itself is a thin top-level script (auth + readfile); the
 * security-relevant decisions (path containment, basename sanitisation,
 * missing-file semantics, audit logging) live in the helper functions and
 * are tested here directly so a single PHP subprocess is not required.
 */
final class DownloadBackupHandlerTest extends TestCase
{
    /**
     * @var string
     */
    private string $tempDir = '';

    /**
     * @var string
     */
    private string $backupDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Construct a layout where FS_ROOT = tempDir and the backup dir is
        // its sibling (matches the new secure-backup-access layout).
        $parent = sys_get_temp_dir() . '/fs_dl_test_' . uniqid('', true);
        $this->tempDir = $parent . '/fsroot';
        $this->backupDir = $parent . '/backups';
        if (!mkdir($this->tempDir, 0755, true) || !mkdir($this->backupDir, 0700, true)) {
            $this->fail('No se pudo crear el layout temporal: ' . $parent);
        }

        // Seed a real backup file and a hostile symlink escape.
        file_put_contents($this->backupDir . '/real.sql.gz', 'real contents');
        file_put_contents($this->tempDir . '/outside.txt', 'outside data');
        if (!symlink($this->tempDir . '/outside.txt', $this->backupDir . '/escape.sql.gz')) {
            $this->markTestSkipped('No se pudo crear el symlink de prueba.');
        }
    }

    protected function tearDown(): void
    {
        $this->rrmdir(dirname($this->tempDir));
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    // ============================================================
    // system_updater_resolve_backup_file — happy path
    // ============================================================

    #[Test]
    public function resolveBackupFileAcceptsLegitimateFilename(): void
    {
        $resolution = system_updater_resolve_backup_file('real.sql.gz', $this->backupDir);

        $this->assertSame('ok', $resolution['status']);
        $this->assertSame('real.sql.gz', $resolution['name']);
        $this->assertSame(
            $this->backupDir . DIRECTORY_SEPARATOR . 'real.sql.gz',
            $resolution['real_path']
        );
    }

    #[Test]
    public function resolveBackupFileRejectsEmptyNameAsInvalid(): void
    {
        $resolution = system_updater_resolve_backup_file('', $this->backupDir);

        $this->assertSame('invalid', $resolution['status']);
        $this->assertFalse($resolution['real_path']);
    }

    // ============================================================
    // system_updater_resolve_backup_file — path traversal (3 cases)
    // ============================================================

    /**
     * @return array<string, array{0: string}>
     */
    public static function traversalNameProvider(): array
    {
        return [
            'dotdot segments' => ['../../../../etc/passwd'],
            'absolute path' => ['/etc/shadow'],
            'symlink escape to parent' => ['escape.sql.gz'],
            'only dotdot' => ['..'],
            'only dot' => ['.'],
        ];
    }

    #[Test]
    #[DataProvider('traversalNameProvider')]
    public function resolveBackupFileRejectsTraversalAttempts(string $name): void
    {
        $resolution = system_updater_resolve_backup_file($name, $this->backupDir);

        $this->assertContains(
            $resolution['status'],
            ['traversal', 'invalid', 'missing_file'],
            'Traversal attempt must NOT resolve to ok. Got: ' . json_encode($resolution)
        );
        $this->assertNotSame(
            'ok',
            $resolution['status'],
            'Traversal must never produce a real_path. Input: ' . $name
        );
    }

    #[Test]
    public function resolveBackupFileRejectsSisterDirPrefixAttack(): void
    {
        // Create a sibling dir whose name starts with the backup dir's name
        // (e.g. /tmp/backups-evil/). A naive str_starts_with without the
        // trailing separator would let /tmp/backups-evil/... be served.
        $parent = dirname($this->backupDir);
        $sister = $parent . DIRECTORY_SEPARATOR . basename($this->backupDir) . '-evil';
        if (is_dir($sister)) {
            $this->rrmdir($sister);
        }
        mkdir($sister, 0755, true);
        file_put_contents($sister . '/leak.txt', 'must not leak');

        try {
            // The user requests a path that realpath() would resolve inside
            // the evil sister.  We use an absolute path inside that dir
            // and rely on basename() to strip the prefix; the result must
            // be a basename that does not exist in the legitimate backup
            // dir, so the resolution is "missing_file" (not "ok").
            $resolution = system_updater_resolve_backup_file(
                $sister . DIRECTORY_SEPARATOR . 'leak.txt',
                $this->backupDir
            );

            $this->assertNotSame(
                'ok',
                $resolution['status'],
                'Absolute path into a sister dir must NOT resolve to ok'
            );
        } finally {
            $this->rrmdir($sister);
        }
    }

    // ============================================================
    // system_updater_resolve_backup_file — missing file
    // ============================================================

    #[Test]
    public function resolveBackupFileReportsMissingFile(): void
    {
        $resolution = system_updater_resolve_backup_file('does-not-exist.sql.gz', $this->backupDir);

        $this->assertSame('missing_file', $resolution['status']);
        $this->assertSame('does-not-exist.sql.gz', $resolution['name']);
        $this->assertFalse($resolution['real_path']);
    }

    // ============================================================
    // system_updater_resolve_backup_file — shape pre-validation
    // ============================================================

    #[Test]
    public function resolveBackupFileRejectsTraversalByShapeAsSecurity(): void
    {
        // A value with directory separators is an attempted escape by SHAPE:
        // it must be classified as traversal (SECURITY + 400 upstream), NOT
        // silently reduced by basename() to a missing file (404, unlogged).
        $resolution = system_updater_resolve_backup_file('../evil.sql.gz', $this->backupDir);

        $this->assertSame('traversal', $resolution['status']);
        $this->assertSame('../evil.sql.gz', $resolution['name']);
        $this->assertFalse($resolution['real_path']);
    }

    #[Test]
    public function resolveBackupFileRejectsWindowsSeparatorAsSecurity(): void
    {
        $resolution = system_updater_resolve_backup_file('..\\evil.sql.gz', $this->backupDir);

        $this->assertSame('traversal', $resolution['status']);
        $this->assertFalse($resolution['real_path']);
    }

    // ============================================================
    // system_updater_resolve_backup_file — canonical backup dir
    // ============================================================

    #[Test]
    public function resolveBackupFileAcceptsLegitimateFileWithNonCanonicalBackupDir(): void
    {
        // The resolved backup dir may be non-canonical (symlink parent, `..`
        // components in an FS_BACKUP_DIR override). The containment check
        // must canonicalize it first or legitimate files are falsely
        // rejected.
        $alias = $this->backupDir . '/alias';
        if (!symlink($this->backupDir, $alias)) {
            $this->markTestSkipped('No se pudo crear el symlink de directorio de prueba.');
        }

        try {
            $resolution = system_updater_resolve_backup_file('real.sql.gz', $alias);

            $this->assertSame('ok', $resolution['status']);
            $this->assertSame(
                $this->backupDir . DIRECTORY_SEPARATOR . 'real.sql.gz',
                $resolution['real_path']
            );
        } finally {
            @unlink($alias);
        }
    }

    #[Test]
    public function resolveBackupFileReportsMissingWhenBackupDirDoesNotExist(): void
    {
        $resolution = system_updater_resolve_backup_file(
            'real.sql.gz',
            $this->backupDir . '/does-not-exist'
        );

        $this->assertSame('missing_file', $resolution['status']);
        $this->assertFalse($resolution['real_path']);
    }

    // ============================================================
    // system_updater_record_download_audit — appends to debug log
    // ============================================================

    #[Test]
    public function recordDownloadAuditAppendsToDebugLog(): void
    {
        $logDir = (defined('FS_FOLDER') ? FS_FOLDER : dirname(dirname(dirname(__DIR__))))
            . '/tmp';
        $logFile = $logDir . '/system_updater_debug.log';

        $previousSize = is_file($logFile) ? (int) filesize($logFile) : 0;
        $previousContents = is_file($logFile) ? (string) file_get_contents($logFile) : '';

        system_updater_record_download_audit(
            'DOWNLOAD',
            'admin',
            'real.sql.gz',
            $this->backupDir . '/real.sql.gz',
            13,
            '127.0.0.1',
            null
        );

        $this->assertFileExists($logFile, 'debug log file must exist after audit call');
        $newContents = (string) file_get_contents($logFile);
        $appended = substr($newContents, $previousSize);
        $this->assertNotEmpty($appended, 'audit call must append at least one new byte');

        $this->assertStringContainsString(
            'BACKUP_DOWNLOAD',
            $appended,
            'audit line must use the BACKUP_DOWNLOAD tag'
        );
        $this->assertStringContainsString(
            'admin',
            $appended,
            'audit line must include the user nick'
        );
        $this->assertStringContainsString(
            'real.sql.gz',
            $appended,
            'audit line must include the file basename'
        );
        $this->assertStringContainsString(
            '127.0.0.1',
            $appended,
            'audit line must include the remote IP'
        );
    }

    #[Test]
    public function recordDownloadAuditIncludesSecurityNoteForRejection(): void
    {
        $logFile = (defined('FS_FOLDER') ? FS_FOLDER : dirname(dirname(dirname(__DIR__))))
            . '/tmp'
            . '/system_updater_debug.log';
        $previousSize = is_file($logFile) ? (int) filesize($logFile) : 0;

        system_updater_record_download_audit(
            'SECURITY',
            'anonymous',
            '../../etc/passwd',
            $this->backupDir,
            0,
            '203.0.113.7',
            'path_traversal_rejected:..'
        );

        $appended = substr((string) file_get_contents($logFile), $previousSize);
        $this->assertStringContainsString('SECURITY', $appended);
        $this->assertStringContainsString('path_traversal_rejected', $appended);
        $this->assertStringContainsString('203.0.113.7', $appended);
    }
}
