# Archive Report: Secure Backup File Access

**Change**: `secure-backup-access`
**Archived**: 2026-06-26
**Plugin**: system_updater
**Path**: `plugins/system_updater/openspec/changes/archive/2026-06-26-secure-backup-access/`

## Verification summary
- Final phpunit: 116 tests, 199 assertions, 0 failures, 0 errors, 1 pre-existing skip
- All 7 requirements from `plugin-backup-access/spec.md` covered
- Spec scenarios: 15/16 fully verified, 1 partial (HTTP 200 happy path — ddev lacks `php-cgi`; structural + unit tests cover the same code paths)
- Severity: 0 CRITICAL / 1 WARNING / 1 SUGGESTION (both noted in verify-report; not blocking)
- Verdict: **PASS WITH WARNINGS**

## Pre-existing vulnerability closed
Before this change, `curl /backups/{file}` returned `HTTP 200` with no auth
(confirmed via direct test). After this change:
- Legacy webroot path `/backups/{file}` → 302 (file moved, framework catches)
- New endpoint `/plugins/system_updater/download_backup.php` (no session) → 401
- New endpoint with path traversal `?file=../../../../etc/passwd` → 401 (auth runs first)
- New endpoint with missing file → 401 (auth runs first)
- New endpoint with valid session + valid CSRF + valid file → 200 (covered by 25 unit/structural tests)

## Orchestrator-applied post-apply fixes
Two CRITICAL issues were discovered and fixed during sdd-verify (before
archive). They are part of the change's effective diff:

1. **`OidcProvider/.htaccess:153` allowlist did not include `download_backup.php`**
   The general rule `RewriteRule ^plugins/.+\.php$ - [F,L]` blocked the new
   endpoint. Fixed by adding `download_backup` to the allowlist. Without this
   fix, the endpoint was completely inaccessible in production Apache.

2. **`view/admin_updater.html.twig` used relative `download_backup.php` paths**
   (5 occurrences), which resolve to the webroot, not the plugin directory.
   Fixed via `sed`: all 5 links now use `plugins/system_updater/download_backup.php`,
   matching the pattern already used by the JS for `process_*.php` endpoints.

## Production changes (recap for the reviewer)
- `plugins/system_updater/lib/backup_manager.php` — added `resolve_backup_dir()` (public static) + `resolve_backup_dir_with($override, $fsFolder)` seam at L211-237; tightened `ensureBackupDirectoryExists()` to `chmod 0700` and added loud startup error; replaced 1 call site of `$this->fsRoot.'/'.self::BACKUP_DIR` with `self::resolve_backup_dir()`. Net: ~30 lines of production change.
- `plugins/system_updater/lib/process_bootstrap.php` — added `mode: 'plain'` to `system_updater_process_init()` (no SSE headers, no deflate, no buffering); fail-loud on unknown mode. Net: ~10 lines.
- `plugins/system_updater/lib/download_backup_handler.php` (NEW) — 2 pure helpers: `system_updater_resolve_backup_file()` (3-layer containment: basename → realpath → str_starts_with with trailing separator) and `system_updater_record_download_audit()`. Net: ~80 lines.
- `plugins/system_updater/download_backup.php` (NEW) — top-level auth-gated endpoint; mirrors `process_restore.php` bootstrap with `mode: 'plain'`; reads `?file=`, validates CSRF, streams via `fopen/fread` (chunked). Net: ~80 lines.
- `plugins/system_updater/view/admin_updater.html.twig` — replaced 5 `&action=download_backup&file=...` links with `plugins/system_updater/download_backup.php?file={basename}&su_csrf_token={fsc.su_csrf_token}`. Net: ~5 lines (sed).
- `plugins/system_updater/controller/admin_updater.php` — removed `case 'download_backup'` and `actionDownloadBackup()`. Net: -20 lines.
- `plugins/system_updater/.htaccess` — added `download_backup.php` to `mod_deflate` no-gzip, `mod_security2` SecRuleEngine Off, and `mod_headers` `Cache-Control: no-store` + `X-Content-Type-Options: nosniff` blocks. Net: ~20 lines.
- `OidcProvider/.htaccess` — added defence-in-depth comment to the `^backups(/.*)?$` rule; added `download_backup` to the `plugins/system_updater/...` allowlist. Net: 2 lines.

## Test baseline (for future changes to this plugin)
- After this change: 116 tests, 199 assertions, 0 failures, 0 errors, 1 pre-existing skip
- Pre-change baseline: 91 tests, 149 assertions
- 25 new tests across 3 new files:
  - `tests/BackupManagerBackupDirTest.php` (5 tests)
  - `tests/DownloadBackupHandlerTest.php` (11 tests, 5 path-traversal variants via dataprovider)
  - `tests/DownloadBackupScriptTest.php` (9 structural tests)
  - 1 regression update to `tests/BackupManagerFilenameTest.php` (sibling backup path)

## Files in this archive
- `proposal.md` — original contract
- `design.md` — technical design (109 lines, English, under 800 words)
- `specs/plugin-backup-access/spec.md` — full new spec (canonical mirror in `plugins/system_updater/openspec/specs/plugin-backup-access/spec.md`)
- `tasks.md` — 33 tasks, 33/33 complete (5.4 archive was just performed)
- `verify-report.md` — PASS WITH WARNINGS
- `DEPLOY.md` — operator runbook for moving existing backups + setting perms
- `archive-report.md` — this file

## What was NOT done (explicit)
- No new entry in core `openspec/`.
- No `config.yaml` added to the plugin.
- No commit, no push, no PR was created (per the orchestrator's "vamos allá" mode — the user is in work session, not delivery mode).
- No HTTP 200 happy-path smoke test verified end-to-end in ddev (PHP-CGI not available; covered by 25 structural + unit tests).

## Follow-up suggestions (from verify-report)
1. Add an HTTP 200 happy-path test when a real CI environment with `php-cgi` is available.
2. Consider adding a defensive `.htaccess` (or equivalent) inside the new `backups/` directory (sibling of FS_FOLDER) with `Require all denied` as belt-and-suspenders.
3. Consider replacing the `FS_BACKUP_DIR` constant override with an `.env`-based config if the project standardises on env-driven config.
