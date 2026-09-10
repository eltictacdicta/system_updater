<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testHistoryBranchExposesResolvedReleaseBoundsAndZip(): void
    {
        $downloader = $this->makeDownloader([
            [
                'id' => 97,
                'nombre' => 'tpvmod',
                'version' => '1.9.0',
                'descripcion' => 'TPV',
                'instalado' => true,
                'zip_link' => 'https://example.test/tpvmod-master.zip',
                'releases' => [
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
                ],
            ],
        ]);

        $updates = $downloader->getAvailableUpdates(
            [['name' => 'tpvmod', 'version' => '1.8.0']],
            '0.14'
        );

        $this->assertCount(1, $updates);
        $this->assertSame('public', $updates[0]['source']);
        $this->assertSame('1.8.0', $updates[0]['current_version']);
        $this->assertSame('1.8.1', $updates[0]['new_version']);
        $this->assertSame('0.13', $updates[0]['min_version']);
        $this->assertSame('0.16', $updates[0]['max_version']);
        $this->assertSame('https://example.test/tpvmod-1.8.1.zip', $updates[0]['zip_link']);
        $this->assertTrue($updates[0]['resolved_from_history']);
    }

    public function testCatalogVersionsArrayActsAsEquivalentHistory(): void
    {
        $history = [
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
        ];

        $base = [
            'id' => 97,
            'nombre' => 'tpvmod',
            'version' => '1.9.0',
            'descripcion' => 'TPV',
            'instalado' => true,
            'zip_link' => 'https://example.test/tpvmod-master.zip',
        ];

        $withReleases = $this->makeDownloader([array_merge($base, ['releases' => $history])]);
        $withVersions = $this->makeDownloader([array_merge($base, ['versions' => $history])]);

        $installed = [['name' => 'tpvmod', 'version' => '1.8.0']];

        $fromReleases = $withReleases->getAvailableUpdates($installed, '0.14');
        $fromVersions = $withVersions->getAvailableUpdates($installed, '0.14');

        $this->assertCount(1, $fromVersions);
        $this->assertSame('1.8.1', $fromVersions[0]['new_version']);
        $this->assertSame($fromReleases, $fromVersions);
    }

    public function testNoUpdateListedWhenResolverReturnsNull(): void
    {
        $downloader = $this->makeDownloader([
            [
                'id' => 97,
                'nombre' => 'tpvmod',
                'version' => '1.9.0',
                'descripcion' => 'TPV',
                'instalado' => true,
                'releases' => [
                    [
                        'version' => '1.9.0',
                        'min_version' => '0.20',
                        'max_version' => '',
                        'zip_url' => 'https://example.test/tpvmod-1.9.0.zip',
                    ],
                ],
            ],
        ]);

        $updates = $downloader->getAvailableUpdates(
            [['name' => 'tpvmod', 'version' => '1.8.0']],
            '0.14'
        );

        $this->assertSame([], $updates);
    }

    public function testNoDowngradeWhenOnlyCompatibleReleaseIsNotNewer(): void
    {
        $downloader = $this->makeDownloader([
            [
                'id' => 97,
                'nombre' => 'tpvmod',
                'version' => '1.8.0',
                'descripcion' => 'TPV',
                'instalado' => true,
                'releases' => [
                    [
                        'version' => '1.8.0',
                        'min_version' => '',
                        'max_version' => '',
                        'zip_url' => 'https://example.test/tpvmod-1.8.0.zip',
                    ],
                ],
            ],
        ]);

        $updates = $downloader->getAvailableUpdates(
            [['name' => 'tpvmod', 'version' => '1.8.0']],
            '0.14'
        );

        $this->assertSame([], $updates);
    }

    public function testPrivateHistoryBranchExposesResolvedRelease(): void
    {
        $downloader = $this->makeDownloader([], [
            [
                'id' => 'priv_5',
                'nombre' => 'private_tpv',
                'version' => '1.9.0',
                'descripcion' => 'Privado',
                'instalado' => true,
                'releases' => [
                    [
                        'version' => '1.8.1',
                        'min_version' => '0.13',
                        'max_version' => '0.16',
                        'zip_url' => 'https://example.test/private_tpv-1.8.1.zip',
                    ],
                ],
            ],
        ], true);

        $updates = $downloader->getAvailableUpdates(
            [['name' => 'private_tpv', 'version' => '1.8.0']],
            '0.14'
        );

        $this->assertCount(1, $updates);
        $this->assertSame('private', $updates[0]['source']);
        $this->assertSame('1.8.1', $updates[0]['new_version']);
        $this->assertSame('https://example.test/private_tpv-1.8.1.zip', $updates[0]['zip_link']);
        $this->assertTrue($updates[0]['resolved_from_history']);
    }

    public function testDownloadPrivateUsesZipUrlOverrideWhenProvided(): void
    {
        $downloader = $this->makePrivateDownloadCaptureDownloader([[
            'id' => 'priv_5',
            'nombre' => 'private_tpv',
            'version' => '1.9.0',
            'zip_link' => 'http://127.0.0.1:1/private_tpv-master.zip',
            'instalado' => true,
        ]]);

        $result = $downloader->download_private('priv_5', 'https://example.test/private_tpv-1.8.1.zip');

        $this->assertFalse($result);
        $this->assertSame(['https://example.test/private_tpv-1.8.1.zip'], $downloader->fetchedPrivateZipUrls);
    }

    /**
     * @param string|null $override
     */
    #[DataProvider('provideEmptyOverrides')]
    public function testDownloadPrivateKeepsCatalogZipLinkWhenOverrideIsEmpty(?string $override): void
    {
        $downloader = $this->makePrivateDownloadCaptureDownloader([[
            'id' => 'priv_5',
            'nombre' => 'private_tpv',
            'version' => '1.9.0',
            'zip_link' => 'http://127.0.0.1:1/private_tpv-master.zip',
            'instalado' => true,
        ]]);

        $result = $downloader->download_private('priv_5', $override);

        $this->assertFalse($result);
        $this->assertSame(['http://127.0.0.1:1/private_tpv-master.zip'], $downloader->fetchedPrivateZipUrls);
    }

    public function testNoHistoryKeepsBranchTipBehavior(): void
    {
        $downloader = $this->makeDownloader([
            [
                'id' => 90,
                'nombre' => 'catalogo_core',
                'version' => '2.0.0',
                'descripcion' => 'Catálogo',
                'instalado' => true,
                'zip_link' => 'https://example.test/catalogo_core.zip',
                'min_version' => '0.10',
                'max_version' => '0.30',
            ],
        ]);

        $updates = $downloader->getAvailableUpdates(
            [['name' => 'catalogo_core', 'version' => '1.0.0']],
            '0.14'
        );

        // PU-14: exact branch-tip output, no zip_link / resolved_from_history keys.
        $this->assertSame([[
            'name' => 'catalogo_core',
            'description' => 'Catálogo',
            'current_version' => '1.0.0',
            'new_version' => '2.0.0',
            'source' => 'public',
            'id' => 90,
            'min_version' => '0.10',
            'max_version' => '0.30',
        ]], $updates);
    }

    public function testDownloadUsesZipUrlOverrideWhenProvided(): void
    {
        $downloader = $this->makeDownloadCaptureDownloader([[
            'id' => 97,
            'nombre' => 'tpvmod',
            'version' => '1.9.0',
            'zip_link' => 'http://127.0.0.1:1/tpvmod-master.zip',
            'instalado' => true,
        ]]);

        $result = $downloader->download(97, 'https://example.test/tpvmod-1.8.1.zip');

        $this->assertFalse($result);
        $this->assertSame(['https://example.test/tpvmod-1.8.1.zip'], $downloader->fetchedZipUrls);
    }

    /**
     * @param string|null $override
     */
    #[DataProvider('provideEmptyOverrides')]
    public function testDownloadKeepsCatalogZipLinkWhenOverrideIsEmpty(?string $override): void
    {
        $downloader = $this->makeDownloadCaptureDownloader([[
            'id' => 97,
            'nombre' => 'tpvmod',
            'version' => '1.9.0',
            'zip_link' => 'http://127.0.0.1:1/tpvmod-master.zip',
            'instalado' => true,
        ]]);

        $result = $downloader->download(97, $override);

        $this->assertFalse($result);
        $this->assertSame(['http://127.0.0.1:1/tpvmod-master.zip'], $downloader->fetchedZipUrls);
    }

    public static function provideEmptyOverrides(): array
    {
        return [
            'null override' => [null],
            'empty override' => [''],
        ];
    }

    public function testFindPublicUpdateByNameReturnsResolvedHistoryEntry(): void
    {
        $downloader = $this->makeDownloader([[
            'id' => 97,
            'nombre' => 'tpvmod',
            'version' => '1.9.0',
            'descripcion' => 'TPV',
            'instalado' => true,
            'zip_link' => 'https://example.test/tpvmod-master.zip',
            'releases' => [
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
            ],
        ]]);

        $entry = $downloader->findPublicUpdateByName(
            'tpvmod',
            [['name' => 'tpvmod', 'version' => '1.8.0']],
            '0.14'
        );

        $this->assertIsArray($entry);
        $this->assertSame('1.8.1', $entry['new_version']);
        $this->assertSame('https://example.test/tpvmod-1.8.1.zip', $entry['zip_link']);
        $this->assertTrue($entry['resolved_from_history']);
    }

    public function testFindPublicUpdateByNameReturnsNullWhenPluginHasNoUpdate(): void
    {
        $downloader = $this->makeDownloader([[
            'id' => 97,
            'nombre' => 'tpvmod',
            'version' => '1.9.0',
            'descripcion' => 'TPV',
            'instalado' => true,
        ]]);

        $entry = $downloader->findPublicUpdateByName(
            'tpvmod',
            [['name' => 'tpvmod', 'version' => '1.9.0']],
            '0.14'
        );

        $this->assertNull($entry);
    }

    /**
     * @param array<int, array<string, mixed>> $publicEntries
     */
    private function makeDownloadCaptureDownloader(array $publicEntries): object
    {
        return new class($publicEntries) extends \plugin_downloader {
            /** @var list<string> */
            public array $fetchedZipUrls = [];

            public function __construct(private array $publicEntries)
            {
                parent::__construct();
            }

            public function downloads()
            {
                return $this->publicEntries;
            }

            public function private_downloads($force_reload = false)
            {
                return [];
            }

            public function is_private_plugins_enabled()
            {
                return false;
            }

            // Test seam: intercept the download fetch to assert the effective URL
            // without performing any real network I/O.
            protected function fetchZipToFile(string $zipUrl, string $zipPath): bool
            {
                $this->fetchedZipUrls[] = $zipUrl;
                return false;
            }
        };
    }

    /**
     * @param array<int, array<string, mixed>> $privateEntries
     */
    private function makePrivateDownloadCaptureDownloader(array $privateEntries): object
    {
        return new class($privateEntries) extends \plugin_downloader {
            /** @var list<string> */
            public array $fetchedPrivateZipUrls = [];

            public function __construct(private array $privateEntries)
            {
                parent::__construct();
            }

            public function is_private_plugins_enabled()
            {
                return true;
            }

            public function get_private_config()
            {
                return [
                    'github_token' => 'test-token',
                    'private_plugins_url' => 'https://example.test/private_plugins.json',
                    'enabled' => true,
                ];
            }

            public function private_downloads($force_reload = false)
            {
                return $this->privateEntries;
            }

            // Test seam: capture the effective private ZIP URL without real I/O.
            protected function fetchPrivateZipToFile(string $zipUrl, string $zipPath, string $token): bool
            {
                $this->fetchedPrivateZipUrls[] = $zipUrl;
                return false;
            }
        };
    }

    /**
     * @param array<int, array<string, mixed>> $publicEntries
     * @param array<int, array<string, mixed>> $privateEntries
     */
    private function makeDownloader(
        array $publicEntries,
        array $privateEntries = [],
        bool $privateEnabled = false
    ): object {
        return new class($publicEntries, $privateEntries, $privateEnabled) extends \plugin_downloader {
            public function __construct(
                private array $publicEntries,
                private array $privateEntries,
                private bool $privateEnabled
            ) {
                parent::__construct();
            }

            public function downloads()
            {
                return $this->publicEntries;
            }

            public function private_downloads($force_reload = false)
            {
                return $this->privateEntries;
            }

            public function is_private_plugins_enabled()
            {
                return $this->privateEnabled;
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
