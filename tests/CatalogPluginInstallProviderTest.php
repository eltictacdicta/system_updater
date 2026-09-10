<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/system_updater/lib/CatalogPluginInstallProvider.php';

class CatalogPluginInstallProviderTest extends TestCase
{
    public function testReturnsLocalRequirementsBeforeCatalog(): void
    {
        $tempRoot = sys_get_temp_dir() . '/fs_catalog_provider_' . uniqid('', true);
        mkdir($tempRoot . '/plugins/business_data', 0777, true);
        file_put_contents(
            $tempRoot . '/plugins/business_data/fsframework.ini',
            "version = 1\nrequire = catalogo_core\n"
        );

        $downloader = new class extends \plugin_downloader {
            public function downloads()
            {
                return [
                    [
                        'id' => 91,
                        'nombre' => 'business_data',
                        'require' => 'legacy_support',
                        'link' => 'https://github.com/example/business_data',
                        'zip_link' => 'https://github.com/example/business_data/archive/main.zip',
                    ],
                ];
            }
        };

        $provider = new \CatalogPluginInstallProvider($downloader);
        $ref = new \ReflectionProperty(\CatalogPluginInstallProvider::class, 'localReader');
        $ref->setAccessible(true);
        $ref->setValue($provider, new \FSFramework\Core\Plugin\LocalPluginRequirementsReader($tempRoot . '/plugins'));

        $this->assertSame(['catalogo_core'], $provider->getDirectRequirements('business_data'));

        $this->removeTree($tempRoot);
    }

    public function testInstallRejectsPluginsOutsideCatalog(): void
    {
        $tempRoot = sys_get_temp_dir() . '/fs_catalog_miss_' . uniqid('', true);
        mkdir($tempRoot . '/plugins', 0777, true);

        $downloader = new class extends \plugin_downloader {
            public function downloads()
            {
                return [];
            }
        };

        $provider = new \CatalogPluginInstallProvider($downloader);
        $ref = new \ReflectionProperty(\CatalogPluginInstallProvider::class, 'localReader');
        $ref->setAccessible(true);
        $ref->setValue($provider, new \FSFramework\Core\Plugin\LocalPluginRequirementsReader($tempRoot . '/plugins'));

        $this->assertFalse($provider->installIfAvailable('api_base'));
        $this->assertStringContainsString('catálogo público', $provider->getLastError());

        $this->removeTree($tempRoot);
    }

    public function testInstallUsesDownloaderForCatalogEntry(): void
    {
        $tempRoot = sys_get_temp_dir() . '/fs_catalog_dl_' . uniqid('', true);
        mkdir($tempRoot . '/plugins', 0777, true);

        $downloader = new class($tempRoot) extends \plugin_downloader {
            public array $downloadedIds = [];

            public function __construct(private string $tempRoot)
            {
                parent::__construct();
            }

            public function downloads()
            {
                return [
                    [
                        'id' => 90,
                        'nombre' => 'catalogo_core',
                        'link' => 'https://github.com/example/catalogo_core',
                        'zip_link' => 'https://github.com/example/catalogo_core/archive/main.zip',
                    ],
                ];
            }

            public function download($plugin_id, ?string $zipUrlOverride = null)
            {
                $this->downloadedIds[] = (int) $plugin_id;
                mkdir($this->tempRoot . '/plugins/catalogo_core', 0777, true);
                file_put_contents(
                    $this->tempRoot . '/plugins/catalogo_core/fsframework.ini',
                    "version = 1\nrequire = \n"
                );

                return true;
            }
        };

        $provider = new \CatalogPluginInstallProvider($downloader);
        $ref = new \ReflectionProperty(\CatalogPluginInstallProvider::class, 'localReader');
        $ref->setAccessible(true);
        $ref->setValue($provider, new \FSFramework\Core\Plugin\LocalPluginRequirementsReader($tempRoot . '/plugins'));

        $this->assertTrue($provider->installIfAvailable('catalogo_core'));
        $this->assertSame([90], $downloader->downloadedIds);

        $this->removeTree($tempRoot);
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
