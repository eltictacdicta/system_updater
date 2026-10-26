<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where the plugin CSRF token may be minted.
 *
 * `su_csrf_token` is not an identity (CSRF-01) — it is a *provenance* claim:
 * "this request reached a page that rendered the token, and that page was
 * reached through an authorized gate". Its whole security value therefore
 * depends on how few places can mint it. AUTH-05 and CSRF-01 state that
 * contract as **two authorized minting points, each behind its own gate**:
 *
 *   1. a render of the `#[AdminOnly]` `admin_updater` page, gated by the
 *      attribute plus an authenticated administrator session;
 *   2. `recovery.php`, gated by the per-installation secret compared
 *      constant-time (RACC-04) plus an administrator session in the console
 *      (REC-06/RACC-07).
 *
 * The file holds the contract in **two methods, and it needs both**. The
 * subset half says "no more than these two": every file that invokes a minting
 * helper resolves to an entry point in the authorized set, and no `process_*`
 * script and no `download_backup.php` is in it. The equality half says "and
 * both of them": the set is exactly `{admin_updater, recovery.php}`.
 *
 * Neither implies the other. The subset alone was true while the console minted
 * nothing — a console that cannot restore, which is the state `RACC-07`
 * reverses. The equality alone would pass if both points moved to two
 * unauthorized entry points, because it only compares against the same pair by
 * set, so the subset's per-file resolution is what keeps the list honest.
 *
 * Two things this file must never become:
 *
 *   - a test that the set has a single element. That framing is the one the
 *     product owner reversed (`RACC-07`); "only one minting point" contradicts
 *     the two-gate design and is not this contract;
 *   - a runtime session test. It is a source-tree enumeration, exactly as
 *     AUTH-05's testing note prescribes, so it needs no database, no session
 *     and no `session_start()`.
 */
final class CsrfMintingPointsTest extends TestCase
{
    /**
     * The two gates AUTH-05 authorizes. `recovery.php` is a permitted entry
     * point even before it mints; the subset says "no more than these", not
     * "all of these today".
     */
    private const AUTHORIZED_ENTRY_POINTS = ['admin_updater', 'recovery.php'];

    /**
     * The module through which the console reaches the minting helpers.
     *
     * Named in the equality half as a *premise*, so a contract that silently
     * loses the console's mint reports "the second gate stopped minting"
     * instead of a diff between two short lists.
     */
    private const CONSOLE_MINT_MODULE = 'lib/recovery_csrf.php';

    private const MINTING_HELPERS = [
        'system_updater_csrf_generate',
        'system_updater_csrf_field',
        'system_updater_csrf_meta',
    ];

    /**
     * The module that *defines* the helpers.
     *
     * Excluded from the call-site scan on purpose: `_field()` and `_meta()`
     * call `_generate()` inside their own bodies, and counting the defining
     * module as a minting point would make the enumeration meaningless (every
     * reachable page would inherit it). The effective minting point of that
     * internal call is whoever calls `_field()`/`_meta()`, which this scan does
     * see.
     */
    private const HELPER_MODULE = 'lib/csrf_token.php';

    /**
     * The subset property: every HTTP entry point able to reach a minting
     * helper belongs to the authorized pair.
     */
    #[Test]
    public function noUnauthorizedEntryPointCanMint(): void
    {
        $root = (string) realpath(dirname(__DIR__));
        $productionFiles = $this->productionFiles($root);

        $this->assertNotSame([], $productionFiles, 'premise: the plugin has production PHP files');

        foreach (self::MINTING_HELPERS as $helper) {
            $definitions = [];
            foreach ($productionFiles as $relative) {
                $source = (string) file_get_contents($root . '/' . $relative);
                if (strpos($source, 'function ' . $helper . '(') !== false) {
                    $definitions[] = $relative;
                }
            }

            $this->assertSame(
                [self::HELPER_MODULE],
                $definitions,
                $helper . ' must be defined once, in the helper module'
            );
        }

        $minters = $this->mintingFiles($root, $productionFiles);

        $this->assertContains(
            'lib/twig_compat.php',
            $minters,
            'premise: the admin page reaches the helpers through twig_compat, so an empty scan proves nothing'
        );

        $includeMap = $this->includeMap($root, $productionFiles);
        $entryPoints = [];

        foreach ($minters as $relative) {
            $resolved = $this->entryPointsFor($root . '/' . $relative, $root, [], $includeMap);

            $this->assertNotSame(
                [],
                $resolved,
                $relative . ' mints a token but resolves to no HTTP entry point; a minting path that cannot be'
                . ' traced is treated as unauthorized until the resolver is taught about it'
            );

            foreach ($resolved as $entryPoint) {
                $entryPoints[$entryPoint] = true;
            }
        }

        $entryPoints = array_keys($entryPoints);
        sort($entryPoints);

        $this->assertContains(
            'admin_updater',
            $entryPoints,
            'premise: the authorized page is reachable, so this test is not passing vacuously'
        );

        $this->assertSame(
            [],
            array_values(array_diff($entryPoints, self::AUTHORIZED_ENTRY_POINTS)),
            'a third entry point able to mint su_csrf_token breaks its provenance: found '
            . implode(', ', $entryPoints)
        );

        $this->assertNotContains('download_backup.php', $entryPoints, 'the download endpoint must not mint');

        foreach (glob($root . '/process_*.php') ?: [] as $processScript) {
            $this->assertNotContains(
                basename($processScript),
                $entryPoints,
                'a process_* script must never mint: it is a client of the token, not a gate'
            );
        }
    }

    /**
     * The equality property: the set of HTTP entry points able to reach a
     * minting helper is **exactly** the two gates AUTH-05 authorizes.
     *
     * The subset method above says "no more than these two". This one says "and
     * both of them, and no third" — which is the whole contract, because a token
     * whose provenance set is allowed to shrink is a console that cannot restore
     * (`RACC-07`) and one whose set is allowed to grow is a token whose
     * provenance is no longer two gates.
     *
     * Both halves are asserted, and neither is a restatement of the other:
     *
     *   - the two premises — the admin page's route into the helpers and the
     *     console's — are asserted by name, so losing either one is red here
     *     with its own message rather than as a two-element list diff;
     *   - the equality itself is `assertSame` against the authorized pair, so a
     *     third minting entry point is red as well.
     *
     * `assertSame` is deliberately not `assertCount(2, …)`: the latter would
     * pass for any two entry points, including two unauthorized ones.
     */
    #[Test]
    public function mintingReachesExactlyTwoGatedEntryPoints(): void
    {
        $root = (string) realpath(dirname(__DIR__));
        $productionFiles = $this->productionFiles($root);

        $minters = $this->mintingFiles($root, $productionFiles);

        $this->assertContains(
            'lib/twig_compat.php',
            $minters,
            'premise: the #[AdminOnly] page reaches the helpers through twig_compat'
        );
        $this->assertContains(
            self::CONSOLE_MINT_MODULE,
            $minters,
            'premise: the console mints after its two gates (RACC-07), so its mint module must reach the helpers'
        );

        $includeMap = $this->includeMap($root, $productionFiles);
        $entryPoints = [];

        foreach ($minters as $relative) {
            foreach ($this->entryPointsFor($root . '/' . $relative, $root, [], $includeMap) as $entryPoint) {
                $entryPoints[$entryPoint] = true;
            }
        }

        $entryPoints = array_keys($entryPoints);
        sort($entryPoints);

        $expected = self::AUTHORIZED_ENTRY_POINTS;
        sort($expected);

        $this->assertSame(
            $expected,
            $entryPoints,
            'minting entry points must be exactly the two gated ones; found ' . implode(', ', $entryPoints)
        );
    }

    /**
     * Production PHP files of the plugin, as paths relative to the plugin root.
     *
     * Test, documentation and generated trees are excluded: they are not part of
     * the shipped call graph, and the tests legitimately call the helpers.
     *
     * @return array<int, string>
     */
    private function productionFiles(string $root): array
    {
        $excludedTopLevel = ['tests', 'docs', 'openspec', 'vendor', 'view', 'translations', 'themes', 'tmp'];

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $topLevel = explode('/', $relative)[0];

            if (in_array($topLevel, $excludedTopLevel, true)) {
                continue;
            }

            $files[] = $relative;
        }

        sort($files);

        return $files;
    }

    /**
     * Files that invoke a minting helper, excluding the module that defines them.
     *
     * Comments are stripped first, for the same reason the console's own tests
     * strip them: a docblock that *names* a helper — to explain that this module
     * is the one the console reaches it through, say — is not a call site, and
     * counting it would turn the enumeration into a spellcheck of the prose.
     * Only code that can actually call the function counts.
     *
     * @param array<int, string> $productionFiles
     * @return array<int, string>
     */
    private function mintingFiles(string $root, array $productionFiles): array
    {
        $pattern = '/(?<!function )\b(?:' . implode('|', self::MINTING_HELPERS) . ')\s*\(/';

        $minters = [];
        foreach ($productionFiles as $relative) {
            if ($relative === self::HELPER_MODULE) {
                continue;
            }

            $source = $this->codeWithoutComments((string) file_get_contents($root . '/' . $relative));
            if (preg_match($pattern, $source) === 1) {
                $minters[] = $relative;
            }
        }

        return $minters;
    }

    /**
     * Source without comments or docblocks.
     *
     * `token_get_all()` is used rather than a regex so a `//` inside a string
     * literal is not mistaken for a comment — this scan decides which files can
     * mint a credential, and a rule that can be argued with by quoting is not a
     * rule.
     */
    private function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * Reverse include graph: target real path => files that `require` it.
     *
     * @param array<int, string> $productionFiles
     * @return array<string, array<int, string>>
     */
    private function includeMap(string $root, array $productionFiles): array
    {
        $map = [];

        foreach ($productionFiles as $relative) {
            $includer = $root . '/' . $relative;

            foreach ($this->includeExpressions((string) file_get_contents($includer)) as $expression) {
                $target = $this->resolveIncludeExpression($expression, $includer);
                if ($target === null) {
                    continue;
                }

                $map[$target][] = $includer;
            }
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function includeExpressions(string $source): array
    {
        preg_match_all('/(?:require|include)(?:_once)?\s+([^;]+);/', $source, $matches);

        return array_map('trim', $matches[1] ?? []);
    }

    /**
     * Resolve the two include shapes this plugin uses for its own files.
     *
     * Anything else (`FS_FOLDER . '/...'`, a computed variable, a plain literal
     * resolved against the framework root) points outside the plugin's minting
     * surface, so it contributes no edge. Returning `null` there is deliberate:
     * inventing an edge would fabricate a minting point.
     */
    private function resolveIncludeExpression(string $expression, string $includerFile): ?string
    {
        $expression = trim($expression);

        if (preg_match('/^__DIR__\s*\.\s*([\'"])(.+?)\1$/', $expression, $match) === 1) {
            return $this->realPathOrNull(dirname($includerFile) . '/' . $match[2]);
        }

        if (preg_match(
            '/^dirname\(\s*__DIR__\s*(?:,\s*(\d+)\s*)?\)\s*\.\s*([\'"])(.+?)\2$/',
            $expression,
            $match
        ) === 1) {
            $levels = isset($match[1]) && $match[1] !== '' ? (int) $match[1] : 1;

            return $this->realPathOrNull(dirname($includerFile, $levels) . '/' . $match[3]);
        }

        return null;
    }

    private function realPathOrNull(string $path): ?string
    {
        $real = realpath($path);

        return $real === false ? null : $real;
    }

    /**
     * Every HTTP entry point able to reach `$file`, directly or through the
     * include graph.
     *
     * @param array<string, bool> $visited guards against include cycles
     * @param array<string, array<int, string>> $includeMap
     * @return array<int, string>
     */
    private function entryPointsFor(string $file, string $root, array $visited, array $includeMap): array
    {
        $real = $this->realPathOrNull($file);
        if ($real === null || isset($visited[$real])) {
            return [];
        }

        $visited[$real] = true;

        $own = $this->entryPointName($real, $root);
        if ($own !== null) {
            return [$own];
        }

        $found = [];
        foreach ($includeMap[$real] ?? [] as $includer) {
            foreach ($this->entryPointsFor($includer, $root, $visited, $includeMap) as $name) {
                $found[$name] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * The HTTP entry point a file *is*, or `null` when it is only includable.
     *
     * Any PHP file at the plugin root is served directly by the web server, so
     * it answers requests under its own name — that is exactly the shape a new
     * minting point would take, and it must resolve to itself rather than to an
     * "untraceable" verdict. `Init.php` is the one exception: the framework
     * loads it, no URL reaches it. A controller is routed to by page name.
     */
    private function entryPointName(string $file, string $root): ?string
    {
        $directory = dirname($file);
        $basename = basename($file);

        if ($directory === $root) {
            return $basename === 'Init.php' ? null : $basename;
        }

        if ($directory === $root . '/controller') {
            return basename($basename, '.php');
        }

        return null;
    }
}
