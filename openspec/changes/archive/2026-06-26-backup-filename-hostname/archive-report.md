# Archive Report: Hostname en nombre de archivo de backup

**Change**: `backup-filename-hostname`
**Archived**: 2026-06-26
**Plugin**: system_updater
**Path**: `plugins/system_updater/openspec/changes/archive/2026-06-26-backup-filename-hostname/`

## Verification summary
- Final phpunit: 90 tests, 147 assertions, 0 failures, 0 errors, 1 pre-existing skip
- All 6 success criteria from proposal: PASS
- Severity: 0 CRITICAL / 3 WARNING / 3 SUGGESTION

## Accepted deviations (user-confirmed)
- **W3** (helper signatures without type hints) — accepted for consistency with the legacy style of `backup_manager.php` (no other method uses PHP type hints, file has no `declare(strict_types=1)`). Functionally equivalent under coercion. Documented as a known follow-up.
- **W1, W2** — known and accepted during interactive review (task 3.1 regression test scope reduced; `gethostname()` not stubbed but computed dynamically for robustness).

## Deferred follow-ups (informational, not part of this archive)
- **S3** — extract magic numbers `63` and `253` in `slugify_host` / `resolve_host_slug` to class constants (`HOST_SLUG_MAX_LEN`, `HOST_HEADER_MAX_LEN`) for self-documentation. Single-line change in a future plugin change.
- **S1, S2** — extra test coverage for SC3 strict-reading and `complete.unified_file` regex. Not blocking.

## Production changes (recap for the reviewer)
- `plugins/system_updater/lib/backup_manager.php` — added `private static function slugify_host($raw)` at L289, `private function resolve_host_slug()` at L321, and replaced the default name in 5 method bodies (L614, L781, L1043, L1239, L1311). Net: ~55 lines added, no other production paths modified, no restore logic modified, no metadata schema modified.
- `plugins/system_updater/tests/BackupManagerFilenameTest.php` — NEW, 362 lines, 36 test cases with 47 assertions, namespace `Tests\SystemUpdater`, `#[CoversClass(backup_manager::class)]`, `declare(strict_types=1)`, LGPL/file header, all green.

## Backward compatibility
Verified by `listBackupsGroupedIncludesLegacyBackupCompleteFiles`: legacy `backup_YYYY-MM-DD_*` files are still listed, grouped, classified, restorable, and cleanable. No production code path requires a slug in the filename. Restore logic is unchanged.

## Security
Hand-traced against hostile inputs (path traversal, angle brackets, spaces, newlines, null bytes, SQL/shell injection, IPv6 brackets, CR, >253 chars): all rejected by the `^[a-zA-Z0-9.\-:]{1,253}$` regex guard in `resolve_host_slug`. Final slug is always `[a-z0-9-]+`, no leading/trailing dashes, max 63 chars. No CRITICAL injection vector found.

## Files in this archive
- `proposal.md` — original contract
- `tasks.md` — 8 tasks, all completed
- `verify-report.md` — PASS WITH WARNINGS verdict
- `archive-report.md` — this file

## What was NOT done (explicit)
- No new entry in core `openspec/`. Verified by listing `plugins/system_updater/openspec/changes/`.
- No `config.yaml` added to the plugin (this plugin operates without one, matching the `restore-database-only` precedent).
- No `specs/` delta folder (this change has no formal spec deltas, matching the `restore-database-only` precedent).
- No production code outside `plugins/system_updater/` was modified.
- No commit, no push, no PR was created.
