# Archive Report: Tipos y constantes para slug de host

**Change**: `backup-slug-types-constants`
**Archived**: 2026-06-26
**Plugin**: system_updater
**Path**: `plugins/system_updater/openspec/changes/archive/2026-06-26-backup-slug-types-constants/`

## Verification summary
- Final phpunit: 91 tests, 149 assertions, 0 failures, 0 errors, 1 pre-existing skip
- All 5 success criteria from proposal: PASS
- Severity: 0 CRITICAL / 0 WARNING / 1 SUGGESTION (cosmetic — SC5 count drift between proposal and post-change baseline; resolved in preflight)

## Follow-up chain
This change closes the W3 WARNING and S3 SUGGESTION from the previous plugin change (`backup-filename-hostname`, archived at `plugins/system_updater/openspec/changes/archive/2026-06-26-backup-filename-hostname/`). After this archive, the system_updater plugin has 0 open WARNINGs and 2 open SUGGESTIONs from the previous change (S1: extra SC3 strict-reading test; S2: assertion on `complete.unified_file`).

## Orchestrator-applied post-apply fix (PHPDoc)
The apply sub-agent left the PHPDoc of `resolve_host_slug` referencing the literal `253`. The orchestrator applied a 1-line edit (L316) to update the docblock to reference `self::HOST_HEADER_MAX_LEN` with a "1-253 chars" clarification, then re-ran phpunit (still 91/149/0/0/1). This edit is part of the change's effective diff and is documented for traceability.

## Production changes (recap for the reviewer)
- `plugins/system_updater/lib/backup_manager.php` — added `const HOST_SLUG_MAX_LEN = 63;` at L214, `const HOST_HEADER_MAX_LEN = 253;` at L215, both public and placed after `const VERSION = '2.3.1';`. Changed `slugify_host` signature at L291 to `private static function slugify_host(string $raw): string`. Changed `resolve_host_slug` signature at L323 to `private function resolve_host_slug(): string`. Replaced the literal `63` at L306-307 with `self::HOST_SLUG_MAX_LEN` (both the `strlen(...) > 63` and `substr(..., 0, 63)`). Replaced the literal `253` in the regex at L332 with an interpolation of `self::HOST_HEADER_MAX_LEN`. Updated the PHPDoc at L316 to reflect the interpolated regex with a "1-253 chars" note. Net: ~6 lines of production change, 0 lines of behavior change, 0 changes to call-sites.
- `plugins/system_updater/tests/BackupManagerFilenameTest.php` — added ONE new test method `hostSlugTypeConstantsHaveExpectedValues` (2 assertions: `HOST_SLUG_MAX_LEN === 63`, `HOST_HEADER_MAX_LEN === 253`). No existing test was removed or modified.

## Test baseline (for future changes to this plugin)
- After this change: 91 tests, 149 assertions, 0 failures, 0 errors, 1 pre-existing skip
- Pre-change baseline (from the previous change's verify-report): 90 tests, 147 assertions

## Files in this archive
- `proposal.md` — original contract
- `tasks.md` — 5 tasks, all completed
- `verify-report.md` — PASS, 0/0/1
- `archive-report.md` — this file

## What was NOT done (explicit)
- No new entry in core `openspec/`.
- No `config.yaml` added to the plugin.
- No `specs/` delta folder (this change has no formal spec deltas, matching the precedents).
- No production code outside `plugins/system_updater/` was modified.
- No commit, no push, no PR was created.
