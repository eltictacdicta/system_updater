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

    public function testResolveSessionNameUsesPluginScopedHashWhenConstantMissing(): void
    {
        $seed = str_replace('\\', '/', FS_FOLDER);
        $expected = 'SU_SESS_' . substr(sha1($seed . '_system_updater'), 0, 12);

        $this->assertSame($expected, system_updater_resolve_session_name());
    }

    public function testResolveSessionNamesIncludesFrameworkAndPhpDefaults(): void
    {
        $names = system_updater_resolve_session_names();

        $this->assertContains(system_updater_resolve_session_name(), $names);
        $this->assertContains('PHPSESSID', $names);
    }

    /**
     * El candidato del framework debe ser el nombre que el core realmente usa.
     *
     * Regression guard: when this plugin re-derived the name with the pre-0.28.0
     * formula (seed = install root), the two only matched while FS_SESSION_NAME
     * was set. Without that override the standalone process scripts stopped
     * finding the framework session and denied every authenticated request.
     */
    public function testFrameworkSessionNameComesFromTheCore(): void
    {
        $this->assertSame(
            \FSFramework\Security\SessionManager::resolveSessionName(),
            system_updater_resolve_framework_session_name()
        );
    }

    public function testResolvedNamesContainTheCoreSessionName(): void
    {
        $this->assertContains(
            \FSFramework\Security\SessionManager::resolveSessionName(),
            system_updater_resolve_session_names()
        );
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
        if (defined('FS_PATH')) {
            $this->markTestSkipped('FS_PATH ya estaba definido.');
        }

        define('FS_PATH', '');

        $this->assertSame('/', system_updater_resolve_cookie_path());
    }

    public function testNormalizeCookiePathValueForSubdirectory(): void
    {
        $this->assertSame('/accounts/', system_updater_normalize_cookie_path_value('/accounts'));
    }

    public function testSessionIsValidAcceptsLegacyNickWithoutTimestamps(): void
    {
        $this->assertTrue(system_updater_session_is_valid([
            '_sf2_attributes' => [
                'user_nick' => 'admin',
            ],
        ]));
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

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testEnsureFsPathForProcessScriptMatchesIndexRoot(): void
    {
        $_SERVER['REQUEST_URI'] = '/plugins/system_updater/process_core_update.php?action=start';

        system_updater_ensure_fs_path();

        $this->assertTrue(defined('FS_PATH'));
        $this->assertSame('', FS_PATH);
        $this->assertSame('/', system_updater_resolve_cookie_path());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testPrimeFsPathBeforeConfigAvoidsPluginSubdirectory(): void
    {
        $_SERVER['REQUEST_URI'] = '/plugins/system_updater/process_backup.php?action=start';
        $_SERVER['SCRIPT_NAME'] = '/plugins/system_updater/process_backup.php';

        system_updater_prime_fs_path_from_request();

        $this->assertTrue(defined('FS_PATH'));
        $this->assertSame('', FS_PATH);
        $this->assertSame('/', system_updater_resolve_cookie_path());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testCookiePathUsesAppRootWhenConfigDefinedPluginPath(): void
    {
        if (!defined('FS_PATH')) {
            define('FS_PATH', '/plugins/system_updater/');
        }
        $_SERVER['REQUEST_URI'] = '/plugins/system_updater/process_core_update.php?action=start';

        $this->assertSame('/', system_updater_resolve_cookie_path());
    }
}
