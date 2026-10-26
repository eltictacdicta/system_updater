<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/core_updater.php';

/**
 * Contract of PU-16: a core update must not write the core's planning tree
 * into the installation.
 *
 * The prune is a closed allowlist driven from a single declaration, confined
 * to the staging source that `prepareCoreSource()` populated, and it never
 * touches runtime or user data. Everything here is asserted over a temp tree
 * built in `setUp()` and removed in `tearDown()`: the suite has no database and
 * never uses the real installation.
 *
 * The expected names are repeated literally instead of read from production
 * code on purpose. A fixture derived from the implementation would shrink with
 * it and assert nothing; these literals are the requirement's own words.
 */
final class CoreUpdaterPruneTest extends TestCase
{
    /** The eight planning directories, as PU-16 states them. */
    private const PLANNING_DIRECTORIES = [
        'openspec',
        'docs',
        'tools',
        'extras',
        'odd',
        'dev-tools',
        'managed_context',
        'test_suite_analysis',
    ];

    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = rtrim(sys_get_temp_dir(), '/') . '/su_prune_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->restorePermissions($this->tempDir);
        $this->deleteDirectory($this->tempDir);
        $this->tempDir = '';

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function newUpdater(): \core_updater
    {
        return new \core_updater($this->tempDir);
    }

    /**
     * @return array{removed: array<int, string>, failed: array<string, string>}
     */
    private function prune(string $baseDir): array
    {
        $updater = $this->newUpdater();
        $method = new \ReflectionMethod($updater, 'pruneNonRuntimeDirectories');
        $method->setAccessible(true);

        $report = $method->invoke($updater, $baseDir);

        $this->assertIsArray($report, 'the prune report must be an array');
        $this->assertArrayHasKey('removed', $report);
        $this->assertArrayHasKey('failed', $report);

        return $report;
    }

    /**
     * A staging tree holding the eight planning directories, each with a file
     * inside so an empty directory cannot be removed for the wrong reason.
     */
    private function buildPlanningTree(string $base): void
    {
        foreach (self::PLANNING_DIRECTORIES as $name) {
            $dir = $base . '/' . $name;
            mkdir($dir, 0755, true);
            file_put_contents($dir . '/keep.md', 'planning content for ' . $name);
        }
    }

    /**
     * Every runtime and user-data entry PU-16 names as untouchable, plus the
     * plugin SDD artifacts that live under `plugins/`.
     *
     * @return array<string, string> path => content
     */
    private function buildRuntimeTree(string $base): array
    {
        $files = [
            '/vendor/composer.json' => '{"name":"core"}',
            '/themes/AdminLTE/view/base.html.twig' => '{{ base }}',
            '/view/css/app.css' => 'body{}',
            '/src/Core/Kernel.php' => '<?php // kernel',
            '/base/fs_model.php' => '<?php // model',
            '/model/table/fs_users.xml' => '<tabla/>',
            '/controller/login.php' => '<?php // login',
            '/images/logo.png' => 'PNG',
            '/imgs/a.png' => 'PNG',
            '/tmp/cache.php' => 'cache',
            '/backups/core.zip' => 'backup',
            '/translations/en_EN.yaml' => 'key: value',
            '/config/x.php' => '<?php // config dir',
            '/plugins/system_updater/openspec/specs/pu.md' => '# PU-16',
            '/plugins/other_plugin/openspec/specs/x.md' => '# other',
            '/.git/HEAD' => 'ref: refs/heads/master',
            '/config.php' => '<?php // operator config',
            '/.htaccess' => '# operator htaccess',
            '/index.php' => '<?php // entry',
        ];

        foreach ($files as $relative => $content) {
            $path = $base . $relative;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $content);
        }

        return array_combine(
            array_map(static fn (string $relative): string => $base . $relative, array_keys($files)),
            array_values($files)
        );
    }

    /**
     * @param array<int, string> $names
     *
     * @return array<int, string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }

    private function restorePermissions(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        @chmod($path, 0755);

        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            if (!is_link($path . '/' . $entry)) {
                $this->restorePermissions($path . '/' . $entry);
            }
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }

        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    // ------------------------------------------------------------------ tests

    /**
     * PU-16, "A fresh update removes all eight and leaves the runtime
     * directories intact": the eight go, everything else stays byte-identical,
     * and a plugin's own `openspec/` is untouched.
     */
    public function testThePruneRemovesTheEightAndLeavesRuntimeAndUserDataIntact(): void
    {
        $staging = $this->tempDir . '/staging';
        mkdir($staging, 0755, true);
        $this->buildPlanningTree($staging);
        $runtime = $this->buildRuntimeTree($staging);

        $report = $this->prune($staging);

        $this->assertSame(
            $this->sorted(self::PLANNING_DIRECTORIES),
            $this->sorted($report['removed']),
            'the removal must be driven by the eight-name allowlist'
        );
        $this->assertSame([], $report['failed'], 'nothing should fail on a writable tree');

        foreach (self::PLANNING_DIRECTORIES as $name) {
            $this->assertDirectoryDoesNotExist(
                $staging . '/' . $name,
                $name . '/ must not survive inside the staging source'
            );
        }

        foreach ($runtime as $path => $expectedContent) {
            $this->assertFileExists($path, $path . ' must survive the prune');
            $this->assertSame(
                $expectedContent,
                file_get_contents($path),
                $path . ' must survive byte-identical'
            );
        }

        $this->assertFileExists(
            $staging . '/plugins/system_updater/openspec/specs/pu.md',
            'a plugin SDD artifact under plugins/ must never be pruned'
        );
    }

    /**
     * PU-16, "A second update is a no-op": the prune is idempotent and records
     * nothing the second time.
     */
    public function testASecondRunRemovesNothingAndRecordsNothing(): void
    {
        $staging = $this->tempDir . '/staging';
        mkdir($staging, 0755, true);
        $this->buildPlanningTree($staging);
        $this->buildRuntimeTree($staging);

        $this->prune($staging);
        $second = $this->prune($staging);

        $this->assertSame([], $second['removed']);
        $this->assertSame([], $second['failed']);
        $this->assertFileExists($staging . '/vendor/composer.json');
    }

    /**
     * PU-16, "An absent directory does not fail the update": a source tree that
     * never carried the names is not an error, and a missing name cannot become
     * an excuse to remove something else.
     */
    public function testAbsentDirectoriesAreSilentNoOps(): void
    {
        $staging = $this->tempDir . '/runtime_only';
        mkdir($staging, 0755, true);
        $runtime = $this->buildRuntimeTree($staging);

        $report = $this->prune($staging);

        $this->assertSame([], $report['removed']);
        $this->assertSame([], $report['failed']);
        foreach (array_keys($runtime) as $path) {
            $this->assertFileExists($path);
        }
    }

    /**
     * PU-16, "A path that resolves outside the staging directory is refused":
     * a symlink named like an allowlisted directory is refused and reported,
     * never traversed, so the target outside the staging tree survives.
     */
    public function testASymlinkPointingOutsideTheStagingTreeIsRefusedNotTraversed(): void
    {
        $staging = $this->tempDir . '/staging';
        mkdir($staging, 0755, true);
        $this->buildPlanningTree($staging);

        $outside = $this->tempDir . '/outside';
        mkdir($outside, 0755, true);
        file_put_contents($outside . '/secret.txt', 'must survive');

        // The allowlisted name is the *link*, not a real directory.
        $this->deleteDirectory($staging . '/docs');
        $link = $staging . '/docs';
        if (!@symlink($outside, $link)) {
            $this->markTestSkipped(
                'this host cannot create a symlink under ' . $staging
                . ', so PU-16\'s escaping-candidate scenario cannot be produced'
            );
        }

        $report = $this->prune($staging);

        $this->assertArrayHasKey(
            'docs',
            $report['failed'],
            'a symlinked candidate must be reported as refused'
        );
        $this->assertNotContains('docs', $report['removed']);
        $this->assertSame(
            'must survive',
            file_get_contents($outside . '/secret.txt'),
            'the prune must never delete through a symlink'
        );
        $this->assertTrue(is_link($link), 'the link itself must be left alone, not resolved and removed');
        $this->assertSame(
            $this->sorted(array_diff(self::PLANNING_DIRECTORIES, ['docs'])),
            $this->sorted($report['removed']),
            'a refused candidate must not stop the remaining names'
        );
    }

    /**
     * PU-16, "An unremovable directory is reported and the update still
     * completes": the survivor is named, the other seven are still attempted,
     * and nothing throws.
     *
     * `chmod 0555` does not restrict a privileged process, so the precondition
     * is observed rather than assumed and the case is skipped visibly when the
     * host cannot produce it.
     */
    public function testAnUnremovableDirectoryIsReportedAndTheRestStillAttempted(): void
    {
        $staging = $this->tempDir . '/staging';
        mkdir($staging, 0755, true);
        $this->buildPlanningTree($staging);

        $blocked = $staging . '/docs';
        @chmod($blocked, 0555);

        if (is_writable($blocked)) {
            @chmod($blocked, 0755);
            $this->markTestSkipped(
                'cannot make ' . $blocked . ' read-only for uid '
                . (function_exists('posix_geteuid') ? (string) posix_geteuid() : 'unknown')
                . ': a privileged process ignores the 0555 mode, so PU-16\'s unremovable-directory'
                . ' scenario cannot be produced on this host'
            );
        }

        $report = $this->prune($staging);

        $this->assertSame(['docs'], array_keys($report['failed']));
        $this->assertNotSame('', (string) $report['failed']['docs'], 'the failure must carry a reason');
        $this->assertSame(
            $this->sorted(array_diff(self::PLANNING_DIRECTORIES, ['docs'])),
            $this->sorted($report['removed']),
            'the remaining names must still be attempted'
        );
        $this->assertDirectoryExists($blocked, 'the surviving directory must still be there');
        $this->assertDirectoryDoesNotExist($staging . '/extras');
    }

    /**
     * The closed list is enforced on the name itself: a single path segment,
     * no separator and no traversal. This is the guard that keeps the
     * allowlist from becoming a path expression.
     */
    public function testCandidateNamesMustBeASinglePathSegment(): void
    {
        $updater = $this->newUpdater();
        $method = new \ReflectionMethod($updater, 'isPrunableDirectoryName');
        $method->setAccessible(true);

        foreach (['openspec', 'docs', 'dev-tools', 'test_suite_analysis'] as $valid) {
            $this->assertTrue($method->invoke($updater, $valid), $valid . ' is a plain directory name');
        }

        foreach (['../etc', 'foo/bar', 'foo\\bar', '..', '.', '', '/absolute', 'a/../b'] as $invalid) {
            $this->assertFalse($method->invoke($updater, $invalid), 'must be rejected: ' . var_export($invalid, true));
        }
    }

    /**
     * PU-16, "the installation root never is": the prune is confined to what
     * `prepareCoreSource()` populated. Pinned structurally because a single
     * call site passing `$this->rootPath` would delete an operator's tree.
     */
    public function testNoCallSiteEverPrunesTheInstallationRoot(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/lib/core_updater.php');

        $this->assertSame(
            1,
            preg_match_all('/\$this->pruneNonRuntimeDirectories\(/', $source),
            'the primitive must have exactly one caller (the staging wrapper)'
        );
        $this->assertSame(
            2,
            preg_match_all('/\$this->pruneStagingSource\(/', $source),
            'PU-16: both source branches must be pruned — the ZIP extraction and the git clone'
        );
        $this->assertSame(
            0,
            preg_match_all('/\$this->prune(?:NonRuntimeDirectories|StagingSource)\(\s*\$this->rootPath/', $source),
            'the installation root must never be pruned'
        );
        $this->assertSame(
            0,
            preg_match_all('/\$this->prune(?:NonRuntimeDirectories|StagingSource)\([^)]*FS_FOLDER/', $source),
            'FS_FOLDER is the installation root; it must never be pruned'
        );
    }
}
