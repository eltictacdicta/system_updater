<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/system_updater/lib/plugin_downloader.php';

final class PluginDownloaderUpdatesTest extends TestCase
{
    public function testGetAvailableUpdatesDetectsPublicPluginWithNewerRemoteVersion(): void
    {
        $tempRoot = sys_get_temp_dir() . '/fs_updates_' . uniqid('', true);
        mkdir($tempRoot . '/plugins/catalogo_core', 0777, true);
        mkdir($tempRoot . '/plugins/system_updater/data', 0777, true);

        file_put_contents(
            $tempRoot . '/plugins/catalogo_core/fsframework.ini',
            "version = 1.0.0\n"
        );

        $downloader = new class($tempRoot) extends \plugin_downloader {
            public function __construct(private string $customRoot)
            {
                parent::__construct();
                $ref = new \ReflectionProperty(\plugin_downloader::class, 'fsRoot');
                $ref->setAccessible(true);
                $ref->setValue($this, $this->customRoot);
            }

            public function downloads()
            {
                return [
                    [
                        'id' => 90,
                        'nombre' => 'catalogo_core',
                        'version' => '2.0.0',
                        'descripcion' => 'Catálogo',
                        'instalado' => true,
                        'zip_link' => 'https://example.test/catalogo_core.zip',
                    ],
                ];
            }

            public function private_downloads($force_reload = false)
            {
                return [];
            }

            public function is_private_plugins_enabled()
            {
                return false;
            }
        };

        $installed = [
            ['name' => 'catalogo_core', 'version' => '1.0.0'],
        ];

        $updates = $downloader->getAvailableUpdates($installed);

        $this->assertCount(1, $updates);
        $this->assertSame('catalogo_core', $updates[0]['name']);
        $this->assertSame('public', $updates[0]['source']);
        $this->assertSame('2.0.0', $updates[0]['new_version']);

        $this->removeTree($tempRoot);
    }

    public function testGetAvailableUpdatesNormalizesIntegerLocalVersion(): void
    {
        $tempRoot = sys_get_temp_dir() . '/fs_updates_' . uniqid('', true);
        mkdir($tempRoot . '/plugins/catalogo_core', 0777, true);

        $downloader = new class($tempRoot) extends \plugin_downloader {
            public function __construct(private string $customRoot)
            {
                parent::__construct();
                $ref = new \ReflectionProperty(\plugin_downloader::class, 'fsRoot');
                $ref->setAccessible(true);
                $ref->setValue($this, $this->customRoot);
            }

            public function downloads()
            {
                return [
                    [
                        'id' => 90,
                        'nombre' => 'catalogo_core',
                        'version' => '1.1.0',
                        'descripcion' => 'Catálogo',
                        'instalado' => true,
                        'zip_link' => 'https://example.test/catalogo_core.zip',
                    ],
                ];
            }

            public function private_downloads($force_reload = false)
            {
                return [];
            }

            public function is_private_plugins_enabled()
            {
                return false;
            }
        };

        $installed = [
            ['name' => 'catalogo_core', 'version' => '1'],
        ];

        $updates = $downloader->getAvailableUpdates($installed);

        $this->assertCount(1, $updates);
        $this->assertSame('1.0.0', $updates[0]['current_version']);
        $this->assertSame('1.1.0', $updates[0]['new_version']);

        $this->removeTree($tempRoot);
    }

    public function testFindPublicEntryByNameReturnsCatalogRow(): void
    {
        $downloader = new class extends \plugin_downloader {
            public function downloads()
            {
                return [
                    ['id' => 97, 'nombre' => 'tpvmod', 'version' => '3.0.0', 'instalado' => true],
                ];
            }
        };

        $entry = $downloader->findPublicEntryByName('tpvmod');

        $this->assertIsArray($entry);
        $this->assertSame(97, $entry['id']);
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
