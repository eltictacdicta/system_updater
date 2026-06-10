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
        // Clean session state before each test
        $_SESSION = [];
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
        $this->assertSame(
            $token,
            $_SESSION['system_updater']['csrf_tokens']['system_updater_csrf']['value']
        );
        $this->assertArrayHasKey('created_at', $_SESSION['system_updater']['csrf_tokens']['system_updater_csrf']);
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
        $this->assertSame(
            $token2,
            $_SESSION['system_updater']['csrf_tokens']['system_updater_csrf']['value']
        );
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

        // Simulate expiry by backdating created_at
        $_SESSION['system_updater']['csrf_tokens']['system_updater_csrf']['created_at'] = time() - SYSTEM_UPDATER_CSRF_TTL - 1;

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

        // Token should now exist in session
        $this->assertArrayHasKey('system_updater', $_SESSION);
        $this->assertArrayHasKey('csrf_tokens', $_SESSION['system_updater']);
        $this->assertArrayHasKey('system_updater_csrf', $_SESSION['system_updater']['csrf_tokens']);
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

        $this->assertArrayHasKey('system_updater', $_SESSION);
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
