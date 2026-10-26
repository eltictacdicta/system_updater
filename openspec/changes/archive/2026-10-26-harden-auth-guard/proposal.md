# Proposal: Harden the plugin authentication guard

## Intent

The unauthenticated assessment of 2026-10-24 produced eight validated findings.
Six were core and are closed by `2026-10-25-harden-auth-boundaries` (archived in
the **core** repository at `openspec/changes/archive/2026-10-25-harden-auth-boundaries/`).
The remaining two live entirely in this plugin, and that change named them as
out of scope and explicitly assigned their SDD to this repository (core
`proposal.md:43-47`, `:534`, and `:452-457`, which forbids copying plugin SDD
into the core tree).

Both findings are one failure of the same kind: **this plugin's standalone
entry points decide "is this an administrator?" from something that is not an
identity.**

- `recovery.php` decides it from a password guess that no account-protection
  control guards, and answers differently for "no such account", "wrong
  password" and "not an administrator".
- `lib/session_auth.php::system_updater_is_logged_in()` decides it from a
  **framework CSRF token** — a value the public login page hands to anonymous
  visitors — and never checks the administrator flag at all.

Full control of this ERP sits behind those two doors: the recovery console
lists and restores complete database and application backups, and the four
administrative scripts download backups, restore them and replace the
application code. What the assessment demonstrated is the enumeration oracle,
the total absence of throttling, and anonymous execution of the handler bodies
of `download_backup.php`, `process_backup.php`, `process_core_update.php` and
`process_restore.php`. What it did **not** demonstrate is a successful password
guess, a backup download, a restore or a core update: the plugin-local
`su_csrf_token` refused every data-bearing action. That second control is why
this is MEDIUM and not CRITICAL, and it is the control this change must keep.

## Provenance — this document has been amended

This proposal is not in its first revision, and the deltas under `specs/` are the
authoritative text: where this prose and a delta differ, the delta governs. Two
product-owner decisions arrived after the proposal was written and approved. The
analysis of the two findings is unchanged; scope, the token model and the
downstream statements that depended on them have been reconciled. Dated
**2026-10-25**.

**S1 — two requirements added.** The original proposal scoped the recovery
endpoint to "make it safe while it exists" and deliberately said nothing about
its reachability or its dependencies. The product owner added two binding
requirements on top of the findings:

- **Core independence.** `recovery.php` must be independent of the core:
  **`config.php` is the only core file it may depend on** (database credentials
  only). No `vendor/autoload.php`, no core classes.
- **Per-installation secret entry point (stealth-like).** The plain path must be
  inaccessible and indistinguishable from a non-existent path, a generated
  per-installation secret unlocks it, and three lose-the-secret escapes exist:
  CLI `--secret`, CLI `--rotate-secret`, and `SU_RECOVERY_SECRET` in
  `config.php`.

These produced two new capabilities — `plugin-recovery-access`
(`RACC-01`…`RACC-08`) and `plugin-recovery-login` (`REC-01`…`REC-08`) — plus
new requirements in `plugin-auth-guard` and `plugin-csrf-token`. Consequences
they carry, which the original prose below does not state: the dependency rule
supersedes VULN-0001 §7's *mechanism* — the `legacy_support` class bridge is
named in §7 and again in risk 7, and in both places the bridge is replaced by a
plugin-local one; the outcome is kept and strengthened by `REC-07` — and it
supersedes risk 6's decision to use the core `CsrfManager` for the recovery form
(`REC-01`). It also changes the *mechanism* — never the outcome — of VULN-0001
§3, §4 and §5: the ban bucket is read natively over `ip.log` (`REC-03`),
`LoginThrottle` is consulted whenever it is loadable with a local fallback that
is no more permissive when it is not (`REC-04`), and the unknown-user path
compares against a fixed dummy bcrypt hash declared by the script instead of
calling a core helper (`REC-05`).

**S2 — one minting point became two, each gated.** The original proposal
asserted, in VULN-0004 §4, in the token table, in risk 5, in the Affected Areas
table and in one success criterion, that `su_csrf_token` has `admin_updater` as
its `only` minting point, and treated a second one as a weakening. That framing is
superseded, and the decision it supported — leaving the recovery console unable
to restore anything — was overruled by the product owner. Minting is now **two
authorized points, each behind its own gate**: `admin_updater`, gated by the
`#[AdminOnly]` declaration plus an authenticated administrator session, and
`recovery.php`, gated by the per-installation secret **then** an authenticated
administrator session (`AUTH-05`, `CSRF-01`, `RACC-07`). No security claim is
softened: passing the second gate proves operator-level access to the
installation, so it trades one strong provenance for another equally strong one
rather than introducing a weak link. Storage, format, 4 h TTL and
`hash_equals()` comparison are unchanged, and a token minted behind either gate
still confers no identity (`CSRF-01`). The same reversal brought the console's
restore path into scope, where the original revision had listed it as an
adjacent defect, an out-of-scope item and a non-goal.

Everything else — the two findings, their analysis, and the risk register except
the anchors named above — stands as written.

## Scope

### In Scope

| # | Finding | Severity | Boundary that fails | Primary surfaces |
|---|---|---|---|---|
| VULN-0004 | Framework CSRF token accepted as an authenticated session | MEDIUM | Standalone session guard: identity derived from a non-credential, administrator flag never verified | `lib/session_auth.php` |
| VULN-0001 | Unthrottled administrative recovery login | MEDIUM | Credential verification without the framework's ban check, attempt counter, or uniform failure | `recovery.php` |

Recommended order: **VULN-0004 first** (one branch plus one dead helper in one
file; restores the stated contract of an already-specified endpoint), then
**VULN-0001** (touches a standalone page, its form, and its credential flow),
then the reachability work of S1 (it rewrites the same file's bootstrap and the
console's restore path).

**Added by the product owner on 2026-10-25 (S1 — see "Provenance").** These
requirements sit on top of the two findings, not instead of them: neither finding
is narrowed, and the console must satisfy both. The dependency rule is stated
once, plainly: **`recovery.php` may depend on `config.php` and on nothing else
from the core** — `config.php` is where the database credentials live, and it is
the whole of the permitted core surface. `vendor/autoload.php`, core classes and
the framework bootstrap are forbidden dependencies, and the console must still
authenticate and restore when they are missing or damaged, because that state is
the one it exists for.

| Capability | Requirements | What the code must satisfy |
|---|---|---|
| `plugin-recovery-access` | `RACC-01` | `config.php` is the only core file the script loads or includes; the Composer autoloader and every core class are removed from its dependency graph. Where a core service supplied a control, the console implements that policy locally against the state the core uses — never a parallel bucket. |
| `plugin-recovery-access` | `RACC-02`…`RACC-04` | Reachability is gated by a per-installation secret: CSPRNG-generated, persisted with `0600`, unique per installation, resolved without core code, and compared constant-time. Without it the plain path answers an HTTP 404 that is byte-identical to a request for a non-existent path — no console marker, no session, no database connection, byte-identical for absent, empty and wrong. |
| `plugin-recovery-access` | `RACC-05`, `RACC-08` | After the gate, authorization is carried by the console's own session marker, not the URL, and the marker must be non-transferable and never accepted outside the console; the session name resolves without a core class. |
| `plugin-recovery-access` | `RACC-06` | A lost secret is never a permanent lockout: three independent escapes (CLI `--secret`, CLI `--rotate-secret`, `SU_RECOVERY_SECRET` in `config.php`), plus a one-time error-log line on first generation. The CLI flags are inert for web SAPIs. |
| `plugin-recovery-access` | `RACC-07` | The console's restore path must actually work: stepped `begin`/`chunk` only, `su_csrf_token` still mandatory on every issued request, and minting only after the secret gate **and** an authenticated administrator session. |
| `plugin-recovery-login` | `REC-01`…`REC-08` | The credential handler keeps every bound of VULN-0001 and adopts the A1-compatible forms the dependency rule forces: a self-contained login token in the console's own session namespace (never `_csrf`, never `system_updater.csrf_tokens`), a native `ip.log` ban read reproducing the core's semantics, a plugin-local fallback limiter no more permissive than `LoginThrottle`, and a plugin-local legacy-hash bridge that preserves the outcome of VULN-0001 §7. |
| `plugin-auth-guard`, `plugin-csrf-token` | `AUTH-05`, `CSRF-01` | The token contract these two capabilities already state is re-expressed as two authorized, independently gated minting points (S2). No change to storage, format, TTL or comparison. |

The acceptance criteria for these requirements are the scenarios of the five
deltas. The Success Criteria list at the end of this document is the original
two-finding gate and is not restated to cover them.

### Out of Scope

- **The core repository — all of it.** No file under `base/`, `src/`,
  `controller/`, `model/`, `themes/` or the core `openspec/` is modified, and no
  core spec delta is created. Core helpers are reused read-only through their
  existing public APIs; if a needed core capability turned out to be missing,
  that would be a separate core change, not a quiet edit from here. Within that
  boundary `recovery.php` restricts itself further: `config.php` is the only core
  file it may load at all (S1, above), so for that script "reused read-only" means
  the constants and credentials `config.php` defines, not core classes.
- **Post-authentication object-level authorization** across customer, sales,
  catalogue and company controllers. It is the report's highest-value untested
  area and it is untested because no credentials were available — a proof gap,
  not a clean result. Closing either finding here does not claim anything about
  it.
- **Removing, disabling or relocating the recovery endpoint**, and the
  deployment-side alternatives in recommendation 1 (loopback/CLI-only
  invocation, reverse-proxy deny rules). That is an operator decision about
  whether the endpoint should exist; this change makes it safe while it exists,
  and S1's secret gate controls who may reach it without removing it.
- **Centralised authentication middleware for standalone plugin scripts**
  (report recommendation 11), **CSP `unsafe-inline` reduction** (9), and
  **session-cookie/HSTS transport** (core VULN-0007). All core-level or
  cross-plugin work.
- The remaining proof gaps of the same report: session-identifier rotation on
  successful login, server-side logout invalidation, and user/timing
  enumeration through the login and reset endpoints.

## VULN-0004 — A CSRF token accepted as an authenticated session

### Where it fails

| Reference | Behaviour today |
|---|---|
| `lib/session_auth.php:411-442` | `system_updater_is_logged_in()`: accepts a session containing `user_nick`, `user_id`, `_sf2_attributes.user_nick` or `_sf2_attributes.user_logged_in` — and then falls through to a token branch |
| `lib/session_auth.php:430-435` | `if (system_updater_request_has_valid_csrf()) { return true; }` — a valid framework CSRF token is treated as an authenticated session |
| `lib/session_auth.php:395-409` | `system_updater_request_has_valid_csrf()`: reads `_csrf_token`, `_token` or `X-CSRF-Token` and delegates to `\FSFramework\Security\CsrfManager::isValid()` |
| `lib/session_auth.php` (whole file) | The administrator flag is checked **nowhere**; every consumer is an administrator-only operation |
| `lib/process_bootstrap.php:143-145` | Every action other than `start`/`begin`/`chunk` is guarded by `system_updater_require_authenticated_session()` → the function above |
| `lib/process_bootstrap.php:135-141` | `start`/`begin`/`chunk` never call it: they authenticate with `ensure_request_csrf()`, the plugin's own token |
| `download_backup.php:42` | Plain-mode bootstrap applies the same guard before its own plugin-token check at `:57-76` |

The premise in that branch's comment — "only authenticated users receive
a CSRF token" — is false: `index.php?page=login` is public and mints one into
the anonymous visitor's own session. A token that the requester can mint for
themselves, bound to a session they already own, proves only "this request came
from a page I loaded".

### Required behaviour

1. **Identity comes only from session state written by a real login.** The
   branch at `:430-435` MUST be removed; no narrower token check may replace
   it. A CSRF token is never an authentication credential, in any variant.
2. **The token helper becomes unreachable and MUST be deleted.**
   `system_updater_request_has_valid_csrf()` has no other production caller
   (only its definition and this branch), so leaving it in place preserves a
   ready-made way to reintroduce the defect.
3. **The resolved user MUST be an administrator.** After a session is accepted
   structurally, the guard MUST resolve the nickname and verify the
   administrator flag against `fs_users` (or through the framework's
   authoritative admin resolver, which re-reads the row), and MUST fail closed
   when the row, the lookup, or the helper is unavailable. A logged-in
   non-administrator MUST be refused on all four scripts.
4. **The plugin token mechanism MUST NOT change.**
   `su_csrf_token` / `ensure_request_csrf()` keeps authenticating
   `start`/`begin`/`chunk`, keeps its `SU_SESS` storage, its 4h TTL and its
   `hash_equals()` comparison. Its minting points are **two, each behind its own
   gate** (`AUTH-05`, `CSRF-01`, `RACC-07`): the `#[AdminOnly]` `admin_updater`
   page, gated by the attribute plus an authenticated administrator session, and
   `recovery.php`, gated by the per-installation secret (`RACC-04`) **and then**
   an authenticated administrator session in the console (`REC-06`). Validation
   runs in a request that re-reads the application from disk, so it cannot tell
   the two apart and MUST NOT try to.
5. **Anonymous must be 401, never 403.** An unauthenticated request to
   `download_backup.php` MUST be refused by the session guard before the
   plugin-token check, so the response is the documented 401 and never a 403
   produced by code that is only reachable past the guard. The ordering already
   holds; the change must keep it and assert it.
6. **No new failure mode for real administrators.** A genuine administrator
   session (`user_nick`/`user_id`, valid expiry) MUST continue to pass
   `status`/`progress` polling and backup download unchanged.

### Why the plugin token is not the same mistake

| | Framework CSRF (`_csrf_token`) | Plugin token (`su_csrf_token`) |
|---|---|---|
| Who can obtain it | Anyone: the public login page mints it for anonymous visitors | Exactly two gated entry points: a rendered `#[AdminOnly]` `admin_updater` page, or the console after its per-installation secret and an authenticated administrator login |
| Provenance | Pre-authentication | Post-authentication at both points — an authenticated administrator on the `#[AdminOnly]` page (`controller/admin_updater.php:31`, `:149` → `lib/twig_compat.php:107-124`), or the holder of the installation secret *plus* an authenticated administrator in the console (`RACC-07`) |
| Entropy / binding | Symfony session token | 256-bit `random_bytes(32)`, bound to `SU_SESS`, 4h TTL, `hash_equals()` |
| Legitimate role | CSRF only | Actions executed by an already-authenticated administrator |

The security property is **who can obtain the value**, not the label "CSRF".
Removing the framework-token branch does not weaken the plugin token and must
not be presented as doing so. Nor does the console's gated minting point weaken
it: passing the secret gate proves operator-level access to the installation,
the administrator credential is still required before any token is minted
(`REC-06`, `RACC-07`), and a token from either point remains a non-credential for
identity (`CSRF-01`).

## VULN-0001 — Unthrottled administrative recovery login

### Where it fails

| Reference | Behaviour today |
|---|---|
| `recovery.php:86-112` | Credential check performed inline against `fs_users` and evaluated on every POST |
| `recovery.php:104` | `El usuario no es administrador.` — a third distinct failure string |
| `recovery.php:107` | `Contraseña incorrecta.` |
| `recovery.php:110` | `Usuario no encontrado.` — account-existence oracle |
| `recovery.php:70-83` | Existing-session revalidation: already re-reads the row and dies for a non-admin — correct, keep it |
| `recovery.php:101` | Sets `$_SESSION['user_id']` without regenerating the session identifier |
| `recovery.php:145-155` | Login form carries no CSRF field of any kind |
| `recovery.php:49` | Session starts before any credential decision |

The file never includes `base/fs_login.php` or `base/fs_ip_filter.php` and keeps
no counter of its own, so it is a fourth credential-submission handler that
bypasses both `LoginThrottle` and the IP ban — the two controls whose entire
purpose is to make online guessing impractical. Because it is served directly
by PHP-FPM it also never passes through `index.php`, so no core control can
reach it by construction.

### Required behaviour

1. **A CSRF verdict gates the submission.** The login form MUST carry a token
   and the handler MUST refuse a request whose token is missing or invalid
   with one generic message. This mirrors core `LC-06`
   (`openspec/specs/login-csrf/spec.md`): the verdict is authoritative, and a
   rejected submission MUST NOT reach credential evaluation.
2. **A rejected token MUST NOT count as an attempt.** Counting the rejection
   path would let a cross-site POST burn a victim's IP budget — the exact
   reasoning behind the `LC-06` correction and `LTI-03`. Validation precedes
   counting.
3. **The IP ban MUST be honoured before any credential is evaluated.**
   `fs_ip_filter::is_banned()` is read exactly as the primary login does; an
   already-banned address is refused without reaching `password_verify()`.
4. **The attempt counter MUST be the one the primary login uses.**
   `LoginThrottle::isThrottled()` is consulted before evaluation and
   `recordFailure()` is recorded for a failed attempt, so the two entry points
   share one budget and an attacker cannot double it by switching doors.
   `clear()` runs on success.
5. **Exactly one failure string for every rejection reason.** Unknown user,
   wrong password and non-administrator MUST produce the same body, and the
   unknown-user path MUST still execute a password hash comparison
   (`getDummyHash()`) so response time does not distinguish the cases either.
6. **The credential MUST be evaluated once, and success MUST rotate the
   session identifier.** `session_regenerate_id(true)` immediately before
   writing `$_SESSION['user_id']`.
7. **Preserve what already works.** The legacy SHA1/MD5 verification bridge
   through `legacy_support` MUST remain reachable — this is the one screen that
   exists for an administrator whose hash has not been migrated yet. The
   authenticated-session branch at `:70-83` keeps its administrator re-check.
8. **No new writer to the authentication ban bucket.**
   `fs_ip_filter::set_attempt()` keeps its three production call sites
   (`LTI-03`); this endpoint records failures through `LoginThrottle` only. The
   residual asymmetry is recorded in Risks.

## Systemic root cause

The assessment named two root causes; both of the remaining findings are their
plugin-side instances, and the fixes are the same shape as the core's — **bind
the decision to the fact it should depend on.**

1. **Controls are bound to one request path, not to the action.** The login
   throttle, the IP ban and the uniform failure message live in
   `index.php` → `fs_controller` → `fs_login`. `recovery.php` reaches the user
   table through another door and therefore had to re-implement credential
   verification — and re-implemented it without any of the three controls. The
   checks were never missing as a design decision; they were unreachable
   because they are welded to a request path this endpoint does not use.
2. **CSRF and authentication are conflated.** A value proving "this request
   came from my page" was accepted as proof of "this user is logged in". The
   core instance (VULN-0003) discarded a verdict; this plugin's instance
   (VULN-0004) promoted a verdict to an identity — the same conflation seen
   from both sides.

A third, plugin-local cause compounds the second: **no consumer-side
authorization fact was ever checked.** The guard answers "is somebody logged
in?" while all four of its consumers are administrator-only operations, so even
a correct answer to the question asked would have been the wrong question. That
is why the fix adds administrator verification instead of only deleting a
branch.

## Relationship to the archived core change

| | `2026-10-25-harden-auth-boundaries` (core) | this change (plugin) |
|---|---|---|
| Problem | Core controls bound to one request path; core login CSRF verdict discarded | Plugin standalone guards bound to request shape, not identity |
| `LC-06` (CSRF verdict authoritative, credential evaluated at most once, 0 attempts on the rejection path) | Enforced in `fs_controller` / `login` | Mirrored in `recovery.php`'s credential handler **and** applied from the other side: a verdict never confers identity |
| `LC-02` (token validated under the unified `FSSESS` session, transport/storage consistency) | Amended by that change; untouched here | Not touched: token name resolution, storage, and rotation stay exactly as `lib/session_auth.php` implements them today. Only **what the verdict entitles a request to** changes |
| `LTI-01`/`LTI-03` (authenticator bucket owned by credential handlers; validation before counting) | Made the reset flow use its own bucket | Recovery is a credential handler, so reading the ban and using `LoginThrottle` is sanctioned; adding a fourth `set_attempt()` writer is not |
| These two findings | Declared out of scope, assigned to this repository's SDD (`proposal.md:43-47`, `:534`) | Delivered here; no duplication, no contradiction |

No core behavior is *forked*. The plugin consumes `LoginThrottle` (cache
counters, same thresholds and windows) whenever that class is loadable, reads the
ban verdict from the same `ip.log` state the core reads rather than loading
`fs_ip_filter`, and uses the framework's authoritative admin resolution on the
guard scripts. Where the dependency rule bars a core class, the console
implements that class's *policy* against the *same* state and the same
thresholds (`RACC-01`, `REC-03`, `REC-04`); it never keeps a parallel bucket and
never a laxer rule.

### Relationship to this plugin's canonical specs

- **`plugin-backup-access` — already specifies the property that was broken.**
  "Requirement: Auth-Gated Download Endpoint" states that
  `download_backup.php` MUST require an *authenticated session* and a valid
  CSRF token, and its "Unauthenticated request is rejected → 401" scenario is
  precisely what an anonymous core-CSRF token defeated (it produced 403, i.e.
  the guard had already passed). The code contradicted the canonical spec; this
  change makes the code true to it rather than rewriting the requirement. The
  spec phase adds the missing discriminating scenario (anonymous + core token
  → 401).
- **`plugin-csrf-token` — silent on identity, and that silence was exploited.**
  Nothing in that spec authorizes promoting a token to an identity, so the code
  never had textual cover. Its Token Storage requirement ("MUST NOT read/write
  core session keys (`_sf2_attributes`, `_csrf`)") is also contradicted in
  spirit: the removed branch delegated validation to the core manager, which
  reads `_csrf`, so an authorization decision depended on a store the spec
  keeps the plugin out of. This change adds one explicit clause — the token is
  never an authentication credential and the session guard MUST NOT consult
  core CSRF — and leaves the rest of the capability, including
  `su_csrf_token`, intact.
- **`plugin-updates`** — contains no authentication requirement; unaffected.

## Capabilities

### New Capabilities

| Capability | Covers |
|---|---|
| `plugin-auth-guard` | Standalone session guard: identity from a real login session only, administrator verification with fail-closed behaviour, no token-as-identity, unchanged plugin-token authenticator whose minting points are two and each gated |
| `plugin-recovery-login` | Recovery console credential check: CSRF gate, ban gate, shared attempt counter, uniform failure (message and timing), single evaluation, session rotation — in the A1-compatible forms: self-contained login token, native `ip.log` read, local fallback limiter, plugin-local legacy-hash bridge |
| `plugin-recovery-access` | Reachability and dependency contract of the emergency console: `config.php` as its only core dependency, per-installation secret gate with 404 indistinguishability and three lost-secret escapes, session-carried authorization, session name without core code, and a restore path that works behind both gates |

### Modified Capabilities

| Capability | Delta intent |
|---|---|
| `plugin-csrf-token` | Add one requirement (`CSRF-01`): a CSRF token is never an authentication credential; the session guard must not consult framework CSRF. The same delta states the token's provenance as two authorized, independently gated minting points. No change to generation, storage, TTL, validation or view helpers |

(`plugin-backup-access` gains a scenario, not a requirement change: it already
demands an authenticated session.)

## Approach

Decisions only; no code in this phase.

| # | Decision | Where |
|---|----------|-------|
| 1 | Delete the token branch and the now-unreferenced helper | `lib/session_auth.php:395-409`, `:430-435` |
| 2 | Verify the administrator flag by re-reading `fs_users` for the resolved nick; fail closed on missing class, missing row or lookup error | `lib/session_auth.php` (guard path) |
| 3 | Extract the guard's decision into a pure function over (session array, admin resolver) so it is testable without a database | `lib/session_auth.php` |
| 4 | Add the CSRF field and validate it first; rejected verdict is inert (no counting, no evaluation). The token is self-contained in the console's own session namespace — no `CsrfManager` (`REC-01`) | `recovery.php:145-155`, `:86-112` |
| 5 | Read the ban bucket natively over `ip.log` with the core's semantics (no `fs_ip_filter` class), and gate on `LoginThrottle::isThrottled()` when it is loadable, with a no-more-permissive local fallback when it is not; `recordFailure()` on failure, `clear()` on success | `recovery.php:86-112` (`REC-03`, `REC-04`) |
| 6 | Collapse the three failure strings into one message and use a fixed dummy bcrypt hash constant for the unknown-user path, not a core helper | `recovery.php:95-111` (`REC-05`) |
| 7 | `session_regenerate_id(true)` before writing the session identity | `recovery.php:101` |
| 8 | Leave `lib/process_bootstrap.php` unchanged: `start`/`begin`/`chunk` keep authenticating with the plugin token | `lib/process_bootstrap.php:135-145` |
| 9 | RED tests first (`strict_tdd: true`), including the DB-free extraction from decision 3 and the guard-ordering assertion for 401-vs-403 | `tests/` |
| 10 | Implement the console's reachability and dependency contract per the S1 requirements — the `config.php`-only dependency graph, the secret gate and its escapes, the session marker, the two-gate mint, and the stepped restore drive | `recovery.php`, `RACC-01`…`RACC-08` |

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `lib/session_auth.php:395-409` | Removed | Token-reading helper, no remaining caller |
| `lib/session_auth.php:411-442` | Modified | Session-only identity plus administrator verification |
| `recovery.php:86-112` | Modified | CSRF gate, ban gate, shared throttle, uniform failure, single evaluation |
| `recovery.php:101` | Modified | Session identifier rotated on success |
| `recovery.php:145-155` | Modified | CSRF field on the login form |
| `lib/process_bootstrap.php` | Unchanged (verified) | `start`/`begin`/`chunk` do not use the patched function |
| `download_backup.php:57-76` | Unchanged (verified) | Second, plugin-local token check stays |
| `recovery.php` (whole script) | Modified (S1) | Per `plugin-recovery-access`: `config.php`-only dependency (the `vendor/autoload.php` load at `:25-28` is removed), secret gate and session marker, secret resolution and the CLI escapes, native `ip.log` read, plugin-local legacy bridge, `su_csrf_token` minted after both gates, and the stepped `begin`/`chunk` restore drive |
| `lib/twig_compat.php`, `controller/admin_updater.php` | Unchanged (verified) | One of the two authorized minting points for `su_csrf_token`; the other is `recovery.php` behind its own gates |
| `tests/SessionAuthTest.php` + new tests | Modified / New | Guard decision, admin denial, token-not-identity |

No schema change. No frontend/framework change. No Composer dependency, so no
`vendor/` update (this plugin has no Composer manifest).

## Non-goals

- **Any change in the core repository**, including core specs, core tests and
  the archived `harden-auth-boundaries` artifacts (read-only context).
- **Object-level authorization after login.** Never tested by the assessment;
  nothing here claims it.
- Making the recovery endpoint unreachable, CLI-only, or loopback-only.
- Changing backup semantics, backup format, or the console's UI beyond what
  `plugin-recovery-access` requires for the secret gate and the restore path.
- Replacing the plugin token with the framework token (or the reverse).
- Adding fail-closed behaviour for the missing-`SessionPolicy` case in
  `system_updater_session_is_valid()` (`:209-211`). That fail-open only skips
  idle expiry for an already-authenticated session; it cannot authenticate
  anybody, it belongs to session-expiry policy rather than to either finding,
  and it is not claimed as closed here.
- Hardening `recovery.php`'s unescaped output of the account nick
  (`recovery.php:194`). Not part of either finding; the value is
  administrator-created, not attacker-controlled today.

### Adjacent defects discovered (one now in scope by product-owner decision)

- **The recovery console's restore buttons could not work** (`recovery.php:335`
  opens `process_restore.php?action=start&...` with no `su_csrf_token`, so since
  `plugin-csrf-token` every `start` was refused with `Token CSRF ausente`). The
  original revision recorded this as an adjacent defect deliberately left alone,
  because repairing it required deciding whether `recovery.php` may mint plugin
  tokens at all. That decision is now made: it may, behind its own two gates and
  using the stepped `begin`/`chunk` protocol (`RACC-07`; see "Provenance").
  The defect is therefore **in scope** and closed by this change.
- Recovery duplicates the credential check instead of sharing one
  implementation with a future core-side contract. Consolidation is the
  report's medium-term recommendation, not this change.

## Risks and Unknowns

| # | Risk / unknown | Likelihood | Mitigation |
|---|----------------|------------|------------|
| 1 | `LoginThrottle` fails **open** when the cache is unavailable (core design: a cache outage must not lock everyone out) | Low | Same behaviour as the primary login; never worse than today, where recovery has no limit at all. Document it; do not invent a second counter |
| 2 | Asymmetry: recovery failures never escalate to the `ip.log` ban, because adding `set_attempt()` there would break `LTI-03`'s three-call-site invariant | Medium | Failures still consume the shared `LoginThrottle` budget, so guessing is bounded. If reviewers require symmetric bans, that is a core `LTI` revision first, then a one-line follow-up here |
| 3 | Administrator re-read adds one primary-key lookup per guarded request, including `status`/`progress` polling | Medium | The lookup is by nick on a small table and only on script entry. No caching: a cache reintroduces a revoked-admin window that the core's own `fs_auth` deliberately avoids. Measure during apply; revisit only with evidence |
| 4 | The guard's new admin requirement could lock out an administrator whose row is unreadable (DB outage, nick drift between `user_id` and `fs_users`) | Low | Fail closed by design, but the deny message already tells the operator to sign in again; recovery remains the documented path to restore access. Verify the recovery session itself still passes after rotation |
| 5 | The plugin token's provenance is enforced at minting time, not per request. Minting is authorized from exactly two gates; if a future change mints `su_csrf_token` from a third entry point, `start`/`begin`/`chunk` silently lose their gated provenance | Low | Add a contract test enumerating every HTTP entry point able to reach `system_updater_csrf_generate()`/`_field()`/`_meta()` and asserting the set is exactly the two authorized points — a render of the `#[AdminOnly]` `admin_updater` page, and `recovery.php` after its secret gate and administrator authentication. The test must assert that set, never that it has one element (`AUTH-05`, `CSRF-01`). This is design-inherent, not introduced here |
| 6 | Adding CSRF to the recovery form forces a token-mechanism choice: the framework token lives in `FSSESS` (already open in `recovery.php:49`), while the plugin token lives in `SU_SESS` and minting it would switch sessions mid-script and risk writing login state into the wrong session | Medium | Decision: use the framework token **for the form only**, never for identity. `CsrfManager` is usable standalone (native `$_SESSION['_csrf']` storage; `SessionManager::getInstance()` adopts an already-started session with the resolved name). The design phase must confirm token-id/namespace alignment inside this script before coding |
| 7 | Legacy-hash administrators regress if the uniform-failure refactor drops the `legacy_support` bridge | Medium | Explicit requirement (VULN-0001 §7) plus a test that a legacy hash still authenticates through the bridge |
| 8 | The suite cannot touch a database (`tests/bootstrap.php` defines constants only), so the admin re-read and the credential check are not directly unit-testable | High | Mandatory extraction of the decision into pure functions over injected data (Approach #3); DB-touching code stays a thin, statically asserted shell in the house style of `DownloadBackupScriptTest` |
| 9 | Fixing the guard makes previously "reachable" anonymous code paths return 401; any consumer that relied on the lenient behaviour would now break | Low | Only the four administrative scripts consume the guard, and their legitimate consumer is an administrator browser; `start`/`begin`/`chunk` are untouched |
| 10 | Unknown: whether any operator workflow depends on `recovery.php` being usable by a non-administrator account | Low | The console already refuses non-administrators on the existing-session branch (`:77-81`); no supported workflow exists |

## Success Criteria

- [ ] Anonymous request carrying a self-minted framework CSRF token (`_csrf_token`, `_token`, `X-CSRF-Token`) is refused with **401** by `download_backup.php`, `process_backup.php`, `process_core_update.php` and `process_restore.php`
- [ ] A session with only `_csrf` populated is not authenticated; `system_updater_request_has_valid_csrf` does not exist
- [ ] An authenticated **non-administrator** session is refused with 401 on all four scripts
- [ ] A genuine administrator session still polls `status`/`progress` and downloads a backup
- [ ] `start`/`begin`/`chunk` still authenticate with `su_csrf_token` only, and the HTTP entry points able to mint it are exactly two: the `#[AdminOnly]` `admin_updater` page, and `recovery.php` after its secret gate and an authenticated administrator login
- [ ] `recovery.php` returns one identical failure body for unknown user, wrong password and non-administrator, with comparable response time
- [ ] `recovery.php` refuses a banned IP before evaluating any credential
- [ ] Sustained wrong guesses on `recovery.php` reach the `LoginThrottle` refusal within its window (6 per 600 s per nick + IP), and a submission whose CSRF token is invalid records no attempt
- [ ] A successful recovery login rotates the session identifier and clears the attempt counter
- [ ] A legacy SHA1/MD5 hash still authenticates, through whatever bridge the current core allows a `config.php`-only script to use (`REC-07`)
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` green
- [ ] No file outside `plugins/system_updater/` modified; no entry in the core `openspec/`

## Rollback Plan

Revert `lib/session_auth.php` and `recovery.php` to the released revision. Both
changes are additive to behaviour (a stricter guard and a stricter credential
check); they introduce no schema and no new dependency, so a revert restores
today's behaviour exactly — including its defects. The plugin then behaves as at
`2.11.15`, with `su_csrf_token` still the only control on data-bearing actions.
S1's one persisted artifact — the per-installation secret file (`RACC-02`) — is
left orphaned by a revert and read by nothing, since the reverted script has no
gate to consult it with.

## Dependencies

None. No new Composer dependency, no schema change, no core change, no
deployment step (unlike the core change, nothing here requires an operator
action).

S1 *removes* dependencies instead of adding any: `recovery.php` currently loads
`vendor/autoload.php` (`recovery.php:25-28`) and reaches core classes through it,
and after this change `config.php` is the only core file it may load. The
per-installation secret is generated on first use and written once to the PHP
error log, so deployment still needs no operator action; if the value is lost
later, `RACC-06` documents the escapes (CLI `--secret`, CLI `--rotate-secret`,
`SU_RECOVERY_SECRET` in `config.php`).

## Notes on execution

- `openspec/config.yaml` declares `strict_tdd: true`; tests are written RED
  before the fix, which is why Approach #3 (pure decision extraction) is a
  prerequisite rather than a preference.
- Runner: `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml`.
- Release versioning (`fsframework.ini`, currently `2.11.15`) is decided at
  merge time by the release workflow, not by this proposal.
