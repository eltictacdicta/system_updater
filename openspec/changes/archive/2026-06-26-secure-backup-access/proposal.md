# Proposal: Secure Backup File Access

## Intent

Backups in `OidcProvider/backups/` are publicly downloadable (HTTP 200, no auth). Confirmed via curl in ddev — nginx bypasses `.htaccess:137`. The host-slug filename only protects against opportunistic enumeration; DB dumps leak PII and credentials.

Fix: relocate backups outside the webroot (HTTP access impossible by definition) and add an auth-gated download endpoint.

## Scope

### In Scope
- `BACKUP_DIR` resolves outside `FS_FOLDER` (default: `dirname(FS_FOLDER) . '/backups'`, override via `FS_BACKUP_DIR`)
- New `download_backup.php`: admin session + CSRF, `basename()`-sanitized, `readfile()` stream with `Content-Disposition: attachment`, logs download
- "Download" button per backup row in `admin_updater.html.twig`
- Keep defensive `.htaccess` rule for Apache

### Out of Scope
- Restore flow changes (already auth-gated; only `BACKUP_DIR` update)
- Object storage, at-rest encryption, rate limiting (future)
- One-time migration of existing files (deploy ops)

## Capabilities

### New Capabilities
None

### Modified Capabilities
None (infra hardening; reuses existing CSRF + auth)

## Approach

| # | Change | Where |
|---|--------|-------|
| 1 | `BACKUP_DIR` outside webroot; `FS_BACKUP_DIR` override | `lib/backup_manager.php:212` |
| 2 | New `download_backup.php`: session+CSRF, `basename()`+`realpath()` check, `readfile()` | `download_backup.php` (new) |
| 3 | Reuse `system_updater_require_authenticated_session()` + `ensure_request_csrf()` | `lib/session_auth.php`, `lib/csrf_guard.php` |
| 4 | Download button per backup row | `view/admin_updater.html.twig` |
| 5 | Log download (user, file, size, IP, ts) | same |

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `lib/backup_manager.php:212` | Modified | `BACKUP_DIR` outside webroot |
| `download_backup.php` | New | Auth-gated download endpoint |
| `view/admin_updater.html.twig` | Modified | Download button per backup |
| `process_restore.php:102-110` | Modified | Read new `BACKUP_DIR` |
| `controller/admin_updater.php` | Modified | Pass new `BACKUP_DIR` to template |
| `lib/csrf_guard.php`, `lib/session_auth.php` | Reuse | No change |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Existing backups stranded at old path | Med | Move files at deploy; new code reads only new path; deploy note |
| New path not writable in production | Low | Default `dirname(FS_FOLDER) . '/backups'`; `FS_BACKUP_DIR` override; fail loud if unwritable |
| Path-traversal via `?file=` | Low | `basename()` + `realpath()` + `str_starts_with($realpath, BACKUP_DIR)` |
| DoS via large downloads | Low | `readfile()` + PHP execution limit; rate-limit future work |

## Rollback Plan

1. Revert `BACKUP_DIR` to `'backups'` (webroot-relative).
2. Delete `download_backup.php`; revert `admin_updater.html.twig`.
3. `.htaccess:137` regains effect on Apache.

## Dependencies

None.

## Success Criteria

- [ ] `curl /backups/{file}` → 404 or refused
- [ ] `download_backup.php` w/o session → 401/403
- [ ] `download_backup.php` w/ invalid CSRF → 403
- [ ] `?file=../../../etc/passwd` → rejected
- [ ] Admin downloads via UI; event logged
- [ ] Restore works against new `BACKUP_DIR`
