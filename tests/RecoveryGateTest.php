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

use Tests\SystemUpdater\Support\ConsoleHttpTestCase;

require_once __DIR__ . '/Support/ConsoleHttpTestCase.php';

/**
 * The harness has to work before it can prove anything (U4.0).
 *
 * Also the home of RACC-04's falsifiable subset: HTTP status, the header set
 * PHP controls, `Set-Cookie`, the session store and the sentinel `config.php`
 * are response facts, and this is the only SAPI in which they exist.
 */
final class RecoveryGateTest extends ConsoleHttpTestCase
{
    public function testTheHarnessServesTheFixtureTreeAndTearsItDown(): void
    {
        $harness = $this->newHarness();
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
}
