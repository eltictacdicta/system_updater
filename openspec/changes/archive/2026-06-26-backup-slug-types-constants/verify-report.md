# Verify Report: Tipos y constantes para slug de host

## Result
**PASS**

## Phpunit
- Suite run: `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml`
- Result: `Tests: 91, Assertions: 149, PHPUnit Deprecations: 12, Skipped: 1` — 0 failures, 0 errors.
- Exact match with the orchestrator's preflight expected new baseline. The 12 deprecations and 1 skip are pre-existing in the suite and unrelated to this change.
- New test method (filtered run `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml --filter hostSlugTypeConstantsHaveExpectedValues`): **1 test, 2 assertions, OK** — confirms the new method asserts both `HOST_SLUG_MAX_LEN === 63` and `HOST_HEADER_MAX_LEN === 253`.

## Success Criteria (from proposal)

- [x] **SC1** — `slugify_host()` firma `private static function slugify_host(string $raw): string` | **PASS**
  - Evidence: `lib/backup_manager.php:291` → `private static function slugify_host(string $raw): string`.
  - The `static` modifier is preserved (it was already `static` before this change). PHP 8.2+ allows `static` on private methods.

- [x] **SC2** — `resolve_host_slug()` firma `private function resolve_host_slug(): string` | **PASS**
  - Evidence: `lib/backup_manager.php:324` → `private function resolve_host_slug(): string`.

- [x] **SC3** — Existen `const HOST_SLUG_MAX_LEN = 63;` y `const HOST_HEADER_MAX_LEN = 253;` | **PASS**
  - Evidence: `lib/backup_manager.php:214-215`:
    - `const HOST_SLUG_MAX_LEN = 63;`
    - `const HOST_HEADER_MAX_LEN = 253;`
  - Both use plain `const` (PHP defaults to public visibility), so they are public class constants — no `private` keyword.
  - Order verified: `BACKUP_DIR` (L212) → `VERSION` (L213) → `HOST_SLUG_MAX_LEN` (L214) → `HOST_HEADER_MAX_LEN` (L215). The two new constants land exactly where task 1.1 specified (right after `VERSION`, before the `$fsRoot` property docblock at L217).

- [x] **SC4** — Las literales `63` y `253` ya no aparecen en los cuerpos de los helpers | **PASS**
  - Body grep `grep -nE 'strlen\(\$slug\) > 63|substr\(\$slug, 0, 63\)|\{1,253\}' lib/backup_manager.php` → **0 matches** (exit 1).
  - `slugify_host` body (L306-307):
    - `if (strlen($slug) > self::HOST_SLUG_MAX_LEN) {`
    - `$slug = substr($slug, 0, self::HOST_SLUG_MAX_LEN);`
  - `resolve_host_slug` body (L333):
    - `if ($raw === '' || !preg_match('/^[a-zA-Z0-9.\-:]{1,' . self::HOST_HEADER_MAX_LEN . '}$/', $raw)) {`
  - **NOTE (not a finding)**: The docblocks of both helpers still contain the descriptive English phrases "capped at 63 chars" (L285) and "1-253 chars" (L317). These are human-readable prose, not code-fence literals — they are not in backticks and they don't look like hardcoded regex values. The proposal SC4 wording is "en los **cuerpos** de los helpers", and task 3.1 says the same. The orchestrator's targeted PHPDoc fix (see below) addressed the actual code-fence literal at L316, which was the real defect. Leaving the descriptive English in place is consistent with the documented scope of the orchestrator's fix.

- [x] **SC5** — Suite PHPUnit completa sigue pasando: 90 tests, 147 assertions, 0 failures, 0 errors | **PASS (with NOTE on count)**
  - Evidence: 91 tests, 149 assertions, 0 failures, 0 errors — matches the orchestrator's preflight expected new baseline.
  - **NOTE**: The proposal's SC5 number is 90/147 (the "before" state) but the actual after state is 91/149. The +1 test / +2 assertions come from the new `hostSlugTypeConstantsHaveExpectedValues` test method (task 0.1) which the proposal's "Out of Scope" section explicitly said it would not add, but the task then added as the TDD red step. The orchestrator's preflight codified the 91/149 as the expected new baseline. The behavior is correct — the change ships a passing test for both constants — and the count delta is fully accounted for by the single new test method.

## PHPDoc fix (orchestrator-applied)
- **State**: applied.
- **Evidence**:
  - `lib/backup_manager.php:316` reads:
    `* Each candidate must match `/^[a-zA-Z0-9.\-:]{1,' . self::HOST_HEADER_MAX_LEN . '}$/` (i.e. 1-253 chars; defends against ...`
  - The code-fenced regex now interpolates `self::HOST_HEADER_MAX_LEN` (matching the body at L333), instead of the literal `253` that the apply sub-agent left in the docblock. The parenthetical English "(i.e. 1-253 chars; ...)" is unchanged.
  - This is the only post-apply edit applied by the orchestrator. The fix is consistent: code AND docblock now reference the constant.

## Containment
- Production code modified: only `lib/backup_manager.php` (constants at L214-215, signature changes at L291 and L324, magic-number replacements at L306-307 and L333, docblock code-fence fix at L316).
- Test code modified: only `tests/BackupManagerFilenameTest.php` (new test method `hostSlugTypeConstantsHaveExpectedValues` at L367-380, plus the class-level `#[CoversClass(backup_manager::class)]` was already there from the previous change).
- Tasks artifact updated: `openspec/changes/backup-slug-types-constants/tasks.md` (expected, per the SDD workflow).
- No core `openspec/` entry: the core `openspec/changes/` directory contains 7 changes (`consolidate-session-csrf`, `first-login-password-change`, `fix-system-updater-backup`, `migrate-catalog-domain-to-catalogo-core`, `ventas-clientes-controller-dedup`, `ventas-clientes-dispatch-regression-test`, plus an archive directory) — **none is `backup-slug-types-constants`**. The change is fully owned by the plugin, per the `fsframework-plugin-sdd` routing rule ("Default al plugin si el plugin es el beneficiario principal" + the anti-pattern rule against creating a core entry for a plugin-only change).
- No files outside `plugins/system_updater/` were modified. The `find` for files newer than the proposal turned up only `tmp/...` and `.phpunit.cache/test-results` (build/runtime artifacts), and `git status` reports the working tree as clean (the plugin directory is gitignored, per the previous verify-report's containment check).
- `openspec/changes/backup-slug-types-constants/` contains only `proposal.md` and `tasks.md` (this `verify-report.md` will land here after the orchestrator commits it). No `specs/` subfolder — consistent with the `restore-database-only` and `backup-filename-hostname` precedents (this plugin does not use formal specs).
- Scope-check: `grep -nE ': string$|: int$|: bool$|: array$|private const |public const |protected const ' lib/backup_manager.php` returns **only** the 2 helper signatures (L291, L324). No other method was type-hinted, and no other const was added. ✅

## Backward compatibility
- The 2 new constants are new public symbols. They are referenced in 6 locations in `backup_manager.php` (declarations at L214-215, body at L306-307, body+docblock at L316 and L333) and in the new test (L372-378). No other production code references them — verified by `grep -rnE 'HOST_SLUG_MAX_LEN|HOST_HEADER_MAX_LEN' --include='*.php' .` (which returned only the expected 8 matches inside the plugin).
- No public API change: helper signatures are still `private` (callable only from inside the class); the only externally visible addition is 2 read-only class constants, which are purely additive.
- The 5 call-sites of `resolve_host_slug()` are byte-identical to the pre-change state (modulo the +2 line shift from the inserted constants at L214-215):
  - L616 (`create_backup_with_progress`): `$baseName = $customName ? $customName : $this->resolve_host_slug() . '_' . $timestamp;` — `customName` truthy check preserved, `resolve_host_slug() . '_' . $timestamp` default preserved, no extra suffix.
  - L783 (`create_database_backup`): `$fileName = ($customName ? $customName : $this->resolve_host_slug() . '_' . $timestamp) . '.sql.gz';` — `.sql.gz` appended once.
  - L1045 (`create_database_backup_with_progress`): same as L783.
  - L1241 (`create_files_backup`): `$fileName = ($customName ? $customName : $this->resolve_host_slug() . '_' . $timestamp) . '.zip';` — `.zip` appended once.
  - L1313 (`create_files_backup_with_progress`): same as L1241.
  - Timestamp format `date('Y-m-d_H-i-s')` is preserved at all 5 sites.
- All 5 call-sites still have the `customName` truthy check first, so user-supplied custom names continue to win (no regression for `createBackupWithProgressCustomNameIsPreservedVerbatim`).
- The previous `backup-filename-hostname` change's tests (36 tests) all still pass; this PR is purely additive and does not touch any method other than the 2 helpers.

## Test file quality check (`tests/BackupManagerFilenameTest.php`)

| Check | Result |
|---|---|
| `declare(strict_types=1);` | ✓ L21 |
| `namespace Tests\SystemUpdater;` | ✓ L23 |
| `#[CoversClass(backup_manager::class)]` | ✓ L44 (pre-existing from previous change) |
| New method uses `#[Test]` attribute | ✓ L367 |
| Clear method name `hostSlugTypeConstantsHaveExpectedValues` | ✓ L368 |
| Asserts `HOST_SLUG_MAX_LEN === 63` | ✓ L370-374 (uses `assertSame(63, backup_manager::HOST_SLUG_MAX_LEN, '...')`) |
| Asserts `HOST_HEADER_MAX_LEN === 253` | ✓ L375-379 (uses `assertSame(253, backup_manager::HOST_HEADER_MAX_LEN, '...')`) |
| No `@coversNothing` | ✓ |
| No `assertTrue(true)` placeholder | ✓ |
| Lives in the same class as the other test methods | ✓ L45 (`class BackupManagerFilenameTest extends TestCase`) |
| Clear assertion messages referencing DNS / RFC 1035 intent | ✓ "DNS label limit" and "RFC 1035 total host length" |

## CRITICAL findings
**None.**

## WARNING findings
**None.**

## SUGGESTION findings
- **[S1] SC5 stale count in proposal** — `proposal.md:35`.
  - The proposal says "90 tests, 147 assertions" because it was written before task 0.1 added the TDD red test. The actual after state is 91/149, which is what the orchestrator's preflight codified.
  - Not blocking; the count is trivially updated when the proposal is revised in a follow-up. No action required for this archive.

## Files verified
- `/home/javier/proyectos/one-login/OidcProvider/plugins/system_updater/lib/backup_manager.php` — production code with 2 new public class constants (L214-215), 2 new typed helper signatures (L291, L324), 3 magic-number→constant replacements in code (L306-307, L333), and the orchestrator's 1-line PHPDoc code-fence fix (L316). No other method was touched.
- `/home/javier/proyectos/one-login/OidcProvider/plugins/system_updater/tests/BackupManagerFilenameTest.php` — pre-existing test class now includes 1 new method (`hostSlugTypeConstantsHaveExpectedValues` at L367-380). 36 prior tests + 1 new = 37 tests in this file, all pass.
- `/home/javier/proyectos/one-login/OidcProvider/plugins/system_updater/openspec/changes/backup-slug-types-constants/proposal.md` — contract; 5 success criteria, all PASS (with a NOTE on the SC5 count drift caused by task 0.1).
- `/home/javier/proyectos/one-login/OidcProvider/plugins/system_updater/openspec/changes/backup-slug-types-constants/tasks.md` — 5 implementation tasks (0.1, 1.1, 2.1, 3.1, 4.1) marked done. Verified against the actual code.
- `/home/javier/proyectos/one-login/OidcProvider/plugins/system_updater/phpunit.xml` — unchanged; the suite runs identically.

## Notes for archive
- The change has no `design.md` or `specs/` delta — consistent with the `restore-database-only` and `backup-filename-hostname` precedents (tasks + proposal + verify-report only).
- The orchestrator's PHPDoc fix at L316 is part of the change and should be mentioned in the archive report so the archive reflects the full set of edits.
- No CRITICAL or hard contract issues. The implementation is functionally equivalent to the pre-change code (same regex, same cap), is backward compatible (no public API change, no call-site change), and the test suite is green (91/91 tests pass with 0 failures and 0 errors).
- The remaining descriptive English "capped at 63 chars" (L285) and "1-253 chars" (L317) in the two docblocks is acceptable scope — they are not code-fence literals and were intentionally left untouched by both the apply sub-agent and the orchestrator's fix. If a future change wants to remove them for stylistic consistency, it would be a follow-up SUGGESTION, not a regression.
