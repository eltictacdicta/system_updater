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

require_once FS_FOLDER . '/model/fs_page.php';
require_once dirname(__DIR__) . '/controller/admin_updater.php';

/**
 * admin_updater downloads and deploys code and replaces plugin files. Its
 * access must be declared in code, not left to a role row: the old positional
 * constructor flag is obsolete and ignored.
 */
final class AdminUpdaterAdminOnlyTest extends TestCase
{
    #[Test]
    public function theUpdaterPageIsDeclaredAdminOnly(): void
    {
        $this->assertTrue(
            \fs_page::is_admin_only_class('admin_updater'),
            'admin_updater must declare #[\FSFramework\Attribute\AdminOnly].'
        );
    }

    #[Test]
    public function theDeclarationSurvivesWithoutAnInstalledRoleRow(): void
    {
        // The attribute is resolved from the class, so it holds even when the
        // fs_pages row was never updated by a migration.
        $reflection = new \ReflectionClass('admin_updater');
        $names = array_map(static fn ($attribute) => $attribute->getName(), $reflection->getAttributes());

        $this->assertContains('FSFramework\\Attribute\\AdminOnly', $names);
    }
}
