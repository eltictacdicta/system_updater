```yaml
schema: gentle-ai.archive-result/v1
change: plugin-compatible-update-resolver
plugin: system_updater
ownership: plugin-local
archived_at: 2026-09-10
final_verdict: pass_with_warnings
final_suite: 209 tests / 414 assertions / 0 failures
requirements_added: PU-11..PU-15
core_openspec_entry: none
```

# Archive Report — plugin-compatible-update-resolver

**Change**: `plugin-compatible-update-resolver`
**Plugin**: `system_updater` (own git repo, ownership `plugin-local`)
**Archive path**: `plugins/system_updater/openspec/changes/archive/2026-09-10-plugin-compatible-update-resolver/`
**Canonical spec synced**: `plugins/system_updater/openspec/specs/plugin-updates/spec.md` (PU-01..PU-10 unchanged; PU-11..PU-15 appended)
**Commit/tag/push**: none performed (per instruction).

## Closing Summary

The change was verified as `pass_with_warnings` (0 blockers, 0 CRITICAL findings, 15/15 scenarios, archive-ready: yes). The prior CRITICAL (compatibility guard evaluated the branch tip instead of the resolved release) and both coherence warnings were already resolved before the verify pass. Since the verify pass, three post-verify fixes landed (see below) and the suite grew from 208/411 to the final **209 tests / 414 assertions / 0 failures**. The change is archived; the plugin canonical spec is the source of truth for PU-01..PU-15.

## Pre-archive Checks

| # | Check | Result |
|---|-------|--------|
| 1 | All artifacts present (`proposal.md`, `specs/plugin-updates/spec.md`, `design.md`, `tasks.md`, `verify-report.md`) | ✅ Present |
| 2 | `verify-report.md` has no CRITICAL findings | ✅ `critical_findings: 0`, `blockers: 0`, `verdict: pass_with_warnings`, archive-ready: yes |
| 3 | `tasks.md` has no unchecked implementation tasks | ✅ All T1..T13 `[x]`; 0 unchecked |
| 4 | No entry under core `openspec/changes/` for this change | ✅ Not present (anti-pattern avoided) |
| 5 | Final suite green | ✅ 209 tests / 414 assertions / 0 failures; 2 warnings / 12 deprecations / 1 skip (pre-existing baseline) |

## Requirement Coverage (PU-11..PU-15)

| Requirement | Title | Scenarios | Result |
|-------------|-------|-----------|--------|
| PU-11 | Historial de versiones append-only por plugin | 3/3 | ✅ COMPLIANT |
| PU-12 | Resolver puro de la última versión compatible | 7/7 | ✅ COMPLIANT |
| PU-13 | Cableado del actualizador al resolver por historial | 2/2 | ✅ COMPLIANT |
| PU-14 | Fallback retrocompatible a la punta de rama | 1/1 | ✅ COMPLIANT |
| PU-15 | Sin auto-downgrade ni resolución del grafo de dependencias | 2/2 | ✅ COMPLIANT |
| **Total** | | **15/15** | ✅ |

Test evidence per requirement is unchanged from `verify-report.md` (Spec Compliance Matrix). Key anchors:

- PU-11: `PluginDownloaderUpdatesTest::testHistoryBranchExposesResolvedReleaseBoundsAndZip`, `PluginDownloaderTest::testGetRemotePluginReleasesParsesPublicRawHistory`, `PluginDownloaderUpdatesTest::testCatalogVersionsArrayActsAsEquivalentHistory`.
- PU-12: `PluginCompatibilityCheckerResolverTest` (14 cases), including determinism/no-I/O.
- PU-13: `PluginDownloaderUpdatesTest::testHistoryBranchExposesResolvedReleaseBoundsAndZip`, `testNoUpdateListedWhenResolverReturnsNull`.
- PU-14: `PluginDownloaderUpdatesTest::testNoHistoryKeepsBranchTipBehavior` (`assertSame` full-array).
- PU-15: `testNoDowngradeWhenOnlyCompatibleReleaseIsNotNewer` + all PU-12 no-downgrade tests; graph resolution intentionally absent.

## Final Suite Result

Command: `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml`

```text
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.3.33
Configuration: /var/www/html/plugins/system_updater/phpunit.xml

..............................................W................  63 / 209 ( 30%)
............................................................... 126 / 209 ( 60%)
............................................................... 189 / 209 ( 90%)
.............S......                                            209 / 209 (100%)

Time: 00:04.137, Memory: 10.00 MB
OK, but there were issues!
Tests: 209, Assertions: 414, Warnings: 2, PHPUnit Deprecations: 12, Skipped: 1.
```

Exit code 0. The 2 warnings / 12 deprecations / 1 skip are the pre-existing baseline, not introduced by this change.

Delta vs `verify-report.md`: the verify pass recorded **208 tests / 411 assertions**; this archive run records **209 / 414** because of the post-verify fix (c) below (one new non-scalar-bounds test). No failures.

## Post-verify Fixes (landed AFTER the verify pass)

These changes are part of the archived candidate and are the reason the final suite is 209/414 instead of 208/411. They are recorded here because they postdate the verify evidence revision.

1. **CodeRabbit fix (a) — remote I/O limited to installed plugins.**
   `get_remote_plugin_releases()` is now called **only for installed catalog entries**, in both hydration paths:
   - public catalog: `lib/plugin_downloader.php` L240 — `if (!empty($downloadList[$key]['instalado']) && !$this->entryHasReleaseHistory(...))`
   - private catalog: `lib/plugin_downloader.php` L611 — `if (!empty($this->private_download_list[$key]['instalado']) && !$this->entryHasReleaseHistory(...))`

   Non-installed plugins no longer trigger a `releases.json` remote fetch during list hydration.

2. **CodeRabbit fix (b) — tolerant bound normalization.**
   `plugin_compatibility_checker::normalizeBounds()` now routes `min_version`/`max_version` through `stringOrEmpty()` (`lib/plugin_compatibility_checker.php` L63–L64; helper at L471). Non-scalar values (e.g. arrays/objects from malformed history) are coerced to `''` instead of triggering warnings/type errors.

3. **New unit test for non-scalar bounds.**
   `PluginCompatibilityCheckerTest::testNormalizeReleaseHistoryCoercesNonScalarBoundsToEmpty()` (tests/PluginCompatibilityCheckerTest.php L302) covers fix (b). This is the +1 test / +3 assertions vs the verify run.

4. **CodeRabbit re-review**: returned **0 findings** after fixes (a)–(c).

## Accepted Follow-ups (out of scope, non-blocking)

- **`catalog_id` tip ambiguity guard**: `resolveHistoryDownloadReference()` can fall back to the catalog row's tip-bound `zip_link` via loose `==` id match. Prefer `zip_url`; skip listing when only a tip-bound `catalog_id` is available. Declared out of scope.
- **HTTPS/host allowlist and `rawurlencode` hardening for history-supplied ZIP URLs**: the history `zip_url`/`zip_link` flows into `download()`/`download_private()` without an https-only or expected-host allowlist, and `get_remote_plugin_releases()` interpolates `user`/`repo`/`branch` without `rawurlencode`. Same trust class as the pre-existing catalog `zip_link`; not a new boundary. Declared out of scope.

## Explicit Confirmations

- ✅ **No core `openspec/` entry** was created for this change (plugin-local ownership respected; anti-pattern avoided).
- ✅ **Delta specs synced** to the plugin canonical spec: PU-11..PU-15 (and their scenarios) appended after PU-10 in `plugins/system_updater/openspec/specs/plugin-updates/spec.md`; PU-01..PU-10 untouched, no renumbering or duplication.
- ✅ **Backward compatibility (PU-14 fallback) intact**: plugins without version history keep the exact branch-tip behavior, asserted by `testNoHistoryKeepsBranchTipBehavior` (full-array `assertSame`).
- ✅ **No implementation code modified** during archive; only the canonical spec was updated and the change dir moved.
- ✅ **No commit/tag/push** performed.

## Archived Artifacts

- `proposal.md`
- `specs/plugin-updates/spec.md` (delta)
- `design.md`
- `tasks.md` (T1..T13 all `[x]`)
- `verify-report.md` (verdict `pass_with_warnings`, archive-ready: yes)
- `archive-report.md` (this file)

---
*Archive produced by the `sdd-archive` executor under the `fsframework-plugin-sdd` plugin workflow. Final suite evidence: 209 tests / 414 assertions / 0 failures / exit 0. Canonical spec now holds PU-01..PU-15.*
