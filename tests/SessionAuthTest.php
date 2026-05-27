<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/session_auth.php';

final class SessionAuthTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('FS_FOLDER')) {
            define('FS_FOLDER', dirname(dirname(dirname(__DIR__))));
        }
    }

    public function testResolveSessionNameUsesFsFolderHashWhenConstantMissing(): void
    {
        $seed = str_replace('\\', '/', FS_FOLDER);
        $expected = 'FSSESS_' . substr(sha1($seed), 0, 12);

        $this->assertSame($expected, system_updater_resolve_session_name());
    }

    public function testResolveSessionNamesIncludesFrameworkAndPhpDefaults(): void
    {
        $names = system_updater_resolve_session_names();

        $this->assertContains(system_updater_resolve_session_name(), $names);
        $this->assertContains('PHPSESSID', $names);
    }

    public function testSessionHasUserDetectsSymfonyAttributes(): void
    {
        $this->assertTrue(system_updater_session_has_user([
            '_sf2_attributes' => [
                'user_nick' => 'admin',
            ],
        ]));
    }

    public function testSessionHasUserDetectsLegacyNick(): void
    {
        $this->assertTrue(system_updater_session_has_user([
            'user_nick' => 'admin',
        ]));
    }
}
