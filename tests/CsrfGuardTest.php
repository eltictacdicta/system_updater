<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once dirname(__DIR__) . '/lib/csrf_guard.php';

final class CsrfGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        unset($_SERVER['HTTP_X_SU_CSRF_TOKEN']);
    }

    #[Test]
    public function readFromRequestGetsTokenFromGet(): void
    {
        $_GET['su_csrf_token'] = 'token_from_get';

        $this->assertSame('token_from_get', system_updater_csrf_read_from_request());
    }

    #[Test]
    public function readFromRequestGetsTokenFromPost(): void
    {
        $_POST['su_csrf_token'] = 'token_from_post';

        $this->assertSame('token_from_post', system_updater_csrf_read_from_request());
    }

    #[Test]
    public function readFromRequestGetsTokenFromHeader(): void
    {
        $_SERVER['HTTP_X_SU_CSRF_TOKEN'] = 'token_from_header';

        $this->assertSame('token_from_header', system_updater_csrf_read_from_request());
    }

    #[Test]
    public function readFromRequestPrefersGetOverPost(): void
    {
        $_GET['su_csrf_token'] = 'get_token';
        $_POST['su_csrf_token'] = 'post_token';

        $this->assertSame('get_token', system_updater_csrf_read_from_request());
    }

    #[Test]
    public function readFromRequestReturnsEmptyWhenMissing(): void
    {
        $this->assertSame('', system_updater_csrf_read_from_request());
    }

    #[Test]
    public function ensureRequestCsrfPassesWithValidToken(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $token = system_updater_csrf_generate();
        $_GET['su_csrf_token'] = $token;

        // Should return without exiting
        ensure_request_csrf();

        // If we reach here, the function returned normally (no exit)
        $this->assertTrue(true);
    }

    #[Test]
    public function ensureRequestCsrfPassesWithPostToken(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $token = system_updater_csrf_generate();
        $_POST['su_csrf_token'] = $token;

        ensure_request_csrf();

        $this->assertTrue(true);
    }

    #[Test]
    public function ensureRequestCsrfPassesWithHeaderToken(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $token = system_updater_csrf_generate();
        $_SERVER['HTTP_X_SU_CSRF_TOKEN'] = $token;

        ensure_request_csrf();

        $this->assertTrue(true);
    }

    #[Test]
    public function validateRejectsTokenFromDifferentSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        // Generate a token, then clear session (simulating a different session)
        system_updater_csrf_generate();
        $_SESSION = [];

        $this->assertFalse(system_updater_csrf_validate('any_token_value'));
    }

    #[Test]
    public function guardDoesNotReferenceCoreSessionKeys(): void
    {
        // Verify the guard file does not contain references to core session internals
        $guardCode = file_get_contents(dirname(__DIR__) . '/lib/csrf_guard.php');

        $this->assertStringNotContainsString('_sf2_attributes', $guardCode);
        $this->assertStringNotContainsString("'_csrf'", $guardCode);
        $this->assertStringNotContainsString('CsrfManager', $guardCode);
        $this->assertStringNotContainsString('system_updater_csrf_decode_token', $guardCode);
        $this->assertStringNotContainsString('system_updater_csrf_search_in_array', $guardCode);
        $this->assertStringNotContainsString('system_updater_csrf_find_value_in_session', $guardCode);
    }

    #[Test]
    public function guardFileIsUnderLineBudget(): void
    {
        $guardFile = dirname(__DIR__) . '/lib/csrf_guard.php';
        $lineCount = count(file($guardFile));

        $this->assertLessThan(150, $lineCount, 'csrf_guard.php must be under 150 lines');
    }

    #[Test]
    public function failureResponseFunctionExists(): void
    {
        $this->assertTrue(function_exists('system_updater_csrf_failure_response'));
    }

    #[Test]
    public function ensureRequestCsrfFunctionExists(): void
    {
        $this->assertTrue(function_exists('ensure_request_csrf'));
    }
}
