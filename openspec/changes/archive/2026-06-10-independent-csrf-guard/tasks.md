# Tasks: Independent CSRF Guard for system_updater

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~480 (csrf_guard.php rewrite dominates: ~300 deletions) |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | Single PR (bulk is pure deletion of dead code, not review-dense) |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Full change: new token system + guard rewrite + view + tests | PR 1 | ~300 of 480 changed lines are pure deletions of dead multi-storage decoder; review-dense lines ~180 |

## Phase 1: Foundation — Plugin Token System

- [x] 1.1 Create `lib/csrf_token.php` with `system_updater_csrf_generate(string $tokenId = 'system_updater_csrf'): string` — generates via `bin2hex(random_bytes(32))`, stores at `$_SESSION['system_updater']['csrf_tokens'][$tokenId]` with `['value' => ..., 'created_at' => time()]`, ensures session active via `session_status()` check.
- [x] 1.2 Add `system_updater_csrf_validate(string $token, string $tokenId = 'system_updater_csrf'): bool` — reads stored entry, checks TTL (4h default via constant `SYSTEM_UPDATER_CSRF_TTL`), uses `hash_equals()`, removes expired entries. Returns false for empty/missing.
- [x] 1.3 Add `system_updater_csrf_field(string $tokenId = 'system_updater_csrf'): string` — returns `<input type="hidden" name="su_csrf_token" value="{token}">`, auto-generates if none exists.
- [x] 1.4 Add `system_updater_csrf_meta(string $tokenId = 'system_updater_csrf'): string` — returns `<meta name="su-csrf-token" content="{token}">`, auto-generates if none exists.

## Phase 2: Core — Rewrite CSRF Guard

- [x] 2.1 Rewrite `lib/csrf_guard.php`: keep `system_updater_csrf_failure_response()` unchanged. Remove all functions: `system_updater_csrf_validate_with_core`, `system_updater_csrf_validate_against_session`, `system_updater_csrf_decode_token`, `system_updater_csrf_find_value_in_session`, `system_updater_csrf_search_in_array`, `system_updater_csrf_read_all_stored_tokens`, `system_updater_csrf_read_stored_token`, `system_updater_csrf_verify_token`.
- [x] 2.2 Rewrite `ensure_request_csrf()`: add `require_once __DIR__ . '/csrf_token.php'`, read token from `$_GET['su_csrf_token']`, `$_POST['su_csrf_token']`, or `X-SU-CSRF-Token` header. Call `system_updater_csrf_validate()`. On success: `session_write_close()` and return. On failure: log and call `system_updater_csrf_failure_response()`. Remove all CsrfManager/core session references. Target <150 lines total file.

## Phase 3: Integration — View and Twig Compat

- [x] 3.1 Update `lib/twig_compat.php`: rename existing `system_updater_csrf_meta()` → `system_updater_core_csrf_meta()` and `system_updater_csrf_field()` → `system_updater_core_csrf_field()` (internal helpers for controller forms). Add `require_once` for `csrf_token.php`. In `system_updater_prepare_view_compat()`, add `$controller->su_csrf_meta_html = system_updater_csrf_meta()` and `$controller->su_csrf_token = system_updater_csrf_generate()` using the plugin token functions.
- [x] 3.2 Update `view/admin_updater.html.twig`: add `{{ fsc.su_csrf_meta_html|raw }}` after existing `{{ fsc.csrf_meta_html|raw }}` (line 8). In JS (line 346), add `var suCsrfToken = $('meta[name="su-csrf-token"]').attr('content') || '';`. Replace `_csrf_token=' + encodeURIComponent(csrfToken)` with `su_csrf_token=' + encodeURIComponent(suCsrfToken)` in all three SSE URL constructions (process_backup.php line 460, process_core_update.php line 586, process_restore.php line 728). Keep `csrfToken` for Resumable.js upload headers (line 911-929).

## Phase 4: Testing

- [x] 4.1 Create `tests/CsrfTokenTest.php`: test generate→validate round-trip (`@runInSeparateProcess`), consecutive generates produce different tokens, expired token rejected (set `created_at` to `time() - TTL - 1`), tampered token rejected, empty token rejected, missing session entry rejected, `system_updater_csrf_field()` HTML output contains `name="su_csrf_token"`, `system_updater_csrf_meta()` HTML output contains `name="su-csrf-token"`, auto-generate on first helper call.
- [x] 4.2 Rewrite `tests/CsrfGuardTest.php`: remove all tests referencing `_sf2_attributes`, `_csrf`, `system_updater_csrf_read_stored_token`, `system_updater_csrf_verify_token`. Replace with: test `ensure_request_csrf()` passes with valid `su_csrf_token` in `$_GET`, test SSE error event on invalid token (mock `$_SERVER['REQUEST_URI']` with `process_backup.php`), test JSON 403 on invalid token for non-SSE URI, test missing token rejection.

## Implementation Order

Phase 1 first (csrf_token.php is the foundation everything depends on). Phase 2 second (guard rewrite depends on Phase 1). Phase 3 third (view/twig depends on Phase 1). Phase 4 last (tests validate all previous phases). Each phase is completable in one session.

## Next Step

Ready for implementation (sdd-apply). If user wants to split into chained PRs due to Medium budget risk, discuss chain strategy before apply.
