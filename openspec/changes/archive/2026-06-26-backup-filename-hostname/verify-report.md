# Verify Report: Hostname en nombre de archivo de backup

## Result
**PASS WITH WARNINGS**

## Phpunit
- Suite run: `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml`
- Result: `Tests: 90, Assertions: 147, PHPUnit Deprecations: 12, Skipped: 1` — 0 failures, 0 errors
- New test file (`BackupManagerFilenameTest`): **36 tests, 47 assertions** — all pass
- The 3 deprecations on the isolated new-test run are PRE-EXISTING in `SessionAuthTest` (doc-comment metadata) and unrelated to this change. The 9 other deprecations across the full suite are also pre-existing.
- The 1 skip (`Resolve cookie path uses root for document root install`) is PRE-EXISTING and unrelated to this change.
- Isolated run: `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml --filter BackupManagerFilenameTest` → `Tests: 36, Assertions: 47, OK, but there were issues!` (3 pre-existing deprecations, no failures).

## Success Criteria (from proposal)

- [x] **SC1** — Archivo nuevo se llama `<host-slug>_<YYYY-MM-DD>_<HH-MM-SS>_complete.zip` y los auxiliares comparten el mismo base | **PASS**
  - Evidence: `backup_manager.php:614` builds `$baseName = slug_timestamp`; L695 builds `packageName = baseName . '_complete.zip'`; L624 calls `create_database_backup_with_progress($baseName . '_db', ...)`; L638 calls `create_files_backup_with_progress($baseName . '_files', ...)`.
  - Tests: `createDatabaseBackupDefaultUsesHostSlug` (regex `/^example-com_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql\.gz$/`), `createFilesBackupDefaultUsesHostSlug` (regex `/^example-com_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/`), `createBackupWithProgressDefaultUsesHostSlug` (regex on `complete.backup_name`). All pass.
  - Manual trace: with `HTTP_HOST=example.com`, the file becomes `example-com_<date>_<time>_complete.zip`; auxiliaries are `example-com_<date>_<time>_db.sql.gz` and `example-com_<date>_<time>_files.zip`. Shared base confirmed.

- [x] **SC2** — Slug cumple `[a-z0-9-]+` y respeta el cap de 63 chars | **PASS**
  - Evidence: `slugify_host` at L289-308 — replace `[^a-z0-9-]+` with `-`, collapse, trim, cap at 63. Manual probe of `slugify_host(str_repeat('a', 70))` → 63 'a' chars. Manual probe of `slugify_host('Example.COM:8080')` → `'example-com-8080'`. Data provider covers `'cap 63 chars after trim'` and `'cap 63 after trim of leading dashes'`.

- [x] **SC3** — Intento de host header injection produce slug `unknown` (no entra al nombre) | **PASS (with SUGGESTION)**
  - Evidence: `resolve_host_slug` at L321-340 applies `/^[a-zA-Z0-9.\-:]{1,253}$/` guard; injection `../../etc/passwd` is rejected (contains `/`), chain falls through.
  - Test: data set `'rejects host injection falls through to server_name'` confirms HTTP_HOST=`../../etc/passwd` does NOT end up in the slug — falls through to SERVER_NAME's value.
  - Manual probe (hostile inputs): path traversal, angle brackets, spaces, newlines, null bytes, SQL injection, shell injection, IPv6 brackets, CR, >253 chars — all rejected by the regex guard.
  - **SUGGESTION**: The proposal wording says "produce slug `unknown`" but the actual contract is "injection does not end up in the filename". A test with all sources failing (HTTP_HOST=injection, SERVER_NAME=injection, gethostname() not stubbable → falls through to real hostname) would more literally prove the "unknown" case, but the current test is functionally sufficient.

- [x] **SC4** — Backups antiguos con nombre `backup_YYYY-MM-DD_*` siguen siendo listados y restaurables sin cambios | **PASS (with WARNING)**
  - Evidence: `list_backups_grouped` at L2558 strips `_db.sql.gz`, `_files.zip`, `_complete.zip` suffixes and groups by the remainder. A `backup_2024-01-15_10-30-00_*` triplet is grouped under base `backup_2024-01-15_10-30-00` regardless of the prefix being `backup_` or `<host-slug>_`.
  - Test: `listBackupsGroupedIncludesLegacyBackupCompleteFiles` creates the legacy triplet, calls `list_backups_grouped()`, and asserts the group exists with `complete`, `database`, `files` linked, and `can_restore_complete = true`. Passes.
  - **WARNING (W1)**: The task 3.1 explicitly required the test to also "confirme que `restore_database()` lo resuelve sin error" on the legacy fixture. The test only verifies listing/grouping — it does NOT call `restore_database()` or `resolve_database_backup_source()` on the legacy file. Restore logic is unchanged by this PR, so the behavior is correct, but the regression-test scope is reduced vs. the task contract.

- [x] **SC5** — `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` pasa | **PASS**
  - Evidence: 90 tests, 147 assertions, 0 failures, 0 errors. Re-ran from scratch and confirmed.

- [x] **SC6** — Tests unitarios cubren: dominio normal, subdominio, IP literal, puerto, `localhost`, host vacío/inválido, intento de inyección, cap de longitud, colapso de dashes repetidos, trim leading/trailing | **PASS**
  - Evidence: `slugifyHostProvider` (17 cases) covers all 11 required buckets; `resolveHostSlugProvider` (13 cases) covers chain ordering, regex guard, injection, length cap, unsafe chars, multi-level subdomains, IPs, localhost, ported hosts. Plus 6 non-data-provider tests for full-path integration (database backup, files backup, unified backup, custom name, system hostname fallback, legacy list). Total: 36 new test cases.

## Adversarial hand-trace of `slugify_host` and `resolve_host_slug`

| Input | Expected | Got | Notes |
|---|---|---|---|
| `Example.COM` | `example-com` | `example-com` | lowercased, `.` → `-` |
| `a-..b` | `a-b` | `a-b` | `..` is one greedy match of `[^a-z0-9-]+` → single `-` |
| `a`×70 | 63 `a` | 63 `a` | cap applied AFTER trim |
| `""` | `unknown` | `unknown` | empty after trim → fallback |
| `../../etc/passwd` (directly to slugify) | n/a — never reaches slugify | `etc-passwd` | regex guard upstream prevents this in production |
| `   ` (spaces) | `unknown` | `unknown` | spaces → `-` → collapse → trim → empty |
| `.....` (dots) | `unknown` | `unknown` | same |
| `my_host` | `my-host` | `my-host` | `_` is not in safe set |
| `Example.COM:8080` | `example-com-8080` | `example-com-8080` | `:` → `-` |

`resolve_host_slug` chain hand-traced against hostile inputs (`foo/bar`, `../../etc/passwd`, `evil<host>`, `foo bar`, `host\nname`, 254×`a`, `''`, `[2001:db8::1]`, null byte, CR, `' OR 1=1 --`, `$(whoami)`): all rejected by the regex guard and fall through to the system hostname (DDEV `oidcprovider-web` → `oidcprovider-web`). No unsafe filename can be produced by the chain. The only chars that pass the regex are `[a-zA-Z0-9.\-:]`, and after `slugify_host` even `.` and `:` become `-`. The final slug is always `[a-z0-9-]+` with no leading/trailing dashes, no consecutive dashes, max 63 chars. **No CRITICAL injection vector found.**

## Collateral damage check (5 modified call-sites)

- **L614** `create_backup_with_progress`: `$baseName = $customName ? $customName : $this->resolve_host_slug() . '_' . $timestamp;` — `customName` wins on truthiness; default uses slug; timestamp via `date('Y-m-d_H-i-s')` (unchanged from before).
- **L695** `create_unified_package`: `$packageName = $baseName . '_complete.zip';` — suffix appended once. No collision risk.
- **L781** `create_database_backup`: `$fileName = ($customName ? $customName : ...slug_timestamp...) . '.sql.gz';` — `customName` wins; default uses slug; `.sql.gz` appended once.
- **L1043** `create_database_backup_with_progress`: same pattern as L781. Custom name passes through unchanged. Tested by `createDatabaseBackupDefaultUsesHostSlug` (default) and indirectly verified for the custom-name path.
- **L1239** `create_files_backup`: `$fileName = ($customName ? $customName : ...slug_timestamp...) . '.zip';` — same contract. Tested by `createFilesBackupDefaultUsesHostSlug`.
- **L1311** `create_files_backup_with_progress`: same as L1239.

**Verdict**: customName override preserved verbatim at every site (test `createBackupWithProgressCustomNameIsPreservedVerbatim` passes). Suffixes appended exactly once. Timestamp unchanged. **No collateral damage.**

`create_pre_update_backup` at L2709 also calls `create_backup($backupName, true)` with a non-empty `$backupName` (`pre_update_<safeName>_<timestamp>`), so the customName path wins and the slug is never used. Confirmed safe.

## Test file quality check (`tests/BackupManagerFilenameTest.php`)

| Check | Result |
|---|---|
| `declare(strict_types=1);` | ✓ L21 |
| `namespace Tests\SystemUpdater;` | ✓ L23 |
| LGPL / file header | ✓ L2-L19 |
| `#[CoversClass(backup_manager::class)]` | ✓ L44 |
| Data providers are `static` | ✓ `slugifyHostProvider` L148, `resolveHostSlugProvider` L191 |
| Data provider naming | ✓ camelCase, descriptive keys |
| 0.1 matrix covered (not stubbed) | ✓ All required cases present and not skipped |
| No `@coversNothing` | ✓ |
| No `assertTrue(true)` placeholders | ✓ |
| setUp cleans `$_SERVER` and creates temp dir | ✓ L57-L77 |
| tearDown restores `$_SERVER` and cleans temp dir | ✓ L79-L107 |
| Uses `require_once` to load class (non-PSR-4) | ✓ L32 |

**WARNING (W2)**: The task 0.1 said "setUp ... y stubea `gethostname()`". The actual test does NOT stub `gethostname()` — `resolveHostSlugFallsBackToSystemHostnameWhenNoServerVars` works around this by calling `gethostname()` itself and computing the expected slug dynamically. This is more robust than a stub (the test won't break if the implementation changes how it calls gethostname), but it is a deviation from the task's literal wording. **Not a contract break.**

**WARNING (W3)**: The proposal and task both specify the helper signatures as `slugify_host(string $raw): string` and `resolve_host_slug(): string`. The current implementation declares them as `private static function slugify_host($raw)` and `private function resolve_host_slug()` — missing the parameter type hint and the return type hint. The behavior is functionally equivalent, but the typed signatures from the proposal are not present. **Deviation, not a contract break.**

## Containment check (modification scope)

- Plugin folder is gitignored (`plugins/system_updater/` is in `.ddev` ignore list, see `git status --ignored`).
- Files modified since `proposal.md` (mtime check): only
  - `plugins/system_updater/lib/backup_manager.php`
  - `plugins/system_updater/tests/BackupManagerFilenameTest.php`
  - `plugins/system_updater/openspec/changes/backup-filename-hostname/tasks.md` (expected; tasks get updated during apply)
- **No modifications outside the plugin.** No core `src/`, `base/`, `controller/`, `model/`, or other plugins touched.
- No `openspec/changes/backup-filename-hostname/` entry in the **core** `openspec/`. Only the plugin's `openspec/changes/backup-filename-hostname/` exists.

## Change directory contents

`plugins/system_updater/openspec/changes/backup-filename-hostname/`:
- `proposal.md` (3799 bytes, jun 26 18:45)
- `tasks.md` (2857 bytes, jun 26 19:01)
- (no `verify-report.md` yet — this file will land here after the orchestrator commits the verify report)
- (no `design.md`, no `specs/`, no `apply-report.md` — consistent with the `restore-database-only` precedent which is tasks+proposal only)

No leftover from other changes. No unexpected files.

## Backward compatibility check

- `list_backups_grouped` (L2558) strips the `_db.sql.gz`, `_files.zip`, `_complete.zip` suffixes by regex, then groups by the remainder. This works for any prefix: `backup_2024-01-15_10-30-00` and `example-com_2024-01-15_10-30-00` both group correctly.
- `get_backup_type` (L2514) classifies by suffix presence (`_complete.zip`, `.sql.gz`, `.zip`), independent of the prefix.
- `get_backup_file_path` (L2178) uses `basename($file)` to strip directories, then joins with `backupPath`. Safe.
- `delete_backup_group` (L2624) builds patterns by appending suffixes to the base name. Works for legacy `backup_*` AND new `<host-slug>_*` base names.
- `clean_old_backups` (L2670) groups by `type` (which is suffix-derived), so mixed legacy + new files of the same type are kept together. No regression.
- `resolve_database_backup_source` (L1710) accepts both `.sql.gz` and `_complete.zip` files. Independent of prefix.
- `restore_complete` (L1494) and `restore_database` (L1771) extract the metadata and inner files; they don't care about the prefix.

**Conclusion**: No production code path now hard-requires a slug in the filename. Legacy `backup_YYYY-MM-DD_*` files are still listed, classified, restorable, and cleanable. **Backward compatibility preserved.**

## CRITICAL findings
**None.**

## WARNING findings

- **[W1] Task 3.1 partial coverage** — `tests/BackupManagerFilenameTest.php:332` (`listBackupsGroupedIncludesLegacyBackupCompleteFiles`).
  - The task explicitly required the test to verify both `list_backups_grouped()` grouping AND that `restore_database()` "lo resuelve sin error" on a legacy fixture.
  - Current test only verifies grouping. The actual `restore_database()` / `resolve_database_backup_source()` path is unchanged by this PR and is covered for the modern case by `BackupManagerRestoreTest` (static source-content checks), so behavior is correct.
  - **Severity**: WARNING (deviation from task that does not break the contract — `list_backups_grouped` proves the legacy file is reachable as a `complete` type, and the existing `BackupManagerRestoreTest` proves the restore path handles `_complete.zip` regardless of prefix).
  - **Suggested fix** (apply-phase, not now): add `assertNotFalse($manager->resolve_database_backup_source($legacyComplete))` to the regression test.

- **[W2] gethostname() not stubbed** — `tests/BackupManagerFilenameTest.php` (setUp + `resolveHostSlugFallsBackToSystemHostnameWhenNoServerVars` at L237).
  - The task 0.1 said "setUp ... y stubea `gethostname()`". The actual test does not stub it; instead the test that exercises the gethostname fallback computes the expected value from the real `gethostname()`.
  - This is a more robust pattern (test is decoupled from internal call shape), but it does not match the task's literal wording.
  - **Severity**: WARNING (deviation from task that does not break the contract).
  - **Suggested fix** (apply-phase, not now): use `runkit7` or `uopz` to stub `gethostname()` to a known value, or add a `// phpspec-helpers` comment explaining the dynamic-compute approach.

- **[W3] Helper signatures lack type hints** — `lib/backup_manager.php:289, 321`.
  - The proposal and task both specify `slugify_host(string $raw): string` and `resolve_host_slug(): string`. The implementation uses untyped signatures: `private static function slugify_host($raw)` and `private function resolve_host_slug()`.
  - Functionally identical, but doesn't match the proposal's typed signature.
  - **Severity**: WARNING (deviation from proposal that does not break the contract; PHP 8.2 project would benefit from explicit types).
  - **Suggested fix** (apply-phase, not now): add `string $raw` parameter type and `: string` return type to both helpers.

## SUGGESTION findings

- **[S1] SC3 strict-reading test gap** — `tests/BackupManagerFilenameTest.php`.
  - The proposal SC3 says "produce slug `unknown`" for an injection attempt. The current test only covers the fall-through path (injection → fall to SERVER_NAME → produces valid slug from SERVER_NAME). A literal "injection + all sources fail → `unknown`" test would be ideal but is hard to write without stubbing gethostname AND both `$_SERVER` keys. The behavior IS correct (verified by hand-trace of the implementation), but the test could be more thorough.
  - **Suggested fix**: add a data set with HTTP_HOST=`../../etc/passwd`, SERVER_NAME=null/empty, and assert the result is a slug derived from `gethostname()` (i.e. NOT `'unknown'` unless all three sources are also invalid — which is impractical in a unit test). The current test coverage is sufficient; this is a nice-to-have.

- **[S2] Test could check `complete.unified_file` filename directly** — `tests/BackupManagerFilenameTest.php:296-312`.
  - The test verifies `complete.backup_name` matches the pattern, but `complete.unified_file` (which would be `example-com_<date>_<time>_complete.zip`) is not asserted. The behavior is verified by code reading + the auxiliary tests; this is a minor coverage nicety.
  - **Suggested fix**: add `assertMatchesRegularExpression('/^example-com_.*_complete\.zip$/', $result['complete']['unified_file'] ?? '')`.

- **[S3] Cap and DNS-label limit could be class constants** — `lib/backup_manager.php:304, 330`.
  - The `63` and `253` numbers are magic numbers. Extracting them to `const HOST_SLUG_MAX_LEN = 63;` and `const HOST_HEADER_MAX_LEN = 253;` would make intent clearer and let tests reference them.
  - **Suggested fix**: extract constants and reference them in both code and tests.

## Files verified

- `/home/javier/proyectos/one-login/OidcProvider/plugins/system_updater/lib/backup_manager.php` — production code, contains the new `slugify_host` (L289) and `resolve_host_slug` (L321) helpers and the 5 modified call-sites (L614, L781, L1043, L1239, L1311). Helpers are secure, call-sites preserve `customName` override, suffixes appended once, timestamp unchanged.
- `/home/javier/proyectos/one-login/OidcProvider/plugins/system_updater/tests/BackupManagerFilenameTest.php` — new test file, 36 tests, 47 assertions, all pass. Has LGPL header, `declare(strict_types=1)`, correct namespace, `#[CoversClass]`, static data providers. Covers the full 0.1 matrix.
- `/home/javier/proyectos/one-login/OidcProvider/plugins/system_updater/tests/BackupManagerRestoreTest.php` — pre-existing, still passes (6 tests). Static source-content checks on `resolve_database_backup_source` and `execute_database_restore` remain valid; this PR does not modify those methods.
- `/home/javier/proyectos/one-login/OidcProvider/plugins/system_updater/tests/ProcessBackupTest.php` — pre-existing, still passes (4 tests). Unaffected by this PR.
- `/home/javier/proyectos/one-login/OidcProvider/plugins/system_updater/phpunit.xml` — config; test suite `system_updater` includes `tests/`; bootstrap from `../../tests/bootstrap.php`. No changes needed.
- `/home/javier/proyectos/one-login/OidcProvider/plugins/system_updater/openspec/changes/backup-filename-hostname/proposal.md` — contract; 6 success criteria, all PASS.
- `/home/javier/proyectos/one-login/OidcProvider/plugins/system_updater/openspec/changes/backup-filename-hostname/tasks.md` — all 5 implementation tasks (0.1, 1.1, 1.2, 2.1, 2.2, 2.3, 3.1, 4.1) marked done. Verified against the actual code.

## Notes for archive

- The change has no `design.md` or `specs/` delta — consistent with the `restore-database-only` precedent (tasks + proposal only). Archive step can copy the directory as-is once the orchestrator proceeds.
- The 3 WARNINGs (W1, W2, W3) are non-blocking; the user can decide whether to fold them into a follow-up apply or accept them as acceptable scope choices.
- No CRITICAL or hard contract issues. The implementation is safe (no injection can produce an unsafe filename), backward compatible (legacy `backup_YYYY-MM-DD_*` files remain listed and restorable), and the test suite is green (90/90 tests pass with 0 failures and 0 errors).
- Suggested follow-up (informational only, not part of this change): consider extracting the magic numbers `63` and `253` into class constants for self-documentation (S3).
