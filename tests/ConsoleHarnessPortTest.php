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
 * The harness must serve *its* fixture and nobody else.
 *
 * The port is drawn at random, so another process can already be listening on
 * it. `php -S` then cannot bind and exits, but `fsockopen()` connects to the
 * foreign process and the accept condition ("something answers on this port")
 * is satisfied by a server that never read a single file of the fixture. Every
 * request in the case then goes to the wrong server, which produces either an
 * intermittent failure or — much worse — an assertion of **absence** that
 * passes without the console ever being observed ("no `Set-Cookie`", "no
 * session file", "the body does not reveal the console").
 *
 * The collision is not staged: a real `php -S` holds the port, the harness is
 * pinned to the same port, and the harness has to refuse loudly instead of
 * answering with the stranger.
 */
final class ConsoleHarnessPortTest extends ConsoleHttpTestCase
{
    private string $foreignRoot = '';

    /** @var resource|null */
    private $foreign = null;

    protected function tearDown(): void
    {
        if (is_resource($this->foreign)) {
            @proc_terminate($this->foreign);
            @proc_close($this->foreign);
        }
        $this->foreign = null;

        if ($this->foreignRoot !== '') {
            ConsoleHttpHarness::removeTree($this->foreignRoot);
            $this->foreignRoot = '';
        }

        parent::tearDown();
    }

    /**
     * A port a foreign server answers on must be refused, not adopted.
     */
    public function testAPortAnotherServerAnswersOnIsRefusedInsteadOfAdopted(): void
    {
        $port = $this->startForeignServer();

        $harness = $this->newHarness();
        $failure = null;

        try {
            $harness->boot('', $port);
        } catch (\RuntimeException $error) {
            $failure = $error;
        }

        $this->assertInstanceOf(
            \RuntimeException::class,
            $failure,
            'the harness accepted a port whose server is not the fixture: every later assertion would'
            . ' observe the wrong server, and the absence assertions would pass without ever seeing'
            . ' the console'
        );
        $this->assertStringContainsString(
            '127.0.0.1:' . $port,
            $failure->getMessage(),
            'the failure must name the port it could not take'
        );
    }

    /**
     * The positive control for the pinned port: the same seam still starts a
     * server when the port really is free, so the refusal above is about the
     * foreign listener and not about pinning a port.
     */
    public function testAPinnedFreePortStillServesTheFixture(): void
    {
        $port = $this->freePort();

        $harness = $this->newHarness();
        $harness->boot('', $port);

        file_put_contents($harness->root . '/probe.html', 'harness-ok');
        $response = $harness->request('/probe.html');

        $this->assertSame($port, (int) parse_url($harness->url('/'), PHP_URL_PORT), 'premise: the pinned port is the one in use');
        $this->assertSame(200, $response['status'], 'the fixture answered: ' . $response['curlError']);
        $this->assertSame('harness-ok', $response['body']);
    }

    /**
     * Take a port from the kernel, release it, and put a real `php -S` on it
     * serving a tree that is deliberately not a console fixture.
     */
    private function startForeignServer(): int
    {
        $port = $this->freePort();

        $this->foreignRoot = rtrim(sys_get_temp_dir(), '/') . '/su_foreign_' . bin2hex(random_bytes(6));
        if (!mkdir($this->foreignRoot, 0777, true) && !is_dir($this->foreignRoot)) {
            $this->fail('cannot create the foreign server tree');
        }
        file_put_contents($this->foreignRoot . '/index.html', 'not the console');

        $log = $this->foreignRoot . '/server.log';
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];

        $process = @proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $this->foreignRoot],
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            $this->fail('cannot start the foreign server');
        }

        $this->foreign = $process;

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorString, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return $port;
            }
            usleep(50_000);
        }

        $this->fail('the foreign server never listened on port ' . $port);
    }

    private function freePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorString);
        if (!is_resource($socket)) {
            $this->fail('cannot reserve a port: ' . $errorString);
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
