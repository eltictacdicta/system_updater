<?php

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/system_updater/lib/plugin_catalog_manager.php';

class PluginCatalogManagerTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/fs_catalog_test_' . uniqid('', true);
        mkdir($this->tempRoot . '/plugins/system_updater/data', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempRoot);
    }

    public function testValidateCatalogRequiresMandatoryFields(): void
    {
        $manager = new \plugin_catalog_manager($this->tempRoot);

        $this->assertFalse($manager->validateCatalog([]));
        $this->assertNotEmpty($manager->getErrors());

        $this->assertFalse($manager->validateCatalog([
            ['nombre' => 'demo', 'link' => 'https://example.com'],
        ]));
    }

    public function testSaveAndLoadLocalCatalog(): void
    {
        $manager = new \plugin_catalog_manager($this->tempRoot);
        $entries = [
            [
                'id' => 1,
                'nombre' => 'catalogo_core',
                'descripcion' => 'Catálogo base',
                'link' => 'https://github.com/eltictacdicta/catalogo_core',
                'zip_link' => 'https://github.com/eltictacdicta/catalogo_core/archive/main.zip',
                'branch' => 'main',
                'estable' => true,
            ],
        ];

        $this->assertTrue($manager->saveLocalCatalog($entries));
        $this->assertSame('catalogo_core', $manager->loadLocalCatalog()[0]['nombre']);
        $this->assertStringContainsString('catalogo_core', $manager->getLocalCatalogJson());
    }

    public function testParseCatalogJsonRejectsInvalidPayload(): void
    {
        $manager = new \plugin_catalog_manager($this->tempRoot);
        $this->assertNull($manager->parseCatalogJson('{invalid'));
    }

    public function testSanitizeRepoRejectsInvalidFormat(): void
    {
        $manager = new \plugin_catalog_manager($this->tempRoot);
        $method = new \ReflectionMethod(\plugin_catalog_manager::class, 'sanitizeRepo');
        $method->setAccessible(true);

        $this->assertSame(
            \plugin_catalog_manager::DEFAULT_REPO,
            $method->invoke($manager, 'https://github.com/evil/../other-repo')
        );
        $this->assertNotEmpty($manager->getErrors());
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
