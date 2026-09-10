<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/system_updater/lib/plugin_compatibility_checker.php';

/**
 * Coherence between the branch-tip compatibility guard and the resolved
 * historical release: the guard must validate the bounds of the release that
 * will actually be applied, not always the branch tip.
 */
final class PluginUpdateEntryCompatibilityTest extends TestCase
{
    public function testResolvedCompatibleEntryIsAllowedEvenWhenBranchTipIsIncompatible(): void
    {
        $branchTip = ['min_version' => '0.17', 'max_version' => ''];
        $resolved = [
            'min_version' => '0.13',
            'max_version' => '0.16',
            'resolved_from_history' => true,
        ];

        $evaluation = \plugin_compatibility_checker::evaluateUpdateEntryForCore(
            '0.14',
            $resolved,
            $branchTip
        );

        $this->assertTrue($evaluation['compatible']);
        $this->assertNull($evaluation['violation']);
    }

    public function testResolvedEntryWhoseOwnMinBoundIsIncompatibleIsBlocked(): void
    {
        $branchTip = ['min_version' => '0.13', 'max_version' => '0.16'];
        $resolved = [
            'min_version' => '0.17',
            'max_version' => '',
            'resolved_from_history' => true,
        ];

        $evaluation = \plugin_compatibility_checker::evaluateUpdateEntryForCore(
            '0.14',
            $resolved,
            $branchTip
        );

        $this->assertFalse($evaluation['compatible']);
        $this->assertSame('min', $evaluation['violation']);
    }

    public function testResolvedEntryWhoseOwnMaxBoundIsIncompatibleIsBlocked(): void
    {
        $evaluation = \plugin_compatibility_checker::evaluateUpdateEntryForCore(
            '0.20',
            [
                'min_version' => '0.13',
                'max_version' => '0.16',
                'resolved_from_history' => true,
            ],
            ['min_version' => '0.10', 'max_version' => '']
        );

        $this->assertFalse($evaluation['compatible']);
        $this->assertSame('max', $evaluation['violation']);
    }

    public function testFallbackEntryUsesBranchTipBoundsExactlyAsToday(): void
    {
        $branchTip = ['min_version' => '0.17', 'max_version' => ''];

        // No resolved_from_history flag: the branch tip governs, as before.
        $evaluation = \plugin_compatibility_checker::evaluateUpdateEntryForCore(
            '0.14',
            ['min_version' => '0.13', 'max_version' => '0.16'],
            $branchTip
        );

        $expected = \plugin_compatibility_checker::validateRemotePluginForCore('0.14', $branchTip);

        $this->assertSame($expected, $evaluation);
        $this->assertFalse($evaluation['compatible']);
        $this->assertSame('min', $evaluation['violation']);
    }

    public function testFallbackEntryCompatibleWhenBranchTipIsCompatible(): void
    {
        $branchTip = ['min_version' => '0.13', 'max_version' => '0.16'];

        $evaluation = \plugin_compatibility_checker::evaluateUpdateEntryForCore(
            '0.14',
            ['min_version' => '0.13', 'max_version' => '0.16'],
            $branchTip
        );

        $this->assertTrue($evaluation['compatible']);
        $this->assertNull($evaluation['violation']);
    }

    public function testMissingUpdateEntryFallsBackToBranchTipBounds(): void
    {
        $branchTip = ['min_version' => '0.17', 'max_version' => ''];

        $evaluation = \plugin_compatibility_checker::evaluateUpdateEntryForCore('0.14', [], $branchTip);

        $this->assertFalse($evaluation['compatible']);
        $this->assertSame('min', $evaluation['violation']);
    }
}
