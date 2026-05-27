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

    public function testResolveCookiePathUsesRootForDocumentRootInstall(): void
    {
        if (!defined('FS_PATH')) {
            define('FS_PATH', '');
        }

        $this->assertSame('/', system_updater_resolve_cookie_path());
    }

    public function testNormalizeCookiePathValueForSubdirectory(): void
    {
        $this->assertSame('/accounts/', system_updater_normalize_cookie_path_value('/accounts'));
    }

    public function testSessionIsValidRejectsExpiredSymfonySession(): void
    {
        if (!class_exists('FSFramework\\Security\\SessionPolicy')) {
            $this->markTestSkipped('SessionPolicy no disponible.');
        }

        $expiredLogin = time() - \FSFramework\Security\SessionPolicy::getAbsoluteTimeout() - 60;

        $this->assertFalse(system_updater_session_is_valid([
            '_sf2_attributes' => [
                'user_nick' => 'admin',
                'login_time' => $expiredLogin,
                'last_activity' => $expiredLogin,
            ],
        ]));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testEnsureFsPathForProcessScriptMatchesIndexRoot(): void
    {
        $_SERVER['REQUEST_URI'] = '/plugins/system_updater/process_core_update.php?action=start';

        system_updater_ensure_fs_path();

        $this->assertTrue(defined('FS_PATH'));
        $this->assertSame('', FS_PATH);
        $this->assertSame('/', system_updater_resolve_cookie_path());
    }
}
