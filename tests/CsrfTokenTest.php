<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once dirname(__DIR__) . '/lib/csrf_token.php';

final class CsrfTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Each test starts from a fresh framework-like session and no plugin cookie.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        unset($_COOKIE[system_updater_resolve_session_name()], $GLOBALS['system_updater_previous_session']);
        session_name('PHPSESSID');
        session_id(session_create_id());
        $_SESSION = [];
    }

    /** Reads a slot from the plugin store, leaving the caller's session as it was. */
    private function pluginSlot(string $slot = 'system_updater_csrf'): ?array
    {
        system_updater_csrf_ensure_session();
        $entry = $_SESSION['system_updater']['csrf_tokens'][$slot] ?? null;
        system_updater_csrf_restore_session();

        return $entry;
    }

    /**
     * Regression: minting from a page must not hijack the framework session.
     * Inheriting its id made the framework continue the request on the plugin's
     * session after a logout/login, so its own CSRF token was always invalid.
     */
    #[Test]
    public function generatingKeepsTheFrameworkSessionIntact(): void
    {
        session_name('FSSESS_regression');
        @session_start();
        $frameworkId = session_id();
        $_SESSION['_csrf'] = ['fs_form' => 'framework-token'];
        $_COOKIE[system_updater_resolve_session_name()] = 'stalecookieid0123456789abcdef'; // destroyed on logout

        $token = system_updater_csrf_generate();

        $this->assertSame('FSSESS_regression', session_name());
        $this->assertSame($frameworkId, session_id());
        $this->assertSame('framework-token', $_SESSION['_csrf']['fs_form'] ?? null);
        $this->assertArrayNotHasKey('system_updater', $_SESSION, 'plugin tokens live in their own session');
        $this->assertNotSame($frameworkId, $_COOKIE[system_updater_resolve_session_name()]);
        $this->assertTrue(system_updater_csrf_validate($token));
        $this->assertSame($frameworkId, session_id(), 'validating also resumes the framework session');
    }

    #[Test]
    public function generateProducesUniqueTokenInSession(): void
    {
        // Ensure session is active for this test
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $token = system_updater_csrf_generate();

        $this->assertSame(64, strlen($token), 'Token must be 64-char hex (bin2hex of 32 bytes)');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $slot = $this->pluginSlot();
        $this->assertSame($token, $slot['value'] ?? null);
        $this->assertArrayHasKey('created_at', $slot);
    }

    #[Test]
    public function consecutiveGeneratesProduceDifferentTokens(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $token1 = system_updater_csrf_generate();
        $token2 = system_updater_csrf_generate();

        $this->assertNotSame($token1, $token2);
        $this->assertSame($token2, $this->pluginSlot()['value'] ?? null);
    }

    #[Test]
    public function generateValidateRoundTrip(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $token = system_updater_csrf_generate();

        $this->assertTrue(system_updater_csrf_validate($token));
    }

    #[Test]
    public function expiredTokenIsRejected(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $token = system_updater_csrf_generate();

        // Simulate expiry by backdating created_at. csrf_generate() calls
        // session_write_close() at the end (intentional, to persist the token
        // before SSE scripts read it), so we must reopen the session to mutate
        // $_SESSION and then close it again for csrf_ensure_session() in
        // csrf_validate() to read the backdated value from disk.
        system_updater_csrf_ensure_session();
        $_SESSION['system_updater']['csrf_tokens']['system_updater_csrf']['created_at'] = time() - SYSTEM_UPDATER_CSRF_TTL - 1;
        @session_write_close();

        $this->assertFalse(system_updater_csrf_validate($token));
        // Expired entry should be cleaned up
        $this->assertArrayNotHasKey(
            'system_updater_csrf',
            $_SESSION['system_updater']['csrf_tokens'] ?? []
        );
    }

    #[Test]
    public function tokenWithinTtlIsValid(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $token = system_updater_csrf_generate();

        // Simulate token created 1 hour ago (well within 4h TTL)
        $_SESSION['system_updater']['csrf_tokens']['system_updater_csrf']['created_at'] = time() - 3600;

        $this->assertTrue(system_updater_csrf_validate($token));
    }

    #[Test]
    public function tamperedTokenIsRejected(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $token = system_updater_csrf_generate();

        // Flip a character in the middle
        $tampered = substr($token, 0, 32) . ($token[32] === 'a' ? 'b' : 'a') . substr($token, 33);

        $this->assertFalse(system_updater_csrf_validate($tampered));
    }

    #[Test]
    public function emptyTokenIsRejected(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        system_updater_csrf_generate();

        $this->assertFalse(system_updater_csrf_validate(''));
    }

    #[Test]
    public function missingSessionEntryIsRejected(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $this->assertFalse(system_updater_csrf_validate('some_random_token_value_that_is_long_enough'));
    }

    #[Test]
    public function fieldHelperEmitsCorrectHtml(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $token = system_updater_csrf_generate();
        $html = system_updater_csrf_field();

        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('name="su_csrf_token"', $html);
        $this->assertStringContainsString('value="' . $token . '"', $html);
    }

    #[Test]
    public function metaHelperEmitsCorrectHtml(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $token = system_updater_csrf_generate();
        $html = system_updater_csrf_meta();

        $this->assertStringContainsString('<meta', $html);
        $this->assertStringContainsString('name="su-csrf-token"', $html);
        $this->assertStringContainsString('content="' . $token . '"', $html);
    }

    #[Test]
    public function fieldHelperAutoGeneratesTokenIfNoneExists(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $html = system_updater_csrf_field();

        // Token should now exist in the plugin store
        $this->assertNotNull($this->pluginSlot());
        $this->assertStringContainsString('value="', $html);
        // Value should not be empty
        $this->assertDoesNotMatchRegularExpression('/value=""/', $html);
    }

    #[Test]
    public function metaHelperAutoGeneratesTokenIfNoneExists(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $html = system_updater_csrf_meta();

        $this->assertNotNull($this->pluginSlot());
        $this->assertStringContainsString('content="', $html);
        $this->assertDoesNotMatchRegularExpression('/content=""/', $html);
    }

    #[Test]
    public function customTokenIdIsIsolated(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $tokenA = system_updater_csrf_generate('slot_a');
        $tokenB = system_updater_csrf_generate('slot_b');

        $this->assertNotSame($tokenA, $tokenB);
        $this->assertTrue(system_updater_csrf_validate($tokenA, 'slot_a'));
        $this->assertTrue(system_updater_csrf_validate($tokenB, 'slot_b'));
        $this->assertFalse(system_updater_csrf_validate($tokenA, 'slot_b'));
        $this->assertFalse(system_updater_csrf_validate($tokenB, 'slot_a'));
    }

    #[Test]
    public function getStoredReturnsNullWhenMissing(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $this->assertNull(system_updater_csrf_get_stored());
    }

    #[Test]
    public function getStoredReturnsValueWhenPresent(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $token = system_updater_csrf_generate();

        $this->assertSame($token, system_updater_csrf_get_stored());
    }
}
