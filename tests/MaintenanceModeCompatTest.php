<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/maintenance_mode_compat.php';

final class MaintenanceModeCompatTest extends TestCase
{
    #[Test]
    public function operationWarningsIncludeStealthWhenNotReady(): void
    {
        $warnings = system_updater_get_operation_warnings('admin');

        $this->assertIsArray($warnings);

        if (!system_updater_maintenance_stealth_required()) {
            $this->assertSame([], $warnings);
            return;
        }

        $this->assertNotEmpty($warnings);
        $this->assertSame('stealth', $warnings[0]['type']);
        $this->assertSame('warning', $warnings[0]['level']);
    }

    #[Test]
    public function recentActiveUsersReturnsEmptyWithoutFrameworkFolder(): void
    {
        $this->assertSame([], system_updater_get_recent_active_users('admin'));
    }

    #[Test]
    public function stealthRequiredMessageIsInformative(): void
    {
        $message = system_updater_maintenance_stealth_required_message();

        $this->assertStringContainsString('modo stealth', $message);
        $this->assertStringContainsString('admin_stealth', $message);
    }
}
