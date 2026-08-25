<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/system_updater/lib/public_catalog_lookup.php';

final class PublicCatalogLookupTest extends TestCase
{
    public function testLocalCatalogListsKnownPlugin(): void
    {
        $this->assertTrue(system_updater_catalog_lists_plugin('catalogo_core', FS_FOLDER));
    }

    public function testLocalCatalogRejectsUnknownPlugin(): void
    {
        $this->assertFalse(system_updater_catalog_lists_plugin('api_base', FS_FOLDER));
    }

    public function testEntriesContainHelperMatchesByNombre(): void
    {
        $entries = [
            ['nombre' => 'demo_plugin'],
            ['nombre' => 'otro'],
        ];

        $this->assertTrue(system_updater_catalog_entries_contain_plugin($entries, 'demo_plugin'));
        $this->assertFalse(system_updater_catalog_entries_contain_plugin($entries, 'missing'));
    }
}
