# Tasks: Secure Backup File Access

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~290 (helper + 6 sites + 1 new file + UI + htaccess + tests) |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

## Phase 1: Foundation (helper + path resolution)

- [x] 1.1 Write `BackupManagerBackupDirTest::testResolveBackupDirDefault` (RED): assert `dirname(FS_FOLDER) . '/backups'`
- [x] 1.2 Implement `backup_manager::resolve_backup_dir(): string` returning `dirname(FS_FOLDER) . '/backups'` (GREEN)
- [x] 1.3 Write `BackupManagerBackupDirTest::testResolveBackupDirOverride` (RED): define `FS_BACKUP_DIR`, assert override
- [x] 1.4 Extend `resolve_backup_dir()` to honor `FS_BACKUP_DIR` when defined+non-empty (GREEN)
- [x] 1.5 Write `BackupManagerBackupDirTest::testUnwritableDirStartupError` (RED): `chmod 0500` temp dir, expect errors[]
- [x] 1.6 Tighten `ensureBackupDirectoryExists()` to `0700` perms + loud error on unwritable (GREEN)
- [x] 1.7 Replace 6 call sites of `$this->fsRoot.'/'.self::BACKUP_DIR` (across `backup_manager`) with `self::resolve_backup_dir()`. Note: the actual code has only one composition site (line 272); the rest of the file uses `$this->backupPath` which is set at line 272. Replaced in 1.2 above.

## Phase 2: Auth-Gated Download Endpoint

- [x] 2.1 Create `download_backup.php` skeleton: `process_bootstrap.php mode:'plain'`, session+CSRF bootstrap, 401/403 exit paths
- [x] 2.2-2.3 Test 401/403 path: covered by structural `DownloadBackupScriptTest` + the `process_bootstrap` `'plain'` mode assertion (deferred HTTP smoke to sdd-verify)
- [x] 2.4 Add `?file=` resolution: `basename()`+`realpath()`+`str_starts_with()` containment (extracted to `system_updater_resolve_backup_file()`)
- [x] 2.5-2.6 `DownloadBackupHandlerTest::resolveBackupFileRejectsTraversalAttempts` (RED→GREEN, 5 data provider cases + 1 sister-dir prefix attack)
- [x] 2.7 Add `readfile()` with headers: `Content-Disposition: attachment` + `Content-Type: application/octet-stream` + `Content-Length` (in `download_backup.php` + structural test)
- [x] 2.8-2.9 200 / SHA-256 body match: deferred to sdd-verify (smoke test with curl). Helper covered by unit tests.
- [x] 2.10 Add `system_updater_debug_log('DOWNLOAD', json)` audit line (via `system_updater_record_download_audit`)
- [x] 2.11-2.12 `DownloadBackupHandlerTest::recordDownloadAuditAppendsToDebugLog` (RED→GREEN)
- [x] 2.13 Add missing-file 404 path (no log entry) (in `download_backup.php` and helper)
- [x] 2.14-2.15 `DownloadBackupHandlerTest::resolveBackupFileReportsMissingFile` (RED→GREEN)

## Phase 3: UI Wiring

- [x] 3.1 In `view/admin_updater.html.twig`, replace `&action=download_backup&file=...` with `download_backup.php?file={basename}&su_csrf_token={token}`
- [x] 3.2 Inject the CSRF token via `fsc.su_csrf_token` (already exposed by `twig_compat.php::system_updater_prepare_view_compat()` at template render time)
- [x] 3.3 In `controller/admin_updater.php`, drop `case 'download_backup'` and `actionDownloadBackup()`
- [x] 3.4 Verify in ddev: structural test `DownloadBackupScriptTest::adminUpdaterTemplateLinksToDownloadBackupEndpoint` covers the URL contract; manual smoke test deferred to sdd-verify

## Phase 4: Server Config + Cleanup

- [x] 4.1 Add `FilesMatch "download_backup\.php$"` block to `plugins/system_updater/.htaccess`: `mod_deflate` off, `SecRuleEngine Off`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff` (no SSE headers)
- [x] 4.2 Update `OidcProvider/.htaccess:136-137` comment to clarify it stays as defense-in-depth
- [x] 4.3 Add deploy note: `mv <FS_FOLDER>/backups/* $(dirname <FS_FOLDER>)/backups/ && chmod 0700 <new-dir>`; remove old dir only after empty (added in `DEPLOY.md`)

## Phase 5: Verification + Archive

- [x] 5.1 Run `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` — 116 tests, 199 assertions, all green
- [x] 5.2 Manual smoke test in ddev: legacy `/backups/...` → 302 (file moved); `download_backup.php` no-session → 401; path-traversal/missing-file → 401 (auth runs first). 200 happy path: structural+unit tests cover (ddev lacks php-cgi for full HTTP roundtrip)
- [x] 5.3 Write `verify-report.md` — PASS WITH WARNINGS; 15/16 spec scenarios verified; 2 CRITICAL issues found and fixed during verify (.htaccess allowlist + template relative path)
- [ ] 5.4 Archive to `plugins/system_updater/openspec/changes/archive/2026-06-26-secure-backup-access/` (DEFERRED to sdd-archive)
