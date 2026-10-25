<?php

declare(strict_types=1);

namespace Tests\SystemUpdater;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once FS_FOLDER . '/plugins/system_updater/lib/session_auth.php';

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

    private function guardSource(): string
    {
        return (string) file_get_contents(FS_FOLDER . '/plugins/system_updater/lib/session_auth.php');
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
