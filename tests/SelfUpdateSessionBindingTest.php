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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/updater_manager.php';

/**
 * The self-update manifest is written by the plugin (inside the app, with the
 * admin session) and consumed by updater.php (outside the bootstrap, unable to
 * open that session). Binding the manifest to the framework session with an
 * HMAC means a token leaked from tmp/ is not enough to finish the swap.
 */
final class SelfUpdateSessionBindingTest extends TestCase
{
    private array $cookiesBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cookiesBackup = $_COOKIE;
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookiesBackup;

        parent::tearDown();
    }

    #[Test]
    public function bindingCarriesTheSessionNameAndTheHmacOfTheSessionId(): void
    {
        $sessionName = (string) session_name();
        $this->assertNotSame('', $sessionName, 'A CLI run always has a session name.');

        // Prefer the active session id: the implementation does the same.
        $sessionId = session_id() !== '' ? (string) session_id() : 'session-id-for-test';
        $_COOKIE[$sessionName] = $sessionId;

        $binding = $this->buildBinding();

        $this->assertSame($sessionName, $binding['session_name'] ?? null);
        $this->assertSame(
            hash_hmac('sha256', $sessionId, (string) \FSFramework\Security\SecretManager::getSecret()),
            $binding['session_fingerprint'] ?? null
        );
    }

    #[Test]
    public function bindingIsEmptyWithoutASessionId(): void
    {
        if (session_id() !== '') {
            $this->markTestSkipped('There is an active PHP session in this process.');
        }

        $_COOKIE = [];

        $this->assertSame([], $this->buildBinding());
    }

    /**
     * @return array<string, string>
     */
    private function buildBinding(): array
    {
        $manager = (new \ReflectionClass(\updater_manager::class))->newInstanceWithoutConstructor();

        $method = new \ReflectionMethod(\updater_manager::class, 'buildSessionBinding');
        $method->setAccessible(true);

        return (array) $method->invoke($manager);
    }
}
