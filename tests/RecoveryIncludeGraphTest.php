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

use PHPUnit\Framework\Attributes\Test;
use Tests\SystemUpdater\Support\ConsoleHttpHarness;
use Tests\SystemUpdater\Support\ConsoleHttpTestCase;
use Tests\SystemUpdater\Support\PolicyProbe;

/**
 * RACC-01: `config.php` is the console's only core dependency.
 *
 * Two halves, because neither alone is evidence
 * --------------------------------------------
 * The **static graph** is the contract: enumerate every `require`/`include`
 * reachable from `recovery.php` and its plugin-local files, and assert that
 * exactly one resolved path lies outside the plugin. That is what a reviewer
 * reads, and it is what a new dependency breaks first.
 *
 * The **temp install root** is the behaviour: a tree with no `vendor/`, no
 * `base/` and no `src/` and a sentinel `config.php` whose database is
 * unreachable. The console must reach that boundary without a class error. A
 * static list cannot prove that a file it does not name is not loaded at
 * runtime; a run cannot prove that some other branch of the same file does not
 * load one. Both are asserted.
 *
 * Why `vendor/autoload.php` and `lib/session_auth.php` are named individually
 * -------------------------------------------------------------------------
 * They are the two dependencies this slice removes, and they are the two a
 * "just in case" include would put back. `session_auth.php` also pulls
 * `base/config2.php` and the framework bootstrap, so it is not one core file —
 * it is the whole kernel, on the one screen that has to work when the kernel
 * does not.
 */
final class RecoveryIncludeGraphTest extends ConsoleHttpTestCase
{
    private const CONSOLE = '/plugins/system_updater/recovery.php';
    private const PLUGIN = '/plugins/system_updater';

    /**
     * The console's whole graph. No `lib/session_auth.php` and no vendor tree:
     * if the console still needs either, this fixture is where it fatals.
     */
    private const FILES = [
        'recovery.php',
        'lib/recovery_secret.php',
        'lib/recovery_session.php',
        'lib/recovery_handler.php',
        'lib/recovery_policy.php',
        'lib/recovery_legacy_hash.php',
        'lib/recovery_login_token.php',
        'lib/backup_manager.php',
        'lib/SqlDumpReader.php',
    ];

    /**
     * Every file the graph is allowed to reach inside the plugin, sorted.
     *
     * Kept as an exact list and not as a count: "one external entry" says
     * nothing about a new internal one, and a file that joins the graph is a
     * file that has to be re-reviewed for what it loads.
     */
    private const EXPECTED_INTERNAL = [
        self::PLUGIN . '/lib/SqlDumpReader.php',
        self::PLUGIN . '/lib/backup_manager.php',
        self::PLUGIN . '/lib/recovery_handler.php',
        self::PLUGIN . '/lib/recovery_legacy_hash.php',
        self::PLUGIN . '/lib/recovery_login_token.php',
        self::PLUGIN . '/lib/recovery_policy.php',
        self::PLUGIN . '/lib/recovery_secret.php',
        self::PLUGIN . '/lib/recovery_session.php',
    ];

    /**
     * The one include this test cannot resolve, and why it is still RACC-01.
     *
     * `lib/recovery_session.php` includes the config file by a path it has
     * already `realpath()`ed and refused when unusable, so the target is not a
     * literal in the source. It is asserted separately — that include *is*
     * `FS_FOLDER/config.php`, the entry RACC-01 allows — rather than waved
     * through here, and any second variable include fails this list.
     */
    private const EXPECTED_UNRESOLVABLE = ['$file'];

    #[Test]
    public function exactlyOneIncludeResolvesOutsideThePluginAndItIsConfigPhp(): void
    {
        $graph = $this->includeGraph();

        $this->assertSame(
            self::EXPECTED_UNRESOLVABLE,
            $graph['unresolvable'],
            'every include must be a literal path, or the one defensive config read accounted for below'
        );
        $this->assertSame(
            ['FS_FOLDER/config.php'],
            $graph['external'],
            'the only path outside plugins/system_updater/ is FS_FOLDER/config.php'
        );
        $this->assertSame(self::EXPECTED_INTERNAL, $graph['internal']);
    }

    /**
     * The variable include, opened up.
     *
     * `recovery_session_read_config_constants()` runs `include $file` on a
     * `realpath()`, and `realpath()` is what keeps a crafted path out. The two
     * properties that make it a `config.php` read and not an arbitrary one:
     * it returns `false`-safe before the include, and its only callers pass the
     * installation's own `config.php`.
     */
    #[Test]
    public function theOnlyVariableIncludeIsTheDefensiveConfigRead(): void
    {
        $source = self::codeWithoutComments(
            (string) file_get_contents(dirname(__DIR__) . '/lib/recovery_session.php')
        );

        $this->assertStringContainsString('realpath($configFile)', $source, 'the path is resolved, never trusted');
        $this->assertStringContainsString('if ($target === false)', $source, 'an unresolvable path is refused');
        $this->assertStringContainsString('include $file;', $source);
        $this->assertStringContainsString('get_included_files()', $source, 'a file already run is not run twice');
        $this->assertStringContainsString('ob_start()', $source, 'a damaged config.php cannot paint the response');
        $this->assertStringContainsString('catch (\\Throwable', $source, 'and a parse error is swallowed');

        $console = self::codeWithoutComments(
            (string) file_get_contents(dirname(__DIR__) . '/recovery.php')
        );

        $this->assertStringContainsString(
            "FS_FOLDER . '/config.php'",
            $console,
            'the console names the installation config whenever it calls into the reader'
        );
    }

    #[Test]
    public function theConsoleNeverReferencesTheComposerAutoloader(): void
    {
        foreach ($this->graphSources() as $source => $code) {
            $this->assertStringNotContainsString(
                'vendor/autoload.php',
                $code,
                $source . ' must not load the Composer autoloader: it is the dependency RACC-01 removes'
            );
        }
    }

    #[Test]
    public function theConsoleNeverRequiresTheSessionAuthLibrary(): void
    {
        foreach ($this->graphSources() as $source => $code) {
            $this->assertStringNotContainsString(
                'session_auth.php',
                $code,
                $source . ' must not load lib/session_auth.php: it pulls base/config2.php and the kernel'
            );
        }
    }

    /**
     * RACC-01's second clause, as a static property of every file in the graph.
     *
     * `class_exists()` without the second argument *loads* the class under
     * Composer. Inside the console that is a core dependency that no include
     * enumeration can see, so the autoload-disabled form is what the graph is
     * allowed to contain. `class_exists('X')` is exactly how the previous
     * implementation reached `LegacyCompatibility`.
     */
    #[Test]
    public function noFileInTheGraphCanTriggerTheAutoloader(): void
    {
        foreach ($this->graphSources() as $source => $code) {
            preg_match_all('/class_exists\s*\(([^)]*)\)/', $code, $matches);

            foreach ($matches[1] as $arguments) {
                $this->assertStringContainsString(
                    'false',
                    $arguments,
                    $source . ' asks class_exists(' . trim($arguments) . '), which would autoload a core class'
                );
            }
        }
    }

    /**
     * The behavioural half: no `vendor/`, no `base/`, no `src/`, a sentinel
     * `config.php` and a database that cannot be reached.
     *
     * The expected outcome is the login form, not the backup list: the operator
     * still has to authenticate, and with no database nobody can be verified.
     * What must not appear is a class error, a fatal, or a stack trace — the
     * three ways a missing dependency announces itself.
     */
    #[Test]
    public function theConsoleBootsWithNoVendorNoBaseAndNoSrc(): void
    {
        $secret = bin2hex(random_bytes(32));
        $harness = $this->newHarness(self::FILES);
        $harness->boot(implode("\n", [
            "define('FS_DB_HOST', '127.0.0.1');",
            "define('FS_DB_PORT', 1);",
            "define('FS_DB_USER', 'nobody');",
            "define('FS_DB_PASS', 'nothing');",
            "define('FS_DB_NAME', 'none');",
            "define('FS_SESSION_NAME', 'FSSESS_graph');",
            '',
        ]));
        $harness->persistSecret($secret);

        $this->assertDirectoryDoesNotExist($harness->root . '/vendor');
        $this->assertDirectoryDoesNotExist($harness->root . '/base');
        $this->assertDirectoryDoesNotExist($harness->root . '/src');

        $response = $harness->request(self::CONSOLE . '?secret=' . $secret);

        $this->assertSame(200, $response['status'], 'the console boots without a core: ' . $response['body']);
        $this->assertStringContainsString('name="nick"', $response['body'], 'the login form is rendered');
        $this->assertTrue($harness->configWasLoaded(), 'premise: config.php is the boundary that was reached');

        foreach (['Fatal error', 'Class &quot;', 'Class not found', 'Uncaught', 'Stack trace'] as $classError) {
            $this->assertStringNotContainsString(
                $classError,
                $response['body'],
                'a missing dependency would announce itself here'
            );
        }

        $errorLog = $harness->errorLogContents();

        foreach (['Fatal error', 'Class not found', 'Uncaught'] as $classError) {
            $this->assertStringNotContainsString(
                $classError,
                $errorLog,
                'the console wrote a class error to the log: ' . $errorLog
            );
        }
    }

    /**
     * RACC-01's outcome, not its mechanism: with no autoloader and no core tree,
     * a SHA-1 administrator still authenticates.
     *
     * This is the case the ordering in `tasks.md` protects. Removing
     * `vendor/autoload.php` before the plugin-local verifier existed would have
     * locked out every administrator whose hash is still SHA-1 or MD5 — the exact
     * operator the console exists for. So the assertion is not "the file is not
     * required": it is that the credential path answers `ok` in a process that
     * has never loaded Composer, over a tree that contains no `base/`, no `src/`
     * and no `vendor/`.
     *
     * A subprocess is the only honest seam. This suite's bootstrap requires the
     * autoloader, so in-process `class_exists('...')` would answer `true` for
     * every core class regardless of what the console needs.
     */
    #[Test]
    public function aLegacyHashAdministratorAuthenticatesWithNoAutoloaderAndNoCore(): void
    {
        $root = rtrim(sys_get_temp_dir(), '/') . '/su_no_core_' . bin2hex(random_bytes(6));
        $library = $root . '/plugins/system_updater/lib';
        $sessions = $root . '/sessions';
        mkdir($library, 0700, true);
        mkdir($sessions, 0700, true);

        foreach (['recovery_handler.php', 'recovery_policy.php', 'recovery_legacy_hash.php', 'recovery_login_token.php'] as $file) {
            copy(dirname(__DIR__) . '/lib/' . $file, $library . '/' . $file);
        }

        foreach (['recovery_secret.php', 'recovery_session.php', 'backup_manager.php', 'SqlDumpReader.php'] as $file) {
            copy(dirname(__DIR__) . '/lib/' . $file, $library . '/' . $file);
        }

        try {
            $this->assertDirectoryDoesNotExist($root . '/vendor');
            $this->assertDirectoryDoesNotExist($root . '/base');
            $this->assertDirectoryDoesNotExist($root . '/src');

            $plain = 'correct-horse-battery-staple';

            // The console's own include order, minus `config.php` — the boundary
            // a probe cannot cross without a database. Everything else is the
            // graph `recovery.php` loads, so a core dependency anywhere in it
            // fails this case and not only a dependency of the handler.
            $probe = PolicyProbe::run(
                [
                    $library . '/recovery_secret.php',
                    $library . '/recovery_session.php',
                    $library . '/recovery_handler.php',
                    $library . '/backup_manager.php',
                ],
                ['FS_FOLDER' => $root],
                implode("\n", [
                    'ini_set("session.save_path", ' . var_export($sessions, true) . ');',
                    'ini_set("session.use_cookies", "0");',
                    'session_id(str_repeat("a", 32));',
                    'session_start();',
                    '$_SESSION = [];',
                    '$token = recovery_login_token_mint();',
                    '$result = recovery_handler_authenticate(',
                    '    ["token" => $token, "nick" => "admin", "password" => ' . var_export($plain, true) . ',',
                    '     "ip" => "203.0.113.7", "now" => time()],',
                    '    [',
                    '        "shared_available" => false,',
                    '        "verifier" => static fn (string $stored, string $given, ?string $salt): bool => sha1($given) === $stored,',
                    '        "lookup" => static fn (string $nick): ?array => ["nick" => "admin",',
                    '            "password" => sha1(' . var_export($plain, true) . '), "admin" => 1],',
                    '        "is_banned" => static fn (string $ip, int $now): bool => false,',
                    '        "reserve" => static fn (string $n, string $i, int $t): array',
                    '            => ["reserved" => true, "throttled" => false],',
                    '        "release" => static fn (string $n, string $i): bool => true,',
                    '        "record" => static fn (string $b, string $n, string $i, int $t) => null,',
                    '        "clear" => static fn (string $b, string $n, string $i, int $t) => null,',
                    '    ]',
                    ');',
                    '$core = array_values(array_filter(',
                    '    get_included_files(),',
                    '    static fn (string $file): bool => $file !== __FILE__',
                    '        && !str_starts_with($file, ' . var_export($library, true) . ')',
                    '));',
                    'echo json_encode([',
                    '    "outcome" => $result["outcome"],',
                    '    "nick" => $result["identity"]["user_nick"] ?? null,',
                    '    "autoloadable_core" => class_exists("FSFramework\\\\Security\\\\SessionManager"),',
                    '    "outside_plugin" => $core,',
                    ']);',
                ])
            );

            $report = PolicyProbe::decodeJson($probe['output']);
            $this->assertIsArray($report, 'the probe printed a verdict, not a warning: ' . $probe['output']);
            $this->assertSame('ok', $report['outcome'], 'a SHA-1 administrator authenticates with no autoloader');
            $this->assertSame('admin', $report['nick']);
            $this->assertFalse($report['autoloadable_core'], 'premise: this process has no Composer autoloader and no core tree');
            $this->assertSame([], $report['outside_plugin'], 'the credential path loaded nothing outside its own tree');
        } finally {
            ConsoleHttpHarness::removeTree($root);
        }
    }

    /**
     * Every PHP source the console can load, as path => comment-free code.
     * @return array<string, string>
     */
    private function graphSources(): array
    {
        $graph = $this->includeGraph();
        $sources = [];

        foreach (array_merge([self::PLUGIN . '/recovery.php'], $graph['internal']) as $relative) {
            $sources[$relative] = self::codeWithoutComments(
                (string) file_get_contents(dirname(__DIR__, 3) . $relative)
            );
        }

        return $sources;
    }

    /**
     * Walk `require`/`include` from `recovery.php` and classify every target.
     *
     * Literal `__DIR__ . '<path>'` is resolved and recursed into, `FS_FOLDER .
     * '<path>'` is an external entry, and anything else — a variable, a call —
     * is `unresolvable`, because a path this test cannot read is a path a
     * reviewer cannot read either.
     *
     * @return array{internal: list<string>, external: list<string>, unresolvable: list<string>}
     */
    private function includeGraph(): array
    {
        $root = dirname(__DIR__, 3);
        $plugin = $root . self::PLUGIN;

        $internal = [];
        $external = [];
        $unresolvable = [];
        $queue = [$plugin . '/recovery.php'];
        $seen = [];

        while ($queue !== []) {
            $file = array_shift($queue);
            $relative = substr($file, strlen($root));
            $seen[$relative] = true;

            foreach ($this->includeExpressions((string) file_get_contents($file)) as $expression) {
                if ($expression === '') {
                    continue;
                }

                if (preg_match('/^FS_FOLDER\s*\.\s*[\'"]([^\'"]+)[\'"]$/', $expression, $matches) === 1) {
                    $external['FS_FOLDER' . $matches[1]] = true;

                    continue;
                }

                if (preg_match('/^__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]$/', $expression, $matches) !== 1) {
                    $unresolvable[$expression] = true;

                    continue;
                }

                $target = dirname($file) . $matches[1];
                $targetRelative = substr($target, strlen($root));

                if (!is_file($target)) {
                    $unresolvable['missing: ' . $targetRelative] = true;

                    continue;
                }

                $internal[$targetRelative] = true;

                if (!isset($seen[$targetRelative])) {
                    $seen[$targetRelative] = true;
                    $queue[] = $target;
                }
            }
        }

        $externalEntries = array_keys($external);
        sort($externalEntries);

        $internalEntries = array_keys($internal);
        sort($internalEntries);

        return [
            'internal' => $internalEntries,
            'external' => $externalEntries,
            'unresolvable' => array_values(array_keys($unresolvable)),
        ];
    }

    /**
     * The expressions a file passes to `require`/`require_once`/`include`/
     * `include_once`, whitespace-collapsed.
     *
     * @return list<string>
     */
    private function includeExpressions(string $source): array
    {
        $includes = [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE];
        $expressions = [];
        $collecting = false;
        $current = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], $includes, true)) {
                $collecting = true;
                $current = '';

                continue;
            }

            if (!$collecting) {
                continue;
            }

            if ($token === ';') {
                $expressions[] = trim((string) preg_replace('/\s+/', ' ', $current));
                $collecting = false;
                $current = '';

                continue;
            }

            $current .= is_array($token) ? $token[1] : $token;
        }

        return $expressions;
    }
}
