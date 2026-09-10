<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/system_updater/lib/plugin_compatibility_checker.php';

final class PluginCompatibilityCheckerTest extends TestCase
{
    public function testNormalizeVersionStripsVPrefix(): void
    {
        $this->assertSame('0.14.0', \plugin_compatibility_checker::normalizeVersion('v0.14.0'));
    }

    public function testNormalizeVersionPadsPluginSemver(): void
    {
        $this->assertSame('1.0.0', \plugin_compatibility_checker::normalizeVersion('1'));
        $this->assertSame('1.1.0', \plugin_compatibility_checker::normalizeVersion('1.1'));
        $this->assertSame('1.1.1', \plugin_compatibility_checker::normalizeVersion('1.1.1'));
    }

    public function testIsRemoteVersionNewerUsesPaddedVersions(): void
    {
        $this->assertTrue(\plugin_compatibility_checker::isRemoteVersionNewer('1.1.0', '1'));
        $this->assertFalse(\plugin_compatibility_checker::isRemoteVersionNewer('1.0.0', '1'));
    }

    public function testCoreWithinBoundsWhenNoMaxVersion(): void
    {
        $result = \plugin_compatibility_checker::evaluateCoreAgainstPlugin(
            '0.14.0',
            '0.13.0',
            ''
        );

        $this->assertTrue($result['compatible']);
        $this->assertNull($result['violation']);
    }

    public function testCoreExceedsMaxVersionIsIncompatible(): void
    {
        $result = \plugin_compatibility_checker::evaluateCoreAgainstPlugin(
            '0.16.0',
            '0.13.0',
            '0.15.0'
        );

        $this->assertFalse($result['compatible']);
        $this->assertSame('max', $result['violation']);
    }

    public function testCoreBelowMinVersionIsIncompatible(): void
    {
        $result = \plugin_compatibility_checker::evaluateCoreAgainstPlugin(
            '0.12.0',
            '0.13.0',
            '0.20.0'
        );

        $this->assertFalse($result['compatible']);
        $this->assertSame('min', $result['violation']);
    }

    public function testCoreEqualToMinVersionIsCompatible(): void
    {
        $result = \plugin_compatibility_checker::evaluateCoreAgainstPlugin(
            '0.13.0',
            '0.13.0',
            '0.20.0'
        );

        $this->assertTrue($result['compatible']);
        $this->assertNull($result['violation']);
    }

    public function testCoreEqualToMaxVersionIsCompatible(): void
    {
        $result = \plugin_compatibility_checker::evaluateCoreAgainstPlugin(
            '0.15.0',
            '0.13.0',
            '0.15.0'
        );

        $this->assertTrue($result['compatible']);
        $this->assertNull($result['violation']);
    }

    public function testEmptyCoreVersionIsTreatedAsUnknown(): void
    {
        $result = \plugin_compatibility_checker::evaluateCoreAgainstPlugin(
            '',
            '0.13.0',
            '0.15.0'
        );

        $this->assertTrue($result['compatible']);
        $this->assertNull($result['violation']);
    }

    public function testCoreUpdateWarningsWhenLocalExceedsMaxAndNoPendingRemote(): void
    {
        $tempRoot = sys_get_temp_dir() . '/fs_compat_' . uniqid('', true);
        mkdir($tempRoot . '/plugins/tpvmod', 0777, true);
        file_put_contents(
            $tempRoot . '/plugins/tpvmod/fsframework.ini',
            "version = 1\nmin_version = \"0.13\"\nmax_version = \"0.14\"\n"
        );

        $warnings = \plugin_compatibility_checker::getCoreUpdateWarnings(
            '0.15.0',
            ['tpvmod'],
            $tempRoot
        );

        $this->assertCount(1, $warnings);
        $this->assertSame('plugin_max_version', $warnings[0]['type']);
        $this->assertStringContainsString('tpvmod', $warnings[0]['message']);

        $this->removeTree($tempRoot);
    }

    public function testCoreUpdateWarningsSkippedWhenPendingRemoteIsCompatible(): void
    {
        $tempRoot = sys_get_temp_dir() . '/fs_compat_' . uniqid('', true);
        mkdir($tempRoot . '/plugins/tpvmod', 0777, true);
        file_put_contents(
            $tempRoot . '/plugins/tpvmod/fsframework.ini',
            "version = 1\nmin_version = \"0.13\"\nmax_version = \"0.14\"\n"
        );

        $warnings = \plugin_compatibility_checker::getCoreUpdateWarnings(
            '0.15.0',
            ['tpvmod'],
            $tempRoot,
            [
                'tpvmod' => [
                    'min_version' => '0.13',
                    'max_version' => '0.16',
                ],
            ]
        );

        $this->assertSame([], $warnings);

        $this->removeTree($tempRoot);
    }

    public function testCoreUpdateWarningsWhenPendingRemoteAlsoIncompatible(): void
    {
        $tempRoot = sys_get_temp_dir() . '/fs_compat_' . uniqid('', true);
        mkdir($tempRoot . '/plugins/tpvmod', 0777, true);
        file_put_contents(
            $tempRoot . '/plugins/tpvmod/fsframework.ini',
            "version = 1\nmin_version = \"0.13\"\nmax_version = \"0.14\"\n"
        );

        $warnings = \plugin_compatibility_checker::getCoreUpdateWarnings(
            '0.15.0',
            ['tpvmod'],
            $tempRoot,
            [
                'tpvmod' => [
                    'min_version' => '0.13',
                    'max_version' => '0.14',
                ],
            ]
        );

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('remota pendiente tampoco', $warnings[0]['message']);

        $this->removeTree($tempRoot);
    }

    public function testValidateRemotePluginUsesRemoteIniBoundsAgainstCurrentCore(): void
    {
        $result = \plugin_compatibility_checker::validateRemotePluginForCore('0.15.0', [
            'min_version' => '0.13',
            'max_version' => '0.16',
        ]);

        $this->assertTrue($result['compatible']);

        $blocked = \plugin_compatibility_checker::validateRemotePluginForCore('0.17.0', [
            'min_version' => '0.13',
            'max_version' => '0.16',
        ]);

        $this->assertFalse($blocked['compatible']);
        $this->assertSame('max', $blocked['violation']);
    }

    public function testClassifyPluginBlockedUntilTargetCoreUpdate(): void
    {
        $result = \plugin_compatibility_checker::classifyPluginUpdateAgainstCore('0.14.0', '0.15.0', [
            'min_version' => '0.15',
            'max_version' => '0.20',
        ]);

        $this->assertSame('blocked_by_core', $result['update_status']);
        $this->assertTrue($result['blocked_by_core']);
        $this->assertFalse($result['compatible_with_current_core']);
        $this->assertTrue($result['compatible_with_target_core']);
        $this->assertSame('0.15', $result['required_core_version']);
    }

    public function testClassifyPluginReadyOnCurrentCore(): void
    {
        $result = \plugin_compatibility_checker::classifyPluginUpdateAgainstCore('0.15.0', '0.16.0', [
            'min_version' => '0.14',
            'max_version' => '0.20',
        ]);

        $this->assertSame('ready', $result['update_status']);
        $this->assertTrue($result['compatible_with_current_core']);
        $this->assertFalse($result['blocked_by_core']);
    }

    public function testEnrichPluginUpdatesAddsCompatibilityMetadata(): void
    {
        $enriched = \plugin_compatibility_checker::enrichPluginUpdatesWithCoreCompatibility([
            [
                'name' => 'tpvmod',
                'current_version' => '1.0.0',
                'new_version' => '1.1.0',
                'min_version' => '0.15',
                'max_version' => '',
            ],
        ], '0.14.0', '0.15.0');

        $this->assertCount(1, $enriched);
        $this->assertSame('blocked_by_core', $enriched[0]['update_status']);
    }

    public function testNormalizeReleaseHistoryDropsInvalidEntries(): void
    {
        $result = \plugin_compatibility_checker::normalizeReleaseHistory([
            'not-an-array',
            42,
            null,
            ['min_version' => '0.13'],
            ['version' => ''],
            ['version' => '1.8.1', 'min_version' => '0.13', 'max_version' => '0.16'],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('1.8.1', $result[0]['version']);
        $this->assertSame('0.13', $result[0]['min_version']);
        $this->assertSame('0.16', $result[0]['max_version']);
    }

    public function testNormalizeReleaseHistoryPreservesDownloadReference(): void
    {
        $result = \plugin_compatibility_checker::normalizeReleaseHistory([
            [
                'version' => '1.8.1',
                'min_version' => '0.13',
                'max_version' => '0.16',
                'zip_url' => 'https://example.com/v1.8.1.zip',
                'catalog_id' => 97,
            ],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('https://example.com/v1.8.1.zip', $result[0]['zip_url']);
        $this->assertSame(97, $result[0]['catalog_id']);
    }

    public function testNormalizeReleaseHistoryDefaultsMissingBoundsToEmpty(): void
    {
        $result = \plugin_compatibility_checker::normalizeReleaseHistory([
            ['version' => '1.8.1', 'zip_url' => 'https://example.com/v1.8.1.zip'],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('', $result[0]['min_version']);
        $this->assertSame('', $result[0]['max_version']);
    }

    public function testNormalizeReleaseHistoryReturnsEmptyForInvalidHistory(): void
    {
        $this->assertSame([], \plugin_compatibility_checker::normalizeReleaseHistory([]));
        $this->assertSame([], \plugin_compatibility_checker::normalizeReleaseHistory(['a', 'b', 1]));
    }

    public function testNormalizeReleaseHistoryNeverThrowsOnOddEntries(): void
    {
        $result = \plugin_compatibility_checker::normalizeReleaseHistory([
            ['version' => ['nested'], 'min_version' => ['x']],
            ['version' => '2.0', 'min_version' => null, 'max_version' => false],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('2.0', $result[0]['version']);
        $this->assertSame('', $result[0]['min_version']);
        $this->assertSame('', $result[0]['max_version']);
    }

    public function testNormalizeReleaseHistoryCoercesNonScalarBoundsToEmpty(): void
    {
        $result = \plugin_compatibility_checker::normalizeReleaseHistory([
            ['version' => '1.8.1', 'min_version' => ['x'], 'max_version' => (object) ['y' => 1]],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('', $result[0]['min_version']);
        $this->assertSame('', $result[0]['max_version']);
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
