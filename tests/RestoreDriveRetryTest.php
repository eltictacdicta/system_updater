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
use PHPUnit\Framework\TestCase;
use Tests\SystemUpdater\Support\NodeProbe;

require_once dirname(__DIR__) . '/lib/recovery_view.php';
require_once __DIR__ . '/Support/NodeProbe.php';

/**
 * The console's restore drive retries the action it was running, and only
 * retries failures a retry can fix.
 *
 * The defect this replaces
 * -----------------------
 * `runRestoreStep()` kept no record of the action in flight: the `catch` branch
 * re-issued `chunk` for every failure. A transport cut or a 5xx on the `begin`
 * request therefore retried with `chunk` while `begin` had never armed the
 * restore state on disk, and the operator got a server error on top of the
 * original one. The same branch also treated every non-2xx response as a network
 * cut, so a 401/403 from the session or CSRF guard was retried five times and
 * then reported as a connectivity problem — the wrong cause, named confidently.
 *
 * Why the assertions run the driver
 * ---------------------------------
 * Which action a retry issues is a property of the running client, not of its
 * source text: `runRestoreStep(action)` with the right argument and a hardcoded
 * `runRestoreStep('chunk')` can be two lines apart and read the same. Node runs
 * the generated script against a stubbed browser (`Support/node/`), and the
 * request log is the evidence.
 */
final class RestoreDriveRetryTest extends TestCase
{
    protected function setUp(): void
    {
        if (!NodeProbe::available()) {
            $this->markTestSkipped('node is unavailable, so the emitted driver cannot be executed.');
        }
    }

    /**
     * The driver is generated, so it is parsed rather than trusted: a syntax
     * error in the emitted script is a blank console, which is the worst moment
     * to discover it.
     */
    #[Test]
    public function theEmittedDriverIsValidJavaScriptInBothShapes(): void
    {
        foreach (['a-token' => bin2hex(random_bytes(32)), 'no-token' => ''] as $shape => $token) {
            $result = NodeProbe::check($this->driverScript($token));

            $this->assertSame(0, $result['exit'], $shape . ': ' . $result['output']);
        }
    }

    /**
     * Defect 1, first half: a `begin` that never answered has armed nothing, so
     * a `chunk` retry would ask the server to advance a state that does not
     * exist.
     */
    #[Test]
    public function aBeginFailureIsRetriedAsBeginNotChunk(): void
    {
        $run = $this->drive('begin-transport');

        $this->assertSame('begin', $run['requests'][0] ?? null, 'premise: the drive starts with begin');
        $this->assertSame(
            'begin',
            $run['requests'][1] ?? null,
            'a begin with no progress leaves no state on disk: the retry must re-issue begin'
        );
    }

    /**
     * Defect 1, second half: once progress exists the state is on disk, so the
     * retry is `chunk` — the resumable half of the protocol.
     */
    #[Test]
    public function aChunkFailureAfterProgressIsRetriedAsChunk(): void
    {
        $run = $this->drive('chunk-transport-after-progress');

        $this->assertSame(
            ['begin', 'chunk', 'chunk'],
            $run['requests'],
            'after progress the drive resumes with chunk, so the retry is chunk too'
        );
    }

    /**
     * Defect 2, first half: a 401 is the session or CSRF guard rejecting the
     * request. Retrying it spends the budget on a request the server has already
     * decided to refuse, and delays the only action that helps.
     */
    #[Test]
    public function aRejectedSessionIsTerminalAndNamesTheRealCause(): void
    {
        $run = $this->drive('unauthorized');
        $log = implode("\n", $run['log']);

        $this->assertCount(1, $run['requests'], 'a 401 will always be refused: it must not be retried');
        $this->assertStringContainsString('sesión', $log, 'the message names the session, which is the real cause');
        $this->assertStringContainsString('Vuelve a iniciar sesión', $log);
        $this->assertStringContainsString('No se puede confirmar', $log);
    }

    /**
     * Defect 2, second half: the diagnosis. A rejection is not a network cut,
     * and telling the operator to look at connectivity sends them to the wrong
     * place.
     */
    #[Test]
    public function aRejectedSessionIsNotReportedAsANetworkFailure(): void
    {
        $log = implode("\n", $this->drive('unauthorized')['log']);

        $this->assertStringNotContainsString('Corte', $log, 'the server answered: nothing was cut');
        $this->assertStringNotContainsString('error de red', $log);
        $this->assertStringNotContainsString('error del servidor o de la red', $log);
    }

    /**
     * A 403 is the same class as a 401 — the guard refusing, not a transport
     * failure — so it must not enter the retry budget either.
     */
    #[Test]
    public function aForbiddenResponseIsTerminalToo(): void
    {
        $run = $this->drive('forbidden');

        $this->assertCount(1, $run['requests'], 'a 403 is a decision, not a cut');
        $this->assertStringContainsString('Vuelve a iniciar sesión', implode("\n", $run['log']));
    }

    /**
     * A 5xx *is* transient, so it keeps the bounded budget — and the retry
     * re-issues the action in flight, which here is still `begin`. The terminal
     * message must not claim a restore it cannot verify.
     */
    #[Test]
    public function aServerErrorRetriesTheActionInFlightWithinTheBudget(): void
    {
        $run = $this->drive('server-error');
        $log = implode("\n", $run['log']);

        $this->assertCount(6, $run['requests'], 'one attempt plus the five bounded retries, and no more');
        $this->assertSame(
            ['begin'],
            array_values(array_unique($run['requests'])),
            'every retry re-issues the action that failed'
        );

        $this->assertStringContainsString('Vuelve a iniciar sesión', $log);
        $this->assertStringContainsString('No se puede confirmar', $log);
        $this->assertStringNotContainsString('El avance queda guardado', $log);
        $this->assertStringNotContainsString('completada con éxito', $log);
    }

    /**
     * @return array{requests: list<string>, log: list<string>}
     */
    private function drive(string $scenario): array
    {
        $run = NodeProbe::driveRestore($this->driverScript(bin2hex(random_bytes(32))), $scenario);

        $this->assertIsArray($run, 'the harness must print one JSON document');
        $this->assertArrayNotHasKey('error', $run, 'the drive must run to a settled state');

        return [
            'requests' => array_values((array) ($run['requests'] ?? [])),
            'log' => array_values((array) ($run['log'] ?? [])),
        ];
    }

    /**
     * The emitted bytes are a `<script>` element; Node parses the code inside it.
     */
    private function driverScript(string $token): string
    {
        $driver = recovery_view_restore_driver($token);

        return str_replace(['<script>', '</script>'], '', $driver);
    }
}
