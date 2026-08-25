<?php

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/system_updater/lib/plugin_downloader.php';

class PluginDownloaderTest extends TestCase
{
    public function testDownloadsFallsBackToNextCatalogUrl(): void
    {
        $downloader = new class extends \plugin_downloader {
            public array $requestedUrls = [];

            protected function getPublicDownloadCatalogUrls()
            {
                return ['https://catalog.invalid/primary.json', 'https://catalog.valid/secondary.json'];
            }

            protected function fetchRemoteContents($url, $timeout = 10)
            {
                $this->requestedUrls[] = $url;

                if ($url === 'https://catalog.valid/secondary.json') {
                    return json_encode([
                        [
                            'nombre' => 'clientes_core',
                            'creador' => 'FSFramework',
                            'descripcion' => 'Plugin de clientes',
                            'version' => '2.0.0',
                            'link' => 'https://github.com/eltictacdicta/clientes_core',
                            'zip_link' => 'https://github.com/eltictacdicta/clientes_core/archive/master.zip',
                        ],
                    ]);
                }

                return false;
            }
        };

        $downloader->refresh();

        $downloads = $downloader->downloads();

        $this->assertCount(2, $downloader->requestedUrls);
        $this->assertSame('clientes_core', $downloads[0]['nombre']);
        $this->assertSame('2.0.0', $downloads[0]['version']);
    }

    public function testDownloadsMergesLocalCatalogEntries(): void
    {
        $tempRoot = sys_get_temp_dir() . '/fs_downloader_test_' . uniqid('', true);
        mkdir($tempRoot . '/plugins/system_updater/data', 0777, true);

        file_put_contents(
            $tempRoot . '/plugins/system_updater/data/custom_plugins.json',
            json_encode([
                [
                    'id' => 90,
                    'nombre' => 'catalogo_core',
                    'descripcion' => 'Catálogo local',
                    'link' => 'https://github.com/eltictacdicta/catalogo_core',
                    'zip_link' => 'https://github.com/eltictacdicta/catalogo_core/archive/main.zip',
                    'branch' => 'main',
                ],
            ], JSON_UNESCAPED_SLASHES)
        );

        $downloader = new class($tempRoot) extends \plugin_downloader {
            public function __construct(private string $customRoot)
            {
                parent::__construct();
                $ref = new \ReflectionProperty(\plugin_downloader::class, 'fsRoot');
                $ref->setAccessible(true);
                $ref->setValue($this, $this->customRoot);
            }

            protected function getPublicDownloadCatalogUrls()
            {
                return ['https://catalog.valid/secondary.json'];
            }

            protected function fetchRemoteContents($url, $timeout = 10)
            {
                return json_encode([
                    [
                        'id' => 87,
                        'nombre' => 'facturacion_base',
                        'descripcion' => 'Remoto',
                        'link' => 'https://github.com/eltictacdicta/facturacion_base',
                        'zip_link' => 'https://github.com/eltictacdicta/facturacion_base/archive/master.zip',
                    ],
                ]);
            }

            protected function get_remote_plugin_ini($plugin_data, $token = null)
            {
                return false;
            }
        };

        $downloads = $downloader->downloads();
        $names = array_column($downloads, 'nombre');

        $this->assertContains('facturacion_base', $names);
        $this->assertContains('catalogo_core', $names);

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