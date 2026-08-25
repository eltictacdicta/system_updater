# Design: Secure Backup File Access

## 1. Technical Approach

**Layer 1 (data)**: relocate `BACKUP_DIR` outside the webroot (sibling of
`FS_FOLDER`) with `FS_BACKUP_DIR` override; `backup_manager::resolve_backup_dir()`
centralises resolution for every read/write site. **Layer 2 (access)**: new
top-level `download_backup.php` reuses the plugin's session+CSRF primitives,
enforces path containment (`basename()` + `realpath()` + `str_starts_with`),
streams with `Content-Disposition: attachment`, emits an audit line. UI
rewires each row's link from the controller's `action=download_backup` to the
new endpoint. `OidcProvider/.htaccess:137` stays during transition.

## 2. Architecture Decisions

| Decision | Choice | Alternatives | Rationale |
|---|---|---|---|
| Default path | `dirname(FS_FOLDER) . '/backups'` (sibling) | Inside `FS_FOLDER`; env | Unreachable by web server by definition. |
| Override | `FS_BACKUP_DIR` constant | `.env`; per-call arg | Same pattern as `FS_FOLDER`/`FS_DB_*`. |
| Enforcement | `resolve_backup_dir()` in constructor; dir `0700` | Lazy on first write | Spec mandates startup rejection. |
| Auth | Reuse `system_updater_require_authenticated_session()` | Roll new check | Same function gates `process_*`. |
| CSRF | Reuse `ensure_request_csrf()` reading `su_csrf_token` GET | New helper | Same surface as `process_*`. |
| Path-traversal | `basename()` → `realpath()` → `str_starts_with($real, $dir.'/')` | Whitelist regex | Spec mandates this; trailing `/` blocks `/backups-evil/`. |
| Response | Plain PHP, JSON errors, `readfile()`, `Content-Type: application/octet-stream`, `Content-Disposition: attachment` | SSE; chunked JSON | SSE headers break `readfile()`. |
| Audit log | `system_updater_debug_log('DOWNLOAD', json)` → `tmp/system_updater_debug.log` | New `audit.log`; DB | `debug_log.php` is the de-facto channel. |
| UI href | `download_backup.php?file={basename}&su_csrf_token={token}` | POST; signed URL | GET matches UX; CSRF covers mutation. |
| Token injection | `system_updater_csrf_meta()` once per render | Per-row regen | Session-bound; one TTL window suffices. |
| Defense in depth | Keep `.htaccess:137` | Remove now | Old dir may still exist; rule is free. |

## 3. Data Flow

```
admin_updater.html.twig
  └─ <a href="download_backup.php?file={basename}&su_csrf_token={token}">
        ▼
download_backup.php
  ├─ process_bootstrap (mode: 'plain')
  ├─ require_authenticated_session()           ── 401
  ├─ ensure_request_csrf()                      ── 403
  ├─ $name = basename($_GET['file'])
  │   $real = realpath($dir . '/' . $name)
  │   if (!str_starts_with($real, $dir.'/'))    ── 400 + security log
  │   if (!is_file($real))                      ── 404
  ├─ header(Content-Disposition: attachment; filename="$name")
  │   header(Content-Type: application/octet-stream)
  │   header(Content-Length: ...)
  │   readfile($real)
  └─ system_updater_debug_log('DOWNLOAD', {user, file, size, ip, ts})
```

## 4. File Changes

| File | Action | Description |
|---|---|---|
| `lib/backup_manager.php:212` | Modify | Keep `const BACKUP_DIR` for BC; stop composing paths from it. |
| `lib/backup_manager.php` | Modify | Add `public static function resolve_backup_dir(): string` (`FS_BACKUP_DIR` if defined, else sibling). Switch all `$this->fsRoot.'/'.self::BACKUP_DIR` sites (~272, ~699, ~785, ~1047, ~1243, ~1315) to it. Tighten perms `0700` in `ensureBackupDirectoryExists()` (~351). |
| `download_backup.php` | Create | Top-level endpoint, mirrors `process_restore.php` bootstrap with `mode: 'plain'`. Containment + readfile + audit. |
| `controller/admin_updater.php:257-262,758-791` | Modify | Drop `case 'download_backup'` and `actionDownloadBackup()`. |
| `view/admin_updater.html.twig:298-300,315,323` | Modify | Replace `&action=download_backup&file=...` with `download_backup.php?file={basename}&su_csrf_token={token}`. |
| `tests/DownloadBackupTest.php` | Create | Subprocess harness, seeded session+CSRF; assert 200/401/403/400/404 + headers + log line. |
| `tests/BackupManagerBackupDirTest.php` | Create | `resolve_backup_dir()` default + `FS_BACKUP_DIR`; constructor creates `0700`; unwritable dir → startup error. |
| `OidcProvider/.htaccess:136-137` | Keep | No change. |
| `plugins/system_updater/.htaccess` | Modify | Add `FilesMatch "download_backup\.php$"`: disable `mod_deflate`, `SecRuleEngine Off`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`. No SSE headers. |

## 5. Interfaces / Contracts

```php
// backup_manager (static, pure)
public static function resolve_backup_dir(): string
// FS_BACKUP_DIR if defined & non-empty; else rtrim(dirname(FS_FOLDER),'/').'/backups'.
```

```
// download_backup.php (top-level, no class)
// bootstrap: process_bootstrap.php with mode='plain'
// auth: system_updater_require_authenticated_session(); ensure_request_csrf();
// resolve: $name=basename($_GET['file']); $dir=backup_manager::resolve_backup_dir();
// contain: $real=realpath($dir.'/'.$name); reject unless str_starts_with($real,rtrim($dir,'/').'/')
// stream: Content-Disposition + readfile($real); exit
// audit: system_updater_debug_log('DOWNLOAD', json)
```

**CSRF lifecycle**: rendered once per page via `system_updater_csrf_meta()` into
`fsc.su_csrf_meta_html` (template line 9). 4 h TTL (`SYSTEM_UPDATER_CSRF_TTL`),
session-bound. JS reads `<meta name="su-csrf-token">` and appends it to every
download href. Independent session (`SU_SESS_*`) so admin page and download
script share the token.

## 6. Testing Strategy

| Layer | What | How |
|---|---|---|
| Unit | `resolve_backup_dir()` default + `FS_BACKUP_DIR` | Toggle constant in `BackupManagerBackupDirTest`. |
| Unit | `0700` perms + unwritable-dir startup error | `chmod 0500` temp dir, assert errors[]. |
| Integration | 200, headers, bytes match | Subprocess PHP, seeded session+CSRF, assert SHA-256. |
| Integration | 401/403/400/404 + security log | Same harness, vary inputs, capture `error_log`. |
| Integration | `process_restore.php` reads new `BACKUP_DIR` | Seed file, run restore stub, assert success. |
| E2E | — | covered by integration. |

## 7. Migration / Rollout

- **No auto-migration** of `FS_FOLDER/backups/`. Deploy note: `mv <FS_FOLDER>/backups/* $(dirname <FS_FOLDER>)/backups/` and `chmod 0700 <new-dir>`. New code reads only the new path.
- `.htaccess:137` MUST stay until operator confirms old dir is empty; removal in a follow-up.
- New `BACKUP_DIR` added to deploy checklist: exists, `0700`, web-user owned, PHP-FPM writable.
- **Rollback**: revert default, delete `download_backup.php`, restore controller arm, point template back.

## 8. Open Questions

None.
