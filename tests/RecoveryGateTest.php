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

use Tests\SystemUpdater\Support\ConsoleHttpHarness;
use Tests\SystemUpdater\Support\ConsoleHttpTestCase;

require_once __DIR__ . '/Support/ConsoleHttpTestCase.php';

/**
 * RACC-04 over the only SAPI that has a status code and a header list.
 *
 * The requirement's literal demand — a body byte-identical to a request for a
 * non-existent path — is not achievable at the PHP level and this suite records
 * why instead of pretending: the built-in server generates its own 404 page and
 * echoes the requested path into it, measured on 2026-10-25 as 541 bytes for
 * `/nope.php` and 542 bytes for `/other.php`, and under Apache or nginx the body
 * belongs to the server too. What *is* falsifiable, and what these cases assert,
 * is the subset the spec records:
 *
 *   - HTTP 404, the same status a genuinely non-existent path gets;
 *   - the three failure inputs (absent, empty, wrong) take one code path and
 *     produce byte-identical bodies;
 *   - the header set PHP controls does not vary with secret correctness;
 *   - no `Set-Cookie`, no session file, no `Location`, no `Referrer-Policy`;
 *   - the body carries no console marker and no information that varies with
 *     secret correctness.
 */
final class RecoveryGateTest extends ConsoleHttpTestCase
{
    private const CONSOLE = '/plugins/system_updater/recovery.php';
    private const MISSING = '/plugins/system_updater/nope.php';
    private const SESSION_NAME = 'FSSESS_fixture';

    /**
     * The console's full render graph, so the failure cases observe the shipped
     * behaviour rather than a fixture that dies on a missing include.
     */
    private const CONSOLE_FILES = [
        'recovery.php',
        'lib/recovery_secret.php',
        'lib/session_auth.php',
        'lib/backup_manager.php',
        'lib/SqlDumpReader.php',
    ];

    public function testTheHarnessServesTheFixtureTreeAndTearsItDown(): void
    {
        $harness = $this->newHarness(self::CONSOLE_FILES);
        $harness->boot();

        file_put_contents($harness->root . '/probe.html', 'harness-ok');

        $response = $harness->request('/probe.html');

        $this->assertSame(200, $response['status'], 'the built-in server answered: ' . $response['curlError']);
        $this->assertSame('harness-ok', $response['body']);
        $this->assertSame(
            '127.0.0.1',
            (string) parse_url($harness->url('/'), PHP_URL_HOST),
            'premise: the fixture is bound to loopback and not to the real installation'
        );
        $this->assertDirectoryDoesNotExist($harness->root . '/vendor');
        $this->assertDirectoryDoesNotExist($harness->root . '/base');
        $this->assertDirectoryDoesNotExist($harness->root . '/src');
    }

    /**
     * The defect, stated as one case: the console's answer must not depend on
     * whether the caller knew the secret, and it must not admit that it is
     * anything other than a missing file.
     */
    public function testARequestWithoutTheSecretIsIndistinguishableFromAMissingPath(): void
    {
        $secret = $this->newSecret();
        $harness = $this->gateFixture($secret);

        $absent = $harness->request(self::CONSOLE);
        $empty = $harness->request(self::CONSOLE . '?secret=');
        $wrong = $harness->request(self::CONSOLE . '?secret=' . $this->newSecret());
        $missing = $harness->request(self::MISSING);

        foreach (['absent' => $absent, 'empty' => $empty, 'wrong' => $wrong] as $label => $response) {
            $this->assertSame(404, $response['status'], 'the ' . $label . ' case must answer 404, not ' . $response['status'] . ': ' . $response['body']);
            $this->assertSame(
                [],
                $harness->headersMatching($response, 'set-cookie'),
                'the ' . $label . ' case must not set a session cookie'
            );
            $this->assertSame(
                [],
                $harness->headersMatching($response, 'referrer-policy'),
                'the ' . $label . ' case carries the failure header set, not the success one'
            );
            $this->assertSame([], $harness->headersMatching($response, 'location'));
            $this->assertBodyCarriesNoConsoleMarker($response['body'], $label);
            $this->assertStringNotContainsString($secret, $response['body'], 'the secret never reaches a body');
        }

        $this->assertSame(
            $absent['body'],
            $empty['body'],
            'absent and empty must be byte-identical: one code path, not an early return'
        );
        $this->assertSame(
            $absent['body'],
            $wrong['body'],
            'absent and wrong must be byte-identical'
        );

        $this->assertSame(
            $this->controllableHeaderNames($absent),
            $this->controllableHeaderNames($empty),
            'the header set that PHP controls must not vary between failure inputs'
        );
        $this->assertSame(
            $this->controllableHeaderNames($absent),
            $this->controllableHeaderNames($wrong),
            'the header set that PHP controls must not vary between failure inputs'
        );

        // Against a genuinely non-existent path: the same status and no cookie.
        // The bodies cannot match (the server echoes the path); that gap is
        // recorded in the class docblock and in the PR body instead of asserted.
        $this->assertSame($missing['status'], $absent['status'], 'a missing path and a secret-less request share a status');
        $this->assertSame([], $harness->headersMatching($missing, 'set-cookie'));
        $this->assertSame([], $harness->headersMatching($absent, 'set-cookie'));

        $this->assertSame([], $harness->sessionFiles(), 'a secret-less request must not create a session file');
        $this->assertFalse($harness->configWasLoaded(), 'a secret-less request must never load config.php');
        $this->assertTrue($harness->secretExists(), 'premise: the secret was persisted, so nothing was generated');
    }

    /**
     * RACC-03 "the gate answers before `config.php` is loaded": a config that
     * would fatal if executed cannot paint the response, and its parse error is
     * never echoed.
     */
    public function testTheGateAnswersWithADamagedConfigInsteadOfAPhpError(): void
    {
        $harness = $this->gateFixture($this->newSecret());
        $harness->writeRawConfig("<?php\nthis is not valid php\n");
        $harness->forgetConfigLoad();

        $response = $harness->request(self::CONSOLE);

        $this->assertSame(404, $response['status'], 'a damaged config.php must still answer the 404: ' . $response['body']);
        $this->assertStringNotContainsString('Parse error', $response['body']);
        $this->assertStringNotContainsString('syntax error', $response['body']);
        $this->assertFalse($harness->configWasLoaded(), 'the gate runs before config.php, so nothing executed it');
    }

    /**
     * RACC-02 "generated at install time or on first use", observed over HTTP.
     *
     * The first request cannot carry the secret — nobody knows it yet — so it
     * must generate, persist and log the value once, disclose nothing, and still
     * answer the 404. The operator then reads it from the log (RACC-06) or the
     * shell and comes back.
     */
    public function testTheFirstRequestGeneratesTheSecretOnceWithoutDisclosingIt(): void
    {
        $harness = $this->newHarness(self::CONSOLE_FILES);
        $harness->boot();

        $first = $harness->request(self::CONSOLE);
        $second = $harness->request(self::CONSOLE);

        $this->assertSame(404, $first['status'], 'the first request learns nothing: ' . $first['body']);
        $this->assertSame(404, $second['status']);
        $this->assertTrue($harness->secretExists(), 'the first use persists a secret');

        $log = $harness->errorLogContents();
        $this->assertSame(
            1,
            preg_match_all('/\[system_updater\] recovery console secret generated/', $log),
            'the generation line is written exactly once, not per request'
        );
        $this->assertSame(1, preg_match('#[0-9a-f]{64}#', $log), 'and it carries the value exactly once');

        preg_match('#([0-9a-f]{64})#', $log, $matches);
        $generated = $matches[1] ?? '';
        $this->assertSame(64, strlen($generated), 'premise: a secret was logged');
        $this->assertStringNotContainsString($generated, $first['body'], 'the log is a server-side sink only');
        $this->assertStringNotContainsString($generated, $first['headers']);
        $this->assertStringNotContainsString($generated, $second['body']);
    }

    private function gateFixture(string $secret): ConsoleHttpHarness
    {
        $harness = $this->newHarness(self::CONSOLE_FILES);
        $harness->boot($this->gateConfigBody());
        $harness->persistSecret($secret);

        return $harness;
    }

    /**
     * A config that is valid and complete, so "was it loaded?" is the only thing
     * the sentinel measures. The database coordinates point at a closed port:
     * the suite never opens a connection, and the console's render path must
     * survive the refusal (RACC-01's "config.php is the only hard stop").
     */
    private function gateConfigBody(): string
    {
        return implode("\n", [
            "define('FS_DB_HOST', '127.0.0.1');",
            "define('FS_DB_PORT', 1);",
            "define('FS_DB_USER', 'nobody');",
            "define('FS_DB_PASS', 'nothing');",
            "define('FS_DB_NAME', 'none');",
            "define('FS_SESSION_NAME', '" . self::SESSION_NAME . "');",
            '',
        ]);
    }

    private function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * @param array{headerLines: string[]} $response
     * @return string[]
     */
    private function controllableHeaderNames(array $response): array
    {
        $names = [];

        foreach ($response['headerLines'] as $line) {
            if (stripos($line, 'HTTP/') === 0) {
                continue;
            }

            $name = strtolower(trim(explode(':', $line, 2)[0]));

            // `Host`/`Date`/`Connection` belong to the server's response envelope,
            // not to the script; comparing them would assert the clock.
            if (in_array($name, ['date', 'host', 'connection', 'content-length'], true)) {
                continue;
            }

            $names[] = $name;
        }

        sort($names);

        return $names;
    }

    private function assertBodyCarriesNoConsoleMarker(string $body, string $label): void
    {
        foreach ([
            'Modo Recuperaci',
            'MODO RECUPERACI',
            'system_updater',
            'Copias de Seguridad',
            'name="nick"',
            'name="password"',
            'Iniciar Sesión',
            'backup',
        ] as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $body,
                'the ' . $label . ' body must not reveal the console: found "' . $marker . '"'
            );
        }
    }
}
