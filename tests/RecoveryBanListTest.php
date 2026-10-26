<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\SystemUpdater\Support\PolicyProbe;

require_once dirname(__DIR__) . '/lib/recovery_policy.php';
require_once __DIR__ . '/Support/PolicyProbe.php';

/**
 * REC-03: the console's ban gate reads the state the primary login writes.
 *
 * The console may not load `base/fs_ip_filter.php` (RACC-01), so it parses
 * `FS_FOLDER/tmp/<FS_TMP_NAME>ip.log` itself. That makes this a **second parser
 * of an internal file format**, which is a drift risk by construction: the day
 * the core changes `read_line()` or `is_banned()`, a plugin that guesses will
 * disagree with the panel about who is banned.
 *
 * The drift guard is therefore not a comment. It is
 * {@see testTheCoreReaderAndThePluginParserAgree()}, which runs the real
 * `fs_ip_filter` against the very same fixture file in a child process (the
 * path is built from constants the suite's bootstrap already fixed, so only a
 * child can point it at a fixture) and asserts the two verdicts are equal for
 * every address. Where the core is not present - this plugin also ships
 * standalone - that test skips with a reason naming the file it looked for.
 */
final class RecoveryBanListTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->removeTree($root);
        }
        $this->roots = [];

        parent::tearDown();
    }

    private function tempRoot(): string
    {
        $root = rtrim(sys_get_temp_dir(), '/') . '/su_ban_' . bin2hex(random_bytes(6));
        mkdir($root . '/tmp', 0777, true);
        $this->roots[] = $root;

        return $root;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }

        @rmdir($path);
    }

    /**
     * @param list<string> $lines
     */
    private function writeBucket(string $root, array $lines): string
    {
        $path = recovery_policy_ip_log_path($root);
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    /**
     * Literal lines, in the exact format `fs_ip_filter::save()` writes:
     * `ip;count;expire`, one per line.
     *
     * @return array<string, array{0: list<string>, 1: string, 2: bool}>
     */
    public static function provideBucketLines(): array
    {
        $future = time() + 3600;
        $past = time() - 3600;

        return [
            // fs_ip_filter::is_banned() uses `>` against MAX_ATTEMPTS (5), so 6 bans.
            'six attempts' => [['203.0.113.7;6;' . $future], '203.0.113.7', true],
            'five attempts' => [['203.0.113.7;5;' . $future], '203.0.113.7', false],
            'nine attempts but expired' => [['203.0.113.7;9;' . $past], '203.0.113.7', false],
            'two fields' => [['203.0.113.7;6'], '203.0.113.7', false],
            'empty line' => [[''], '203.0.113.7', false],
            'no lines at all' => [[], '203.0.113.7', false],
            'another address' => [['203.0.113.7;9;' . $future], '198.51.100.9', false],
            'non-numeric count' => [['203.0.113.7;abc;' . $future], '203.0.113.7', false],
            'non-numeric expiry' => [['203.0.113.7;6;abc'], '203.0.113.7', false],
            'surrounding whitespace' => [[' 203.0.113.7;6;' . $future . ' '], '203.0.113.7', true],
            'one banned among several' => [
                [
                    '198.51.100.9;3;' . $future,
                    '192.0.2.1;9;' . $past,
                    '203.0.113.7;7;' . $future,
                ],
                '203.0.113.7',
                true,
            ],
        ];
    }

    /**
     * @param list<string> $lines
     */
    #[Test]
    #[DataProvider('provideBucketLines')]
    public function theParserReproducesTheCoreVerdict(array $lines, string $ip, bool $banned): void
    {
        $this->assertSame($banned, recovery_policy_is_banned($lines, $ip, time()));
    }

    #[Test]
    public function anAbsentBucketDoesNotBanAndIsNeverCreated(): void
    {
        $root = $this->tempRoot();
        $path = recovery_policy_ip_log_path($root);

        $this->assertFileDoesNotExist($path);
        $this->assertFalse(recovery_policy_is_ip_banned('203.0.113.7', $root));
        $this->assertFileDoesNotExist($path, 'the endpoint must not create the ban bucket');
    }

    #[Test]
    public function aBannedAddressIsRefusedFromTheFixtureFile(): void
    {
        $root = $this->tempRoot();
        $this->writeBucket($root, ['203.0.113.7;6;' . (time() + 3600)]);

        $this->assertTrue(recovery_policy_is_ip_banned('203.0.113.7', $root));
        $this->assertFalse(recovery_policy_is_ip_banned('198.51.100.9', $root));
    }

    #[Test]
    public function theBucketPathIsTheFileThePrimaryLoginWrites(): void
    {
        $root = $this->tempRoot();

        $this->assertSame(
            $root . '/tmp/' . FS_TMP_NAME . 'ip.log',
            recovery_policy_ip_log_path($root),
            'same directory, same FS_TMP_NAME prefix, same file name as fs_ip_filter'
        );
    }

    #[Test]
    public function theBanReadNeverWritesTheBucket(): void
    {
        $root = $this->tempRoot();
        $lines = [
            '198.51.100.9;3;' . (time() + 3600),
            '192.0.2.1;9;' . (time() - 3600),
            '203.0.113.7;7;' . (time() + 3600),
        ];
        $path = $this->writeBucket($root, $lines);

        $before = md5_file($path);
        $beforeMtime = filemtime($path);

        foreach (['203.0.113.7', '198.51.100.9', '192.0.2.1', '192.0.2.2'] as $ip) {
            recovery_policy_is_ip_banned($ip, $root);
        }

        clearstatcache(true, $path);

        $this->assertSame($before, md5_file($path), 'REC-03: the endpoint never writes this bucket');
        $this->assertSame($beforeMtime, filemtime($path));
        $this->assertSame(implode("\n", $lines) . "\n", file_get_contents($path));
    }

    #[Test]
    public function theCoreReaderAndThePluginParserAgree(): void
    {
        $coreFile = dirname(__DIR__, 3) . '/base/fs_ip_filter.php';

        if (!is_file($coreFile)) {
            $this->markTestSkipped(
                'The core is not present in this checkout; looked for ' . $coreFile
                . '. This plugin also ships standalone, where there is nothing to drift against.'
            );
        }

        $root = $this->tempRoot();
        $this->writeBucket($root, [
            '203.0.113.7;6;' . (time() + 3600),
            '198.51.100.9;5;' . (time() + 3600),
            '192.0.2.1;9;' . (time() - 3600),
            '192.0.2.2;7',
            '192.0.2.3;0;' . (time() + 3600),
        ]);

        $addresses = ['203.0.113.7', '198.51.100.9', '192.0.2.1', '192.0.2.2', '192.0.2.3', '192.0.2.4'];

        $probe = PolicyProbe::run(
            [$coreFile],
            ['FS_FOLDER' => $root, 'FS_TMP_NAME' => FS_TMP_NAME],
            '$filter = new fs_ip_filter(); $out = [];'
            . ' foreach (' . var_export($addresses, true) . ' as $ip) { $out[$ip] = $filter->is_banned($ip); }'
            . ' echo json_encode($out);'
        );

        $this->assertSame(0, $probe['exit'], 'the core probe must run: ' . $probe['output']);

        $coreVerdicts = PolicyProbe::decodeJson($probe['output']);
        $this->assertIsArray($coreVerdicts, 'the core probe must print one JSON object: ' . $probe['output']);

        $lines = recovery_policy_read_ban_lines(recovery_policy_ip_log_path($root));
        $now = time();

        foreach ($addresses as $ip) {
            $this->assertSame(
                $coreVerdicts[$ip],
                recovery_policy_is_banned($lines, $ip, $now),
                'REC-03: the plugin parser must not disagree with fs_ip_filter about ' . $ip
            );
        }
    }

    #[Test]
    public function theDeclaredBanConstantsMatchTheCore(): void
    {
        $coreFile = dirname(__DIR__, 3) . '/base/fs_ip_filter.php';

        if (!is_file($coreFile)) {
            $this->markTestSkipped(
                'The core is not present in this checkout; looked for ' . $coreFile
                . '. The plugin declares its own copy for the damaged-core state, and there is nothing to compare it with here.'
            );
        }

        require_once $coreFile;

        $this->assertSame(
            \fs_ip_filter::MAX_ATTEMPTS,
            RECOVERY_POLICY_BAN_MAX_ATTEMPTS,
            'REC-03: the ban threshold must be the core threshold, not a second policy'
        );
        $this->assertSame(\fs_ip_filter::BAN_SECONDS, RECOVERY_POLICY_BAN_SECONDS);
    }
}
