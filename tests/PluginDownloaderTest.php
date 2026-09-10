<?php

namespace Tests\SystemUpdater;

use PHPUnit\Framework\Attributes\DataProvider;
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

            // T11: hydration now fetches releases.json; keep the test hermetic.
            protected function get_remote_plugin_releases(array $plugin_data, ?string $token = null): array
            {
                return [];
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

            // T11: hydration now fetches releases.json; keep the test hermetic.
            protected function get_remote_plugin_releases(array $plugin_data, ?string $token = null): array
            {
                return [];
            }
        };

        $downloads = $downloader->downloads();
        $names = array_column($downloads, 'nombre');

        $this->assertContains('facturacion_base', $names);
        $this->assertContains('catalogo_core', $names);

        $this->removeTree($tempRoot);
    }

    public function testGetRemotePluginReleasesParsesPublicRawHistory(): void
    {
        $response = json_encode([
            [
                'version' => '1.8.1',
                'min_version' => '0.13',
                'max_version' => '0.16',
                'zip_url' => 'https://example.test/tpvmod-1.8.1.zip',
            ],
            [
                'version' => '1.9.0',
                'min_version' => '0.17',
                'max_version' => '',
                'zip_url' => 'https://example.test/tpvmod-1.9.0.zip',
            ],
        ]);

        $downloader = $this->makeReleasesDownloader($response);
        $releases = $downloader->fetchReleases([
            'link' => 'https://github.com/acme/tpvmod',
            'branch' => 'main',
        ]);

        $this->assertSame(
            ['https://raw.githubusercontent.com/acme/tpvmod/main/releases.json'],
            $downloader->fetchedUrls
        );
        $this->assertCount(2, $releases);
        $this->assertSame('1.8.1', $releases[0]['version']);
        $this->assertSame('0.13', $releases[0]['min_version']);
        $this->assertSame('0.16', $releases[0]['max_version']);
        $this->assertSame('https://example.test/tpvmod-1.8.1.zip', $releases[0]['zip_url']);
        $this->assertSame('1.9.0', $releases[1]['version']);
    }

    public function testGetRemotePluginReleasesDefaultsToMasterBranch(): void
    {
        $downloader = $this->makeReleasesDownloader(json_encode([
            ['version' => '1.0.0', 'zip_url' => 'https://example.test/1.0.0.zip'],
        ]));

        $downloader->fetchReleases(['link' => 'https://github.com/acme/tpvmod']);

        $this->assertSame(
            ['https://raw.githubusercontent.com/acme/tpvmod/master/releases.json'],
            $downloader->fetchedUrls
        );
    }

    public function testGetRemotePluginReleasesDropsMalformedEntries(): void
    {
        $downloader = $this->makeReleasesDownloader(json_encode([
            ['version' => '1.2.3', 'zip_url' => 'https://example.test/1.2.3.zip'],
            'not-an-array',
            ['no_version' => true],
        ]));

        $releases = $downloader->fetchReleases(['link' => 'https://github.com/acme/tpvmod']);

        $this->assertCount(1, $releases);
        $this->assertSame('1.2.3', $releases[0]['version']);
    }

    /**
     * @param mixed $response
     */
    #[DataProvider('provideFailingReleasesResponses')]
    public function testGetRemotePluginReleasesReturnsEmptyOnFailure($response): void
    {
        $downloader = $this->makeReleasesDownloader($response);

        $releases = $downloader->fetchReleases(['link' => 'https://github.com/acme/tpvmod']);

        $this->assertSame([], $releases);
    }

    public static function provideFailingReleasesResponses(): array
    {
        return [
            'network error string' => ['ERROR'],
            'network error false' => [false],
            'invalid json' => ['not-json'],
            'non-array json' => [json_encode('hello')],
            'empty history' => [json_encode([])],
        ];
    }

    public function testGetRemotePluginReleasesReturnsEmptyWithoutRepositoryFields(): void
    {
        $downloader = $this->makeReleasesDownloader(json_encode([
            ['version' => '1.0.0', 'zip_url' => 'https://example.test/1.0.0.zip'],
        ]));

        $releases = $downloader->fetchReleases([]);

        $this->assertSame([], $releases);
        $this->assertSame([], $downloader->fetchedUrls);
    }

    private function makeReleasesDownloader($response): object
    {
        return new class($response) extends \plugin_downloader {
            public array $fetchedUrls = [];

            public function __construct(public $response)
            {
                parent::__construct();
            }

            protected function fetchRemoteContents($url, $timeout = 10)
            {
                $this->fetchedUrls[] = $url;
                return $this->response;
            }

            public function fetchReleases(array $plugin_data, ?string $token = null): array
            {
                return $this->get_remote_plugin_releases($plugin_data, $token);
            }
        };
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