# Delta for plugin-recovery-access (new capability)

## Provenance

- **Source**: none. This capability is **not** described by `proposal.md`. It is a scope
  addition introduced by the product owner after the proposal was approved, and is
  binding for this change.
- **Capability status**: new. No canonical `openspec/specs/plugin-recovery-access/`
  exists.
- **Relationship to `plugin-recovery-login`**: that capability governs *how credentials
  are verified* once a request has reached the form. This one governs *whether the console
  is reachable at all* and *what it may depend on*. They are separate so that a future
  change to the credential policy cannot silently widen reachability, and vice versa.

### Product-owner scope addition — amended into `proposal.md` on 2026-10-25

`proposal.md` correctly treats "removing, disabling or relocating the recovery endpoint"
as out of scope and makes the endpoint safe *while it exists*. The product owner then
decided two things the proposal does not carry, and this delta records them verbatim:

- **A1 — dependency settlement.** `config.php` is the **only** core file `recovery.php`
  may depend on, because it is the only place the database credentials live. The Composer
  autoloader, core classes and the framework bootstrap are **not** permitted dependencies.
  The endpoint MUST still authenticate and restore when core PHP classes and
  `vendor/autoload.php` are unavailable or damaged — that is the exact scenario the script
  exists for.
- **A2 — per-installation secret entry point.** The console MUST be reachable only with a
  per-installation secret, MUST be indistinguishable from a missing file without it, and
  the secret MUST be resolvable without loading core code.

A1 creates a direct contradiction with `proposal.md` VULN-0001 §7 (keep the legacy
`legacy_support` bridge, which is a core+plugin class) and with proposal risk 6 (use the
framework `CsrfManager` for the recovery form). Both are resolved in the companion
`plugin-recovery-login` delta (REC-01, REC-07); this delta resolves the dependency
principle they must obey. `proposal.md` now carries A1 and A2 — in its Scope and in its
provenance note, amended 2026-10-25 — while this file remains the normative statement of
the requirements themselves.

## ADDED Requirements

### Requirement: RACC-01 — `config.php` is the only core dependency

`recovery.php` MUST NOT `require`/`include` any file outside its own directory other than
`FS_FOLDER/config.php`. Specifically it MUST NOT depend on:

- `FS_FOLDER/vendor/autoload.php` or any Composer-provided class;
- any class under `base/`, `src/`, `controller/` or `model/`;
- `base/config2.php` or the framework kernel.

Every fact the script needs beyond database credentials MUST be either (a) a constant that
`config.php` itself defines (`FS_DB_*`, `FS_FOLDER`, `FS_TMP_NAME`, `FS_SESSION_NAME`,
`FS_IP_WHITELIST`), (b) data it reads from disk itself, or (c) a plugin-local value.

Where a core service would normally provide a control, the script MUST implement the
*policy* locally while consuming the *same state* the core consumes — never a parallel
bucket. `RACC-01` is a constraint on dependencies, not a licence to fork security state.

#### Scenario: The console authenticates with a damaged core

- GIVEN `vendor/autoload.php` deleted
- AND a core class the script previously used made unloadable (e.g. `base/fs_login.php`
  or `src/Security/CsrfManager.php` broken or renamed)
- AND a valid `config.php` and a reachable database
- WHEN the operator opens the console with the correct secret and submits valid
  administrator credentials
- THEN the console renders the backup list
- AND no fatal error, no `Class not found`, and no Composer autoload is triggered

#### Scenario: The console restores with a damaged core

- GIVEN the same damaged installation
- AND an operator authenticated through the console
- WHEN a restore is requested through the console's documented path
- THEN the restore starts (the stepped `begin`/`chunk` sequence of `RACC-07`) without
  requiring any core class
- AND no fatal error and no `Class not found` interrupts the sequence

#### Scenario: No core file other than `config.php` is loaded

- GIVEN the source of `recovery.php` and its plugin-local includes
- WHEN the static include graph is enumerated
- THEN the only entry resolving outside `plugins/system_updater/` is
  `FS_FOLDER/config.php`

#### Scenario: A missing `config.php` is the only hard stop

- GIVEN `FS_FOLDER/config.php` does not exist
- AND a request carrying the correct secret
- WHEN the console is opened
- THEN the script stops with HTTP 500 and a plain-text body that names the missing file and
  the directory it was expected in
- AND no other core file is loaded and no database connection is attempted
- AND the message contains no credential, no path to the secret file and no stack trace

#### Scenario: A wrong secret never reaches `config.php`

- GIVEN `FS_FOLDER/config.php` exists but is damaged
- WHEN a request without a valid secret arrives
- THEN the response is the indistinguishable 404 of `RACC-04`
- AND `config.php` is not loaded and its parse errors are never echoed

### Requirement: RACC-02 — Per-installation secret: generation and storage

The console MUST be protected by a secret that:

- is generated from a CSPRNG (`random_bytes(32)`, rendered as 64 hex characters);
- is generated at install time or on first use, and persisted so it survives restarts;
- differs between installations — it MUST NOT be derived solely from values that are
  guessable or shared (install path, database name, hostname, the framework's own session
  seed);
- is stored with restrictive permissions (`0600`) outside the webroot when a suitable
  location is writable, and inside the webroot only as a last resort in a form whose
  direct HTTP request discloses nothing;
- is never written to an HTTP response except through `RACC-06`'s CLI path.

The persisted secret MUST be readable and writable by the PHP user alone; a failed write
MUST be a loud error, never a silent regeneration per request (a per-request regeneration
would make the console permanently unusable while appearing to work).

#### Scenario: Two installations never share a secret

- GIVEN two installations, each with its own framework root and database name, each on its
  first run
- WHEN each resolves its secret
- THEN the two values differ
- AND neither value is derivable from the install path or the database name alone

#### Scenario: The secret survives a restart

- GIVEN a secret already persisted
- WHEN a later request resolves it
- THEN the same value is returned and no new secret is generated

#### Scenario: Fetching the secret over HTTP discloses nothing

- GIVEN the secret file resolved to a location inside the webroot (last-resort case)
- WHEN it is requested directly over HTTP with no console parameters
- THEN the response body contains no hex secret and no PHP source
- AND the console's behavior is unchanged

#### Scenario: An unwritable secret location is a loud error

- GIVEN no writable location for the secret
- WHEN the console is opened with a plausible secret
- THEN the operator sees an error naming the failure
- AND the script does not fall back to a per-request generated secret

### Requirement: RACC-03 — The secret MUST be resolvable without core code

Resolving the secret MUST NOT require `class_exists()`, the Composer autoloader, or any
core file. The resolution order MUST be deterministic and documented, and MUST be:

1. a `SU_RECOVERY_SECRET` constant defined by `config.php` when present (operator pin);
2. the persisted per-installation value of `RACC-02`.

`config.php` MUST NOT be required in order to take branch 2 — the gate of `RACC-04` runs
before `config.php` is loaded, so an installation with a damaged `config.php` still answers
the gate with a plain 404 instead of a PHP error.

#### Scenario: Resolution works with the autoloader removed

- GIVEN `vendor/autoload.php` and every core class unavailable
- WHEN the secret is resolved
- THEN the persisted value is returned and no class is loaded

#### Scenario: The operator pin wins

- GIVEN `SU_RECOVERY_SECRET` defined in `config.php`
- AND a different persisted value on disk
- WHEN the secret is resolved
- THEN the constant value is used
- AND the persisted value is neither read nor rewritten

#### Scenario: The gate answers before `config.php` is loaded

- GIVEN a `config.php` that would fatal if loaded
- WHEN a request arrives with no secret parameter
- THEN the response is the 404 of `RACC-04`, not a fatal error page

### Requirement: RACC-04 — Without the secret the console does not exist

A request that does not carry the correct secret MUST NOT render the console, MUST NOT
reveal that the endpoint exists, and MUST NOT be distinguishable from a request for a
non-existent path. Concretely, the response MUST:

- carry HTTP 404;
- carry no console marker (`Modo Recuperación`, the plugin name, the backup list, a
  login field);
- set no session cookie and start no session;
- open no database connection and read no backup directory;
- be byte-identical for "parameter absent", "parameter empty" and "parameter wrong".

The comparison MUST be constant-time (`hash_equals()`-equivalent), matching the core's
stealth-mode discipline, and the three failure inputs MUST take the same code path — an
early return for "no parameter" is forbidden. Because the secret has a fixed length
(64 hex characters), the length check `hash_equals()` performs first carries no
information about the value.

With the correct secret the console MUST render exactly as it does today.

#### Scenario: No secret parameter

- GIVEN a request to the console script with no secret parameter
- WHEN it is served
- THEN HTTP 404 with the same body as a wrong secret
- AND no `Set-Cookie` header is emitted
- AND no database connection is attempted

#### Scenario: Wrong secret

- GIVEN a request carrying a 64-hex value that is not the installation's secret
- WHEN it is served
- THEN HTTP 404, byte-identical to the absent-parameter case

#### Scenario: Correct secret

- GIVEN a request carrying the installation's secret
- WHEN it is served
- THEN the console renders as it does today (login form or backup list, depending on the
  session)
- AND the response carries `Referrer-Policy: no-referrer`

#### Scenario: The secret never appears in a redirect or in the body

- GIVEN the console has been opened with the secret in the query string
- WHEN any subsequent response is rendered
- THEN the secret value does not appear in the body, in a redirect target, or in a form
  action

### Requirement: RACC-05 — After the gate, authorization is carried by the session, not the URL

Because the gate is a query parameter, submitting the login form or reloading the page
would otherwise require re-supplying it — which is impractical and leaks the secret into
history and into `Referer` for the page's third-party assets. The gate MUST therefore
establish an authorization marker in the session it opens, and subsequent requests MUST be
authorized by that marker.

The marker MUST be:

- written only after a successful constant-time secret comparison;
- scoped to the session that the console itself opened (no framework session key, no
  `_sf2_attributes`, no `_csrf`);
- non-transferable: no other script of this plugin may accept it, and it MUST NOT
  authenticate anything outside the console;
- discarded when the session ends, and rotated by `session_regenerate_id(true)` together
  with the login rotation of `REC-06`.

#### Scenario: The login POST does not need the secret

- GIVEN the console was opened once with the correct secret
- WHEN the login form is submitted to the console URL without any query parameter
- THEN the submission is accepted for credential evaluation
- AND no re-supplied secret is required

#### Scenario: The marker is not a plugin credential

- GIVEN a session holding only the console's authorization marker
- WHEN `download_backup.php` or a `process_*` action is requested with that session
- THEN the request is refused: the marker satisfies nothing outside the console

#### Scenario: A marker cannot be minted without the secret

- GIVEN a request with no valid secret and any session content
- WHEN it is served
- THEN no marker is written and the 404 of `RACC-04` is returned

### Requirement: RACC-06 — A lost secret MUST NOT be a permanent lockout

An emergency entry point that can be locked out permanently is a trap. The installation
MUST provide at least three independent, documented escapes, so that any operator able to
operate the server at all can regain access with no core code available:

1. **CLI read.** When `PHP_SAPI === 'cli'`, the script MUST print the current secret
   (`php recovery.php --secret`) without loading core code and without touching the
   database.
2. **CLI rotation.** When `PHP_SAPI === 'cli'`, the script MUST regenerate and print a new
   secret (`php recovery.php --rotate-secret`), invalidating the previous value.
3. **Operator pin.** Defining `SU_RECOVERY_SECRET` in `config.php` overrides the persisted
   value (`RACC-03`).

In addition, the first generation MUST write the generated secret exactly once to the PHP
error log, under a fixed, documented prefix, so an operator with log access but no shell
can recover it. The log is a server-side sink: the value MUST NOT be echoed to any HTTP
response, ever.

The CLI flags MUST be ignored for non-CLI SAPIs — a web request carrying them behaves
exactly like any other request and yields the 404 of `RACC-04`.

#### Scenario: Recovering from a lost secret over the shell

- GIVEN an operator with shell access and no knowledge of the secret
- WHEN the script is run as a CLI command with the read flag
- THEN the current secret is printed and no HTTP response is involved
- AND no database connection is opened

#### Scenario: Rotating from the shell

- GIVEN an operator runs the rotation flag
- WHEN the command completes
- THEN a new secret is printed and persisted
- AND a request carrying the previous secret is refused with the 404 of `RACC-04`

#### Scenario: Recovering from a lost secret with only file access

- GIVEN an operator who can edit `config.php` but has no shell
- WHEN `SU_RECOVERY_SECRET` is set to a value of their choosing
- THEN the console accepts that value on the next request

#### Scenario: Recovering from the log

- GIVEN the secret was generated on first use
- WHEN the operator reads the PHP error log
- THEN the generated value is present exactly once, under the documented prefix

#### Scenario: The web cannot print the secret

- GIVEN a web request carrying the CLI read flag as a query parameter
- WHEN it is served
- THEN the response is the 404 of `RACC-04`
- AND the secret is not present in the body or in any header

### Requirement: RACC-07 — The console's restore path MUST work, behind the secret and a console-minted token

*A reversal is recorded here deliberately.* An earlier revision of this delta decided the
opposite: that the console's restore buttons would stay unrepaired, because minting
`su_csrf_token` from `recovery.php` would add a second minting point to a token whose
security property is provenance. **The product owner has overruled that decision.** A
console that lists backups and cannot restore any of them defeats the console's purpose,
which is to restore an installation when everything else is broken. The technical objection
also no longer holds under A2: with a per-installation secret entry point, `recovery.php`
has its own provenance, and reaching it with the correct secret proves the requester is the
operator exactly as reaching `admin_updater` proves an authenticated administrator. Minting
behind that gate trades one strong provenance for another equally strong one; it does not
introduce a weak point. The property is therefore stated as **two authorized minting
points, each with its own honest gate** (`AUTH-05`, `CSRF-01`), not as a single point.

A restore started from the emergency console MUST complete on the production host.
Concretely:

1. **Stepped protocol only.** The console MUST drive `action=begin` followed by one or more
   `action=chunk` requests. It MUST NOT present the legacy single-request `action=start` as
   its restore path, because the canonical `plugin-backup-access` requirement "Stepped
   Resumable Restore" states that a single-request restore can never complete on the
   production host.
2. **The token stays mandatory and is not bypassed.** Every request the console issues to
   `process_restore.php` MUST carry a valid `su_csrf_token` — the bootstrap validates
   `begin` and `chunk` validates explicitly. `ensure_request_csrf()` MUST NOT be relaxed,
   skipped, or special-cased for console-originated requests.
3. **Two gates precede the mint.** `recovery.php` MUST mint `su_csrf_token` only after, in
   the same request chain: (a) the gate of `RACC-04` has passed with a constant-time
   comparison (`hash_equals()`-equivalent, the same discipline as `RACC-03` and `RACC-04`),
   and (b) the console holds an authenticated administrator session per
   `plugin-recovery-login`. No token MUST be minted on the login-form render or on any other
   unauthenticated render.
4. **The mechanism is unchanged.** Storage
   (`$_SESSION['system_updater']['csrf_tokens']` in `SU_SESS`), format (64 hex characters),
   4 h TTL and `hash_equals()` comparison remain exactly as `AUTH-05` and the canonical
   `plugin-csrf-token` specify. Only the set of authorized minting points changes.
5. **No core code.** Minting MUST obey `RACC-01`: no `CsrfManager`, no `class_exists()`, no
   Composer autoload. The console implements the same policy locally against the same
   store. Opening the plugin session to perform the write is permitted — `SU_SESS` is where
   the store lives and where the process scripts read it — but it MUST NOT destroy or
   corrupt the console's own session (identity keys and the `RACC-05` marker MUST survive
   it), and it MUST NOT write login identity into the plugin session.
6. **Bound to the minting session.** The token MUST be usable only from the session that
   minted it: a value lifted from another browser's session MUST be refused, as the
   canonical `plugin-csrf-token` validation already requires. The token MUST NOT be emitted
   into a response produced without the secret, into a response produced before
   administrator authentication, into a 404 body, or into a log line.
7. **The marker is not the token, and the token is not the marker.** The `RACC-05`
   authorization marker MUST NOT be accepted as `su_csrf_token`, and a minted
   `su_csrf_token` MUST NOT be accepted as the `RACC-05` marker.
8. **Endpoint secrecy is unchanged.** A request carrying restore parameters but no valid
   secret MUST receive the `RACC-04` 404 — byte-identical to a request for a non-existent
   path — and MUST mint nothing. `process_restore.php` itself stays shared with
   `admin_updater` and MUST NOT be changed to require the secret: the administrator surface
   has no secret to present, and its protection is the token. The secret gates the
   console-side entry point, and the token is the only thing that travels outward from it.

**Decided, not left ambiguous:** a token minted by the console is *interchangeable* with one
minted by `admin_updater` at the three process actions — validation is mechanical and cannot
tell the minting point apart, and MUST NOT try to — and it confers **nothing** on the normal
admin surface. `admin_updater` and every other admin page are gated by `#[AdminOnly]` plus an
authenticated administrator session and never consult `su_csrf_token`; `download_backup.php`
denies on the session guard before the token is read (`AUTH-04`). A recovery-minted token is
a CSRF credential for `start`/`begin`/`chunk` only and remains a non-credential for identity
(`CSRF-01`).

#### Scenario: With the secret and an authenticated console session, restore is accepted

- GIVEN the console was opened with the correct secret
- AND an administrator authenticated through it
- WHEN a restore is triggered from the console's backup list
- THEN a `su_csrf_token` was minted and is present on every request the console issues to
  `process_restore.php`
- AND the issued sequence is `action=begin` followed by at least one `action=chunk`
- AND no request is refused with a CSRF error

#### Scenario: Without the secret the restore path does not exist

- GIVEN a request carrying restore parameters but no secret (absent, empty, or wrong)
- WHEN the console script serves it
- THEN HTTP 404, byte-identical to a request for a non-existent path
- AND no `su_csrf_token` is minted and none appears in the response
- AND no `system_updater.csrf_tokens` entry exists afterwards

#### Scenario: A minted token is still required

- GIVEN a chain that passed the secret gate and authenticated an administrator
- WHEN `action=begin` or `action=chunk` is requested without a valid `su_csrf_token`
- THEN the request is refused as a CSRF failure
- AND no restore state is armed and no statement is executed

#### Scenario: A token from another session is refused

- GIVEN a token minted in console session A
- WHEN it is presented together with session B's cookie
- THEN it is refused
- AND no restore request proceeds on the strength of a token from another session

#### Scenario: The console mints nothing before authentication

- GIVEN a request that passed the secret gate
- AND no authenticated administrator session
- WHEN the login form is rendered
- THEN no `su_csrf_token` is minted
- AND `system_updater.csrf_tokens` is absent from the session, as `REC-01` requires

#### Scenario: A recovery-minted token grants nothing on the admin surface

- GIVEN a token minted by the console after both of its gates
- WHEN `download_backup.php` is requested with it and no login-written session
- THEN the response is the 401 of `AUTH-04`
- AND the token check was never reached
- AND no `#[AdminOnly]` page was reached by presenting the token alone

#### Scenario: No dead control is offered

- GIVEN an authenticated operator on the console
- WHEN the backup list renders
- THEN every restore control it renders points at a request carrying a valid `su_csrf_token`
- AND no control is rendered that is known to be refused

#### Scenario: The listing still works

- GIVEN an authenticated operator and at least one backup on disk
- WHEN the console renders
- THEN every backup group is listed with its name and timestamp

### Requirement: RACC-08 — Session bootstrap degrades without core code

The console MUST resolve the application session name without requiring a core class, in
this order: the core `SessionManager` when it happens to be loadable → `FS_SESSION_NAME`
from `config.php` → a plugin-local derivation. A resolved name that does not match the
application's live session MUST NOT be treated as an authorization failure: it only means
the operator is not single-signed-on from the panel and will be asked to log in.

#### Scenario: The name resolves from `config.php` alone

- GIVEN no core class is loadable
- AND `FS_SESSION_NAME` is defined in `config.php`
- WHEN the console boots
- THEN that name is used for its session

#### Scenario: A name mismatch asks for credentials, it does not deny

- GIVEN a session name derived locally that no browser cookie matches
- WHEN the console is opened with the correct secret
- THEN the login form is rendered
- AND no error is shown and nothing is logged as a security event

## Failure-mode inventory (recorded honestly)

| # | Behaviour | Direction | Why it is tolerated |
|---|---|---|---|
| 1 | The secret is a bearer value in a URL; anyone who reads it once (shell history, log, shoulder) owns the console | Fail-open | Bounded by `RACC-05` (no re-supply after the first request) plus `Referrer-Policy: no-referrer`; rotation is one CLI command. The alternative — no reachability control at all — is today's state. |
| 2 | A secret does not add authentication strength; it adds *reachability* control on top of the credential check | Neither | Explicit: possession of the secret never authenticates anybody. The administrator credential remains mandatory (`plugin-recovery-login`). |
| 3 | `SU_RECOVERY_SECRET` in `config.php` can be weaker than a generated 256-bit value | Fail-open | Operator decision, documented. It exists so an operator is never locked out; `RACC-06` does not depend on it. |
| 4 | Two authorized minting points instead of one: a leaked recovery secret combined with administrator credentials yields a `su_csrf_token` that did not come from a rendered `#[AdminOnly]` page | Neither | Deliberate product-owner decision (`RACC-07`). The second gate is A2's per-installation secret, which is not weaker than the first, and the token still confers neither identity (`CSRF-01`) nor access to any admin page. |
| 5 | A read-only "is the secret right?" oracle exists — 404 vs. console | Low | The comparison is constant-time and the two outcomes differ only by the caller's knowledge of a 256-bit value; there is no incremental leak. |
| 6 | The console can now instruct `process_restore.php` to overwrite the installation. An operator mistake there is destructive, where the previous decision made it impossible | Availability | This is the console's purpose (`RACC-07`): an emergency console that cannot restore is not an emergency console. Bounded by the two gates, the stepped protocol's resumability, and the existing stale-lock healing. |

## Traceability

| Requirement | Capability | Source | Note |
|---|---|---|---|
| RACC-01 | plugin-recovery-access | A1 (product owner, post-proposal) | `config.php`-only dependency |
| RACC-02 | plugin-recovery-access | A2 (product owner, post-proposal) | generation, storage, uniqueness |
| RACC-03 | plugin-recovery-access | A2 + A1 | core-free resolution |
| RACC-04 | plugin-recovery-access | A2 | indistinguishability |
| RACC-05 | plugin-recovery-access | A2 (spec-phase consequence) | URL secret must not be re-supplied |
| RACC-06 | plugin-recovery-access | A2 (lost-secret escape) | three independent escapes |
| RACC-07 | plugin-recovery-access | Product-owner reversal of the original spec-phase decision (supersedes `proposal.md` risk 5's single-point framing) | restore works from the console, behind two gates |
| RACC-08 | plugin-recovery-access | A1 (spec-phase consequence) | session name without core code |

## Testing notes — this suite has no database

- `RACC-01` is asserted statically: the include graph of the script resolves outside
  `plugins/system_updater/` exactly once, to `config.php`; no `vendor/autoload.php`
  reference remains.
- `RACC-02`, `RACC-03`, `RACC-06` are asserted against temporary directories: generation,
  persistence, uniqueness across two temp roots, the operator pin, both CLI paths (invoked
  through a subprocess with a temp `config.php`), and the log line.
- `RACC-04` is asserted by invoking the script as a subprocess with headers captured: status
  404, identical bodies for the three failure inputs, no `Set-Cookie`.
- `RACC-05` is asserted by two sequential subprocess invocations sharing a cookie jar, with
  a stubbed database seam: the second request carries only the cookie.
- `RACC-07` is asserted on rendered output plus the issued request sequence, with no
  database: with an injected administrator row and a passed secret gate, a token is present
  on every `process_restore.php` request and the sequence is `begin` → at least one `chunk`;
  the minting order (secret comparison → administrator authentication → mint) is asserted
  through a step recorder; without the secret the console returns the `RACC-04` body and the
  session store stays empty; `action=chunk` without a token is refused by
  `ensure_request_csrf()`.
- No scenario in this delta needs a live `fs_users`.
