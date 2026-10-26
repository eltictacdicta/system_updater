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
     * PU-16, "MUST NOT delete through a symlink": a link found *inside* a
     * candidate is unlinked as a link, never entered. The candidate's own
     * refusal rule is not enough — the recursion is where a tree gets deleted
     * from the wrong filesystem.
     */
    public function testALinkNestedInsideACandidateIsNeverTraversed(): void
    {
        $staging = $this->tempDir . '/staging';
        mkdir($staging . '/openspec', 0755, true);
        file_put_contents($staging . '/openspec/keep.md', 'planning');

        $outside = $this->tempDir . '/outside_nested';
        mkdir($outside, 0755, true);
        file_put_contents($outside . '/precious.txt', 'must survive');

        if (!@symlink($outside, $staging . '/openspec/link')) {
            $this->markTestSkipped(
                'this host cannot create the nested symlink under ' . $staging
                . ', so PU-16\'s "no deletion through a link" clause cannot be exercised'
            );
        }

        $report = $this->prune($staging);

        $this->assertContains('openspec', $report['removed']);
        $this->assertFileExists(
            $outside . '/precious.txt',
            'a link inside a candidate must be unlinked, never entered: the tree outside the staging'
            . ' directory lost a file'
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
     * PU-16, "A path that resolves outside the staging directory is refused":
     * the refusal must be a decision about the resolved *path*, not about the
     * separator the host's `realpath()` happens to answer with.
     *
     * `realpath()` returns `\`-separated paths on Windows and `/`-separated
     * paths everywhere else, so a containment test assembled from one
     * hard-coded separator mis-judges every legitimate child on the other
     * host. The Windows shape cannot be produced on this host — the filesystem
     * hands back POSIX paths whatever we feed it — so the same helper is driven
     * with both separator shapes as inputs, and both must reach the same
     * verdict for the same directory relationship.
     */
    public function testTheContainmentCheckGivesTheSameVerdictForBothSeparatorShapes(): void
    {
        $updater = $this->newUpdater();
        $method = new \ReflectionMethod($updater, 'isPathInsideBase');
        $method->setAccessible(true);

        $cases = [
            // inside: the two shapes of the identical relationship
            'posix child' => ['/srv/staging', '/srv/staging/openspec', true],
            'windows child' => ['C:\\stage', 'C:\\stage\\openspec', true],
            'windows child, mixed separators' => ['C:\\stage', 'C:\\stage/openspec', true],
            'posix child, base with trailing separator' => ['/srv/staging/', '/srv/staging/docs', true],
            'windows child, base with trailing separator' => ['C:\\stage\\', 'C:\\stage\\docs', true],

            // outside: refused in both shapes
            'posix sibling sharing the base as a prefix' => ['/srv/stage', '/srv/stage-extra/docs', false],
            'windows sibling sharing the base as a prefix' => ['C:\\stage', 'C:\\stage-extra\\docs', false],
            'posix parent' => ['/srv/staging', '/srv', false],
            'windows parent' => ['C:\\stage', 'C:\\', false],
            'windows sibling on another drive' => ['C:\\stage', 'D:\\stage\\openspec', false],
            'posix sibling with a longer name' => ['/srv/staging', '/srv/staging2/docs', false],
            'windows sibling with a longer name' => ['C:\\stage', 'C:\\stage2\\docs', false],

            // degenerate inputs are refused, never accepted by accident
            'empty base' => ['', '/srv/staging/docs', false],
            'empty candidate' => ['/srv/staging', '', false],
        ];

        foreach ($cases as $label => [$base, $candidate, $expected]) {
            $this->assertSame(
                $expected,
                (bool) $method->invoke($updater, $base, $candidate),
                'containment verdict for ' . $label . ': base=' . $base . ' candidate=' . $candidate
            );
        }

        // Anti-vacuity: the helper must be the decision the prune makes. A
        // helper that exists while the call site keeps its own separator-bound
        // prefix test would leave this suite green and the bug in place.
        $source = (string) file_get_contents(dirname(__DIR__) . '/lib/core_updater.php');

        $this->assertSame(
            1,
            preg_match_all('/!\$this->isPathInsideBase\(/', $source),
            'pruneNonRuntimeDirectories() must decide containment through the separator-agnostic helper'
        );
    }

    /**
     * PU-16, "the prune belongs to extractZipSafe()": a branch archive — the
     * shape every real update has — is pruned one level down, in the directory
     * the copy actually reads.
     *
     * This is the case a naive prune of the outer destination passes without
     * doing anything: the eight live in `fs-framework-master/`, so a prune that
     * only looks at the extraction root finds none of them, removes nothing and
     * reports success.
     */
    public function testABranchArchiveIsPrunedInTheDirectoryTheCopyReads(): void
    {
        $zip = $this->buildZip('branch', $this->branchArchiveEntries());
        $destination = $this->tempDir . '/extract';

        $this->assertTrue($this->extractZipSafe($zip, $destination), 'the fixture archive must extract');
        $this->assertArchiveCarriesPlanningTree($zip, 'fs-framework-master/');

        $source = $destination . '/fs-framework-master';
        $this->assertDirectoryExists($source, 'a branch archive extracts into its own top-level directory');
        $this->assertSame(
            ['fs-framework-master'],
            array_values(array_diff((array) scandir($destination), ['.', '..'])),
            'the prune base must not be the outer destination in this shape'
        );

        foreach (self::PLANNING_DIRECTORIES as $name) {
            $this->assertDirectoryDoesNotExist($source . '/' . $name, $name . '/ must be gone from the copied tree');
        }

        $this->assertFileExists($source . '/VERSION');
        $this->assertFileExists($source . '/index.php');
        $this->assertFileExists($source . '/base/fs_model.php');
        $this->assertFileExists($source . '/vendor/autoload.php');
        $this->assertFileExists($source . '/themes/AdminLTE/view/base.html.twig');
        $this->assertFileExists($source . '/plugins/system_updater/openspec/specs/pu.md');
    }

    /**
     * PU-16, the second ZIP shape: an archive whose root already is the core
     * tree has no wrapping directory, so the extraction root is the source root
     * and is pruned in place.
     */
    public function testAnArchiveWhoseRootIsTheCoreTreeIsPrunedAtTheRoot(): void
    {
        $zip = $this->buildZip('root', $this->rootArchiveEntries());
        $destination = $this->tempDir . '/extract_root';

        $this->assertTrue($this->extractZipSafe($zip, $destination));
        $this->assertArchiveCarriesPlanningTree($zip, '');

        $this->assertGreaterThan(
            1,
            count(array_diff((array) scandir($destination), ['.', '..'])),
            'this shape has no single wrapping directory, so the root itself is the source'
        );

        foreach (self::PLANNING_DIRECTORIES as $name) {
            $this->assertDirectoryDoesNotExist($destination . '/' . $name, $name . '/ must be gone from the extracted root');
        }

        $this->assertFileExists($destination . '/VERSION');
        $this->assertFileExists($destination . '/base/fs_model.php');
        $this->assertFileExists($destination . '/vendor/autoload.php');
        $this->assertFileExists($destination . '/plugins/system_updater/openspec/specs/pu.md');
    }

    /**
     * PU-16, "A failed extraction is never pruned": a refused archive leaves the
     * partial tree exactly as it was, and the reported failure stays the
     * extraction failure.
     */
    public function testARefusedArchivePrunesNothing(): void
    {
        $zip = $this->buildZip('refused', [
            '../evil.txt' => 'escape',
            'openspec/keep.md' => 'planning',
        ]);

        $destination = $this->tempDir . '/extract_refused';
        mkdir($destination . '/openspec', 0755, true);
        file_put_contents($destination . '/openspec/sentinel.md', 'pre-existing');

        $updater = $this->newUpdater();
        $this->assertFalse(
            $this->extractZipSafe($zip, $destination, $updater),
            'an archive carrying a traversal entry must be refused'
        );

        $this->assertFileExists(
            $destination . '/openspec/sentinel.md',
            'the prune must not run on a refused extraction'
        );
        $this->assertFileDoesNotExist($this->tempDir . '/evil.txt');
        $this->assertNotSame([], $updater->get_errors(), 'the extraction failure must be reported');
    }

    /**
     * PU-16, end to end through the real update path with an uploaded ZIP (the
     * only source branch that touches no network): the installation receives the
     * runtime tree and none of the eight, the prune outcome is visible in the
     * result, and it changes neither `success` nor the error list.
     *
     * The pre-existing `openspec/` in the installation root is the sentinel for
     * "the prune never runs against the installation": it must survive.
     */
    public function testACoreUpdateLeavesNoPlanningDirectoryInTheInstallation(): void
    {
        $root = $this->tempDir . '/install';
        mkdir($root . '/openspec', 0755, true);
        file_put_contents($root . '/openspec/operator.md', 'pre-existing operator tree');
        mkdir($root . '/plugins/system_updater/openspec/specs', 0755, true);
        file_put_contents($root . '/plugins/system_updater/openspec/specs/pu.md', '# PU-16');

        $zip = $this->buildZip('core', $this->branchArchiveEntries());
        $updater = new \core_updater($root);

        $result = $updater->update_core(false, null, $zip);

        $this->assertTrue($result['success'], 'the prune must not change the update outcome');
        $this->assertSame([], $updater->get_errors(), 'the prune must not add an error');
        $this->assertSame(
            $this->sorted(self::PLANNING_DIRECTORIES),
            $this->sorted($result['pruned_directories']),
            'the update result must name what the prune removed'
        );
        $this->assertSame([], $result['prune_failures']);

        foreach (array_diff(self::PLANNING_DIRECTORIES, ['openspec']) as $name) {
            $this->assertDirectoryDoesNotExist($root . '/' . $name, 'the installation must not receive ' . $name . '/');
        }

        $this->assertFileExists($root . '/openspec/operator.md', 'the installation root is never a prune base');
        $this->assertFileExists($root . '/plugins/system_updater/openspec/specs/pu.md');
        $this->assertFileExists($root . '/VERSION');
        $this->assertFileExists($root . '/index.php');
        $this->assertFileExists($root . '/base/fs_model.php');
    }

    // ------------------------------------------------------- extraction helpers

    /**
     * @param array<string, string> $entries
     */
    private function buildZip(string $name, array $entries): string
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is not available in this PHP build, so the extraction half cannot run');
        }

        $path = $this->tempDir . '/' . $name . '.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);

        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }

        $zip->close();

        return $path;
    }

    /**
     * The branch-archive shape: one top-level directory, the eight inside it,
     * and the runtime entries an update must deliver.
     *
     * @return array<string, string>
     */
    private function branchArchiveEntries(): array
    {
        return $this->prefixedEntries('fs-framework-master/');
    }

    /**
     * The uploaded-ZIP shape: the core tree is the archive root.
     *
     * @return array<string, string>
     */
    private function rootArchiveEntries(): array
    {
        return $this->prefixedEntries('');
    }

    /**
     * @return array<string, string>
     */
    private function prefixedEntries(string $prefix): array
    {
        $entries = [];

        foreach (self::PLANNING_DIRECTORIES as $name) {
            $entries[$prefix . $name . '/keep.md'] = 'planning content for ' . $name;
        }

        $entries[$prefix . 'VERSION'] = '0.13.0';
        $entries[$prefix . 'index.php'] = '<?php // entry';
        $entries[$prefix . 'base/fs_model.php'] = '<?php // model';
        $entries[$prefix . 'vendor/autoload.php'] = '<?php // autoload';
        $entries[$prefix . 'themes/AdminLTE/view/base.html.twig'] = '{{ base }}';
        $entries[$prefix . 'view/css/app.css'] = 'body{}';
        $entries[$prefix . 'plugins/system_updater/openspec/specs/pu.md'] = '# PU-16';

        return $entries;
    }

    /**
     * Anti-vacuity control: the fixture archive really carries the eight before
     * extraction, so "they are gone afterwards" means the prune removed them and
     * not that the fixture never had them.
     */
    private function assertArchiveCarriesPlanningTree(string $zipPath, string $prefix): void
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);

        foreach (self::PLANNING_DIRECTORIES as $name) {
            $this->assertNotFalse(
                $zip->locateName($prefix . $name . '/keep.md'),
                'the fixture archive must carry ' . $name . '/'
            );
        }

        $zip->close();
    }

    private function extractZipSafe(string $zipPath, string $destination, ?\core_updater $updater = null): bool
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $instance = $updater ?? $this->newUpdater();
        $method = new \ReflectionMethod($instance, 'extractZipSafe');
        $method->setAccessible(true);

        return (bool) $method->invoke($instance, $zipPath, $destination);
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

        // Trap: the updater prefers `git clone` whenever git is available. A
        // prune wired only on the ZIP path leaves the property false on the
        // branch that runs most of the time, so both are pinned by argument.
        preg_match_all('/\$this->pruneStagingSource\(([^)]*)\)/', $source, $callSites);

        $arguments = array_map('trim', $callSites[1]);
        sort($arguments);

        $this->assertSame(
            ['$clonePath', '$destination'],
            $arguments,
            'the ZIP extraction and the git clone must both hand a staging directory to the prune'
        );
    }

    /**
     * PU-16, "the allowlist and the core sample cannot drift".
     *
     * Two enforcement points of one decision: the filesystem (never write it)
     * and the edge (refuse to serve it). The always-runnable half pins the
     * plugin's declaration to the eight names the requirement states, so a
     * silent addition or removal is red with or without the core repository
     * present. The drift half compares that set with the core sample's planning
     * deny block and reports a visible skip naming the path it looked for when
     * the plugin is checked out standalone.
     *
     * The runtime and operator denies of the sample (`/vendor/`, `/tmp/`,
     * `/backups/`, `/.git/`) are excluded by name: they are paths this
     * requirement must never touch, and treating them as members would widen
     * the allowlist silently.
     */
    public function testTheAllowlistMatchesTheCoreSampleDenyBlock(): void
    {
        $allowlist = $this->declaredAllowlist();

        // Always runs first: a silent removal or addition is red on its own.
        $expected = self::PLANNING_DIRECTORIES;
        sort($expected);
        sort($allowlist);

        $this->assertSame(
            $expected,
            $allowlist,
            'PU-16: the declared allowlist must be exactly the eight stated names and no others'
        );

        $samplePath = dirname(__DIR__, 3) . '/nginx-sample.conf';

        if (!is_file($samplePath)) {
            $this->markTestSkipped(
                'the two-list comparison did NOT run: the core sample was not found at '
                . $samplePath
                . '. The eight-name assertion above did run; this plugin also ships standalone,'
                . ' where the core sample does not exist.'
            );
        }

        $denied = $this->planningDenies($samplePath);

        $this->assertSame(
            8,
            count($denied),
            'the planning block of ' . $samplePath . ' must deny exactly eight top-level directories'
        );

        foreach (['vendor', 'tmp', 'backups', '.git'] as $runtimeOrOperatorPath) {
            $this->assertNotContains(
                $runtimeOrOperatorPath,
                $denied,
                $samplePath . ': /' . $runtimeOrOperatorPath . '/ is a runtime or operator deny, not a planning one'
            );
            $this->assertNotContains(
                $runtimeOrOperatorPath,
                $allowlist,
                'PU-16 forbids the prune from ever touching /' . $runtimeOrOperatorPath . '/'
            );
        }

        sort($denied);

        $this->assertSame(
            $denied,
            $allowlist,
            'the plugin allowlist and ' . $samplePath . ' deny the same decision;'
            . ' a name added to one and not the other is a defect in either direction'
        );
    }

    /**
     * The plugin's single declaration, read through the same private seam the
     * updater uses. Never a copy of the list: this is the production value.
     *
     * @return array<int, string>
     */
    private function declaredAllowlist(): array
    {
        $method = new \ReflectionMethod(\core_updater::class, 'nonRuntimeDirectories');
        $method->setAccessible(true);

        $declared = $method->invoke(null);

        $this->assertIsArray($declared, 'the allowlist must be declared exactly once, as a list');

        return array_values($declared);
    }

    /**
     * Top-level directory names denied in the core sample's planning block, and
     * only those. The block starts at its own comment and ends where the next
     * section does, so the plugin regex denies below never enter the set.
     *
     * @return array<int, string>
     */
    private function planningDenies(string $samplePath): array
    {
        $content = (string) file_get_contents($samplePath);

        $marker = 'Planning, documentation and analysis trees';
        $start = strpos($content, $marker);

        $this->assertNotFalse(
            $start,
            'the planning block is missing from ' . $samplePath
            . ': the sample no longer carries the second enforcement point'
        );

        $block = substr($content, (int) $start);

        $sectionEnd = strpos($block, '# ---');
        if ($sectionEnd !== false) {
            $block = substr($block, 0, $sectionEnd);
        }

        preg_match_all('#^\s*location\s+\^~\s+/([A-Za-z0-9._-]+)/\s*\{#m', $block, $matches);

        return array_values(array_unique($matches[1]));
    }
}
