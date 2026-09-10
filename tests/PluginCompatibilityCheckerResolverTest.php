<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/system_updater/lib/plugin_compatibility_checker.php';

/**
 * PU-12 / PU-15: pure resolver of the latest compatible release.
 */
final class PluginCompatibilityCheckerResolverTest extends TestCase
{
    public function testPicksHighestCompatibleWhenTipIsIncompatible(): void
    {
        $result = \plugin_compatibility_checker::resolveLatestCompatible(
            '0.14',
            [
                [
                    'version' => '1.8.1',
                    'min_version' => '0.13',
                    'max_version' => '0.16',
                    'zip_url' => 'https://example.com/v1.8.1.zip',
                ],
                [
                    'version' => '1.9.0',
                    'min_version' => '0.17',
                    'max_version' => '',
                    'zip_url' => 'https://example.com/v1.9.0.zip',
                ],
            ],
            '1.8.0'
        );

        $this->assertNotNull($result);
        $this->assertSame('1.8.1', $result['version']);
        $this->assertSame('0.13', $result['min_version']);
        $this->assertSame('0.16', $result['max_version']);
        $this->assertSame('https://example.com/v1.8.1.zip', $result['zip_url']);
    }

    public function testReturnsNullWhenNoReleaseIsCompatible(): void
    {
        $result = \plugin_compatibility_checker::resolveLatestCompatible(
            '0.14',
            [
                ['version' => '1.8.1', 'min_version' => '0.17', 'max_version' => ''],
                ['version' => '1.9.0', 'min_version' => '', 'max_version' => '0.10'],
            ],
            '1.8.0'
        );

        $this->assertNull($result);
    }

    public function testReturnsNullWhenNoReleaseIsNewerThanInstalled(): void
    {
        $result = \plugin_compatibility_checker::resolveLatestCompatible(
            '0.14',
            [
                ['version' => '1.7.0', 'min_version' => '0.13', 'max_version' => '0.16'],
                ['version' => '1.8.0', 'min_version' => '0.13', 'max_version' => '0.16'],
            ],
            '1.8.0'
        );

        $this->assertNull($result);
    }

    public function testNeverReturnsVersionLowerOrEqualToInstalled(): void
    {
        $result = \plugin_compatibility_checker::resolveLatestCompatible(
            '0.14',
            [
                ['version' => '1.8.0', 'min_version' => '', 'max_version' => ''],
                ['version' => '1.7.5', 'min_version' => '', 'max_version' => ''],
            ],
            '1.8.0'
        );

        $this->assertNull($result);
    }

    public function testMinBoundaryEqualIsCompatible(): void
    {
        $result = \plugin_compatibility_checker::resolveLatestCompatible(
            '0.13',
            [['version' => '1.1.0', 'min_version' => '0.13', 'max_version' => '']],
            '1.0.0'
        );

        $this->assertNotNull($result);
        $this->assertSame('1.1.0', $result['version']);
    }

    public function testMinBoundaryGreaterIsIncompatible(): void
    {
        $result = \plugin_compatibility_checker::resolveLatestCompatible(
            '0.13',
            [['version' => '1.1.0', 'min_version' => '0.14', 'max_version' => '']],
            '1.0.0'
        );

        $this->assertNull($result);
    }

    public function testMaxBoundaryEqualIsCompatible(): void
    {
        $result = \plugin_compatibility_checker::resolveLatestCompatible(
            '0.15',
            [['version' => '1.1.0', 'min_version' => '', 'max_version' => '0.15']],
            '1.0.0'
        );

        $this->assertNotNull($result);
        $this->assertSame('1.1.0', $result['version']);
    }

    public function testMaxBoundaryLowerIsIncompatible(): void
    {
        $result = \plugin_compatibility_checker::resolveLatestCompatible(
            '0.16',
            [['version' => '1.1.0', 'min_version' => '', 'max_version' => '0.15']],
            '1.0.0'
        );

        $this->assertNull($result);
    }

    public function testEmptyBoundsAreUnbounded(): void
    {
        $result = \plugin_compatibility_checker::resolveLatestCompatible(
            '9.9',
            [['version' => '1.5.0', 'min_version' => '', 'max_version' => '']],
            '1.0.0'
        );

        $this->assertNotNull($result);
        $this->assertSame('1.5.0', $result['version']);
    }

    public function testComparesNormalizedSemverNotStrings(): void
    {
        $result = \plugin_compatibility_checker::resolveLatestCompatible(
            '0.14',
            [
                ['version' => '1.9', 'min_version' => '', 'max_version' => ''],
                ['version' => '1.10.0', 'min_version' => '', 'max_version' => ''],
            ],
            '1.0.0'
        );

        $this->assertNotNull($result);
        $this->assertSame('1.10.0', $result['version']);
    }

    public function testIgnoresMalformedEntries(): void
    {
        $result = \plugin_compatibility_checker::resolveLatestCompatible(
            '0.14',
            [
                'not-an-array',
                ['min_version' => '0.13', 'max_version' => ''],
                ['version' => '', 'min_version' => '0.13'],
                ['version' => '1.2.0', 'min_version' => '', 'max_version' => ''],
            ],
            ''
        );

        $this->assertNotNull($result);
        $this->assertSame('1.2.0', $result['version']);
    }

    public function testReturnsNullWhenEveryEntryIsMalformed(): void
    {
        $result = \plugin_compatibility_checker::resolveLatestCompatible(
            '0.14',
            ['x', 42, ['version' => ''], ['no_version_key' => true]],
            ''
        );

        $this->assertNull($result);
    }

    public function testDeterministicAcrossInvocations(): void
    {
        $coreVersion = '0.14';
        $versions = [
            ['version' => '1.8.1', 'min_version' => '0.13', 'max_version' => '0.16'],
            ['version' => '1.9.0', 'min_version' => '0.17', 'max_version' => ''],
        ];

        $first = \plugin_compatibility_checker::resolveLatestCompatible($coreVersion, $versions, '1.8.0');
        $second = \plugin_compatibility_checker::resolveLatestCompatible($coreVersion, $versions, '1.8.0');

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertSame('1.8.1', $first['version']);
    }

    public function testEmptyInstalledActsAsPureSelector(): void
    {
        $result = \plugin_compatibility_checker::resolveLatestCompatible(
            '0.14',
            [
                ['version' => '1.2.0', 'min_version' => '0.13', 'max_version' => ''],
                ['version' => '1.5.0', 'min_version' => '0.13', 'max_version' => ''],
            ],
            ''
        );

        $this->assertNotNull($result);
        $this->assertSame('1.5.0', $result['version']);
    }
}
