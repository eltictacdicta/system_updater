<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/maintenance_mode_compat.php';

/**
 * Un SIGKILL no ejecuta `finally`, así que el lock de mantenimiento del restore
 * queda huérfano y el sitio devuelve 503 en las rutas públicas para siempre.
 * Estos tests fijan el comportamiento del auto-sanado.
 */
final class MaintenanceLockRecoveryTest extends TestCase
{
    private const SOURCE = 'system_updater.restore';

    private static string $lockFile = '';

    public static function setUpBeforeClass(): void
    {
        if (!defined('FS_MAINTENANCE_LOCK_FILE')) {
            define('FS_MAINTENANCE_LOCK_FILE', sys_get_temp_dir() . '/fs_su_lock_' . getmypid() . '.json');
        }

        self::$lockFile = (string) FS_MAINTENANCE_LOCK_FILE;
    }

    protected function setUp(): void
    {
        $this->removeLock();
    }

    protected function tearDown(): void
    {
        $this->removeLock();
    }

    private function removeLock(): void
    {
        foreach ([self::$lockFile, self::$lockFile . '.tmp'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeLock(array $state): void
    {
        file_put_contents(self::$lockFile, json_encode($state, JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readLock(): ?array
    {
        if (!is_file(self::$lockFile)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents(self::$lockFile), true);

        return is_array($decoded) ? $decoded : null;
    }

    #[Test]
    public function maintenanceModeIsAvailableInThisEnvironment(): void
    {
        $this->assertTrue(
            system_updater_maintenance_mode_available(),
            'sin fs_maintenance_mode estos tests no probarían nada'
        );
    }

    #[Test]
    public function beginMaintenanceRecordsSourceAndHeartbeat(): void
    {
        $this->assertTrue(system_updater_begin_maintenance([
            'source' => self::SOURCE,
            'message' => 'Restauración en curso.',
        ]));

        $state = $this->readLock();
        $this->assertNotNull($state);
        $this->assertSame(self::SOURCE, $state['source']);
        $this->assertTrue((bool) $state['active']);
        $this->assertGreaterThan(time() - 60, (int) $state['heartbeat']);
    }

    #[Test]
    public function staleRestoreLockIsCleared(): void
    {
        $this->writeLock([
            'active' => true,
            'source' => self::SOURCE,
            'heartbeat' => time() - 600,
        ]);

        $this->assertTrue(system_updater_heal_stale_restore_lock());
        $this->assertNull($this->readLock(), 'el lock huérfano debe liberarse');
    }

    #[Test]
    public function lockWithoutHeartbeatFallsBackToUpdatedAt(): void
    {
        $this->writeLock([
            'active' => true,
            'source' => self::SOURCE,
            'updated_at' => date('c', time() - 600),
        ]);

        $this->assertTrue(system_updater_heal_stale_restore_lock());
        $this->assertNull($this->readLock());
    }

    #[Test]
    public function freshRestoreLockIsKept(): void
    {
        $this->writeLock([
            'active' => true,
            'source' => self::SOURCE,
            'heartbeat' => time(),
        ]);

        $this->assertFalse(system_updater_heal_stale_restore_lock());
        $this->assertNotNull($this->readLock(), 'una restauración viva no debe perder el lock');
    }

    #[Test]
    public function foreignLockIsNeverCleared(): void
    {
        $this->writeLock([
            'active' => true,
            'source' => 'otro_plugin',
            'heartbeat' => time() - 6000,
        ]);

        $this->assertFalse(system_updater_heal_stale_restore_lock());
        $this->assertNotNull($this->readLock(), 'no se toca el lock de otro origen');
    }

    #[Test]
    public function heartbeatRefreshIgnoresForeignLock(): void
    {
        $this->writeLock([
            'active' => true,
            'source' => 'otro_plugin',
            'heartbeat' => 12345,
        ]);

        system_updater_refresh_restore_heartbeat();

        $state = $this->readLock();
        $this->assertNotNull($state);
        $this->assertSame(12345, (int) $state['heartbeat'], 'no debe pisar un lock ajeno');
    }

    #[Test]
    public function heartbeatRefreshUpdatesOwnLock(): void
    {
        $this->writeLock([
            'active' => true,
            'source' => self::SOURCE,
            'heartbeat' => 1,
        ]);

        system_updater_refresh_restore_heartbeat();

        $state = $this->readLock();
        $this->assertNotNull($state);
        $this->assertGreaterThan(time() - 60, (int) $state['heartbeat']);
    }
}
