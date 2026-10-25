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

    // ============================================================
    // BA-01 / CSRF-01 — the guard runs first, the token decides second
    // ============================================================

    /**
     * The plugin token is a CSRF control, never an identity, so the session
     * guard has to answer *before* the token is even read.
     *
     * A status-code assertion cannot prove the order, and the task of proving
     * it is worth stating precisely because both failing orders are plausible:
     * a **403** proves the guard already passed (the token check produced it),
     * while a **401** is consistent with either order — the guard could have
     * denied, or the token could have been read, rejected as unauthenticated
     * and then denied. This test therefore asserts the *call* order in the
     * script and, in the next case, that the code after the guard is
     * unreachable when the guard denies.
     */
    #[Test]
    public function guardRunsBeforeThePluginTokenCheck(): void
    {
        $content = (string) file_get_contents(self::SCRIPT);

        $guardAt = strpos($content, 'system_updater_process_init(');
        $readAt = strpos($content, 'system_updater_csrf_read_from_request(');
        $validateAt = strpos($content, 'system_updater_csrf_validate(');

        $this->assertNotFalse($guardAt, 'premise: the script initialises the bootstrap');
        $this->assertNotFalse($readAt, 'premise: the script reads the plugin token');
        $this->assertNotFalse($validateAt, 'premise: the script validates the plugin token');

        $this->assertLessThan(
            $readAt,
            $guardAt,
            'the bootstrap — which carries the session guard — must run before the token is read'
        );
        $this->assertLessThan(
            $validateAt,
            $guardAt,
            'the session guard must run before the token validation decides anything'
        );

        // The bootstrap is the guard for every action except the three that the
        // AUTH-05 contract keeps on the plugin token alone.
        $bootstrap = (string) file_get_contents(
            FS_FOLDER . '/plugins/system_updater/lib/process_bootstrap.php'
        );

        $this->assertStringContainsString(
            '$sessionId = system_updater_require_authenticated_session();',
            $bootstrap,
            'the bootstrap must require an authenticated session'
        );

        preg_match_all('/\$action === \'([^\']+)\'/', $bootstrap, $matches);
        $this->assertSame(
            ['start', 'begin', 'chunk'],
            array_values(array_unique($matches[1] ?? [])),
            'exactly start/begin/chunk may skip the session guard; any other action is guarded'
        );
    }

    /**
     * AUTH-04 ordering scenario, asserted behaviourally.
     *
     * `download_backup.php` reads the token on the line after the bootstrap
     * returns, so "the guard ran first" is equivalent to "the bootstrap does
     * not return when the guard denies". This runs the real guard under the
     * CLI SAPI in a throwaway install root: no session, no cookies, no
     * database, no config.php. If the guard denied correctly, the sentinel
     * line never executes; that is the sentinel callable of the task, spelled
     * as a line of PHP the interpreter cannot skip.
     */
    #[Test]
    public function aDeniedRequestNeverReachesTheTokenCheck(): void
    {
        if (!function_exists('exec')) {
            $this->markTestSkipped('exec() is unavailable, so the ordering probe cannot run.');
        }

        $root = sys_get_temp_dir() . '/su_order_' . uniqid('', true);
        $this->assertTrue(
            mkdir($root . '/sessions', 0700, true),
            'premise: a throwaway install root'
        );

        try {
            $probe = $root . '/probe.php';
            $guardFile = FS_FOLDER . '/plugins/system_updater/lib/session_auth.php';

            $code = '<?php' . "\n"
                . 'define("FS_FOLDER", ' . var_export($root, true) . ');' . "\n"
                . 'ini_set("session.save_path", ' . var_export($root . '/sessions', true) . ');' . "\n"
                . '$_SERVER["REQUEST_URI"] = "/plugins/system_updater/download_backup.php";' . "\n"
                . '$_COOKIE = [];' . "\n"
                . 'require ' . var_export($guardFile, true) . ';' . "\n"
                . 'system_updater_require_authenticated_session();' . "\n"
                . 'echo "TOKEN_CHECK_REACHED";' . "\n";

            file_put_contents($probe, $code);

            $stdoutFile = $root . '/stdout.txt';
            $stderrFile = $root . '/stderr.txt';
            $ignored = [];
            $status = 0;

            exec(
                escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe)
                . ' > ' . escapeshellarg($stdoutFile)
                . ' 2> ' . escapeshellarg($stderrFile),
                $ignored,
                $status
            );

            $stdout = is_file($stdoutFile) ? (string) file_get_contents($stdoutFile) : '';
            $stderr = is_file($stderrFile) ? (string) file_get_contents($stderrFile) : '';

            $this->assertSame(1, $status, 'a denied request exits non-zero: ' . $stdout . $stderr);
            $this->assertStringNotContainsString(
                'TOKEN_CHECK_REACHED',
                $stdout,
                'the guard denied, so the line that reads the token must never execute'
            );
            $this->assertSame('', trim($stdout), 'a CLI denial writes nothing to stdout');
            $this->assertStringContainsString(
                SYSTEM_UPDATER_GUARD_DENIAL,
                $stderr,
                'the refusal on stderr is the same text the HTTP transports emit'
            );
        } finally {
            $this->rrmdir($root);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
