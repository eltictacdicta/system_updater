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

namespace Tests\SystemUpdater\Support;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ConsoleHttpHarness.php';

/**
 * Shared lifecycle for the console's HTTP cases.
 *
 * Every harness is registered so the test's `tearDown` destroys it even when an
 * assertion throws; the harness registers its own shutdown backstop for the
 * case where the process dies mid-test. Both matter: a leaked `php -S` would
 * hold a port, and a leaked fixture tree under `/tmp` would keep a throwaway
 * secret (and a throwaway unlock value) on disk.
 */
abstract class ConsoleHttpTestCase extends TestCase
{
    /** @var ConsoleHttpHarness[] */
    private array $harnesses = [];

    protected function tearDown(): void
    {
        foreach ($this->harnesses as $harness) {
            $harness->destroy();
        }
        $this->harnesses = [];

        parent::tearDown();
    }

    /**
     * Source with comments and docblocks removed.
     *
     * Prose may name the things the code refuses to do — an identity key the
     * marker must not touch, a class the bootstrap must not autoload — while the
     * assertion has to be about the code. Stripping comments is what keeps those
     * two claims separate instead of turning the test into a spellcheck.
     */
    protected static function codeWithoutComments(string $source): string
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

    protected function newHarness(array $files = ConsoleHttpHarness::CONSOLE_FILES): ConsoleHttpHarness
    {
        if (!ConsoleHttpHarness::curlAvailable()) {
            $this->markTestSkipped('curl is unavailable, so the console HTTP seam cannot observe headers.');
        }

        $harness = new ConsoleHttpHarness($files);
        $this->harnesses[] = $harness;

        return $harness;
    }
}
