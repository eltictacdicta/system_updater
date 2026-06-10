# Proposal: Independent CSRF Guard for system_updater

## Intent

The plugin's SSE process scripts (`process_core_update.php`, `process_backup.php`, `process_restore.php`) bypass `index.php` and must validate CSRF tokens independently. The current `csrf_guard.php` (483 lines) attempts to read tokens from the core's `CsrfManager` session storage, but this is fragile:

- **Storage fragmentation**: tokens may live in `$_SESSION['_sf2_attributes']['_csrf/https-fs_form']`, `$_SESSION['_csrf']['fs_form']`, or flat `$_SESSION` keys depending on the core version (0.14.7 vs current) and `$_SERVER['HTTPS']`.
- **Randomized token decoding**: Symfony 7 wraps tokens as `checksum.base64url(key).base64url(xor(value,key))`, requiring manual XOR decoding.
- **Deep session search**: the guard recursively scans all of `$_SESSION` as a last resort — slow and unpredictable.
- **Namespace drift**: the `https-` / `http-` / plain prefix changes between the controller request and the SSE request if the proxy strips `HTTPS`.

The plugin needs its own CSRF system that generates and validates tokens in a dedicated `$_SESSION` key, independent of the core's internal storage format.

## Scope

### In Scope
- New `lib/csrf_token.php` with `system_updater_csrf_generate()`, `system_updater_csrf_validate()`, `system_updater_csrf_field()`, `system_updater_csrf_meta()`
- Storage in `$_SESSION['system_updater']['csrf_tokens'][tokenId]` with TTL-based expiry
- Rewrite `lib/csrf_guard.php` to use the new token system (target <150 lines)
- Update `lib/twig_compat.php` to emit plugin-specific tokens alongside core tokens
- Update `view/admin_updater.html.twig` to include the plugin-specific hidden field for SSE endpoints
- Update `tests/CsrfGuardTest.php` for the new API

### Out of Scope
- Changes to core `CsrfManager` or `src/Security/`
- Controller-level CSRF (forms going through `index.php` keep using `$this->requireCsrf()`)
- Changes to `session_auth.php` or `process_bootstrap.php` session handling
- Token rotation or multi-tab support beyond current behavior

## Capabilities

### New Capabilities
- `plugin-csrf-token`: Self-contained CSRF token generation, storage, and validation for system_updater process scripts. Independent of core CsrfManager internals.

### Modified Capabilities
None (no specs exist yet; this is a new capability replacing ad-hoc validation logic)

## Approach

| # | Change | File | How |
|---|--------|------|-----|
| 1 | New token class | `lib/csrf_token.php` | Generate tokens with `bin2hex(random_bytes(32))`, store in `$_SESSION['system_updater']['csrf_tokens'][$tokenId]` with timestamp. Validate via `hash_equals()`. TTL: 4 hours. |
| 2 | Rewrite guard | `lib/csrf_guard.php` | Replace 483-line multi-storage decoder with ~100-line guard: read token from `$_GET`/`$_POST`/header, call `system_updater_csrf_validate()`. Keep SSE/JSON error response formatting. |
| 3 | View integration | `lib/twig_compat.php` | `system_updater_csrf_field()` emits `<input type="hidden" name="su_csrf_token" value="...">` using the plugin's own generator. Keep core `csrf_field()` as fallback for controller forms. |
| 4 | JS SSE integration | `view/admin_updater.html.twig` | SSE URLs include `su_csrf_token=...` query param from a `<meta>` tag or data attribute. |
| 5 | Tests | `tests/CsrfGuardTest.php` | Test generate→validate round-trip, expired token rejection, missing token rejection, tampered token rejection. |

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `lib/csrf_token.php` | New | Token generation, storage, validation functions |
| `lib/csrf_guard.php` | Modified | Rewrite: remove multi-storage decoding, use `csrf_token.php` |
| `lib/twig_compat.php` | Modified | `system_updater_csrf_field()` / `system_updater_csrf_meta()` use plugin token |
| `view/admin_updater.html.twig` | Modified | Add `<meta name="su-csrf-token">` for JS SSE URL construction |
| `tests/CsrfGuardTest.php` | Modified | New test cases for plugin token lifecycle |
| `tests/CsrfTokenTest.php` | New | Unit tests for `csrf_token.php` functions |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Existing browser tabs have stale core tokens when plugin updates | Medium | Keep core token fallback in `twig_compat.php` for controller forms; only SSE endpoints switch to plugin tokens |
| Session not started when `csrf_token.php` generates a token | Low | `process_bootstrap.php` already calls `session_start()` before CSRF validation; add defensive `session_status()` check |
| Token stored in session is lost on session regeneration | Low | Re-generate token on next page load; SSE connections are short-lived (<5 min) |

## Rollback Plan

Revert `lib/csrf_guard.php`, `lib/twig_compat.php`, and `view/admin_updater.html.twig` to their previous versions. The new `lib/csrf_token.php` is additive — removing it and restoring the old guard functions is a clean git revert. No schema or session structure changes persist.

## Dependencies

- PHP `random_bytes()` (available since PHP 7.0, well within 8.2+ requirement)
- `$_SESSION` write access (already required by the plugin)

## Success Criteria

- [ ] `csrf_guard.php` reduced from 483 to <150 lines
- [ ] SSE process scripts validate CSRF without reading core's `$_SESSION['_sf2_attributes']`
- [ ] Token generate→validate round-trip works in <1ms (no recursive session scan)
- [ ] All existing `CsrfGuardTest` tests pass or are replaced with equivalent coverage
- [ ] Plugin works with core versions 0.14.7, current, and future (no dependency on internal storage format)
