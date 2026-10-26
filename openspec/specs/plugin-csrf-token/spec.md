# Plugin CSRF Token Specification

## Purpose

Self-contained CSRF token generation, storage, and validation for system_updater SSE process scripts. Independent of core CsrfManager internals — works with any core version.

## Requirements

### Requirement: Token Generation

The system MUST generate tokens via `bin2hex(random_bytes(32))` keyed by token ID `system_updater_csrf`. MUST NOT depend on core token format or namespace.

#### Scenario: Generate produces unique token in session

- GIVEN an active PHP session
- WHEN `system_updater_csrf_generate()` is called
- THEN a 64-char hex token is returned and stored at `$_SESSION['system_updater']['csrf_tokens']['system_updater_csrf']` with a creation timestamp

#### Scenario: Consecutive calls produce different tokens

- GIVEN an existing token in session
- WHEN `system_updater_csrf_generate()` is called again
- THEN the new token differs and the session store is updated

### Requirement: Token Storage

MUST store in `$_SESSION['system_updater']['csrf_tokens']` with creation timestamp. MUST expire after configurable TTL (default: 4h). MUST NOT read/write core session keys (`_sf2_attributes`, `_csrf`).

#### Scenario: Token valid within TTL

- GIVEN a token generated at T with 4h TTL
- WHEN validated at T+1h
- THEN token is found and valid

#### Scenario: Expired token rejected and cleaned

- GIVEN a token generated at T with 4h TTL
- WHEN validated at T+5h
- THEN rejected as expired and removed from session

#### Scenario: No active session triggers start

- GIVEN `session_status() !== PHP_SESSION_ACTIVE`
- WHEN `system_updater_csrf_generate()` is called
- THEN session starts before writing; token stored successfully

### Requirement: Token Validation

MUST use `hash_equals()` for timing-safe comparison. MUST check: (1) token exists in store, (2) not expired. MUST NOT decode Symfony randomized format or perform recursive session scans.

#### Scenario: Valid token passes

- GIVEN a stored token
- WHEN `system_updater_csrf_validate()` receives the same value
- THEN returns true

#### Scenario: Tampered, missing, or empty token rejected

- GIVEN any session state
- WHEN `system_updater_csrf_validate()` receives a wrong, absent, or empty value
- THEN returns false without error

### Requirement: View Helpers

`system_updater_csrf_field()` MUST return `<input type="hidden" name="su_csrf_token" value="{token}">`. `system_updater_csrf_meta()` MUST return `<meta name="su-csrf-token" content="{token}">`. Both MUST auto-generate a token if none exists.

#### Scenario: Field and meta helpers emit correct HTML

- GIVEN an active session
- WHEN either helper is called
- THEN output contains the correct HTML element with the current token value

### Requirement: SSE Guard Integration

Rewritten `csrf_guard.php` MUST validate via `system_updater_csrf_validate()`. MUST read token from `$_GET['su_csrf_token']`, `$_POST['su_csrf_token']`, or `X-SU-CSRF-Token` header. On failure: SSE-formatted error for process scripts, JSON 403 otherwise. MUST NOT reference core CsrfManager storage.

#### Scenario: SSE request with valid token proceeds

- GIVEN a process script receives `su_csrf_token` matching session
- WHEN `ensure_request_csrf()` is called
- THEN returns without error

#### Scenario: SSE request with invalid token gets error event

- GIVEN a process script with invalid `su_csrf_token`
- WHEN `ensure_request_csrf()` is called
- THEN responds `Content-Type: text/event-stream` with `event: error` and JSON message

#### Scenario: Non-SSE request gets 403 JSON

- GIVEN a non-SSE endpoint with invalid token
- WHEN `ensure_request_csrf()` is called
- THEN HTTP 403 with `{"success": false, "message": "..."}`

### Requirement: Backward Compatibility

`twig_compat.php` MUST emit plugin tokens for SSE endpoints. Core `csrf_field()` MUST remain available for controller forms through `index.php`. Old `CsrfGuardTest` tests for core storage reading MUST be replaced with plugin token lifecycle tests.

#### Scenario: Controller forms keep core CSRF; SSE uses plugin token

- GIVEN admin_updater view loaded
- THEN controller forms use `fsc.csrf_field_html` (core token)
- AND JavaScript SSE URLs use `su_csrf_token` from plugin meta tag

### Requirement: A CSRF Token Is Never an Authentication Credential

`CSRF-01`. No value validated by this capability MUST confer, extend or restore an authenticated
identity. A valid `su_csrf_token` proves only *"this request reached a page that rendered the
token, and that page was reached through one of the two authorized gates"*. It MUST NOT be
accepted as evidence that a user is logged in, and it MUST NOT substitute for a session
written by a real login.

The session guard of the standalone process scripts MUST NOT consult framework CSRF state
for any purpose: not `CsrfManager`, not `_csrf`, not `_token`, not `X-CSRF-Token`. This
also removes the last authorization decision in this plugin that depended on the core's
session store, which the capability's Token Storage requirement already keeps the plugin
out of.

This requirement does not weaken `su_csrf_token`. Its security property is **provenance**,
and provenance is **two authorized minting points, each behind its own honest gate**: the
`#[AdminOnly]` `admin_updater` page, gated by the attribute plus an authenticated
administrator session, and `recovery.php`, gated by the A2 per-installation secret compared
constant-time plus an administrator session in the console (`RACC-07`). Storage in
`SU_SESS`, format, 4 h TTL and `hash_equals()` comparison are unchanged, and the token
remains the only authenticator for `start`/`begin`/`chunk`. Minting is authorized from
either gate and from nowhere else.

*Recorded reversal.* An earlier revision of this delta said "only a rendered `#[AdminOnly]`
page mints it" and "single minting point". The product owner reversed that framing; a
second gated point trades one strong provenance for another equally strong one. Any contract
test asserting that no second point exists MUST be rewritten to assert that only these two
points exist, each behind its own gate.

#### Scenario: A token does not open a session-guarded endpoint

- GIVEN a request carrying a valid `su_csrf_token` and no login-written session
- WHEN a session-guarded action is requested (`download_backup.php`, or any `process_*`
  action other than `start`/`begin`/`chunk`)
- THEN the request is refused
- AND the refusal comes from the session guard, not from the token check

#### Scenario: A framework token is not a session either

- GIVEN a request carrying `_csrf_token`, `_token` or `X-CSRF-Token` obtained from a public
  page
- WHEN any guarded action is requested
- THEN the request is refused
- AND no core CSRF manager was consulted

#### Scenario: The plugin token keeps its own contract

- GIVEN a valid `su_csrf_token` for the current `SU_SESS` session
- WHEN `action=start` is requested
- THEN it is accepted exactly as before this change
- AND its storage, TTL and comparison semantics are unchanged

#### Scenario: Only the two authorized minting points exist

- GIVEN the production call graph after this change
- WHEN every HTTP entry point able to reach the minting helpers is enumerated
- THEN the set is exactly two: a render of the `#[AdminOnly]` `admin_updater` page, and
  `recovery.php`'s render after the secret gate and after administrator authentication
- AND no `process_*` script mints a token
- AND no third entry point mints one

#### Scenario: A recovery-minted token is not an identity either

- GIVEN a token minted by `recovery.php` after both of its gates
- WHEN it is presented with no login-written session to a session-guarded action
- THEN the request is refused with the guard's uniform 401 (`AUTH-04`)
- AND the token conferred no identity, exactly as an admin-minted token would not

## Security Constraints

- MUST use `hash_equals()` for all comparisons (timing-safe)
- MUST use `random_bytes()` (CSPRNG) for generation
- MUST close session (`session_write_close()`) immediately after validation for SSE streaming
