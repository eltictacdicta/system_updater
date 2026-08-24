<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/base/fs_plugin_manager.php';
require_once FS_FOLDER . '/plugins/system_updater/lib/PluginSchemaResyncer.php';

final class PluginSchemaResyncerTest extends TestCase
{
    private array $globalsSnapshot = [];

    protected function setUp(): void
    {
        $this->globalsSnapshot = $GLOBALS['plugins'] ?? [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['plugins'] = $this->globalsSnapshot;
    }

    public function testResyncInstalledOrdersDependenciesFirstAndRestoresGlobals(): void
    {
        $GLOBALS['plugins'] = ['pre_existing'];

        $manager = $this->fakeManager(['B', 'A']);

        $result = \PluginSchemaResyncer::resyncInstalled(
            $manager,
            null,
            $this->requirements(['A' => ['B']]),
            $this->installed(['A', 'B'])
        );

        $this->assertTrue($result['success']);
        $this->assertSame(['B', 'A'], $result['updated']);
        $this->assertSame([], $result['failed']);

        // Orden deps-first: B (dependencia) antes que A.
        $this->assertSame(['B', 'A'], $manager->calls);

        // Durante cada llamada, el plugin y sus dependencias son visibles.
        $this->assertContains('B', $manager->globalsDuring['B']);
        $this->assertContains('A', $manager->globalsDuring['A']);
        $this->assertContains('B', $manager->globalsDuring['A']);

        // El snapshot se restaura tras el resync.
        $this->assertSame(['pre_existing'], $GLOBALS['plugins']);
    }

    public function testResyncInstalledWithOnlyPluginResolvesDependencies(): void
    {
        $GLOBALS['plugins'] = ['pre_existing'];

        $manager = $this->fakeManager(['B', 'A']);

        $result = \PluginSchemaResyncer::resyncInstalled(
            $manager,
            'A',
            $this->requirements(['A' => ['B']]),
            $this->installed(['A', 'B'])
        );

        $this->assertTrue($result['success']);
        $this->assertSame(['B', 'A'], $result['updated']);
        $this->assertSame(['B', 'A'], $manager->calls);
        $this->assertSame(['pre_existing'], $GLOBALS['plugins']);
    }

    public function testResyncInstalledCapturesFailedPlugin(): void
    {
        $GLOBALS['plugins'] = ['pre_existing'];

        $manager = $this->fakeManager(['A', 'C']);
        $manager->results['C'] = ['success' => false, 'changes' => [], 'errors' => ['no existe tabla']];

        $result = \PluginSchemaResyncer::resyncInstalled(
            $manager,
            null,
            $this->requirements(['A' => ['C']]),
            $this->installed(['A', 'C'])
        );

        $this->assertFalse($result['success']);
        $this->assertSame(['A'], $result['updated']);
        $this->assertSame(['C'], $result['failed']);
        $this->assertNotEmpty($result['messages']);
        $this->assertStringContainsString('C', $result['messages'][0]);
        $this->assertFalse($result['results']['C']['success']);
        $this->assertSame(['pre_existing'], $GLOBALS['plugins']);
    }

    public function testResyncInstalledRestoresSnapshotAndPropagatesException(): void
    {
        $GLOBALS['plugins'] = ['pre_existing'];

        $manager = $this->fakeManager(['A', 'B']);
        $manager->throwOn = 'A';

        try {
            \PluginSchemaResyncer::resyncInstalled(
                $manager,
                null,
                $this->requirements(['A' => ['B']]),
                $this->installed(['A', 'B'])
            );
            $this->fail('Se esperaba una RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom A', $e->getMessage());
        }

        $this->assertSame(['B', 'A'], $manager->calls);
        $this->assertSame(['pre_existing'], $GLOBALS['plugins']);
    }

    public function testWithDependencyVisibilityAddsAndRestoresOnSuccess(): void
    {
        $GLOBALS['plugins'] = ['pre_existing'];

        $seen = null;
        $result = \PluginSchemaResyncer::withDependencyVisibility(
            'A',
            static function () use (&$seen): string {
                $seen = $GLOBALS['plugins'] ?? [];

                return 'ok';
            },
            $this->requirements(['A' => ['B']]),
            $this->installed(['A', 'B'])
        );

        $this->assertSame('ok', $result);
        $this->assertSame(['pre_existing', 'B', 'A'], $seen);
        $this->assertSame(['pre_existing'], $GLOBALS['plugins']);
    }

    public function testWithDependencyVisibilityRestoresOnException(): void
    {
        $GLOBALS['plugins'] = ['pre_existing'];

        try {
            \PluginSchemaResyncer::withDependencyVisibility(
                'A',
                static function (): void {
                    throw new \RuntimeException('callback boom');
                },
                $this->requirements(['A' => ['B']]),
                $this->installed(['A', 'B'])
            );
            $this->fail('Se esperaba una RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('callback boom', $e->getMessage());
        }

        $this->assertSame(['pre_existing'], $GLOBALS['plugins']);
    }

    private function fakeManager(array $installedNames): object
    {
        return new class($installedNames) extends \fs_plugin_manager {
            public array $calls = [];

            public array $globalsDuring = [];

            public array $results = [];

            public ?string $throwOn = null;

            public function __construct(private array $installedNames)
            {
            }

            public function installed()
            {
                return array_map(
                    static fn(string $name): array => ['name' => $name],
                    $this->installedNames
                );
            }

            public function applyPluginSchemaUpdates(string $plugin_name): array
            {
                $this->calls[] = $plugin_name;
                $this->globalsDuring[$plugin_name] = $GLOBALS['plugins'] ?? [];

                if ($this->throwOn === $plugin_name) {
                    throw new \RuntimeException('boom ' . $plugin_name);
                }

                return $this->results[$plugin_name] ?? ['success' => true, 'changes' => [], 'errors' => []];
            }
        };
    }

    private function requirements(array $map): callable
    {
        return static fn(string $name): array => $map[$name] ?? [];
    }

    private function installed(array $names): callable
    {
        return static fn(string $name): bool => in_array($name, $names, true);
    }
}