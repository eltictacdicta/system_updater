<?php

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/system_updater/lib/backup_manager.php';

/**
 * A restore replaces the database, so the core's CoreSchemaBootstrap stamp
 * must go: otherwise self-heal and the one-shot migrations stay skipped on a
 * database that may be from an older version.
 */
class CoreSchemaStampInvalidationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/su_stamp_' . uniqid('', true);
        // FS_TMP_NAME is a name prefix that may also be a directory ("abc/").
        @mkdir(dirname($this->root . '/tmp/' . FS_TMP_NAME . 'x'), 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/tmp/' . FS_TMP_NAME . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(dirname($this->root . '/tmp/' . FS_TMP_NAME . 'x'));
        @rmdir($this->root . '/tmp');
        @rmdir($this->root);
    }

    public function testRestoreDropsEveryCoreSchemaStampAndNothingElse(): void
    {
        $prefix = $this->root . '/tmp/' . FS_TMP_NAME;
        file_put_contents($prefix . 'core_schema_0.33.0_abcdefabcdef.ok', 'x');
        file_put_contents($prefix . 'core_schema_0.32.0_000000000000.ok', 'x');
        file_put_contents($prefix . 'other.ok', 'x');

        $manager = (new \ReflectionClass(\backup_manager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\backup_manager::class, 'fsRoot'))->setValue($manager, $this->root);
        (new \ReflectionMethod(\backup_manager::class, 'invalidate_core_schema_stamp'))->invoke($manager);

        self::assertSame([], glob($prefix . 'core_schema_*'));
        self::assertFileExists($prefix . 'other.ok');
    }

    public function testBothRestorePathsInvalidateTheStamp(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/plugins/system_updater/lib/backup_manager.php');

        foreach (['public function restore_database(', 'private function restore_step_cleanup('] as $method) {
            $start = strpos($source, $method);
            self::assertIsInt($start, $method);
            $end = strpos($source, "\n    }\n", $start);
            self::assertStringContainsString(
                'invalidate_core_schema_stamp()',
                substr($source, $start, $end - $start),
                $method . ' must drop the core schema stamp.'
            );
        }
    }
}
