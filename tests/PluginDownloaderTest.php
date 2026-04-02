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
}