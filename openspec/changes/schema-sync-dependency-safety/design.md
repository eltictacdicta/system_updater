# Design: schema-sync-dependency-safety

## Technical Approach

Fix the three production root causes (invisible XMLs, alphabetical batch order, unresolvable deps) plugin-locally, with zero core changes:

1. **`PluginUpdateOrderer`** — pure, static dependency-first batch orderer over a name list + requirements callable.
2. **`PluginSchemaResyncer`** — snapshot/append/restore of `$GLOBALS['plugins']` around `applyPluginSchemaUpdates`, plus a shared `withDependencyVisibility()` helper reused by the update flows.
3. **Controller/view** — `resync_plugin_schema` action (PU-10) and dependency ordering + in-memory visibility in both batch actions (PU-04/PU-08/PU-09).

`$GLOBALS['plugins']` is built once per request (config2.php:116) and `fs_model::get_base_dir` (fs_model.php:499) caches a failed lookup in a per-request static — so visibility MUST be active before any model instantiation, and restored after.

## Architecture Decisions

| Decision | Option / Tradeoff | Decision |
|---|---|---|
| **A1 — Orderer algorithm** | DFS (like core `PluginDependencyResolver`, throws on cycle) vs **Kahn's algorithm** (batch-friendly, natural cycle-member detection) | Kahn. Batch has multiple roots; leftover unprocessed nodes = cycle members → append in original batch order, log one `error_log` warning. Self-require edges ignored (no ordering constraint). |
| **A2 — Orderer API** | Instance with provider injected vs **static pure function with injectable callables** | Static `order(array $names, callable $requirementsFn, ?callable $isInstalledFn = null): array`. Defaults wrap `CatalogPluginInstallProvider` (lazy, `require_once` + `new` when `FS_FOLDER` defined, else `[]`) and `is_dir(FS_FOLDER.'/plugins/'.$name)` (else `true`). Tests inject fakes; production works untouched. |
| **A3 — Missing/unknown deps** | Fail batch vs **skip silently** | Non-installed deps excluded from the graph (spec: "skip missing without blocking"). Unknown batch members (no ini, no catalog entry) are leaves → ordered normally. |
| **A4 — Dep-visibility point** | Inside `plugin_downloader::syncPluginDatabaseSchema` (covers store installs too, but mutates shared code used by `admin_plugin_store`) vs **inside `admin_updater::updateInstalledPlugin` around `download()`/`download_private()`** | `updateInstalledPlugin` — the single choke point of ALL updater paths (single, batch, chained). `plugin_downloader.php` untouched; store blast radius zero; visibility stays active through `download() → syncPluginDatabaseSchema()` (plugin_downloader.php:321/639). Implemented via `PluginSchemaResyncer::withDependencyVisibility()`. |
| **A5 — Batch sequencing** | Order only batch members vs **order batch ∪ installed transitive deps and attempt all** | Full sequence (spec PU-04: deps included even when not in batch). Deps are re-installed (same version) then schema-synced — heals the "tables never created" bug. Failures collected in `updated`/`failed`; loop never breaks. |
| **A6 — Resync exceptions** | Catch and swallow vs **restore in `finally`, re-throw** | Restore + re-throw (PU-09 requires restore, not swallow). Controller `try/catch` → JSON `{success:false, message}`. |

## Data Flow

```
Batch action (public/chained)
  names (ready-filtered) ──► PluginUpdateOrderer::order(names, req, installed)
        ──► [depB, depA, ...] ──► foreach: updateInstalledPlugin(name)
              └─► withDependencyVisibility(name, fn)          [snapshot $GLOBALS['plugins']]
                    └─► download() → syncPluginDatabaseSchema → applyPluginSchemaUpdates(name)
                          └─► PluginSchemaSynchronizer → check_table reads $GLOBALS['plugins']  [deps visible]
              finally: restore $GLOBALS['plugins']

Manual resync (PU-10)
  actionResyncPluginSchema (requireCsrf, &plugin=X?) ──► PluginSchemaResyncer::resyncInstalled($manager, $only)
        ──► order(targets) ──► snapshot → foreach: applyPluginSchemaUpdates(name) → restore (finally)
        ──► JSON {success, updated, failed, messages}
```

## File Changes

| File | Action | Description |
|---|---|---|
| `lib/PluginUpdateOrderer.php` | Create | Static Kahn orderer; default requirements/installed callables. |
| `lib/PluginSchemaResyncer.php` | Create | `resyncInstalled()` + static `withDependencyVisibility()`; snapshot/append/restore. |
| `controller/admin_updater.php` | Modify | `case resync_plugin_schema`; order both batch actions; wrap `download()` calls in `updateInstalledPlugin` with visibility. |
| `view/admin_updater.html.twig` | Modify | "Re-sincronizar esquema" button + AJAX handler. |
| `tests/PluginUpdateOrdererTest.php` | Create | TDD, no DB. |
| `tests/PluginSchemaResyncerTest.php` | Create | TDD, no DB, fake manager. |

## Interfaces / Contracts

```php
final class PluginUpdateOrderer {
    /** @param list<string> $pluginNames @return list<string> deps-first, batch ∪ installed transitive deps */
    public static function order(array $pluginNames, callable $requirementsFn, ?callable $isInstalledFn = null): array;
}

final class PluginSchemaResyncer {
    /** @return array{success: bool, updated: list<string>, failed: list<string>, messages: list<string>, results: array<string, array{success: bool, changes: list<string>, errors: list<string}>} */
    public static function resyncInstalled(\fs_plugin_manager $manager, ?string $only = null, ?callable $requirementsFn = null, ?callable $isInstalledFn = null): array;
    /** snapshot + append order([$pluginName]) + restore (finally); returns callback result */
    public static function withDependencyVisibility(string $pluginName, callable $callback, ?callable $requirementsFn = null, ?callable $isInstalledFn = null): mixed;
}
```

Controller action: `case 'resync_plugin_schema'` → `actionResyncPluginSchema()` following the `actionUpdateChainedPlugins` JSON pattern (:786-873): `header(JSON)`, `requireCsrf()` (JS sends `_csrf_token`), optional `&plugin=X` (POST then GET), `echo json_encode([...], JSON_UNESCAPED_UNICODE); exit;`, `try/catch (\Throwable)` for re-thrown resync exceptions.

## Testing Strategy

| Layer | Cases | Approach |
|---|---|---|
| Unit — Orderer | empty → `[]`; single no-deps → `[A]`; direct dep → `[B,A]`; transitive → `[C,B,A]`; dep not in batch included; missing dep skipped (isInstalledFn=false); **cycle** A↔B → no throw, `[A,B]` (original order), warning logged; **self-require** → `[A]`; unknown plugin → `[A]`; stable relative order of independent roots | Pure callables, no DB, `Tests\SystemUpdater` namespace, `require_once FS_FOLDER.'/plugins/system_updater/lib/PluginUpdateOrderer.php'` |
| Unit — Resyncer | resync all: fake `installed()` → recording fake manager asserts call order B,A and that `$GLOBALS['plugins']` contains name+deps during each call; restored after; `$only='A'` → `[B,A]`; failure captured (`failed=['C']`, messages has error); **fake throws → snapshot restored (finally), exception propagates**; `withDependencyVisibility` adds/restores on success AND exception | Anonymous `fs_plugin_manager` subclass with empty constructor (AGENTS.md pattern), overridden `installed()`/`applyPluginSchemaUpdates()` |
| Controller smoke | **Not feasible** without DB (`fs_controller` needs session/DB); controller stays thin — logic lives in lib classes above | `N/A` |

Runner: `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` (bootstrap `tests/bootstrap.php`, no DB). Existing suite must stay green.

## Threat Matrix

`N/A` — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary is added or changed. ZIP download/extraction is pre-existing unchanged behavior.

## Migration / Rollout

No migration. Opt-in resync button (never automatic); resync only creates/migrates tables (idempotent via `createTable` tolerance). Rollback: revert commit in plugin repo, redeploy via manual zip/self-update. No core files touched (`git status` in core repo clean).

## Commit Units

| # | Commit | Contents |
|---|---|---|
| 1 | `feat(system_updater): add dependency-first plugin update orderer` | `lib/PluginUpdateOrderer.php` + `tests/PluginUpdateOrdererTest.php` |
| 2 | `feat(system_updater): add dependency-safe schema resyncer` | `lib/PluginSchemaResyncer.php` + `tests/PluginSchemaResyncerTest.php` |
| 3 | `feat(system_updater): order batch updates by dependency and add manual schema re-sync` | `controller/admin_updater.php` + `view/admin_updater.html.twig` |
| 4 | `docs(system_updater): record schema sync dependency safety SDD` | `openspec/changes/schema-sync-dependency-safety/` (proposal, spec, design, tasks) |

Each unit is independently testable (tests ride with code) and rollback-safe. No Composer dependency → no `vendor/` commit. All commits in `plugins/system_updater` repo (master).

## Open Questions

- None blocking. (Note: single-plugin resync is URL-driven only (`&plugin=X`) in v1; the "all" button is the primary UI. Per-row buttons are a trivial follow-up if wanted.)