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

## Security Constraints

- MUST use `hash_equals()` for all comparisons (timing-safe)
- MUST use `random_bytes()` (CSPRNG) for generation
- MUST close session (`session_write_close()`) immediately after validation for SSE streaming
