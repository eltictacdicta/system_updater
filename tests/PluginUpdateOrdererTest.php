<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/system_updater/lib/PluginUpdateOrderer.php';

final class PluginUpdateOrdererTest extends TestCase
{
    public function testOrderEmptyBatchReturnsEmptyList(): void
    {
        $this->assertSame([], \PluginUpdateOrderer::order([], $this->noRequirements()));
    }

    public function testOrderSinglePluginWithoutDependencies(): void
    {
        $this->assertSame(['A'], \PluginUpdateOrderer::order(['A'], $this->noRequirements()));
    }

    public function testOrderDirectDependencyComesFirst(): void
    {
        $this->assertSame(
            ['B', 'A'],
            \PluginUpdateOrderer::order(
                ['A'],
                $this->requirements(['A' => ['B']]),
                $this->installed(['A', 'B'])
            )
        );
    }

    public function testOrderTransitiveDependenciesDepsFirst(): void
    {
        $this->assertSame(
            ['C', 'B', 'A'],
            \PluginUpdateOrderer::order(
                ['A'],
                $this->requirements(['A' => ['B'], 'B' => ['C']]),
                $this->installed(['A', 'B', 'C'])
            )
        );
    }

    public function testOrderIncludesInstalledDependencyNotInBatch(): void
    {
        $this->assertSame(
            ['B', 'A'],
            \PluginUpdateOrderer::order(
                ['A'],
                $this->requirements(['A' => ['B'], 'B' => []]),
                $this->installed(['A', 'B'])
            )
        );
    }

    public function testOrderSkipsMissingDependencyWithoutBlocking(): void
    {
        $this->assertSame(
            ['A'],
            \PluginUpdateOrderer::order(
                ['A'],
                $this->requirements(['A' => ['B']]),
                $this->installed(['A'])
            )
        );
    }

    public function testOrderCycleDoesNotThrowAndKeepsOriginalOrder(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'fs_orderer_');
        $previous = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $result = \PluginUpdateOrderer::order(
                ['A', 'B'],
                $this->requirements(['A' => ['B'], 'B' => ['A']]),
                $this->installed(['A', 'B'])
            );
        } finally {
            ini_set('error_log', $previous);
        }

        $this->assertSame(['A', 'B'], $result);

        $log = @file_get_contents($logFile);
        $this->assertIsString($log);
        $this->assertStringContainsString('A, B', $log);

        @unlink($logFile);
    }

    public function testOrderIgnoresSelfRequirement(): void
    {
        $this->assertSame(
            ['A'],
            \PluginUpdateOrderer::order(
                ['A'],
                $this->requirements(['A' => ['A']])
            )
        );
    }

    public function testOrderUnknownPluginWithoutCatalogEntryIsLeaf(): void
    {
        $this->assertSame(
            ['A'],
            \PluginUpdateOrderer::order(['A'], static fn(string $name): array => [])
        );
    }

    public function testOrderIndependentRootsKeepStableBatchOrder(): void
    {
        $this->assertSame(
            ['B', 'A'],
            \PluginUpdateOrderer::order(['B', 'A'], $this->noRequirements())
        );
    }

    private function noRequirements(): callable
    {
        return static fn(string $name): array => [];
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