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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Structural / static tests for `download_backup.php` and the
 * `process_bootstrap` `plain` mode that powers it.
 *
 * The runtime / 200-with-body path is covered end-to-end by the integration
 * harness in the plugin (deferred to sdd-verify). Here we pin the surface
 * contract: file exists, has no syntax errors, the bootstrap 'plain' mode
 * does not emit SSE headers, and the endpoint uses the plugin's CSRF guard.
 */
final class DownloadBackupScriptTest extends TestCase
{
    private const SCRIPT = FS_FOLDER . '/plugins/system_updater/download_backup.php';

    #[Test]
    public function downloadBackupScriptExists(): void
    {
        $this->assertFileExists(self::SCRIPT, 'download_backup.php must exist at the plugin root');
    }

    #[Test]
    public function downloadBackupScriptHasNoSyntaxErrors(): void
    {
        $output = [];
        $status = 0;
        exec('php -l ' . escapeshellarg(self::SCRIPT) . ' 2>&1', $output, $status);
        $this->assertSame(
            0,
            $status,
            'download_backup.php must have no syntax errors: ' . implode("\n", $output)
        );
    }

    #[Test]
    public function downloadBackupScriptUsesPlainBootstrapMode(): void
    {
        $content = (string) file_get_contents(self::SCRIPT);

        $this->assertStringContainsString(
            "system_updater_process_init(['mode' => 'plain'",
            $content,
            'download_backup.php must initialise the bootstrap in plain mode'
        );
    }

    #[Test]
    public function downloadBackupScriptEnforcesCsrfAndAuth(): void
    {
        $content = (string) file_get_contents(self::SCRIPT);

        $this->assertStringContainsString(
            'system_updater_csrf_validate(',
            $content,
            'download_backup.php must validate the CSRF token after bootstrap'
        );
        $this->assertStringContainsString(
            'csrf_rejected',
            $content,
            'download_backup.php must audit a rejected CSRF token as a SECURITY event'
        );
    }

    #[Test]
    public function downloadBackupScriptEmitsAttachmentHeadersAndReadfile(): void
    {
        $content = (string) file_get_contents(self::SCRIPT);

        $this->assertStringContainsString(
            "Content-Disposition: attachment",
            $content,
            'download_backup.php must emit Content-Disposition: attachment'
        );
        $this->assertStringContainsString(
            "Content-Type: application/octet-stream",
            $content,
            'download_backup.php must emit Content-Type: application/octet-stream'
        );
        $this->assertStringContainsString(
            'fopen(',
            $content,
            'download_backup.php must stream the file body via fopen/fread'
        );
    }

    #[Test]
    public function plainModeDoesNotEmitSseHeaders(): void
    {
        $bootstrap = (string) file_get_contents(
            FS_FOLDER . '/plugins/system_updater/lib/process_bootstrap.php'
        );

        $this->assertStringContainsString(
            "mode === 'plain'",
            $bootstrap,
            "process_bootstrap.php must implement a 'plain' mode branch"
        );
    }

    #[Test]
    public function plainModeIsUnknownSafe(): void
    {
        $bootstrap = (string) file_get_contents(
            FS_FOLDER . '/plugins/system_updater/lib/process_bootstrap.php'
        );

        // The default fallback for unknown modes must NOT silently downgrade
        // to SSE (it must 500 loudly so the operator notices the typo).
        $this->assertStringContainsString(
            "unknown mode",
            $bootstrap,
            'process_bootstrap.php must fail loud on unknown mode'
        );
    }

    #[Test]
    public function adminUpdaterTemplateLinksToDownloadBackupEndpoint(): void
    {
        $template = (string) file_get_contents(
            FS_FOLDER . '/plugins/system_updater/view/admin_updater.html.twig'
        );

        $this->assertStringContainsString(
            'download_backup.php?file=',
            $template,
            'admin_updater.html.twig must link to download_backup.php (not the legacy controller action)'
        );
        $this->assertStringContainsString(
            'su_csrf_token=',
            $template,
            'admin_updater.html.twig must append the su_csrf_token query parameter'
        );
        $this->assertStringNotContainsString(
            '&action=download_backup',
            $template,
            'admin_updater.html.twig must no longer link to the legacy controller action=download_backup'
        );
    }

    #[Test]
    public function adminUpdaterControllerHasNoDownloadBackupCase(): void
    {
        $controller = (string) file_get_contents(
            FS_FOLDER . '/plugins/system_updater/controller/admin_updater.php'
        );

        $this->assertStringNotContainsString(
            "case 'download_backup'",
            $controller,
            'admin_updater controller must not dispatch action=download_backup anymore'
        );
        $this->assertStringNotContainsString(
            'actionDownloadBackup',
            $controller,
            'admin_updater controller must not declare actionDownloadBackup() anymore'
        );
    }
}
