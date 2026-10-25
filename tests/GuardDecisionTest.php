<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/session_auth.php';

/**
 * Contract of the standalone guard decision.
 *
 * Covers AUTH-01 (a request value is never an identity), AUTH-02 (every
 * candidate session is evaluated), AUTH-03 (the administrator verdict fails
 * closed) and AUTH-06 (a genuine administrator session keeps working).
 *
 * This suite has no database, so the decision is exercised through the two
 * seams the delta names: an injected map of candidate sessions and an injected
 * administrator verdict. Nothing here opens a session, a socket or a row.
 */
final class GuardDecisionTest extends TestCase
{
    private const PLUGIN_SESSION = 'SU_SESS_test';
    private const FRAMEWORK_SESSION = 'FSSESS_test';

    /**
     * The shape that shadowed the framework session before AUTH-02: a plugin
     * session that holds nothing but plugin token state, therefore "non-empty"
     * and therefore the only session the old reader ever looked at.
     *
     * @return array<string, mixed>
     */
    private function pluginSession(): array
    {
        return [
            'system_updater' => [
                'csrf_tokens' => [
                    'system_updater_csrf' => [
                        'token' => str_repeat('a', 64),
                        'expires' => time() + 3600,
                    ],
                ],
            ],
        ];
    }

    /**
     * A framework session written by a completed login.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function loginSession(array $overrides = [], string $nick = 'admin'): array
    {
        $now = time();

        return array_merge([
            'user_nick' => $nick,
            'login_time' => $now,
            'last_activity' => $now,
        ], $overrides);
    }

    /**
     * @param array<string, array<string, mixed>> $sessions
     * @param array<string, mixed> $request
     * @param callable(string): mixed $isAdmin
     * @return array{verdict: string, nick: ?string, reason: string}
     */
    private function decide(array $sessions, array $request, callable $isAdmin): array
    {
        return system_updater_guard_decision($sessions, $request, $isAdmin);
    }

    private function verdictIs(bool $verdict): callable
    {
        return static function (string $nick) use ($verdict): bool {
            return $verdict;
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function requestCarryingToken(string $key, string $value): array
    {
        return [$key => $value];
    }

    // ---------------------------------------------------------------------
    // AUTH-02 — every candidate is evaluated
    // ---------------------------------------------------------------------

    /**
     * AUTH-02 scenario 1: the defect this change exists for.
     *
     * The plugin session comes first and is non-empty (it carries the plugin
     * token), but it holds no identity. The framework session, written by a
     * real login, holds the nick. The decision must accept and report that
     * nick — today's reader returns at the first non-empty session and never
     * reaches it.
     */
    public function testFrameworkIdentityIsFoundWhenThePluginSessionComesFirst(): void
    {
        $decision = $this->decide(
            [
                self::PLUGIN_SESSION => $this->pluginSession(),
                self::FRAMEWORK_SESSION => $this->loginSession(),
            ],
            [],
            $this->verdictIs(true)
        );

        $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_ACCEPT, $decision['verdict']);
        $this->assertSame('admin', $decision['nick'], 'the nick must come from the login session');
        $this->assertSame(SYSTEM_UPDATER_GUARD_REASON_AUTHENTICATED, $decision['reason']);
    }

    /**
     * AUTH-02 scenario 2: plugin token state is "no evidence", not a failed
     * identity.
     */
    public function testPluginSessionAloneResolvesNoIdentity(): void
    {
        $decision = $this->decide(
            [self::PLUGIN_SESSION => $this->pluginSession()],
            [],
            $this->verdictIs(true)
        );

        $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_DENY, $decision['verdict']);
        $this->assertNull($decision['nick']);
        $this->assertSame(SYSTEM_UPDATER_GUARD_REASON_UNAUTHENTICATED, $decision['reason']);
    }

    /**
     * AUTH-02 scenario 3: the purity contract.
     *
     * Runs in its own process so the "no active PHP session" premise is real
     * rather than inherited from another test file that opened one.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDecisionNeverStartsASession(): void
    {
        $this->assertSame(PHP_SESSION_NONE, session_status(), 'premise: no active session');
        $this->assertSame('', session_id());

        $decision = $this->decide(
            [self::FRAMEWORK_SESSION => $this->loginSession()],
            [],
            $this->verdictIs(true)
        );

        $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_ACCEPT, $decision['verdict']);
        $this->assertSame(PHP_SESSION_NONE, session_status(), 'the decision must not start a session');
        $this->assertSame('', session_id(), 'the decision must not adopt a session id');
    }

    /**
     * The nick resolver is the pure part: no session state, no I/O.
     */
    public function testNickResolverIsPureOverTheInjectedSession(): void
    {
        $before = [session_status(), session_id(), session_name(), $_SESSION ?? null];

        $this->assertSame('admin', system_updater_resolve_session_nick(['user_nick' => 'admin']));
        $this->assertSame(
            'admin',
            system_updater_resolve_session_nick(['_sf2_attributes' => ['user_nick' => 'admin']]),
            '_sf2_attributes.user_nick is a login-written identity key'
        );
        $this->assertNull(
            system_updater_resolve_session_nick(['user_id' => '7']),
            'user_id alone cannot be re-read against fs_users, so it resolves no nick'
        );
        $this->assertNull(
            system_updater_resolve_session_nick(['user_nick' => "  \t "]),
            'a whitespace-only nick is not an identity'
        );
        $this->assertNull(system_updater_resolve_session_nick([]));

        $this->assertSame($before, [session_status(), session_id(), session_name(), $_SESSION ?? null]);
    }

    /**
     * The reader enumerates every candidate. Before AUTH-02 it returned at the
     * first non-empty session, so the second candidate was never opened.
     */
    public function testReaderOpensEveryCandidateAndNeverStopsAtTheFirstNonEmpty(): void
    {
        $opened = [];
        $candidates = system_updater_collect_session_candidates(
            [self::PLUGIN_SESSION, self::FRAMEWORK_SESSION],
            [self::PLUGIN_SESSION => 'plugin-id', self::FRAMEWORK_SESSION => 'framework-id'],
            function (string $name, string $id) use (&$opened): array {
                $opened[] = $name . ':' . $id;

                return $name === self::PLUGIN_SESSION ? $this->pluginSession() : $this->loginSession();
            }
        );

        $this->assertSame(
            [self::PLUGIN_SESSION . ':plugin-id', self::FRAMEWORK_SESSION . ':framework-id'],
            $opened,
            'both candidates must be opened, in order — not only the first non-empty one'
        );
        $this->assertSame([self::PLUGIN_SESSION, self::FRAMEWORK_SESSION], array_keys($candidates));
    }

    public function testReaderSkipsCandidatesWithoutACookieOrWithoutContent(): void
    {
        $opened = 0;
        $candidates = system_updater_collect_session_candidates(
            ['A', 'B', 'C', 'A'],
            ['A' => '', 'B' => 'b-id'],
            function (string $name, string $id) use (&$opened): array {
                $opened++;

                return $name === 'B' ? ['user_nick' => 'admin'] : [];
            }
        );

        $this->assertSame(['B'], array_keys($candidates), 'no cookie (A, C) or no content is not a candidate');
        $this->assertSame(1, $opened, 'only the candidates carrying a cookie are opened, once each');
    }

    public function testReaderTreatsAnUnreadableCandidateAsNoEvidence(): void
    {
        $candidates = system_updater_collect_session_candidates(
            ['A', 'B'],
            ['A' => 'a-id', 'B' => 'b-id'],
            static function (string $name, string $id): array {
                if ($name === 'A') {
                    throw new \RuntimeException('session file unreadable');
                }

                return ['user_nick' => 'admin'];
            }
        );

        $this->assertSame(['B'], array_keys($candidates), 'a candidate that fails to open is skipped, not fatal');
    }

    /**
     * The real reader shell with nothing to read: no cookies, no open session.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRealReaderReturnsNoCandidateWhenNothingIsPresent(): void
    {
        $_COOKIE = [];

        $this->assertSame([], system_updater_read_session_candidates());
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    // ---------------------------------------------------------------------
    // AUTH-01 — a request value is never an identity
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function frameworkTokenProvider(): array
    {
        return [
            'query token' => ['_csrf_token'],
            'body token' => ['_token'],
            'header token' => ['X-CSRF-Token'],
        ];
    }

    /**
     * AUTH-01 scenario 1: the token a public page mints for an anonymous
     * visitor confers nothing, and no administrator lookup is attempted.
     */
    #[DataProvider('frameworkTokenProvider')]
    public function testSelfMintedFrameworkTokenConfersNoIdentity(string $tokenKey): void
    {
        $lookups = 0;
        $isAdmin = static function (string $nick) use (&$lookups): bool {
            $lookups++;

            return true;
        };

        $decision = $this->decide(
            [self::FRAMEWORK_SESSION => ['_csrf' => 'x']],
            $this->requestCarryingToken($tokenKey, 'self-minted'),
            $isAdmin
        );

        $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_DENY, $decision['verdict']);
        $this->assertNull($decision['nick']);
        $this->assertSame(0, $lookups, 'no administrator lookup is attempted without an identity');
    }

    /**
     * AUTH-01 scenario 3: an empty request is denied without touching state.
     */
    public function testEmptyRequestAndEmptyCandidateMapAreDeniedWithoutALookup(): void
    {
        $lookups = 0;
        $isAdmin = static function (string $nick) use (&$lookups): bool {
            $lookups++;

            return true;
        };

        $decision = $this->decide([], [], $isAdmin);

        $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_DENY, $decision['verdict']);
        $this->assertNull($decision['nick']);
        $this->assertSame(0, $lookups);
    }

    // ---------------------------------------------------------------------
    // AUTH-04 / AUTH-06 — uniform denial, unchanged expiry
    // ---------------------------------------------------------------------

    /**
     * Anonymous, expired, unknown nick and non-administrator must be
     * indistinguishable to the caller: one verdict, one reason.
     */
    public function testEveryDenialReportsTheSameReason(): void
    {
        if (!class_exists(\FSFramework\Security\SessionPolicy::class)) {
            $this->markTestSkipped('SessionPolicy no disponible.');
        }

        $expiredAt = time() - \FSFramework\Security\SessionPolicy::getAbsoluteTimeout() - 60;

        $decisions = [
            'anonymous' => $this->decide([], [], $this->verdictIs(true)),
            'expired' => $this->decide(
                [self::FRAMEWORK_SESSION => $this->loginSession([
                    'login_time' => $expiredAt,
                    'last_activity' => $expiredAt,
                ])],
                [],
                $this->verdictIs(true)
            ),
            'unknown nick' => $this->decide(
                [self::FRAMEWORK_SESSION => $this->loginSession([], 'deleted-user')],
                [],
                $this->verdictIs(false)
            ),
            'not an administrator' => $this->decide(
                [self::FRAMEWORK_SESSION => $this->loginSession([], 'operator')],
                [],
                $this->verdictIs(false)
            ),
        ];

        $reasons = array_values(array_unique(array_column($decisions, 'reason')));
        $this->assertCount(1, $reasons, 'every denial must be indistinguishable: ' . json_encode($decisions));
        $this->assertSame(SYSTEM_UPDATER_GUARD_REASON_UNAUTHENTICATED, $reasons[0]);

        foreach ($decisions as $case => $decision) {
            $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_DENY, $decision['verdict'], $case);
            $this->assertNull($decision['nick'], $case);
        }
    }

    /**
     * AUTH-06 scenario 2: session expiry stays governed by the existing
     * policy — exactly as before this change.
     */
    public function testExpiredSessionIsDeniedAsBefore(): void
    {
        if (!class_exists(\FSFramework\Security\SessionPolicy::class)) {
            $this->markTestSkipped('SessionPolicy no disponible.');
        }

        $expiredAt = time() - \FSFramework\Security\SessionPolicy::getAbsoluteTimeout() - 60;

        $decision = $this->decide(
            [self::FRAMEWORK_SESSION => $this->loginSession([
                'login_time' => $expiredAt,
                'last_activity' => $expiredAt,
            ])],
            [],
            $this->verdictIs(true)
        );

        $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_DENY, $decision['verdict']);
        $this->assertNull($decision['nick']);
    }

    // ---------------------------------------------------------------------
    // AUTH-04 — one refusal, and the same one for every cause
    // ---------------------------------------------------------------------

    /**
     * The refusal text has a single name.
     *
     * The transport difference (a JSON 401 for the download endpoint, an SSE
     * `error` event for the process scripts) must never become a denial-reason
     * difference. Two literals in two places cannot guarantee that; one named
     * constant can.
     */
    public function testTheRefusalTextHasASingleName(): void
    {
        $this->assertTrue(
            defined('SYSTEM_UPDATER_GUARD_DENIAL'),
            'the refusal text must be a named constant, not a literal copied into each transport'
        );
        $this->assertIsString(SYSTEM_UPDATER_GUARD_DENIAL);
        $this->assertNotSame('', trim(SYSTEM_UPDATER_GUARD_DENIAL));
    }

    /**
     * AUTH-04: the 401 body is `{"success":false,"message":"<the constant>"}`,
     * byte for byte.
     */
    public function testTheJsonRefusalCarriesThatExactText(): void
    {
        $payload = system_updater_denial_payload();

        $this->assertSame(
            ['success' => false, 'message' => SYSTEM_UPDATER_GUARD_DENIAL],
            $payload,
            'the JSON refusal has exactly two keys and the shared text'
        );
        $this->assertSame(
            '{"success":false,"message":"' . SYSTEM_UPDATER_GUARD_DENIAL . '"}',
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            'the serialised body is the documented shape'
        );
    }

    /**
     * AUTH-04: the SSE `error` payload carries the **same string**. The event
     * keeps its own framing and its `percent`, but not its own wording.
     */
    public function testTheSseRefusalCarriesTheSameText(): void
    {
        $payload = system_updater_denial_sse_payload();

        $this->assertSame(
            SYSTEM_UPDATER_GUARD_DENIAL,
            $payload['message'],
            'an SSE refusal must not word the denial differently from the 401'
        );
        $this->assertSame(0, $payload['percent'], 'the SSE error event still reports its percent');
    }

    /**
     * The five causes AUTH-04 enumerates produce one verdict, one reason and —
     * through the emitter's own seam — one text.
     */
    public function testAllFiveDenialCausesCollapseIntoTheSameRefusal(): void
    {
        if (!class_exists(\FSFramework\Security\SessionPolicy::class)) {
            $this->markTestSkipped('SessionPolicy no disponible.');
        }

        $expiredAt = time() - \FSFramework\Security\SessionPolicy::getAbsoluteTimeout() - 60;
        $throwing = static function (string $nick): bool {
            throw new \RuntimeException('database unreachable');
        };

        $causes = [
            'anonymous' => $this->decide([], [], $this->verdictIs(true)),
            'expired' => $this->decide(
                [self::FRAMEWORK_SESSION => $this->loginSession([
                    'login_time' => $expiredAt,
                    'last_activity' => $expiredAt,
                ])],
                [],
                $this->verdictIs(true)
            ),
            'unknown nick' => $this->decide(
                [self::FRAMEWORK_SESSION => $this->loginSession([], 'deleted-user')],
                [],
                $this->verdictIs(false)
            ),
            'not an administrator' => $this->decide(
                [self::FRAMEWORK_SESSION => $this->loginSession([], 'operator')],
                [],
                $this->verdictIs(false)
            ),
            'unresolvable verdict' => $this->decide(
                [self::FRAMEWORK_SESSION => $this->loginSession()],
                [],
                $throwing
            ),
        ];

        $serialised = [];
        foreach ($causes as $cause => $decision) {
            $this->assertSame(
                SYSTEM_UPDATER_GUARD_VERDICT_DENY,
                $decision['verdict'],
                $cause . ' must be refused'
            );
            $this->assertNull($decision['nick'], $cause);
            $serialised[$cause] = json_encode($decision);
        }

        $this->assertCount(1, array_unique($serialised), 'every cause must serialise identically');

        // And the emitter cannot reintroduce the difference: it accepts no
        // cause, holds no copy of the text, and takes both transports from the
        // shared seam.
        $body = $this->functionBody($this->guardSource(), 'system_updater_deny_unauthenticated_request');

        $this->assertNotSame('', $body, 'premise: the deny emitter exists');
        $this->assertStringContainsString(
            'SYSTEM_UPDATER_GUARD_DENIAL',
            $body,
            'the emitter must read the shared constant'
        );
        $this->assertStringNotContainsString(
            'Sesión no válida',
            $body,
            'a literal copy inside the emitter is the second source of truth AUTH-04 forbids'
        );
        $this->assertStringContainsString('system_updater_denial_payload(', $body);
        $this->assertStringContainsString('system_updater_denial_sse_payload(', $body);

        $emitter = new \ReflectionFunction('system_updater_deny_unauthenticated_request');
        $this->assertSame(
            0,
            $emitter->getNumberOfParameters(),
            'an emitter that accepts a cause can word it differently'
        );
    }

    // ---------------------------------------------------------------------
    // AUTH-03 — the administrator verdict fails closed
    // ---------------------------------------------------------------------

    public function testThrowingVerdictFailsClosed(): void
    {
        $isAdmin = static function (string $nick): bool {
            throw new \RuntimeException('database unreachable');
        };

        $decision = $this->decide([self::FRAMEWORK_SESSION => $this->loginSession()], [], $isAdmin);

        $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_DENY, $decision['verdict']);
        $this->assertNull($decision['nick']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonBooleanVerdictProvider(): array
    {
        return [
            'null' => [null],
            'int one' => [1],
            'int zero' => [0],
            'string one' => ['1'],
            'string yes' => ['yes'],
            'empty array' => [[]],
            'object' => [new \stdClass()],
        ];
    }

    /**
     * Only a strict `true` accepts. Anything a resolver may accidentally
     * return instead — null, a truthy scalar, an object — denies.
     */
    #[DataProvider('nonBooleanVerdictProvider')]
    public function testNonBooleanVerdictFailsClosed(mixed $verdict): void
    {
        $isAdmin = static fn (string $nick): mixed => $verdict;

        $decision = $this->decide([self::FRAMEWORK_SESSION => $this->loginSession()], [], $isAdmin);

        $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_DENY, $decision['verdict']);
        $this->assertNull($decision['nick']);
    }

    public function testDeletedRowIsDenied(): void
    {
        $decision = $this->decide(
            [self::FRAMEWORK_SESSION => $this->loginSession([], 'deleted-user')],
            [],
            $this->verdictIs(false)
        );

        $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_DENY, $decision['verdict']);
        $this->assertNull($decision['nick']);
    }

    /**
     * AUTH-03: the verdict is not cached, so a demotion between two requests
     * is effective on the second one.
     */
    public function testDemotionTakesEffectOnTheNextRequest(): void
    {
        $verdicts = [true, false];
        $isAdmin = static function (string $nick) use (&$verdicts): bool {
            return (bool) array_shift($verdicts);
        };

        $sessions = [self::FRAMEWORK_SESSION => $this->loginSession()];

        $first = $this->decide($sessions, [], $isAdmin);
        $second = $this->decide($sessions, [], $isAdmin);

        $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_ACCEPT, $first['verdict']);
        $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_DENY, $second['verdict'], 'no verdict may survive a request');
    }

    /**
     * The production resolver is a thin shell around the row read: it must
     * name the table, carry no cache, and never fall back to `true`.
     */
    public function testProductionResolverReadsTheRowWithoutCache(): void
    {
        $body = $this->functionBody($this->guardSource(), 'system_updater_resolve_admin_verdict');

        $this->assertNotSame('', $body, 'the production administrator resolver must exist');
        $this->assertStringContainsString('fs_users', $body, 'the verdict must come from the fs_users row');
        $this->assertStringNotContainsString('static $', $body, 'the verdict must not be cached across requests (AUTH-03)');
        $this->assertStringNotContainsString('CacheManager', $body);
        $this->assertStringNotContainsString('fs_cache', $body);
    }

    public function testGuardRoutesThroughTheAuthoritativeVerdict(): void
    {
        $body = $this->functionBody($this->guardSource(), 'system_updater_is_logged_in');

        $this->assertStringContainsString(
            'system_updater_resolve_admin_verdict',
            $body,
            'the guard must ask the authoritative administrator verdict'
        );
    }

    // ---------------------------------------------------------------------
    // AUTH-06 — a genuine administrator session keeps working
    // ---------------------------------------------------------------------

    /**
     * The regression contract AUTH-01 makes fragile: the same browser carries
     * a non-empty plugin session and the framework session written by the
     * application login, and a status poll must resolve the login's nick.
     */
    public function testGenuineAdministratorSessionIsAccepted(): void
    {
        $decision = $this->decide(
            [
                self::PLUGIN_SESSION => $this->pluginSession(),
                self::FRAMEWORK_SESSION => $this->loginSession(),
            ],
            ['action' => 'status', 'session' => 'framework-id'],
            $this->verdictIs(true)
        );

        $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_ACCEPT, $decision['verdict']);
        $this->assertSame('admin', $decision['nick']);
    }

    /**
     * Repeated polling must produce the same decision: nothing is memoized
     * between calls, and the second poll must not depend on the first.
     */
    public function testRepeatedPollsResolveTheSameNick(): void
    {
        $sessions = [
            self::PLUGIN_SESSION => $this->pluginSession(),
            self::FRAMEWORK_SESSION => $this->loginSession(),
        ];

        foreach (['status', 'progress', 'progress'] as $index => $action) {
            $decision = $this->decide($sessions, ['action' => $action], $this->verdictIs(true));

            $this->assertSame(SYSTEM_UPDATER_GUARD_VERDICT_ACCEPT, $decision['verdict'], (string) $index);
            $this->assertSame('admin', $decision['nick'], (string) $index);
        }
    }

    // ---------------------------------------------------------------------
    // Load strictness — the file must not depend on the bootstrap
    // ---------------------------------------------------------------------

    /**
     * This file loads its own dependency at file scope, before any `setUp()`.
     *
     * When that path was built from the framework's install-root constant, a
     * bootstrap that did not define it killed the whole suite at load time with
     * "Undefined constant" instead of failing one test — the file was not even
     * collected. The same constant was used to read the guard source, so the
     * two had to be fixed together.
     *
     * Proven the only way it can be: a subprocess that requires the composer
     * autoloader and this file, without defining anything, and then calls the
     * private source helper through reflection.
     */
    public function testContractFileLoadsWithoutTheInstallRootConstant(): void
    {
        if (!function_exists('exec')) {
            $this->markTestSkipped('exec() is unavailable, so the load probe cannot run.');
        }

        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $this->assertFileExists($autoload, 'premise: the composer autoloader');

        $probe = 'require ' . var_export($autoload, true) . ';'
            . 'require ' . var_export(__DIR__ . '/GuardDecisionTest.php', true) . ';'
            . '$reflection = new ReflectionClass(' . var_export(self::class, true) . ');'
            . '$method = $reflection->getMethod("guardSource");'
            . '$method->setAccessible(true);'
            . '$source = $method->invoke($reflection->newInstanceWithoutConstructor());'
            . 'echo is_string($source) && $source !== "" ? "contract-loaded" : "guard-source-empty";';

        $output = [];
        $status = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=E_ALL -r '
            . escapeshellarg($probe) . ' 2>&1',
            $output,
            $status
        );

        $printed = implode("\n", $output);

        $this->assertSame(0, $status, 'the file must load with nothing defined: ' . $printed);
        $this->assertStringContainsString('contract-loaded', $printed);
        $this->assertStringNotContainsString('guard-source-empty', $printed);
    }

    /**
     * Absolute path to the guard source.
     *
     * Relative to this file, never to the framework install root: that constant
     * is a bootstrap concern, and depending on it here made the *suite* fail to
     * load instead of failing a test.
     */
    private function guardSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/lib/session_auth.php');
    }

    /**
     * Body of a top-level function of session_auth.php. The file closes every
     * function with `}` in the first column, so that brace is the boundary.
     */
    private function functionBody(string $source, string $function): string
    {
        $start = strpos($source, 'function ' . $function . '(');
        if ($start === false) {
            return '';
        }

        $end = strpos($source, "\n}", $start);
        if ($end === false) {
            return '';
        }

        return substr($source, $start, $end - $start);
    }
}
