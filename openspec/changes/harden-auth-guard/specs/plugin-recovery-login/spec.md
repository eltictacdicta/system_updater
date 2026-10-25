# Delta for plugin-recovery-login (new capability)

## Provenance

- **Source**: `proposal.md` → VULN-0001, "Required behaviour" 1-8, Approach 4-7, risks 1, 2,
  4, 6, 7, 8.
- **Capability status**: new. No canonical `openspec/specs/plugin-recovery-login/` exists.
- **Scope**: the credential handler of `recovery.php` only. Reachability and dependency
  isolation belong to `plugin-recovery-access`; identity decisions for the standalone
  process scripts belong to `plugin-auth-guard`.

### Product-owner scope addition — recorded in `proposal.md` since the reconciliation

A1 in the companion `plugin-recovery-access` delta (`config.php` is the only permitted core
file) **overrides two decisions in `proposal.md`**. Both overrides are deliberate and are
recorded here, in the requirements that carry them:

- **A1 overrides proposal risk 6** ("use the framework token — `CsrfManager` — for the
  recovery form only"). `CsrfManager` is a core class; under A1 it may not be a dependency.
  → `REC-01` requires a self-contained token instead.
- **A1 overrides VULN-0001 §7's *mechanism*** ("keep the `legacy_support` bridge"). The
  *outcome* (a legacy SHA1/MD5 administrator can still log in) is preserved and made
  **stronger**: it must work with no core or plugin class loadable. → `REC-07`.

`proposal.md` now records A1/A2 in its "Provenance" and "Scope" sections; this file
remains the authoritative statement of the requirements themselves.

## ADDED Requirements

### Requirement: REC-01 — The CSRF verdict is authoritative and precedes everything else

The login form MUST carry a CSRF token, and the handler MUST validate it before any other
step of the submission — before the IP ban read, before the throttle consult, before any
account lookup and before any password comparison.

A submission whose token is missing, empty or wrong MUST be refused with one generic
message and MUST NOT reach credential evaluation by any later path of the same request.
This mirrors core `LC-06` from the other side: there, a discarded verdict must not fall
through to a credential check; here, a rejected verdict must not either.

Because A1 forbids the `CsrfManager` dependency (`RACC-01`), the token MUST be
self-contained:

- minted into the session the console itself opened (`RACC-05`), under a namespace of the
  console's own — never `_csrf`, never `_sf2_attributes`, never
  `system_updater.csrf_tokens`. The exclusion states this token's **contract**, not who is
  allowed to mint: since the recorded reversal, `AUTH-05` authorizes exactly two gated
  minting points, and a console-minted `su_csrf_token` does not contradict it. What the
  contract forbids is a `su_csrf_token` that exists before both console gates are
  satisfied — `RACC-07` mints it only after the secret comparison **and** an authenticated
  administrator session, while the login-form render is by definition unauthenticated. So
  the form's token must live elsewhere, and storing it in `system_updater.csrf_tokens` would
  hand an unauthenticated visitor a value that `start`/`begin`/`chunk` accept, even though it
  would still confer no identity (`CSRF-01`);
- compared with `hash_equals()`;
- bound to the session, so a token harvested from another session is refused.

#### Scenario: Invalid token never reaches the credential check

- GIVEN a POST carrying `nick` and `password` and a token that is not the session's token
- WHEN the submission is handled
- THEN the operator sees one generic CSRF refusal
- AND no account lookup occurred and no password comparison occurred
- AND no counter moved

#### Scenario: A missing token behaves exactly like a wrong one

- GIVEN the same POST with no token field at all
- WHEN the submission is handled
- THEN the response body is byte-identical to the wrong-token case
- AND the same "no counter moved" assertion holds

#### Scenario: The token is not the plugin credential

- GIVEN the console rendered its login form
- WHEN the session is inspected
- THEN the console's token exists in its own namespace
- AND `system_updater.csrf_tokens` is absent, so the value cannot authorize any
  `process_*` action

#### Scenario: A token from another session is refused

- GIVEN a valid token minted for a different session
- WHEN it is submitted with valid credentials
- THEN the submission is refused as a CSRF failure and no credential is evaluated

### Requirement: REC-02 — A rejected verdict records ZERO attempts

A submission rejected by `REC-01` MUST increment no counter, in any bucket. Counting the
rejection path would let a cross-site POST burn a legitimate operator's budget — the exact
reasoning behind core `LC-06` and `LTI-03`.

This MUST hold for the shared `LoginThrottle` budget, for the console's own fallback
counter (`REC-04`) and for the `ip.log` ban bucket (which this endpoint never writes at
all, per `REC-03`).

#### Scenario: A cross-site POST burns nothing

- GIVEN a bounded budget for a nick and IP, with a known count before the request
- WHEN a POST with an invalid token and correct credentials for that nick is handled
- THEN the count is unchanged
- AND `ip.log` is unchanged
- AND the credentials were not evaluated

#### Scenario: A real failed attempt moves exactly one mechanism

- GIVEN a valid token and a wrong password
- WHEN the submission is handled
- THEN exactly one failure is recorded
- AND it is recorded in exactly one counter set (the shared one when available, the
  fallback otherwise), never both for the same submission

### Requirement: REC-03 — The ban gate is read before any credential is evaluated, from the same state the core uses

Before evaluating a credential the handler MUST determine whether the client IP is banned,
reading the **same** state the primary login reads: `FS_FOLDER/tmp/<FS_TMP_NAME>ip.log`,
whose lines are `ip;count;expire`.

Because A1 forbids loading `base/fs_ip_filter.php` (`RACC-01`), the read MUST be native,
and it MUST reproduce the core's semantics exactly:

- a line is considered only when it has three fields and `expire > time()`;
- an IP is banned when `count > 5` (`fs_ip_filter::MAX_ATTEMPTS`), matching
  `is_banned()`;
- an absent file or a malformed line means "not banned", exactly as the core's reader
  behaves — the plugin MUST NOT introduce a stricter or laxer rule.

The endpoint MUST NOT write this file. `set_attempt()` keeps its three production call
sites (`LTI-03`); adding a fourth writer is out of scope for this change.

`FS_TMP_NAME` is defined by `config.php` (`install.php:92`), so this read respects A1 with
no core file loaded.

#### Scenario: A banned address is refused without touching the password

- GIVEN an `ip.log` line `203.0.113.7;6;<future>` and a request from `203.0.113.7`
- WHEN a submission with a valid token and correct credentials is handled
- THEN the submission is refused before any account lookup or password comparison
- AND the refusal body equals the uniform failure of `REC-05`

#### Scenario: Expired entries do not ban

- GIVEN a line `203.0.113.7;9;<past>`
- WHEN a request from that address is handled
- THEN the address is not treated as banned and evaluation proceeds

#### Scenario: A malformed or absent ban file does not ban

- GIVEN the ban file is absent, or contains a line with two fields
- WHEN a request is handled
- THEN the address is not treated as banned
- AND the behaviour matches `fs_ip_filter::is_banned()` for the same input

#### Scenario: The endpoint never writes the ban bucket

- GIVEN any sequence of failed submissions
- WHEN the ban file is inspected afterwards
- THEN its content is byte-identical to before

### Requirement: REC-04 — Recovery shares the primary login's budget, with an A1-compatible bound

When the core throttle is loadable, the handler MUST consult
`LoginThrottle::isThrottled($nick)` before evaluating the credential, MUST record a failed
attempt with `LoginThrottle::recordFailure($nick)`, and MUST clear on success with
`LoginThrottle::clear($nick)`. The recovery endpoint and the primary login then share one
budget by construction: an attacker cannot double the attempt allowance by switching
doors.

When the core throttle is **not** loadable — the damaged-core scenario A1 exists for — the
handler MUST fall back to a plugin-local file counter whose thresholds are **no more
permissive** than the core's: 6 attempts per 600 s per nick + IP, and 20 attempts per 900 s
per account. Reading the same `LoginThrottle` constants at runtime is not possible without
the class; therefore the plugin-local values MUST be declared as named constants and MUST
be asserted against the core constants by a drift test when the class is loadable in the
test run.

The fallback is not a second budget in any single install state: when the core throttle is
unreachable, the primary login cannot consult it either, so no door is left with a larger
allowance than this one.

Both modes MUST honour `REC-02`: only a submission that reached credential evaluation and
failed counts.

#### Scenario: A throttled nick is refused before evaluation

- GIVEN a nick at the shared threshold for this IP
- WHEN a submission with the correct password is handled
- THEN it is refused without evaluating the password
- AND the refusal body equals the uniform failure of `REC-05`

#### Scenario: The budget is shared with the primary login

- GIVEN failures recorded through the primary login for a nick and IP
- WHEN the console is used for that nick from the same IP
- THEN the console sees the same count and throttles at the same threshold

#### Scenario: Damaged-core fallback still bounds guessing

- GIVEN the core throttle class cannot be loaded
- WHEN 6 failures are submitted for one nick from one IP inside the window
- THEN the seventh submission is refused without evaluating the password
- AND a message states that the console is running with its local limiter

#### Scenario: Success clears the budget

- GIVEN a nick below the threshold with one or more recorded failures
- WHEN valid credentials are submitted from the same IP
- THEN the counter used for recording is cleared

### Requirement: REC-05 — Exactly one failure body and one comparable response time

Unknown nick, wrong password and authenticated-but-not-administrator MUST produce the same
body, the same status, and comparable response time. There MUST NOT be a distinct string
for any of the three, and the three MUST NOT be distinguishable by the number of database
queries or by the presence of a password comparison.

The unknown-nick path MUST still execute exactly one `password_verify()` against a fixed
bcrypt hash, so its timing matches the wrong-password path. The dummy hash MUST be a
constant of the script (no per-request `password_hash()` cost) and MUST be a valid bcrypt
hash so `password_verify()` performs the full work.

The credential verdict MUST be a pure function over `(row|null, plain password, verifier)`
so that "exactly one verification" is assertable by injecting a counting verifier — the
suite has no database.

#### Scenario: The three reasons are indistinguishable

- GIVEN three submissions with a valid token: (a) unknown nick, (b) existing nick with a
  wrong password, (c) existing non-administrator with the correct password
- WHEN each is handled
- THEN the three bodies are byte-identical
- AND the three statuses are identical
- AND each performed exactly one `password_verify()` call

#### Scenario: Timing is not an oracle

- GIVEN the same three submissions against a bcrypt-hashed row
- WHEN response times are sampled
- THEN the unknown-nick samples are not an order of magnitude faster than the
  wrong-password samples

#### Scenario: No reason leaks into diagnostics shown to the client

- GIVEN any of the three failures
- WHEN the response body is inspected
- THEN it contains none of "no encontrado", "no es administrador", "contraseña incorrecta"
  as distinct messages — one generic message is rendered

### Requirement: REC-06 — The credential is evaluated once, and success rotates the session and writes the canonical identity

The password MUST be evaluated at most once per request. On success the handler MUST call
`session_regenerate_id(true)` **before** writing identity, so the pre-authentication session
identifier cannot be reused.

On success the session MUST contain the keys the plugin's own guard reads —
`user_nick` **and** `user_id`, plus `login_time` and `last_activity` timestamps consistent
with `SessionPolicy` — because `system_updater_session_is_valid()` resolves identity from
`user_nick` and would return `false` for a session that only carries `user_id`. A recovery
session that cannot pass the plugin's own guard would silently lose the access the console
grants (`proposal.md` risk 4 asks for exactly this verification).

#### Scenario: One evaluation

- GIVEN a submission reaching evaluation
- WHEN the counting verifier observes the call
- THEN exactly one evaluation occurred

#### Scenario: The session identifier rotates

- GIVEN a pre-authentication session id
- WHEN valid administrator credentials are accepted
- THEN the resulting session id differs from the pre-authentication id
- AND the old id is no longer usable

#### Scenario: The recovery session passes the plugin guard on its own merits

- GIVEN a session created by a successful recovery login
- WHEN the `plugin-auth-guard` decision function is applied to it with an injected
  administrator verdict of `true`
- THEN identity is resolved and the verdict is accept
- AND no CSRF token of any kind is required for that verdict

#### Scenario: Rotation happens before the write

- GIVEN the handler's step sequence is observed
- WHEN login succeeds
- THEN the regeneration precedes the first identity write

### Requirement: REC-07 — The legacy hash bridge is preserved locally (A1 override of the mechanism, not the outcome)

An administrator whose stored hash is a pre-bcrypt SHA1 or MD5 digest MUST still be able to
log in. This is the one screen that exists for exactly that administrator.

Under A1 the bridge MUST be plugin-local: no core class, no `legacy_support` plugin class,
no Composer autoload. It MUST implement the same comparisons the core's
`PasswordHasherService::verifyLegacyHash` performs, in the same order, with
`hash_equals()`:

- `sha1($salt . $password)` when a non-null salt is supplied;
- `sha1($password)`;
- `sha1(mb_strtolower($password, 'UTF8'))`;
- `md5($password)`.

The modern path MUST run first: a bcrypt/argon hash MUST be verified with
`password_verify()`, and the legacy comparisons MUST NOT be attempted against a hash that
`password_verify()` recognizes as its own format. The `salt` value MUST be read from the
row when the column exists (the current `fs_users` schema has no `salt` column, so the
salted variant is reachable only on legacy schemas) and passed as `null` otherwise.

If no verification is available at all — neither modern nor legacy matches, or the stored
value is not a recognizable hash — the outcome MUST be the uniform failure of `REC-05`. It
MUST NOT be a bypass ("unknown format accepted"), and it MUST NOT be a distinct message.

#### Scenario: A SHA1 administrator logs in with the autoloader removed

- GIVEN `vendor/autoload.php` deleted and no core or plugin class loadable
- AND a row whose `password` is `sha1($plain)` and `admin` is true
- WHEN the plain password is submitted
- THEN the login succeeds
- AND the session is rotated per `REC-06`

#### Scenario: The lower-cased variant matches

- GIVEN a row whose `password` is `sha1(mb_strtolower($plain, 'UTF8'))`
- WHEN the same plain password is submitted with different casing
- THEN the login succeeds

#### Scenario: An MD5 administrator logs in

- GIVEN a row whose `password` is `md5($plain)` and `admin` is true
- WHEN the plain password is submitted
- THEN the login succeeds

#### Scenario: A modern hash still uses `password_verify()`

- GIVEN a row whose `password` is a bcrypt hash
- WHEN the correct password is submitted
- THEN the verdict comes from `password_verify()` and the legacy comparisons are not
  attempted

#### Scenario: An unrecognizable hash is a plain failure

- GIVEN a row whose `password` is neither a `password_verify()` format nor a matching
  legacy digest
- WHEN the correct plain password is submitted
- THEN the uniform failure of `REC-05` is rendered
- AND no session identity is written

### Requirement: REC-08 — Reusing an existing session keeps its administrator re-check

A request that already carries an authenticated console session MUST re-read the row and
MUST refuse a non-administrator, exactly as the current implementation does. The re-check
MUST NOT be weakened by the login path rewrite, and it MUST NOT be satisfied by the
console's authorization marker (`RACC-05`), which proves reachability, not identity.

#### Scenario: A demoted administrator loses the console

- GIVEN an authenticated console session for a nick whose `admin` flag is now false
- WHEN the console is opened
- THEN it is refused with the existing "administrator required" behaviour
- AND no backup list is rendered

#### Scenario: The marker alone does not authenticate

- GIVEN a session holding only the console's authorization marker and no identity
- WHEN the console is opened
- THEN the login form is rendered

## Recorded gap — not from the proposal

`recovery.php` does not check `fs_users.enabled`, while the primary login refuses a
disabled account. After this change the console therefore still accepts a disabled
administrator. This delta does **not** require a change of that behaviour, because
silently tightening it would remove the operator's last supported way back into an
installation whose only administrator was disabled on purpose (recovery exists for exactly
that class of incident). It is recorded here so the outcome is a decision, not an
oversight, and so the verify phase reports it:

- **Current behaviour (kept)**: a disabled administrator authenticates through the console.
- **Recommended follow-up**: decide it in `recovery-access-policy`, not inside the two
  pentest findings. The restore path is no longer pending — `RACC-07` decides it — so it is
  not part of that follow-up.
- **Falsifiable assertion for verify**: the delta is reported as an accepted gap; no test
  in this change asserts either direction.

## Failure-mode inventory (recorded honestly)

| # | Behaviour | Direction | Why it is tolerated |
|---|---|---|---|
| 1 | `LoginThrottle` fails open when the cache is unavailable (core design) | Fail-open | Identical to the primary login, and strictly better than today's unlimited recovery attempts. Deliberately not "fixed" here: inventing a second always-on counter would diverge from the shared budget. |
| 2 | Recovery failures never escalate to the `ip.log` ban bucket | Asymmetry | Required by `LTI-03`'s three-writer invariant. Guessing stays bounded by `REC-04`; symmetric bans would be a core `LTI` revision first. |
| 3 | The plugin-local fallback counter (`REC-04`) is a second bucket in the damaged-core state | Fail-open, bounded | In that state the primary login cannot use the shared bucket either, so no door gains allowance relative to the other. |
| 4 | The ban read is native and therefore coupled to `ip.log`'s on-disk format | Availability | Mitigated by reproducing `fs_ip_filter::read_line()`/`is_banned()` semantics exactly, by a drift test against the core constants, and by failing to "not banned" only where the core also does. |
| 5 | The legacy bridge is reimplemented (`REC-07`) rather than delegated | Drift | Mandated by A1. Mitigated by copying the core's comparison set and order, using `hash_equals()`, and asserting each variant in tests. A future core change to the legacy algorithm must update both. |
| 6 | A disabled administrator still authenticates | Fail-open | Recorded above as a deliberate, documented gap. |

## Traceability

| Requirement | Capability | Finding / source | Proposal success criterion |
|---|---|---|---|
| REC-01 | plugin-recovery-login | VULN-0001 §1 (+ A1 override of risk 6) | one identical failure body; CSRF field added |
| REC-02 | plugin-recovery-login | VULN-0001 §2 (LC-06 / LTI-03) | invalid token records no attempt |
| REC-03 | plugin-recovery-login | VULN-0001 §3 (+ A1: native read) | banned IP refused before evaluation |
| REC-04 | plugin-recovery-login | VULN-0001 §4 (+ A1 fallback) | throttle refusal within its window |
| REC-05 | plugin-recovery-login | VULN-0001 §5 | identical body, comparable time |
| REC-06 | plugin-recovery-login | VULN-0001 §6 + risk 4 | rotation; session passes the guard |
| REC-07 | plugin-recovery-login | VULN-0001 §7 (+ A1 override of the mechanism) | legacy hash still authenticates |
| REC-08 | plugin-recovery-login | VULN-0001 §7 (existing behaviour) | non-administrator refused |

## Testing notes — this suite has no database

- `REC-01`, `REC-02`, `REC-05`, `REC-07` are asserted through the pure credential-verdict
  function with injected `(row, plain, verifier)` inputs and a counting verifier. No
  database.
- `REC-03` is asserted through the pure ban-file parser over literal line arrays, plus one
  fixture file written to a temporary `tmp/` directory.
- `REC-04` is asserted in both modes: shared mode with the real `LoginThrottle` over a
  test cache adapter, fallback mode with the class unloadable (the test process asserts on
  the plugin-local counter directly).
- `REC-06` is asserted with a session-driving helper that records the order of
  `session_regenerate_id()` and the identity writes; the resulting session array is then
  fed to the `plugin-auth-guard` decision function.
- `REC-08` and `RACC-07` are asserted on rendered output with an injected user row.
- No scenario in this delta requires a live `fs_users`; the row is always an array.
