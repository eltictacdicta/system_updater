<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/csrf_guard.php';

final class CsrfGuardTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testReadStoredTokenFromSf2Attributes(): void
    {
        $_SESSION['_sf2_attributes']['_csrf/fs_form'] = 'expected_stored_token';

        $result = system_updater_csrf_read_stored_token();

        $this->assertSame('expected_stored_token', $result);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testReadStoredTokenFromHttpsWithPrefix(): void
    {
        $_SESSION['_sf2_attributes']['_csrf/https-fs_form'] = 'https_stored_token';

        $result = system_updater_csrf_read_stored_token();

        $this->assertSame('https_stored_token', $result);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testReadStoredTokenPrefersHttpsOverPlain(): void
    {
        $_SESSION['_sf2_attributes']['_csrf/https-fs_form'] = 'https_token';
        $_SESSION['_sf2_attributes']['_csrf/fs_form'] = 'plain_token';

        $result = system_updater_csrf_read_stored_token();

        $this->assertSame('https_token', $result);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testReadStoredTokenFromLegacyKey(): void
    {
        $_SESSION['_csrf/fs_form'] = 'legacy_stored_token';

        $result = system_updater_csrf_read_stored_token();

        $this->assertSame('legacy_stored_token', $result);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testReadStoredTokenEmptyWhenMissing(): void
    {
        // No session keys set
        $result = system_updater_csrf_read_stored_token();

        $this->assertSame('', $result);
    }

    public function testVerifyTokenExactMatch(): void
    {
        $this->assertTrue(system_updater_csrf_verify_token('abc123', 'abc123'));
    }

    public function testVerifyTokenSymfonyRandomizedFormat(): void
    {
        // Build a valid Symfony 7 randomized CSRF token (checksum.key.xored format)
        $storedToken = 'stored_token_for_xor_test';

        // Generate a random key and compute xored = storedToken XOR repeatingKey
        $key = random_bytes(32);

        // Repeat key to match storedToken length for XOR
        $repeatingKey = $key;
        while (strlen($repeatingKey) < strlen($storedToken)) {
            $repeatingKey .= $key;
        }
        $repeatingKey = substr($repeatingKey, 0, strlen($storedToken));

        $xored = $storedToken ^ $repeatingKey;

        // Base64url encode (Symfony convention: rtrim padding, tr +/ → -_)
        $keyEncoded = rtrim(strtr(base64_encode($key), '+/', '-_'), '=');
        $xoredEncoded = rtrim(strtr(base64_encode($xored), '+/', '-_'), '=');

        $submittedToken = 'dummy_checksum.' . $keyEncoded . '.' . $xoredEncoded;

        $this->assertTrue(
            system_updater_csrf_verify_token($submittedToken, $storedToken),
            'Randomized-format CSRF token should verify against its stored value'
        );
    }

    public function testVerifyTokenMismatchRejected(): void
    {
        $this->assertFalse(system_updater_csrf_verify_token('token_a', 'token_b'));
    }

    public function testVerifyTokenMalformedRejected(): void
    {
        // Non-3-part dotted string — should fall through to direct hash_equals comparison
        $this->assertFalse(system_updater_csrf_verify_token('just-one-part', 'stored_value'));
    }

    /**
     * Additional triangulation: a 2-part dotted string is also malformed
     * (neither 3-part Symfony format nor exact match).
     */
    public function testVerifyTokenTwoPartDottedRejected(): void
    {
        $this->assertFalse(system_updater_csrf_verify_token('two.parts', 'stored_value'));
    }
}
