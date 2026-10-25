<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\TestCase;

/**
 * The isolated candidate read must not write session headers to the response.
 *
 * `system_updater_read_session_file()` starts a PHP session for *another*
 * session id to read it. PHP's session module answers every `session_start()`
 * while `session.use_cookies` is on with a `Set-Cookie`, and every start while
 * `session.cache_limiter` is set with `Expires`/`Pragma: no-cache`. The reader
 * only sets `session_id()`, so both stayed on: a `status`/`progress` poll
 * answered with one cookie per candidate session (`SU_SESS_*`, `FSSESS_*`,
 * `PHPSESSID`) carrying the plugin's `path`/`lifetime`/`secure`/`samesite`,
 * plus one more for the caller's session on the way out. That rotates the
 * framework cookie's expiry and scope several times per second, and a
 * differing `path` leaves the browser holding a duplicate.
 *
 * The CLI SAPI cannot observe headers (`headers_list()` is empty, `header()` is
 * a no-op), so this contract uses the house `php -S` seam: a throwaway document
 * root, an ephemeral port, one probe script per case, and the raw response
 * headers of an `http://` stream-wrapper read.
 *
 * Two probes, because there are two distinct claims:
 *  - `isolated`: with no session open, a candidate read emits nothing at all;
 *  - `caller`:    with the caller's session open, the read adds no cookie of
 *                 its own — the only `Set-Cookie` is the caller's, the one the
 *                 bootstrap itself would have written.
 */
final class SessionReadCookieTest extends TestCase
{
    private const CANDIDATE_NAME = 'SU_SESS_cookie_probe';
    private const CANDIDATE_ID = 'candidate-session-id-0123456789';
    private const CALLER_NAME = 'FSSESS_cookie_probe';
    private const CALLER_ID = 'caller-session-id-0123456789';

    private string $workDir = '';

    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('proc_open') || !function_exists('fsockopen')) {
            $this->markTestSkipped('proc_open/fsockopen are unavailable, so the php -S seam cannot run.');
        }

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('allow_url_fopen is disabled, so response headers cannot be read.');
        }

        $this->workDir = sys_get_temp_dir() . '/su_cookie_probe_' . bin2hex(random_bytes(6));
        $sessions = $this->workDir . '/sessions';

        $this->assertTrue(mkdir($sessions, 0700, true), 'premise: a writable session directory');

        // Both sessions are seeded on disk so nothing in a probe has to open
        // one before the code under test runs.
        $this->seedSession(self::CANDIDATE_ID, 'admin');
        $this->seedSession(self::CALLER_ID, 'caller-admin');

        file_put_contents($this->workDir . '/isolated.php', $this->isolatedProbeSource());
        file_put_contents($this->workDir . '/caller.php', $this->callerProbeSource());

        $this->startServer();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            @proc_terminate($this->server);
            @proc_close($this->server);
        }

        $this->server = null;

        if ($this->workDir !== '' && is_dir($this->workDir)) {
            $this->removeDirectory($this->workDir);
        }

        parent::tearDown();
    }

    /**
     * The core defect: reading somebody else's session must not mint cookies.
     */
    public function testAnIsolatedReadEmitsNoSessionCookie(): void
    {
        $response = $this->request('isolated.php');

        $this->assertSame(200, $response['status'], 'premise: the probe answered: ' . $response['raw']);
        $this->assertSame('admin', $response['body']['nick'] ?? null, 'premise: the candidate was read');

        $cookies = $this->headersMatching($response['headers'], 'set-cookie');
        $this->assertSame([], $cookies, 'the isolated read must not emit Set-Cookie: ' . implode(' | ', $cookies));
    }

    /**
     * `session.cache_limiter` writes cache directives for a response that is
     * not the session's, for the same reason: it runs at `session_start()`.
     */
    public function testAnIsolatedReadEmitsNoSessionCacheHeaders(): void
    {
        $response = $this->request('isolated.php');

        $this->assertSame(200, $response['status'], 'premise: the probe answered: ' . $response['raw']);
        $this->assertSame('admin', $response['body']['nick'] ?? null, 'premise: the candidate was read');

        $this->assertSame([], $this->headersMatching($response['headers'], 'pragma'));
        $this->assertSame([], $this->headersMatching($response['headers'], 'expires'));
    }

    /**
     * Both ini settings are restored, so the rest of the request behaves as if
     * the reader had never run.
     */
    public function testTheReaderRestoresTheSessionIniSettings(): void
    {
        $response = $this->request('isolated.php');

        $this->assertSame(200, $response['status'], 'premise: the probe answered: ' . $response['raw']);
        $this->assertSame(
            $response['body']['before'] ?? null,
            $response['body']['after'] ?? null,
            'session.use_cookies and session.cache_limiter must be back to their previous values'
        );
    }

    /**
     * With the caller's session already open (the bootstrap's state), the read
     * must not add a cookie of its own. The only `Set-Cookie` left is the
     * caller's, which the bootstrap writes whether or not this reader exists.
     */
    public function testTheReaderAddsNoCookieOfItsOwnOverAnOpenCallerSession(): void
    {
        $response = $this->request('caller.php');

        $this->assertSame(200, $response['status'], 'premise: the probe answered: ' . $response['raw']);
        $this->assertSame('admin', $response['body']['nick'] ?? null, 'premise: the candidate was read');

        $cookies = $this->headersMatching($response['headers'], 'set-cookie');
        $foreign = array_values(array_filter(
            $cookies,
            static fn (string $header): bool => stripos($header, 'Set-Cookie: ' . self::CALLER_NAME . '=') !== 0
        ));

        $this->assertSame(
            [],
            $foreign,
            'the read must not mint a cookie for a candidate session: ' . implode(' | ', $foreign)
        );
        $this->assertLessThanOrEqual(
            1,
            count($cookies),
            'the caller session must not be re-cookied by the read: ' . implode(' | ', $cookies)
        );
    }

    /**
     * The caller's session must still be the active one afterwards: the
     * bootstrap returns `session_id()` and the rest of the request writes there.
     */
    public function testTheCallerSessionSurvivesAnIsolatedRead(): void
    {
        $response = $this->request('caller.php');

        $this->assertSame(200, $response['status'], 'premise: the probe answered: ' . $response['raw']);
        $this->assertSame(self::CALLER_NAME, $response['body']['name'] ?? null);
        $this->assertSame(self::CALLER_ID, $response['body']['id'] ?? null);
        $this->assertSame(
            PHP_SESSION_ACTIVE,
            $response['body']['status'] ?? null,
            'the caller session must be open again, not merely named'
        );
        $this->assertSame(
            ['user_nick' => 'caller-admin', 'caller_marker' => 'kept'],
            $response['body']['session'] ?? null,
            'the caller session content must be restored, not left emptied by the read'
        );
    }

    private function seedSession(string $sessionId, string $nick): void
    {
        file_put_contents(
            $this->workDir . '/sessions/sess_' . $sessionId,
            'user_nick|s:' . strlen($nick) . ':"' . $nick . '";caller_marker|s:4:"kept";'
        );
    }

    private function isolatedProbeSource(): string
    {
        $root = var_export((string) FS_FOLDER, true);
        $saveDir = var_export($this->workDir . '/sessions', true);
        $candidateName = var_export(self::CANDIDATE_NAME, true);
        $candidateId = var_export(self::CANDIDATE_ID, true);

        return <<<PHP
<?php
require {$root} . '/vendor/autoload.php';

session_save_path({$saveDir});
require {$root} . '/plugins/system_updater/lib/session_auth.php';

\$before = [ini_get('session.use_cookies'), ini_get('session.cache_limiter')];

\$read = system_updater_read_session_file({$candidateName}, {$candidateId});

echo json_encode([
    'nick' => \$read['user_nick'] ?? null,
    'status' => session_status(),
    'before' => \$before,
    'after' => [ini_get('session.use_cookies'), ini_get('session.cache_limiter')],
]);
PHP;
    }

    private function callerProbeSource(): string
    {
        $root = var_export((string) FS_FOLDER, true);
        $saveDir = var_export($this->workDir . '/sessions', true);
        $candidateName = var_export(self::CANDIDATE_NAME, true);
        $candidateId = var_export(self::CANDIDATE_ID, true);
        $callerName = var_export(self::CALLER_NAME, true);
        $callerId = var_export(self::CALLER_ID, true);

        return <<<PHP
<?php
require {$root} . '/vendor/autoload.php';

session_save_path({$saveDir});
require {$root} . '/plugins/system_updater/lib/session_auth.php';

/* The caller session, opened exactly as the standalone bootstrap opens it. */
session_name({$callerName});
session_id({$callerId});
session_start();

\$before = [ini_get('session.use_cookies'), ini_get('session.cache_limiter')];

\$read = system_updater_read_session_file({$candidateName}, {$candidateId});

echo json_encode([
    'nick' => \$read['user_nick'] ?? null,
    'name' => session_name(),
    'id' => session_id(),
    'status' => session_status(),
    'session' => \$_SESSION,
    'before' => \$before,
    'after' => [ini_get('session.use_cookies'), ini_get('session.cache_limiter')],
]);
PHP;
    }

    private function startServer(): void
    {
        $lastError = '';

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $port = random_int(20000, 40000);
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $this->workDir . '/server.log', 'a'],
                2 => ['file', $this->workDir . '/server.log', 'a'],
            ];

            $server = @proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $this->workDir],
                $descriptors,
                $pipes
            );

            if (!is_resource($server)) {
                $lastError = 'proc_open failed';

                continue;
            }

            if ($this->waitForServer($port)) {
                $this->server = $server;
                $this->port = $port;

                return;
            }

            @proc_terminate($server);
            @proc_close($server);
            $lastError = 'the built-in server did not accept a connection on port ' . $port;
        }

        $this->markTestSkipped('php -S could not be started: ' . $lastError);
    }

    private function waitForServer(int $port): bool
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorString, 0.2);

            if (is_resource($socket)) {
                fclose($socket);

                return true;
            }

            usleep(50_000);
        }

        return false;
    }

    /**
     * @return array{status: int, headers: array<int, string>, body: array<string, mixed>, raw: string}
     */
    private function request(string $script): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 10,
            ],
        ]);

        $body = (string) file_get_contents(
            'http://127.0.0.1:' . $this->port . '/' . $script,
            false,
            $context
        );

        $rawHeaders = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];

        $status = 0;
        foreach ($rawHeaders as $header) {
            if (preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        $decoded = json_decode($body, true);

        return [
            'status' => $status,
            'headers' => array_values($rawHeaders),
            'body' => is_array($decoded) ? $decoded : [],
            'raw' => $body,
        ];
    }

    /**
     * @param array<int, string> $headers
     * @return array<int, string>
     */
    private function headersMatching(array $headers, string $name): array
    {
        $matched = [];

        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                $matched[] = $header;
            }
        }

        return $matched;
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir((string) $entry->getPathname());
            } else {
                @unlink((string) $entry->getPathname());
            }
        }

        @rmdir($directory);
    }
}
