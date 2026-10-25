# Delta for plugin-auth-guard (new capability)

## Provenance

- **Source**: `proposal.md` → VULN-0004, "Required behaviour" 1-6, Approach 1-3 and 8.
- **Capability status**: new. No canonical `openspec/specs/plugin-auth-guard/` exists,
  so every requirement below is added by this delta.
- **Consumers**: `lib/process_bootstrap.php` (every action except `start`/`begin`/`chunk`),
  `download_backup.php` (plain-mode bootstrap), and through them
  `process_backup.php`, `process_core_update.php`, `process_restore.php`.
- **Relationship to `plugin-backup-access`**: that capability already requires an
  *authenticated session* for `download_backup.php`. This delta supplies the missing
  definition of "authenticated" and is not a restatement of the download contract.
- **Relationship to `plugin-csrf-token`**: see the companion delta for
  `plugin-csrf-token`. The plugin token is a sibling authenticator, not this guard.

### Not from the proposal — binding additions recorded here

Two clauses below are **not** carried by `proposal.md`. Both came from the
spec phase and are recorded here so the design and verify phases cannot lose them:

1. **SPEC-ADD-1 (session resolution).** The removed CSRF branch existed because the
   guard's *session reader* stops at the first non-empty session. `SU_SESS_*` is
   non-empty by construction (it holds `su_csrf_token`), so the framework session
   holding `user_nick` was never reached. `proposal.md` requires the branch to
   disappear and requires "no new failure mode for real administrators", but does not
   name this defect. `AUTH-02` is the clause that makes the removal survivable.
2. **SPEC-ADD-2 (denial uniformity).** `proposal.md` forbids the 401→403 regression
   for anonymous requests (requirement 5) and requires the administrator check
   (requirement 3) without stating that "not logged in" and "not an administrator"
   must be indistinguishable. `AUTH-04` states it.

## ADDED Requirements

### Requirement: AUTH-01 — Identity comes only from a session written by a real login

The guard MUST derive identity exclusively from session state written by a completed
login (`user_nick` and/or `user_id` in `$_SESSION`, in `_sf2_attributes`, or in the
framework session read by name). No value taken from the request — query string, body,
header, or a session key that any page mints for anonymous visitors — MUST confer
identity.

`system_updater_request_has_valid_csrf()` MUST be deleted, not merely unreferenced: it
has no other production caller, and keeping it preserves a ready-made way to reinstate
the defect.

The guard MUST NOT consult the framework CSRF subsystem (`CsrfManager`, `_csrf`,
`_token`, `X-CSRF-Token`) for any purpose, including as a secondary signal.

#### Scenario: A self-minted framework token is not a session

- GIVEN a request carrying `_csrf_token` (or `_token`, or `X-CSRF-Token`) whose value the
  requester obtained from the public `index.php?page=login` page
- AND a session whose only content is the framework CSRF store (`_csrf`)
- WHEN the guard decides
- THEN the verdict is **deny**
- AND no administrator lookup is attempted

#### Scenario: The token helper is gone

- GIVEN the plugin is installed from this change onward
- WHEN the code base is inspected for `system_updater_request_has_valid_csrf`
- THEN the identifier appears nowhere outside the delta documents and the changing commit

#### Scenario: An empty request is denied without touching state

- GIVEN no cookies and no session variables
- WHEN `download_backup.php` is called
- THEN the guard denies and no database query and no filesystem write occur

### Requirement: AUTH-02 — Every candidate session is evaluated; a non-empty session never shadows identity

The guard's session reader MUST evaluate **all** candidate sessions for the request and
MUST accept if **any** candidate yields a valid identity. It MUST NOT stop at the first
non-empty session.

The plugin session (`SU_SESS_*` — non-empty as soon as `su_csrf_token` exists) and the
framework session (`FSSESS_*`) are distinct candidates. A candidate that carries
`system_updater` token state and no identity evidence MUST be treated as "no identity
evidence", never as "identity evidence that failed".

The identity decision MUST be reachable as a pure function over an injected map of
candidate sessions, so the behaviour above is testable without a session, a database or
network state.

#### Scenario: Both cookies present — the authenticated browser passes on its own merits

- GIVEN a browser holding `SU_SESS_*` containing only
  `system_updater.csrf_tokens.system_updater_csrf`
- AND holding `FSSESS_*` containing `user_nick`, `login_time` and `last_activity` written
  by a real login
- WHEN the guard decides on a `status` or `progress` request that carries **no** CSRF
  token of any kind
- THEN the resolved nick equals the `user_nick` written by the login in the framework
  session
- AND this scenario passes with `system_updater_request_has_valid_csrf()` absent

#### Scenario: The plugin session alone is not evidence

- GIVEN only `SU_SESS_*` with `system_updater.csrf_tokens` and no identity key
- WHEN the guard decides
- THEN no identity is resolved
- AND the verdict is **deny**

#### Scenario: The resolver is a pure function

- GIVEN a candidate map supplied in-process, with no active PHP session
- WHEN the pure resolver is called
- THEN it returns the resolved nick or `null`
- AND it performs no `session_start()`, no I/O and no class lookup

### Requirement: AUTH-03 — The resolved user MUST be an administrator, verified against the row, failing closed

After identity is resolved, the guard MUST verify the administrator flag by re-reading
`fs_users` for that nick (directly or through the framework's authoritative admin
resolver, which re-reads the row). The guard MUST deny when:

- the resolved nick is empty or the row does not exist;
- the `admin` column is falsy;
- the resolver is unavailable, throws, or returns `null`/non-boolean;
- no database connection can be established.

The verdict MUST NOT be cached across requests. An administrator demoted between two
requests MUST be refused on the second.

The production resolver MUST be a thin shell around the query; the decision MUST live in
the pure function of `AUTH-02` combined with an injected verdict, so the failure branches
above are assertable without a database.

#### Scenario: A logged-in non-administrator is refused

- GIVEN a structurally valid session for a nick whose `fs_users.admin` is falsy
- WHEN any guarded action is requested
- THEN the guard denies with the uniform refusal of `AUTH-04`

#### Scenario: A deleted row is refused

- GIVEN a session whose nick no longer exists in `fs_users`
- WHEN any guarded action is requested
- THEN the guard denies

#### Scenario: A failing lookup is refused, not ignored

- GIVEN a resolver that throws, or a database that cannot be reached
- WHEN any guarded action is requested
- THEN the guard denies
- AND no request proceeds on the strength of the session alone

#### Scenario: Demotion takes effect on the next request

- GIVEN an administrator whose flag is cleared between two guarded requests
- WHEN the second request is served
- THEN it is denied, with no cached verdict surviving the first request

#### Scenario: Fail-closed is not unreachable

- GIVEN an administrator whose row is readable and whose flag is set
- WHEN any guarded action is requested
- THEN the guard accepts and the action proceeds unchanged

### Requirement: AUTH-04 — One uniform refusal, and the guard always runs first

Every denial — anonymous, expired, unknown nick, non-administrator, unresolvable verdict
— MUST produce the same refusal body. The guard MUST NOT disclose which condition failed,
neither in the body nor in a status code that distinguishes them.

For `download_backup.php`, an unauthenticated or non-administrator request MUST be refused
with **HTTP 401** and MUST be refused *before* the plugin-token check, so the response can
never be a 403 produced by code that is only reachable past the guard.

For the SSE process scripts, the existing SSE-formatted error event remains the transport
contract for streamed endpoints. Its `message` payload MUST be the same text used for the
401 body, so that the transport difference (event stream vs. JSON) never becomes a
denial-reason difference.

#### Scenario: Anonymous plus a valid plugin token is still 401

- GIVEN no session, and a `su_csrf_token` value that is valid in some other session
- WHEN `download_backup.php` is called
- THEN HTTP 401
- AND the plugin-token check is never reached

#### Scenario: Non-administrator cannot be distinguished from anonymous

- GIVEN a valid session for a non-administrator
- WHEN `download_backup.php` is called
- THEN the response body and status are byte-identical to the anonymous case

#### Scenario: Ordering is asserted, not assumed

- GIVEN the guard is instrumented to record its verdict
- WHEN a request carrying a valid `su_csrf_token` and no session arrives at
  `download_backup.php`
- THEN the guard ran first
- AND the operation did not proceed

### Requirement: AUTH-05 — The plugin-token authenticator is unchanged; its minting points are two, each gated

`su_csrf_token` handling MUST NOT change: storage under
`$_SESSION['system_updater']['csrf_tokens']` in `SU_SESS_*`, 4 h TTL, `hash_equals()`
comparison. The `start`/`begin`/`chunk` actions MUST continue to authenticate with that
token only and MUST NOT be routed through this guard — each of those requests re-reads the
application from disk mid-restore, so validating against the freshly restored code or
session would break the next chunk.

The token's security property is **provenance**, and provenance MUST be stated as **two
authorized minting points, each behind its own honest gate**. It MUST NOT be stated or
tested as "a single minting point":

| Minting point | Gate that authorizes the mint | What passing the gate proves |
|---|---|---|
| the `#[AdminOnly]` `admin_updater` page | the `#[AdminOnly]` declaration plus an authenticated administrator session | an administrator asked for that page |
| `recovery.php` | the A2 per-installation secret, compared constant-time (`RACC-04`), followed by an authenticated administrator session in the console (`REC-06`) | the requester holds the installation secret and signed in |

Neither gate substitutes for the other and neither is weaker; a token minted behind either
one is the same value with the same storage, the same TTL and the same comparison.
Validation MUST NOT consult which point minted it — it cannot, because it runs in a request
that re-reads the application from disk. A console-minted token is therefore interchangeable
with an admin-minted one at `start`/`begin`/`chunk`, and is a credential for nothing else
(`CSRF-01`).

*Recorded reversal.* This requirement previously read "`admin_updater` as the only minting
point", and the companion `plugin-csrf-token` requirement carried the same framing. The
product owner reversed it as part of `RACC-07`: under A2 the console has its own provenance,
so a second gated minting point adds no weak link — it trades one strong gate for another.
`proposal.md` risk 5 and its success criterion still describe the single-point framing; this
delta supersedes them for the token contract. A contract test that still forbids the second
point contradicts `RACC-07` and MUST be rewritten, not kept.

The guard MUST NOT be presented as replacing or strengthening the plugin token, and the
plugin token MUST NOT be presented as an identity.

#### Scenario: start/begin/chunk keep the plugin token as their only authenticator

- GIVEN a `start` request with a valid `su_csrf_token` and no application session
- WHEN the process bootstrap runs
- THEN the request proceeds
- AND the session guard of this capability is not consulted

#### Scenario: Minting reaches exactly two gated entry points

- GIVEN the production call graph
- WHEN every path able to reach `system_updater_csrf_generate()`,
  `system_updater_csrf_field()` or `system_updater_csrf_meta()` is traced to its HTTP entry
  point
- THEN the set of entry points is exactly two: a render of the `#[AdminOnly]`
  `admin_updater` page, and `recovery.php`'s render after the secret gate and after
  administrator authentication
- AND no `process_*` script is among them
- AND no third entry point can reach those helpers

#### Scenario: The second point cannot mint on its own

- GIVEN a console request that did not pass the secret gate, or passed it with no
  authenticated administrator session
- WHEN the response is produced
- THEN no `system_updater.csrf_tokens` entry was created
- AND the console's login-form token (`REC-01`) was not stored in that namespace

#### Scenario: The guard never reads plugin-token state to decide identity

- GIVEN a request whose only credential is `su_csrf_token`
- WHEN a non-`start`/`begin`/`chunk` guarded action is requested
- THEN the guard denies unless a login-written session exists

### Requirement: AUTH-06 — A genuine administrator session keeps working (regression contract)

A real administrator session MUST continue to poll `status`/`progress` and to download a
backup with no behavioural change other than the added row read. This requirement exists
because `AUTH-01` removes the branch that was masking `AUTH-02`'s defect: if the reader
is not fixed, removal alone breaks polling for everybody.

#### Scenario: Polling and download are unaffected

- GIVEN a genuine administrator session written by the application login
- WHEN `status`/`progress` are polled repeatedly and a backup is downloaded
- THEN every poll is accepted and the download returns 200 with the file stream

#### Scenario: Session expiry stays governed by the existing policy

- GIVEN a session whose `login_time`/`last_activity` are expired per `SessionPolicy`
- WHEN a guarded action is requested
- THEN it is denied, exactly as before this change

## Failure-mode inventory (recorded honestly)

| # | Fail-open behaviour that survives this change | Why it is tolerated |
|---|---|---|
| 1 | `system_updater_session_is_valid()` returns `true` when `FSFramework\Security\SessionPolicy` is absent, skipping idle expiry for an already-authenticated session | It cannot authenticate anybody; it only skips expiry. Declared a non-goal by `proposal.md`. Not claimed as closed here. |
| 2 | The administrator verdict is a single row read with no cache, so a database outage denies legitimate administrators | Deliberate: a cache would reopen a revoked-admin window, which the core's own `fs_auth` avoids. Recovery is the documented path back in. |
| 3 | A `progress` poll whose admin re-read fails during a restore (the restore rewrites `fs_users`) is denied | Fail-closed is kept for every action; the UI is required to treat that denial as transient (`plugin-backup-access` delta, stepped-restore scenarios), not as a fatal error. |
| 4 | Denials are not counted anywhere, so an authenticated non-administrator can probe repeatedly | Each probe requires a real login session; counting it would consume `LoginThrottle` budget that belongs to credential handlers (`LTI-03`). |

## Traceability

| Requirement | Capability | Finding / source | Proposal success criterion |
|---|---|---|---|
| AUTH-01 | plugin-auth-guard | VULN-0004 §1-2 | anonymous + core token → 401; helper does not exist |
| AUTH-02 | plugin-auth-guard | SPEC-ADD-1 (spec phase) | genuine admin still polls/downloads |
| AUTH-03 | plugin-auth-guard | VULN-0004 §3 | non-administrator refused on all four scripts |
| AUTH-04 | plugin-auth-guard | VULN-0004 §5, SPEC-ADD-2 | 401, never 403 |
| AUTH-05 | plugin-auth-guard | VULN-0004 §4 (+ product-owner reversal via `RACC-07`) | `start`/`begin`/`chunk` unchanged; two authorized minting points, each gated |
| AUTH-06 | plugin-auth-guard | VULN-0004 §6 | no new failure mode for real administrators |

## Testing notes — this suite has no database

The plugin's PHPUnit bootstrap defines constants only. Therefore:

- `AUTH-01`, `AUTH-02`, `AUTH-04` are asserted through the pure decision function over
  injected session maps and injected request arrays. No database, no `session_start()`.
- `AUTH-03` is asserted by injecting a resolver that returns `true`, `false`, `null`, or
  throws; the production resolver's shape is asserted statically (a single row read by
  nick, no cache, no fallback that returns `true`).
- `AUTH-05` is asserted by enumerating, in the source tree, every HTTP entry point able to
  reach the minting helpers, and asserting that the set equals the two authorized points
  above — never that the set has one element.
- No scenario in this delta requires a live `fs_users`.
