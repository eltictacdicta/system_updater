```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:93af3f086ca5500dbfd371f2a49246ef13a84cb2939cb06355f3614114d3c6a1
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 4/4
scenarios: 11/11
test_command: ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:93af3f086ca5500dbfd371f2a49246ef13a84cb2939cb06355f3614114d3c6a1
build_command: ddev exec php -l plugins/system_updater/lib/PluginUpdateOrderer.php plugins/system_updater/lib/PluginSchemaResyncer.php plugins/system_updater/controller/admin_updater.php plugins/system_updater/tests/PluginUpdateOrdererTest.php plugins/system_updater/tests/PluginSchemaResyncerTest.php
build_exit_code: 0
build_output_hash: sha256:4790f6741959e4e35d304f92aa53297b33b3580116a71a2bfeeb91ef49f22a90
```

# Verification Report — schema-sync-dependency-safety

**Change**: schema-sync-dependency-safety
**Version**: N/A (plugin-local SDD, delta spec v1)
**Mode**: Strict TDD (runner available: `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml`)
**Plugin repo**: `plugins/system_updater` (own git repo, master) — core repo untouched.

## Summary

Independent verification of the plugin-local SDD change `schema-sync-dependency-safety`. All 14 tasks are checked; all 4 delta requirements (PU-04 mod, PU-08 mod, PU-09 added, PU-10 added) are implemented and covered by passing tests. Full plugin suite green (140 tests, 261 assertions, 0 failures), core Base suite green (180 tests, 548 assertions), lint clean on all changed PHP files, zero core files modified, `plugin_downloader.php` untouched. One non-blocking WARNING (design contract signature deviation, intent-matching and documented) and three SUGGESTIONS (manual DB smoke, archive-time language merge, pre-existing PHPUnit deprecations).

## Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 14 |
| Tasks complete | 14 |
| Tasks incomplete | 0 |

## Build & Tests Execution

**Build (lint)**: ✅ Passed — `php -l` on all 5 changed PHP files: no syntax errors.
```text
No syntax errors detected in plugins/system_updater/lib/PluginUpdateOrderer.php
No syntax errors detected in plugins/system_updater/lib/PluginSchemaResyncer.php
No syntax errors detected in plugins/system_updater/controller/admin_updater.php
No syntax errors detected in plugins/system_updater/tests/PluginUpdateOrdererTest.php
No syntax errors detected in plugins/system_updater/tests/PluginSchemaResyncerTest.php
```

**Tests (plugin suite)**: ✅ 140 passed / 0 failed / 1 skipped (pre-existing) — 261 assertions.
```text
.......S......                                                  140 / 140 (100%)
Time: 00:03.344, Memory: 6.00 MB
OK, but there were issues!
Tests: 140, Assertions: 261, PHPUnit Deprecations: 12, Skipped: 1.
```
The 12 PHPUnit deprecations are pre-existing doc-comment metadata deprecations in `SessionAuthTest` (unrelated to this change; will matter only at PHPUnit 12).

**Tests (core Base suite, regression)**: ✅ 180 passed / 0 failed — 548 assertions. `ddev exec php vendor/bin/phpunit --testsuite Base`.

**Tests (new files, isolated)**: ✅ `PluginUpdateOrdererTest` 10/10 (12 assertions), `PluginSchemaResyncerTest` 6/6 (27 assertions); both green under `--filter`.

**Coverage**: ➖ Not available — no code coverage driver (Xdebug/PCOV) in the ddev container. Informational only, not a failure.

## Spec Compliance Matrix

| Requirement | Scenario | Test / Evidence | Result |
|-------------|----------|-----------------|--------|
| PU-04 (mod) | Batch action offers sequential update of N public plugins | `controller/admin_updater.php` `case 'update_all_public_plugins'` (L232) → `actionUpdateAllPublicPlugins` (L718); "Actualizar compatibles ahora" button (view L396-403) | ✅ COMPLIANT |
| PU-04 (mod) | Deps-first topological order over `require` (installed INI first, catalog fallback); installed transitive deps included; missing deps skipped | `PluginUpdateOrderer::order` (L40-118) called at controller L765; default provider `CatalogPluginInstallProvider::getDirectRequirements` (local ini first, catalog fallback — verified L36-52); tests `testOrderDirectDependencyComesFirst`, `testOrderTransitiveDependenciesDepsFirst`, `testOrderIncludesInstalledDependencyNotInBatch`, `testOrderSkipsMissingDependencyWithoutBlocking` | ✅ COMPLIANT |
| PU-04 (mod) | Cycle → no hard fail + warning + original order for cycle members | `order()` leftover cycle members appended in discovery order + single `error_log` warning (L110-117); test `testOrderCycleDoesNotThrowAndKeepsOriginalOrder` (asserts `['A','B']` and log contains `A, B`) | ✅ COMPLIANT |
| PU-08 (mod) | Core updated before plugin when remote requires higher core | Pre-existing behavior unchanged: chained plan gated on `has_chained_update_plan`/`chained_plugin_updates` (checkUpdates L454-459); JS runs chained update only after core update completes | ✅ COMPLIANT (unchanged, intact) |
| PU-08 (mod) | Block plugin update until core compatibility resolved | Pre-existing: `filterReadyPluginUpdates` (L469-490) + `plugin_compatibility_checker` — unchanged, still blocks `blocked_by_core` | ✅ COMPLIANT (unchanged, intact) |
| PU-08 (mod) | Chained plugin batch ordered deps-first with same semantics as public batch | `actionUpdateChainedPlugins` orders via `PluginUpdateOrderer::order($toUpdate)` (L843); same orderer semantics (transitive inclusion, cycle warn+original, missing skip) covered by orderer tests | ✅ COMPLIANT |
| PU-09 (add) | Plugin + installed deps visible in-memory to XML lookup during sync; snapshot/append/restore; never persist-enable | `withDependencyVisibility` (PluginSchemaResyncer L111-127): snapshot `$GLOBALS['plugins']`, `order([$pluginName])` (plugin + installed transitive deps), merge unique, `finally` restore; wraps both `download()` (controller L961) and `download_private()` (L996) inside `updateInstalledPlugin`; only `$GLOBALS['plugins']` mutated, no `enable()`; tests `testResyncInstalledOrdersDependenciesFirstAndRestoresGlobals` (asserts deps visible in `globalsDuring`), `testWithDependencyVisibilityAddsAndRestoresOnSuccess` | ✅ COMPLIANT |
| PU-09 (add) | Restore snapshot on failure/throw (try/finally) | `withDependencyVisibility` try/finally restore + re-throw (L122-126); `resyncInstalled` outer try/finally (L63-87); tests `testResyncInstalledRestoresSnapshotAndPropagatesException`, `testWithDependencyVisibilityRestoresOnException` | ✅ COMPLIANT |
| PU-09 (add) | Installed-but-disabled dep XMLs resolve (no "Archivo model/table/X.xml no encontrado") | Mechanism covered by passing unit tests (dep appended to `$GLOBALS['plugins']` during `applyPluginSchemaUpdates` — the exact input `fs_model::get_base_dir` reads for XML lookup); visibility stays active through `download() → syncPluginDatabaseSchema()`. End-to-end table creation needs a DB and is documented N/A in design (controller smoke) | ✅ COMPLIANT (mechanism) — see SUGGESTION 1 for manual DB smoke |
| PU-10 (add) | Manual resync for all installed or `&plugin=X`, dependency order, no re-download, CSRF required, per-plugin JSON `{success, updated, failed, messages}` | `actionResyncPluginSchema` (L899-939): JSON header, `requireCsrf()`, `plugin` POST-then-GET, `PluginSchemaResyncer::resyncInstalled`; `resyncInstalled` (L39-96): `installed()` → `order` (all or `[$only]`) → snapshot → foreach `applyPluginSchemaUpdates` (no download) → restore; JSON `{success, updated, failed, messages, message}`; tests `testResyncInstalledOrdersDependenciesFirstAndRestoresGlobals`, `testResyncInstalledWithOnlyPluginResolvesDependencies` | ✅ COMPLIANT |
| PU-10 (add) | Idempotent; re-running heals previously-failed tables | `resyncInstalled` never breaks the loop — failures captured in `failed`/`results` and retried on next run (`testResyncInstalledCapturesFailedPlugin`); healing relies on framework `applyPluginSchemaUpdates`/`createTable` tolerance (documented in proposal D2/A5); exception → snapshot restored + `{success:false, message}` JSON | ✅ COMPLIANT (mechanism) — see SUGGESTION 1 |

**Compliance summary**: 11/11 scenarios compliant (2 with a documented DB-outcome note).

## Correctness (Static Evidence)

| Requirement | Status | Notes |
|------------|--------|-------|
| PU-04 deps-first batch | ✅ Implemented | Kahn orderer; local-ini-first provider verified; failures collected, loop never breaks |
| PU-08 chained deps-first | ✅ Implemented | Same orderer; core-first/blocking pre-existing behavior intact |
| PU-09 in-memory visibility | ✅ Implemented | Single choke point `updateInstalledPlugin`; both download paths wrapped; never persist-enables |
| PU-10 manual resync | ✅ Implemented | CSRF, POST→GET `&plugin=X`, dependency order, no re-download, per-plugin JSON, Throwable→`{success:false}` |
| Zero core changes | ✅ Verified | Core `git status --porcelain` empty; change diff = exactly the 10 declared files |
| `plugin_downloader.php` untouched | ✅ Verified | Last commit touching it is pre-existing `6344d42`; absent from `c156a00~1..HEAD` diff |

## Coherence (Design)

| Decision | Followed? | Notes |
|----------|-----------|-------|
| A1 — Kahn over DFS, cycle→warn+original order | ✅ Yes | `leftoverCycleMembers` in discovery order, single `error_log` |
| A2 — Static orderer with injectable callables | ⚠️ Yes (sig deviation) | Second param `?callable $requirementsFn = null` vs design's required `callable` — matches A2's own prose (lazy defaults) and the controller's `order($names)` calls; see WARNING 1 |
| A3 — Missing/unknown deps skipped as leaves | ✅ Yes | `!isInstalled` excluded; unknown = no reqs = leaf |
| A4 — Dep visibility at `updateInstalledPlugin`, not `plugin_downloader` | ✅ Yes | Both download paths wrapped via `withDependencyVisibility`; store blast radius zero |
| A5 — Order batch ∪ installed transitive deps, attempt all | ✅ Yes | `order()` returns full sequence; failures don't break the loop |
| A6 — Restore in `finally`, re-throw | ✅ Yes | Both `withDependencyVisibility` and `resyncInstalled`; controller catches `\Throwable` → JSON |
| PU-10 action pattern (JSON + CSRF + `&plugin=X`) | ✅ Yes | Mirrors `actionUpdateChainedPlugins` pattern (L799-890) |

## TDD Compliance (Strict TDD module)

| Check | Result | Details |
|-------|--------|---------|
| TDD Evidence reported | ✅ | tasks.md RED→GREEN→REFACTOR rows per task (L30-52); engram apply-progress #504: "TDD RED→GREEN→REFACTOR followed per task with --filter commands; suite went 124 → 140 tests" |
| All implementation tasks have tests | ✅ | 10/10 implementation tasks (1.1-3.4) covered by 2 new test files; tasks 4.1-4.4 are docs/verify (no code) |
| RED confirmed (test files exist) | ✅ | `tests/PluginUpdateOrdererTest.php` + `tests/PluginSchemaResyncerTest.php` exist and are committed |
| GREEN confirmed (tests pass on execution) | ✅ | 10/10 + 6/6 pass when executed; full suite 140/140 |
| Triangulation adequate | ✅ | Orderer 10 distinct cases, resyncer 6 distinct cases (multi-case, no single-case gaps) |
| Safety Net for modified files | ✅ | Tasks 3.1-3.3 modified existing files; full suite stayed green after each phase (124→140); Base suite 180/180 green now |

**TDD Compliance**: 6/6 checks passed

## Test Layer Distribution

| Layer | Tests | Files | Tools |
|-------|-------|-------|-------|
| Unit | 16 | 2 | PHPUnit 11 (no DB, injected callables / fake `fs_plugin_manager` subclass) |
| Integration | 0 | 0 | not installed / N/A — controller smoke documented N/A (needs DB+session) |
| E2E | 0 | 0 | not applicable |
| **Total** | **16** | **2** | |

All spec scenarios covered at unit level; the design documents controller smoke as not feasible without DB. Informational — SUGGESTION level only.

## Changed File Coverage

Coverage analysis skipped — no code coverage driver available (no Xdebug/PCOV in ddev container). Informational, not a failure.

## Assertion Quality

**Assertion quality**: ✅ All assertions verify real behavior.

Audit of both test files (16 tests, 39 assertions): no tautologies, no type-only assertions used alone, no ghost loops, no smoke-only tests, no mock-heavy files (fake manager is a recording anonymous subclass, 0 mocks vs 27 assertions in the resyncer file). The empty-batch assertion (`testOrderEmptyBatchReturnsEmptyList`) has companion non-empty tests. `testOrderCycleDoesNotThrowAndKeepsOriginalOrder` asserts the log message content — this verifies the spec-required warning behavior, not incidental implementation detail.

## Issues Found

**CRITICAL**: None

**WARNING**:
1. **Design contract signature deviation (non-blocking)** — `PluginUpdateOrderer::order()` declares `?callable $requirementsFn = null` whereas the design's "Interfaces / Contracts" section declares it as a required `callable`. The implementation matches design decision A2's own prose ("Defaults wrap `CatalogPluginInstallProvider` (lazy, `require_once` + `new` when `FS_FOLDER` defined, else `[]`)") and is required for the controller's `order($names)` calls (L765, L843). No spec is broken; deviation is documented in apply-progress. **Action**: align the design.md Interfaces snippet at archive time.

**SUGGESTION**:
1. **Manual DB smoke recommended** — PU-09 (dep XML resolution / table creation) and PU-10 (healing of previously-failed tables) are covered at the mechanism level by unit tests; the end-to-end DB outcome is documented N/A in design (controller smoke needs DB+session). Run a manual smoke on a staging DB before production deploy: enable an installed-but-disabled dependency, update a plugin that requires it, and confirm no `Archivo model/table/X.xml no encontrado`; then use the "Re-sincronizar esquema" button.
2. **Archive-time language merge** — delta spec is English while the canonical `plugins/system_updater/openspec/specs/plugin-updates/spec.md` is Spanish. At archive, translate the merged PU-04/PU-08/PU-09/PU-10 blocks into neutral Spanish to keep the canonical spec consistent.
3. **Pre-existing PHPUnit deprecations** — 12 doc-comment metadata deprecations from `SessionAuthTest` (not this change); migrate to attributes before PHPUnit 12.

## Verdict

**PASS WITH WARNINGS** — all 4 requirements / 11 scenarios implemented and covered by passing runtime tests; zero CRITICAL findings; one non-blocking design-contract deviation (intent-matching) and three suggestions. Archive-ready per fsframework-plugin-sdd (no CRITICAL issues, all tasks checked).

---
*Evidence: plugin suite 140/140 (exit 0), Base suite 180/180 (exit 0), lint clean on 5 changed files, core repo clean, change diff = 10 declared files, `plugin_downloader.php` untouched. Commits: c156a00, 9703ae3, 319b808, 1006645, 44bba73.*