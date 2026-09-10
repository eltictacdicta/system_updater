```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:0fe695cc92d317fdfdca41d74c3af5dbee9beb1448eb3a50cb33a59776cbdc2d
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 5/5
scenarios: 15/15
test_command: ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:a0e5ca036cb0b409e9187ddbb5f82bea5073a3ebc7fad1635fbd5c2782476df3
build_command: ddev exec php -l plugins/system_updater/lib/plugin_compatibility_checker.php && ddev exec php -l plugins/system_updater/lib/plugin_downloader.php && ddev exec php -l plugins/system_updater/controller/admin_updater.php && ddev exec php -l plugins/system_updater/tests/PluginUpdateEntryCompatibilityTest.php && ddev exec php -l plugins/system_updater/tests/PluginCompatibilityCheckerResolverTest.php && ddev exec php -l plugins/system_updater/tests/PluginCompatibilityCheckerTest.php && ddev exec php -l plugins/system_updater/tests/PluginDownloaderUpdatesTest.php && ddev exec php -l plugins/system_updater/tests/PluginDownloaderTest.php
build_exit_code: 0
build_output_hash: sha256:037a9ea2d42626714e393e6f08ff2e05f544098a85c72524ae9e9a15edb368c5
```

# Verification Report — plugin-compatible-update-resolver

**Change**: plugin-compatible-update-resolver
**Version**: N/A (plugin-local SDD, delta v1 adding PU-11..PU-15)
**Mode**: Strict TDD (runner available: `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml`)
**Plugin repo**: `plugins/system_updater` (own git repo). Change is uncommitted; no commit/tag/push performed.
**Pass type**: fresh verify after a bounded correction (prior verdict was `fail`, 1 CRITICAL + 2 WARNINGs).

## Summary

Fresh verification of the corrected state. The correction resolves the blocking finding and both prior coherence warnings:

1. **CRITICAL (resolved)** — the compatibility guard now evaluates the release that will actually be applied. New pure `plugin_compatibility_checker::evaluateUpdateEntryForCore(core, updateEntry, branchTipEntry)` selects the resolved entry's own bounds when `resolved_from_history === true`, and the branch-tip bounds otherwise. `admin_updater::assertRemotePluginCompatible()` accepts and forwards the resolved entry, and both the single and batch apply paths pass it. The motivating scenario (tip incompatible, resolved release compatible) is now allowed and the resolved ZIP override is reachable.
2. **WARNING-1 (resolved)** — the private path is now symmetric: `download_private($id, ?string $zipUrlOverride)` accepts the resolved ZIP via the `fetchPrivateZipToFile()` seam, the controller obtains and validates the private resolved entry, and computes the override before download.

The full plugin suite is green: **208 tests / 411 assertions / 0 failures / exit 0**; the pre-existing baseline of 2 warnings / 12 PHPUnit deprecations / 1 skip is unchanged (prior run: 199 tests / 391 assertions with the same 2/12/1). The +9 tests / +20 assertions are the correction's new coverage.

Remaining items are non-blocking: the controller glue is still not exercised by an automated harness (its decision is now delegated to a directly-tested pure function), one documentation/skill edit outside the plugin tree is related to Decision #6, and the URL-override hardening items are explicit out-of-scope follow-ups. The change is **archive-ready**.

## Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 13 |
| Tasks complete | 13 (T1–T11 implementation/verification; T12/T13 already checked) |
| Tasks incomplete | 0 |

## Build & Tests Execution

**Build (lint)**: ✅ Passed — `php -l` clean on the 8 changed/added PHP files.
```text
No syntax errors detected in plugins/system_updater/lib/plugin_compatibility_checker.php
No syntax errors detected in plugins/system_updater/lib/plugin_downloader.php
No syntax errors detected in plugins/system_updater/controller/admin_updater.php
No syntax errors detected in plugins/system_updater/tests/PluginUpdateEntryCompatibilityTest.php
No syntax errors detected in plugins/system_updater/tests/PluginCompatibilityCheckerResolverTest.php
No syntax errors detected in plugins/system_updater/tests/PluginCompatibilityCheckerTest.php
No syntax errors detected in plugins/system_updater/tests/PluginDownloaderUpdatesTest.php
No syntax errors detected in plugins/system_updater/tests/PluginDownloaderTest.php
```

**Tests (plugin suite)**: ✅ 208 tests / 411 assertions / 0 failures / 0 errors / exit 0.
```text
..............................................W................  63 / 208 ( 30%)
............................................................... 126 / 208 ( 60%)
............................................................... 189 / 208 ( 90%)
............S......                                             208 / 208 (100%)
Time: 00:03.194, Memory: 10.00 MB
OK, but there were issues!
Tests: 208, Assertions: 411, Warnings: 2, PHPUnit Deprecations: 12, Skipped: 1.
```
Baseline confirmation: the 2 warnings, 12 deprecations and 1 skip are the same pre-existing items as the prior run (199/391 with 2/12/1); none are introduced by this change.

**Tests (corrected focus, testdox)** — 23 tests / 58 assertions, all pass:
- `PluginUpdateEntryCompatibilityTest` — 6 tests (new): resolved-compatible allowed over incompatible tip; resolved entry blocked on its own min/max; fallback and missing-entry use branch-tip bounds exactly as before.
- `PluginDownloaderUpdatesTest` — 17 tests: history bounds/zip exposure, `versions[]` equivalence, no-update-on-null, no-downgrade, private history listing, private override propagation, public override propagation, empty-override fallbacks, PU-14 exact-array regression.

**Coverage**: ➖ Not available — no code-coverage driver (Xdebug/PCOV) in the ddev container.

## Spec Compliance Matrix (PU-11..PU-15)

| Requirement | Scenario | Test / Evidence | Result |
|-------------|----------|-----------------|--------|
| PU-11 | `releases.json` reconocido como historial | `PluginDownloaderUpdatesTest::testHistoryBranchExposesResolvedReleaseBoundsAndZip`; `PluginDownloaderTest::testGetRemotePluginReleasesParsesPublicRawHistory` | ✅ COMPLIANT |
| PU-11 | `versions[]` del catálogo como forma alternativa | `PluginDownloaderUpdatesTest::testCatalogVersionsArrayActsAsEquivalentHistory` | ✅ COMPLIANT |
| PU-11 | límites inmutables por tag | Process invariant (no runtime mutation of history); documented in design §Producción and `fsframework-milestone-release` skill | ✅ COMPLIANT (process) |
| PU-12 | elige la más alta compatible cuando la punta es incompatible | `PluginCompatibilityCheckerResolverTest::testPicksHighestCompatibleWhenTipIsIncompatible` | ✅ COMPLIANT |
| PU-12 | `null` cuando ningún release es compatible | `testReturnsNullWhenNoReleaseIsCompatible` | ✅ COMPLIANT |
| PU-12 | `null` cuando ningún release es más nuevo | `testReturnsNullWhenNoReleaseIsNewerThanInstalled` | ✅ COMPLIANT |
| PU-12 | nunca devuelve ≤ instalada | `testNeverReturnsVersionLowerOrEqualToInstalled` | ✅ COMPLIANT |
| PU-12 | respeta `min_version` | `testMinBoundaryEqualIsCompatible`, `testMinBoundaryGreaterIsIncompatible` | ✅ COMPLIANT |
| PU-12 | respeta `max_version` | `testMaxBoundaryEqualIsCompatible`, `testMaxBoundaryLowerIsIncompatible` | ✅ COMPLIANT |
| PU-12 | determinismo y ausencia de I/O | `testDeterministicAcrossInvocations`; `resolveLatestCompatible()` pure static, no I/O/state | ✅ COMPLIANT |
| PU-13 | la entrada expone los límites del release resuelto | `PluginDownloaderUpdatesTest::testHistoryBranchExposesResolvedReleaseBoundsAndZip` | ✅ COMPLIANT |
| PU-13 | sin release compatible no se lista actualización | `PluginDownloaderUpdatesTest::testNoUpdateListedWhenResolverReturnsNull` | ✅ COMPLIANT |
| PU-14 | regresión — sin historial se mantiene punta de rama | `PluginDownloaderUpdatesTest::testNoHistoryKeepsBranchTipBehavior` (`assertSame` on full array; no `zip_link`/`resolved_from_history`) | ✅ COMPLIANT |
| PU-15 | no se ofrece degradación a nivel del actualizador | `testNoDowngradeWhenOnlyCompatibleReleaseIsNotNewer` + all PU-12 no-downgrade tests | ✅ COMPLIANT |
| PU-15 | el grafo `require` no se resuelve en este change | No graph logic exists; `resolveLatestCompatible(core, versions, installed)` is per-plugin. No dedicated test | ✅ COMPLIANT (by construction) |

**Compliance summary**: 15/15 scenarios compliant at the unit/listing/decision level.

## Correctness (Static Evidence)

| Requirement | Status | Notes |
|------------|--------|-------|
| PU-11 history model | ✅ Implemented | `normalizeReleaseHistory()` pure/defensive; `releases.json` loaded best-effort in `hydrateDownloadList()`/`private_downloads()`; `versions[]` consumed directly |
| PU-12 resolver | ✅ Implemented | `resolveLatestCompatible()` reuses `evaluateCoreAgainstPlugin` + `isRemoteVersionNewer` + normalized `version_compare`; returns the original entry or `null` |
| PU-13 downloader wiring | ✅ Implemented | `getAvailableUpdates($installed, $coreVersion)` → `buildAvailableUpdate()` → `buildHistoryUpdate()`; resolved bounds + `zip_link` + `resolved_from_history` only in the history branch |
| PU-14 fallback | ✅ Implemented | Fallback path returns exactly the legacy field set; regression asserts full-array equality |
| PU-15 anti-downgrade | ✅ Implemented | Strict-newer guard inside the resolver, enforced for every listed source |
| Resolved ZIP application | ✅ Resolved (was CRITICAL) | `evaluateUpdateEntryForCore` + `assertRemotePluginCompatible` + `updateInstalledPlugin` now judge and apply the resolved entry on public single, public batch, and private paths |
| Scope: no core edits | ✅ Verified (plugin) | Plugin `git status` shows only `plugins/system_updater/**`; no core `openspec/` entry for this change |

## Coherence (Design)

| Decision | Followed? | Notes |
|----------|-----------|-------|
| #1 Resolver signature `(core, versions, installed='')`, anti-downgrade in resolver | ✅ Yes | Matches design Option A |
| #2 History shape: `releases.json` preferred, `versions[]` alternative; defensive parse; absent/malformed → fallback | ✅ Yes | `entryHasReleaseHistory()` + `releaseHistoryFromEntry()` precedence implemented |
| #3 Algorithm: bounds → strict-newer → max by normalized semver | ✅ Yes | Matches design pseudocode |
| #4 Wiring: expose resolved version/bounds/ZIP; `null` ⇒ not listed; `download()` override | ✅ Yes (now) | Listing + override implemented; guard now consumes the resolved entry so the override is reachable in the motivating scenario |
| #5 Fallback explicit branch, output identical, PU-14 regression test | ✅ Yes | `assertSame` full-array test |
| #6 History produced at release time (skill) | ⚠️ Related edit outside plugin | `.opencode/skills/fsframework-milestone-release/SKILL.md` (+ `.cursor` mirror) is modified and explicitly documents this change's Decision #6 and names the change. Design listed the skill as "No (referencia)"; the doc edit is a scope nuance, recorded below |
| #7 Strict TDD, pure resolver/parser tests + mocked wiring tests | ✅ Yes | Unit coverage present and hermetic |
| "Private path keeps current behavior" | ✅ Resolved | Private listing and download are now symmetric (both use the resolved release when history exists) |

## TDD Compliance (Strict TDD module)

| Check | Result | Details |
|-------|---------|---------|
| TDD Evidence reported | ✅ | `tasks.md` states RED/GREEN per task (T1–T11); apply engrams record wave-by-wave suite progression 179 → 194 → 199; correction added the 208-test run |
| All implementation tasks have tests | ✅ | 8/8 code tasks have covering test files, including the correction's new `PluginUpdateEntryCompatibilityTest` |
| RED confirmed (tests exist) | ✅ | Test files exist and target methods that did not exist before (`resolveLatestCompatible`, `normalizeReleaseHistory`, `get_remote_plugin_releases`, `download()`/`download_private()` override, `findPublicUpdateByName`, `evaluateUpdateEntryForCore`) |
| GREEN confirmed (tests pass) | ✅ | All referenced tests pass on execution (full suite 208/208, exit 0; corrected focus 23/23) |
| Triangulation adequate | ✅ | Resolver 14 distinct cases; downloader 17 cases with differing expected values; exact-array regression; compatibility evaluator with positive/negative/fallback cases |
| Safety Net for modified files | ✅ | Pre-existing suite kept green across slices; `CatalogPluginInstallProviderTest` updated for the new `download()` signature |
| Formal apply-progress artifact (RED/GREEN table) | ⚠️ | No `apply-progress.md` persisted; RED evidence is reconstructed from `tasks.md` + engram observations. Informational caveat, not a failure |

**TDD Compliance**: 6/6 substantive checks passed (1 informational caveat on artifact form).

## Test Layer Distribution

| Layer | Tests | Files | Tools |
|-------|-------|-------|-------|
| Unit | 48 (change focus) | 5 | PHPUnit 11 (no DB, no boot, no external network) |
| Integration | 0 | 0 | not installed — no `fs_controller` harness |
| E2E | 0 | 0 | not applicable |
| **Total** | **48** | **5** | |

All change tests are hermetic: `download()`/`download_private()` I/O is intercepted via the `fetchZipToFile()`/`fetchPrivateZipToFile()` seams; `releases.json` fetch is stubbed via `fetchRemoteContents()`; catalogs/history are injected via anonymous `plugin_downloader` subclasses. The two capture tests point at `http://127.0.0.1:1/...`, which fails fast with no external network and only serves to capture the effective URL; assertions do not depend on network success.

## Changed File Coverage

Coverage analysis skipped — no coverage tool detected (no Xdebug/PCOV in the ddev container).

## Assertion Quality

**Assertion quality**: ✅ All assertions verify real behavior.

Audit of the 5 change test files (48 tests): concrete value assertions (`assertSame('1.8.1', …)`, exact-array `assertSame`, `assertTrue`/`assertFalse` on behavior), no tautologies, no ghost loops, no type-only-only assertions, no implementation-detail coupling. Empty-result assertions (`assertSame([], …)`, `assertNull`) have companion non-empty/positive tests. The override tests assert the effective URL captured by the seam — behavior, not internals. The correction's `testResolvedCompatibleEntryIsAllowedEvenWhenBranchTipIsIncompatible` asserts the decisive positive outcome, with negative companions (`…MinBoundIsIncompatibleIsBlocked`, `…MaxBoundIsIncompatibleIsBlocked`) and an equivalence companion (`testFallbackEntryUsesBranchTipBoundsExactlyAsToday` using `assertSame` against `validateRemotePluginForCore()`).

## CRITICAL Resolution Evidence (fresh)

The prior blocking finding was: the guard validated the branch tip, so a listed historical release could not be applied. Resolution evidence:

**Pure decision (`lib/plugin_compatibility_checker.php`, L206–L219)**
```php
$boundsSource = (($updateEntry['resolved_from_history'] ?? false) === true)
    ? $updateEntry
    : $branchTipEntry;
return self::validateRemotePluginForCore($coreVersion, self::boundsFromCatalogEntry($boundsSource));
```
- `testResolvedCompatibleEntryIsAllowedEvenWhenBranchTipIsIncompatible`: core `0.14`, tip `min_version=0.17`, resolved `0.13–0.16` → `compatible=true`, `violation=null`.
- `testResolvedEntryWhoseOwnMinBoundIsIncompatibleIsBlocked` / `…MaxBound…` → the resolved release's own bounds still gate (no over-permission).
- `testFallbackEntryUsesBranchTipBoundsExactlyAsToday` → fallback decision is byte-identical to `validateRemotePluginForCore(tip)`.

**Controller wiring (`controller/admin_updater.php`)**
- Public single path: `updateInstalledPlugin()` resolves `$resolvedEntry = findPublicUpdateByName(...)` (L967–971), then `assertRemotePluginCompatible($pluginName, $publicEntry, $coreVersion, $resolvedEntry)` (L973), then `$zipUrlOverride = resolvedHistoryZipOverride($resolvedEntry)` (L981) and `download((int) $publicEntry['id'], $zipUrlOverride)` (L988).
- Public batch path: `actionUpdateAllPublicPlugins()` (L715–790) filters pending via `enrichPluginUpdatesWithCoreCompatibility()` — which classifies using the update entry's own (resolved) min/max → `update_status = 'ready'` — then routes each name through `updateInstalledPlugin()` (L770). Same resolved-entry validation and override.
- Private path: `findPublicUpdateByName(...)` filtered to `source === 'private'` (L1015–1022), `assertRemotePluginCompatible(..., $resolvedEntry)` (L1029), `resolvedHistoryZipOverride` (L1033), `download_private($remote['id'], $zipUrlOverride)` (L1037).
- `assertRemotePluginCompatible()` (L1105–1144) forwards `$resolvedEntry` to `evaluateUpdateEntryForCore` and uses the resolved entry for the core-update hint when applicable.

**ZIP override reachability (`lib/plugin_downloader.php`)**
- `findPublicUpdateByName()` returns the resolved entry with `zip_link` + `resolved_from_history=true` — asserted by `testFindPublicUpdateByNameReturnsResolvedHistoryEntry`.
- `download($id, $zipUrlOverride)` uses the override when non-empty — asserted by `testDownloadUsesZipUrlOverrideWhenProvided`.
- `download_private($id, $zipUrlOverride)` uses the override when non-empty — asserted by `testDownloadPrivateUsesZipUrlOverrideWhenProvided`.

**Conclusion**: in the motivating scenario the plugin is listed, the guard now evaluates the resolved release's bounds and allows the update, and the resolved ZIP is passed down on both single/batch public and private paths. No CRITICAL remains.

## WARNING-1 Resolution Evidence (private path symmetry)

- `download_private($plugin_id, ?string $zipUrlOverride = null)` (L641) selects the override when non-empty, otherwise the catalog `zip_link`; the actual fetch goes through the new `fetchPrivateZipToFile()` seam (L709).
- The controller private branch now obtains the private resolved entry, validates its bounds, and forwards the resolved ZIP (see CRITICAL evidence above), so the private path no longer lists a resolved release while downloading the branch tip.
- Tests: `testPrivateHistoryBranchExposesResolvedRelease` (listing resolved bounds + zip), `testDownloadPrivateUsesZipUrlOverrideWhenProvided` (override honored), `testDownloadPrivateKeepsCatalogZipLinkWhenOverrideIsEmpty` (null/'' fallback).

## Security Review (URL-override path)

Audited per the FSFramework security checklist. No SQL injection, XSS, password, file-upload, CSRF, or open-redirect regressions were introduced. Mutating actions continue to require CSRF (`requireCsrf()` at the updater action handlers, including single L679 and batch L717). Findings on the changed URL path:

- **[WARNING — accepted follow-up]** The history-supplied `zip_url`/`zip_link` flows into `download()`/`download_private()` via `normalizeUrl()` (control-char/HTML-entity/space sanitization delegating to `fs_normalize_url`). There is no `https`-only or expected-host allowlist, so a compromised/malicious plugin repo could direct the admin-privileged updater at an arbitrary URL (SSRF/non-HTTPS). This is the same trust class as the pre-existing catalog `zip_link`, hence not a new boundary, but it is a new remote-controlled input. Explicitly declared out of scope by the orchestrator (deliberate follow-up).
- **[SUGGESTION — accepted follow-up]** `get_remote_plugin_releases()` interpolates `user`, `repo`, and `branch` into the GitHub raw/API URL without `rawurlencode`. The host is fixed (`raw.githubusercontent.com` / `api.github.com`), so this is not host-injection SSRF, but crafted values could inject path/query/fragment segments. Explicitly declared out of scope.

## Issues Found

**CRITICAL**: None.

**WARNING**:

1. **Controller glue has no automated harness.** The compatibility decision is now a directly-tested pure function (`evaluateUpdateEntryForCore`), which removes the prior materiality of this gap; however, the controller wiring that supplies the resolved entry and the override is verified by inspection only. No `fs_controller`/integration harness exists in the suite. Non-blocking: the risk is concentrated in a tested pure decision plus two thin, inspected call sites.
2. **Scope nuance — skill edit outside the plugin tree is related to this change.** `git status` in the parent shows `base/fs_plugin_manager.php` and `controller/admin_home.php` modified. These do **not** belong to this SDD: they touch a different semver theme, are not referenced by any change artifact, and predate the change directory (mtime 13:13/13:14 vs the change at 13:51). Separately, `.opencode/skills/fsframework-milestone-release/SKILL.md` (+ `.cursor` mirror) **does** reference `plugin-compatible-update-resolver` by name and documents Decision #6 (append a `releases.json` entry at release time), so it is a related supporting-doc edit outside the plugin, not unrelated session work. Design listed the skill as "No (referencia)"; record this as a documented scope deviation. It is documentation-only and non-blocking.

**SUGGESTION**:

1. **`catalog_id` download reference can silently point at the branch tip.** `resolveHistoryDownloadReference()` falls back to the catalog row's `zip_link` by loose `==` id match (L1036). In the current one-row-per-plugin catalog that `zip_link` is the branch archive, not the historical tag. Prefer `zip_url`; skip (do not list) when only a tip-bound `catalog_id` is available. Declared out of scope (deliberate follow-up).
2. **Extra `releases.json` fetch per catalog entry** (cached 180 s for public). Output is unchanged for plugins without history; consider fetching lazily only when the branch-tip update is blocked by core compatibility.
3. **Stale note in `tasks.md` T12** still records the prior `FAIL` verdict. `verify-report.md` is the authoritative current verdict (`pass_with_warnings`); the task note is historical and was intentionally left untouched per verify scope.
4. **Pre-existing PHPUnit deprecations.** 12 doc-comment metadata deprecations (unrelated) — migrate to attributes before PHPUnit 12.

## Verdict

**PASS WITH WARNINGS** — the prior CRITICAL and both coherence warnings are resolved with fresh test and source evidence: the resolved historical release is now allowed by the compatibility guard and its ZIP override is reachable on public single, public batch, and private paths; the private list/download halves agree; PU-14 byte-identical fallback and PU-11..PU-15 coverage remain intact; suite green (208/411, exit 0). No blockers, no CRITICAL findings. The remaining warnings are non-blocking (controller glue inspected rather than harness-tested; one related documentation/skill touch outside the plugin tree) and the URL-hardening items are accepted out-of-scope follow-ups. **Archive-ready: yes.**

---
*Evidence: plugin suite 208 tests / 411 assertions / 0 failures / exit 0 (hash sha256:a0e5ca…6df3), lint clean on 8 files (hash sha256:037a9e…68c5), combined evidence revision sha256:0fe695…dc2d, corrected-focus testdox 23 tests / 58 assertions all pass, spec totals 5 requirements / 15 scenarios. Plugin repo change is uncommitted; no commit/tag/push performed per instructions.*
