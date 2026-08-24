# Proposal: schema-sync-dependency-safety

## Intent

Fix production bug when updating plugins via system_updater from an older version. Three root causes:

1. **Missing XML → tables never created.** `fs_model::get_base_dir` (base/fs_model.php:499-513) resolves table XMLs only against `$GLOBALS['plugins']`, which is built ONCE at request start (base/config2.php:116-128) from the enabled list. Installed-but-disabled plugins (or deps whose dirs were replaced mid-update) are invisible, so `check_table` fails with `Archivo model/table/X.xml no encontrado.` + `Error con el xml.`.
2. **Inline FKs fail with errno 150.** `check_table` → `generate_table` emits FKs inline without checking the referenced table exists (fs_model.php:277-316, retryTableCheck:368), while `fs_schema::createTable` IS defensive (omits FK when referenced table missing, fs_schema.php:412-440). Inconsistent paths.
3. **Batch order is alphabetical, not dependency-first.** `plugin_downloader::getAvailableUpdates` sorts by name (plugin_downloader.php:825 usort); no topological ordering exists in the update flow (actionUpdateAllPublicPlugins:714, actionUpdateChainedPlugins:786).

Goal: make schema sync dependency-safe and re-runnable, plugin-local, no core changes.

## Scope

### In Scope

- `lib/PluginUpdateOrderer.php` (NEW) — pure dependency-first topological orderer for a plugin batch. Requirements via `CatalogPluginInstallProvider::getDirectRequirements` (local ini first through `LocalPluginRequirementsReader`, catalog fallback). Cycle → log warning + keep original order for cycle members; missing deps ignored.
- `lib/PluginSchemaResyncer.php` (NEW) — `resyncInstalled(fs_plugin_manager $manager, ?string $only = null): array`: snapshot `$GLOBALS['plugins']`, append each plugin + installed transitive deps in-memory, call `applyPluginSchemaUpdates($name)` in dependency order, restore snapshot; returns `{success, updated, failed, messages}`.
- `controller/admin_updater.php` — new case `resync_plugin_schema` in `processActions` (216-297) → `actionResyncPluginSchema()` with `requireCsrf()` + JSON response (pattern at 788-872). In `actionUpdateAllPublicPlugins` and `actionUpdateChainedPlugins`: order the ready-filtered list via `PluginUpdateOrderer::order(...)`; before each `updateInstalledPlugin($name)` ensure deps are in-memory-added to `$GLOBALS['plugins']` so `syncPluginDatabaseSchema` (plugin_downloader.php:1134) resolves their XMLs.
- `view/admin_updater.html.twig` — "Re-sincronizar esquema" button → AJAX POST with `_csrf_token` (model on :482 / `updateChainedPlugins` JS).
- Tests (strict TDD, `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml`): `tests/PluginUpdateOrdererTest.php`, `tests/PluginSchemaResyncerTest.php` — anonymous subclasses / fake requirement maps, no DB (pattern: PluginDownloaderUpdatesTest.php).
- Spec delta: `plugin-updates` (PU-01..PU-08) gains requirements for dependency-first batch ordering and manual schema re-sync.

### Out of Scope

- **NO core changes.** Defensive FK in `fs_model`/`generate_table` is a future core change (explicitly deferred).
- No persist-enable of dependencies (D1).
- No catalog `require` display enrichment unless trivial.
- No change to single-plugin update flow beyond the dep in-memory add.

## Capabilities

> Contract for sdd-spec.

### New Capabilities

None — `PluginUpdateOrderer` / `PluginSchemaResyncer` are implementation classes, not new spec domains.

### Modified Capabilities

- `plugin-updates`: extend with (a) dependency-first ordering for batch/chained updates (amends PU-04, PU-08), (b) new manual schema re-sync requirement (resync action must resolve installed deps and report per-plugin results).

## Approach

1. Orderer is a pure function over a name list + requirements resolver → fully unit-testable without DB.
2. Resyncer reuses existing idempotent/self-healing behavior: `createTable` tolerates existing tables (fs_schema.php:150-152), `check_table` failures are NOT cached (fs_model.php:132-135), `forgetCheckedTables` exists (:444).
3. Both batch update actions gain in-memory dep visibility, so `applyPluginSchemaUpdates` / `check_table` resolve dep XMLs regardless of enable state.
4. Manual resync = admin self-service fix for the "tables never created" failure, with per-plugin JSON results.

## Design Decisions

- **D1 — In-memory enable only, never persist-enable.** Snapshot + append + restore `$GLOBALS['plugins']`. Persist-enable has wizard/Init/save side effects and survives failed updates. Note: `runInitMigrations` (PluginSchemaSynchronizer.php:57) still runs dep `Init::update()` — acceptable, same as enable.
- **D2 — Batch orderer semantics.** Include installed transitive deps in the sync sequence even when not in the update batch; cycle fallback keeps original order for cycle members; missing deps skipped silently.
- **D3 — Resync scope.** All installed plugins by default; optional `&plugin=X` for a single one.
- **D4 — Non-goals.** No core changes; no catalog require display enrichment unless trivial.
- **D5 — Delivery.** Commit to plugins/system_updater repo (master); production deploys via system_updater self-update (`update_zip_url` master.zip) or manual zip. No core release.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `plugins/system_updater/lib/PluginUpdateOrderer.php` | New | Dependency-first topological orderer |
| `plugins/system_updater/lib/PluginSchemaResyncer.php` | New | In-memory dep-enabled schema re-sync |
| `plugins/system_updater/controller/admin_updater.php` | Modified | `resync_plugin_schema` action; ordering + dep visibility in both batch actions |
| `plugins/system_updater/view/admin_updater.html.twig` | Modified | Re-sync button + AJAX handler |
| `plugins/system_updater/tests/PluginUpdateOrdererTest.php`, `tests/PluginSchemaResyncerTest.php` | New | TDD coverage, no DB |
| `plugins/system_updater/openspec/specs/plugin-updates/spec.md` | Modified | Delta merged at archive |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Cycle in require graph | Low | Warning + original order for cycle members; tests cover cycle |
| `$GLOBALS['plugins']` snapshot restore missed on exception | Med | try/finally restore; resync isolated from update flow |
| Dep Init::update() side effects during resync | Med | Same as enable semantics (D1); resync reports per-plugin so failures surface |
| Batch update slows with dep ordering | Low | Ordering is O(n·deps) in-memory; no extra I/O beyond requirements reads |

## Rollback Plan

- Revert commit in plugins/system_updater repo; deploy previous version via manual zip or self-update.
- No schema is modified destructively: resync only creates/migrates tables; a failed resync leaves DB untouched beyond what `applyPluginSchemaUpdates` already did.
- Resync is opt-in (button), never automatic → worst case is a reported failure with no side effects.

## Dependencies

- `FSFramework\Core\Plugin\LocalPluginRequirementsReader` (core, read-only usage — existing).
- `fs_plugin_manager::applyPluginSchemaUpdates` (base/fs_plugin_manager.php:767 — existing).
- `CatalogPluginInstallProvider::getDirectRequirements` (existing, plugin-local).

## Success Criteria

- [ ] Batch/chained updates install deps first and never log `Archivo model/table/X.xml no encontrado` / `Error con el xml.` for a dependency with a valid XML.
- [ ] Resync action returns per-plugin `{success, updated, failed, messages}` JSON and creates tables for previously-installed-but-never-synced plugins.
- [ ] `PluginUpdateOrdererTest` + `PluginSchemaResyncerTest` pass (no DB), covering order, cycles, missing deps, snapshot restore.
- [ ] Existing system_updater suite stays green (`ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml`).
- [ ] Zero core files modified (`git status` in core repo clean for this change).