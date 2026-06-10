# Design: Independent CSRF Guard for system_updater

## Technical Approach

Replace the 483-line `csrf_guard.php` that decodes Symfony's internal CSRF storage with a self-contained token system. The plugin generates its own tokens (`bin2hex(random_bytes(32))`), stores them in a dedicated `$_SESSION` key, and validates via `hash_equals()`. Zero dependency on core `CsrfManager` internals, storage format, or namespace conventions.

## Architecture Decisions

### Decision: Token format

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Symfony randomized (checksum.key.xored) | Compatible with core, but requires XOR decoding and namespace awareness | **Rejected** |
| Plain `bin2hex(random_bytes(32))` | Simple, timing-safe via `hash_equals()`, no decoding needed | **Chosen** |

**Rationale**: The entire problem stems from decoding Symfony's randomized format across different storage backends. A plain random token eliminates that complexity. The token is single-use per page load, stored server-side in session — no need for client-side obfuscation.

### Decision: Storage location

| Option | Tradeoff | Decision |
|--------|----------|----------|
| `$_SESSION['_sf2_attributes']` (core) | No extra code, but breaks on version mismatch | **Rejected** |
| `$_SESSION['system_updater']['csrf_tokens']` | Isolated, version-independent, explicit TTL control | **Chosen** |

**Rationale**: Dedicated namespace means the plugin never reads core internals. TTL-based expiry (4h) is handled by the plugin, not by Symfony's session bag lifecycle.

### Decision: Core CsrfManager fallback

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Remove core dependency entirely | Clean break, but controller forms using `{{ fsc.csrf_field_html }}` lose fallback | **Rejected** |
| Keep core fallback in `twig_compat.php` for controller forms only | SSE endpoints use plugin tokens; controller POST forms keep working with either | **Chosen** |

**Rationale**: Controller forms go through `index.php` and use `$this->requireCsrf()`. The plugin's `twig_compat.php` emits BOTH a plugin token (for SSE JS) and the core field (for form POST). SSE endpoints validate ONLY the plugin token.

### Decision: Token lifecycle

| Option | Tradeoff | Decision |
|--------|----------|----------|
| One token per SSE session, regenerated per page load | Simple, but stale if user opens multiple tabs | **Chosen** |
| Token per tab / per action | Complex, but multi-tab safe | **Rejected** |

**Rationale**: SSE connections are short-lived (<5 min). The updater page is not a multi-tab workflow. One token per page load, stored with a timestamp, validated and consumed on SSE `start` action.

## Data Flow

```
  admin_updater controller
        │
        ├─ system_updater_csrf_generate()
        │      → token = bin2hex(random_bytes(32))
        │      → $_SESSION['system_updater']['csrf_tokens']['system_updater_csrf']
        │         = { value: token, created_at: time() }
        │
        ├─ twig_compat: system_updater_csrf_meta()
        │      → <meta name="su-csrf-token" content="{token}">
        │
        └─ view renders page
              │
              JS reads meta → csrfToken
              │
              ├─ EventSource('process_backup.php?action=start&su_csrf_token={token}')
              ├─ EventSource('process_core_update.php?action=start&su_csrf_token={token}')
              └─ EventSource('process_restore.php?action=start&su_csrf_token={token}')
                     │
                     process_bootstrap.php
                     └─ ensure_request_csrf()
                            → reads $_GET['su_csrf_token']
                            → system_updater_csrf_validate($token)
                            → hash_equals(stored_value, $token) && !expired
                            → session_write_close()
                            → proceed or 403
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `lib/csrf_token.php` | Create | Token generate/validate/field/meta functions. ~80 lines. |
| `lib/csrf_guard.php` | Modify | Rewrite: remove multi-storage decoder, XOR logic, recursive scan. Use `csrf_token.php`. Target <150 lines. Keep `system_updater_csrf_failure_response()` and `ensure_request_csrf()` signatures. |
| `lib/twig_compat.php` | Modify | `system_updater_csrf_meta()` emits `<meta name="su-csrf-token">` using plugin generator. `system_updater_csrf_field()` emits `<input name="su_csrf_token">`. Keep core `csrf_field()`/`csrf_meta()` calls for controller form POST fallback. |
| `view/admin_updater.html.twig` | Modify | Add `<meta name="su-csrf-token">` from `fsc.su_csrf_meta_html`. JS reads `su-csrf-token` meta for SSE URLs instead of `csrf-token`. Core `csrf-token` meta kept for Resumable.js upload headers. |
| `tests/CsrfTokenTest.php` | Create | Unit tests: generate→validate round-trip, expired token, missing token, tampered token, TTL boundary. |
| `tests/CsrfGuardTest.php` | Modify | Replace core-storage tests with plugin-token tests. Keep `ensure_request_csrf()` integration tests. |

## Interfaces / Contracts

```php
// lib/csrf_token.php — all functions are standalone (no class)

/**
 * Generate a token and store in session.
 * Returns the plaintext token value.
 */
function system_updater_csrf_generate(string $tokenId = 'system_updater_csrf'): string;

/**
 * Validate a submitted token against stored value.
 * Checks hash_equals() and TTL (default 4h).
 * Returns false if missing, expired, or mismatched.
 */
function system_updater_csrf_validate(string $token, string $tokenId = 'system_updater_csrf'): bool;

/**
 * Emit <meta name="su-csrf-token" content="..."> HTML.
 */
function system_updater_csrf_meta(string $tokenId = 'system_updater_csrf'): string;

/**
 * Emit <input type="hidden" name="su_csrf_token" value="..."> HTML.
 */
function system_updater_csrf_field(string $tokenId = 'system_updater_csrf'): string;
```

Session storage structure:
```php
$_SESSION['system_updater']['csrf_tokens'] = [
    'system_updater_csrf' => [
        'value' => 'a1b2c3d4...',       // bin2hex(random_bytes(32))
        'created_at' => 1718000000,     // time()
    ],
];
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `system_updater_csrf_generate()` stores token in `$_SESSION` | `@runInSeparateProcess`, inspect `$_SESSION` after call |
| Unit | `system_updater_csrf_validate()` round-trip | Generate then validate same token → true |
| Unit | Expired token rejected | Set `created_at` to `time() - TTL - 1` → false |
| Unit | Tampered token rejected | Modify one char → false |
| Unit | Missing token rejected | Empty session → false |
| Unit | `system_updater_csrf_meta()` / `_field()` HTML output | Assert correct tag name and token value |
| Integration | `ensure_request_csrf()` with valid plugin token | Set `$_GET['su_csrf_token']`, assert no exit |
| Integration | `ensure_request_csrf()` with invalid token | Assert SSE error event or 403 |

## Migration / Rollout

No migration required. The change is additive (`csrf_token.php`) plus a rewrite of existing code (`csrf_guard.php`). On plugin update:

1. New `csrf_token.php` is loaded by the rewritten `csrf_guard.php`.
2. Next page load of `admin_updater` emits the new `<meta name="su-csrf-token">`.
3. SSE endpoints start validating against the plugin token store.
4. Existing browser tabs without the new meta tag will fail CSRF on next SSE call — user must reload the page. This is acceptable: the updater page is always loaded fresh before starting operations.

## Open Questions

- None
