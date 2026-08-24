# Tasks: Schema Sync Dependency Safety

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 650–800 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR1 orderer → PR2 resyncer → PR3 controller+view → PR4 docs |
| Delivery strategy | ask-on-risk |
| Chain strategy | stacked-to-main |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Dependency-first orderer + tests | PR 1 | `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml --filter PluginUpdateOrdererTest` | N/A — pure static fn over injected callables, no DB | Revert `lib/PluginUpdateOrderer.php` + `tests/PluginUpdateOrdererTest.php` |
| 2 | Dependency-safe schema resyncer + tests | PR 2 | `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml --filter PluginSchemaResyncerTest` | N/A — fake `fs_plugin_manager` subclass, no DB | Revert `lib/PluginSchemaResyncer.php` + `tests/PluginSchemaResyncerTest.php` |
| 3 | Controller resync action + batch ordering + view | PR 3 | `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` (suite green; controller smoke N/A — needs DB) | Manual: admin_updater → "Re-sincronizar esquema" → per-plugin JSON | Revert `controller/admin_updater.php` + `view/admin_updater.html.twig` |
| 4 | SDD docs | PR 4 | N/A — docs only | N/A — docs only | Revert `openspec/changes/schema-sync-dependency-safety/` |

## Phase 1: PluginUpdateOrderer (unit 1)

- [x] 1.1 RED — Create `tests/PluginUpdateOrdererTest.php` (`Tests\SystemUpdater`, `require_once FS_FOLDER.'/plugins/system_updater/lib/PluginUpdateOrderer.php'`, pure callables): empty→`[]`; single→`[A]`; direct dep→`[B,A]`; transitive→`[C,B,A]`; dep-not-in-batch included; missing dep skipped (`isInstalledFn=false`); cycle A↔B→no throw, `[A,B]`, `error_log` warning; self-require→`[A]`; unknown→`[A]`; stable order of independent roots. Run `--filter PluginUpdateOrdererTest` → fail (class missing).
- [x] 1.2 GREEN — Create `lib/PluginUpdateOrderer.php`: `final class PluginUpdateOrderer`, static `order(array $pluginNames, callable $requirementsFn, ?callable $isInstalledFn = null): array` (Kahn's algorithm); defaults wrap `CatalogPluginInstallProvider::getDirectRequirements` (lazy `require_once`+`new` when `FS_FOLDER` defined, else `[]`) and `is_dir(FS_FOLDER.'/plugins/'.$name)` (else `true`); self-require ignored; leftover cycle members appended in original order + one `error_log` warning; missing deps excluded. Run filter → green.
- [x] 1.3 REFACTOR — Extract graph/cycle helpers; re-run filter → green. Commit: `feat(system_updater): add dependency-first plugin update orderer`.

## Phase 2: PluginSchemaResyncer (unit 2)

- [x] 2.1 RED — Create `tests/PluginSchemaResyncerTest.php` (anonymous `fs_plugin_manager` subclass, empty constructor; recording fake overrides `installed()`/`applyPluginSchemaUpdates()`): resync all → call order B,A + `$GLOBALS['plugins']` contains name+deps during each call + restored after; `$only='A'`→`[B,A]`; failure captured (`failed=['C']`, messages has error); fake throws → snapshot restored (finally) + exception propagates; `withDependencyVisibility` adds/restores on success AND exception. Run `--filter PluginSchemaResyncerTest` → fail.
- [x] 2.2 GREEN — Create `lib/PluginSchemaResyncer.php`: `final class PluginSchemaResyncer`, static `resyncInstalled(\fs_plugin_manager $manager, ?string $only = null, ?callable $requirementsFn = null, ?callable $isInstalledFn = null): array{success, updated, failed, messages, results}` (order targets → snapshot → foreach `applyPluginSchemaUpdates` → restore in `finally`); static `withDependencyVisibility(string $pluginName, callable $callback, ?callable $requirementsFn = null, ?callable $isInstalledFn = null): mixed` (snapshot `$GLOBALS['plugins']`, append `order([$pluginName])`, restore in `finally`, re-throw). Run filter → green.
- [x] 2.3 REFACTOR — tidy snapshot/restore; re-run filter → green. Commit: `feat(system_updater): add dependency-safe schema resyncer`.

## Phase 3: Controller + view (unit 3)

- [x] 3.1 Modify `controller/admin_updater.php` `processActions` (216-297): add `case 'resync_plugin_schema'` → `actionResyncPluginSchema()` — `header` JSON, `requireCsrf()`, optional `&plugin=X` (POST then GET), `PluginSchemaResyncer::resyncInstalled($this->plugin_manager, $only)`, `echo json_encode([...], JSON_UNESCAPED_UNICODE); exit;`, `try/catch (\Throwable)` → `{success:false, message}` (pattern :786-873).
- [x] 3.2 Modify `actionUpdateAllPublicPlugins` (714) and `actionUpdateChainedPlugins` (786): order ready-filtered names via `PluginUpdateOrderer::order(...)`; wrap `download()`/`download_private()` inside `updateInstalledPlugin` with `PluginSchemaResyncer::withDependencyVisibility($name, fn)` (A4/A5).
- [x] 3.3 Modify `view/admin_updater.html.twig`: "Re-sincronizar esquema" button → AJAX POST with `_csrf_token` (model on :482 / `updateChainedPlugins` JS); render per-plugin JSON results.
- [x] 3.4 Run full plugin suite → green (controller smoke N/A — `fs_controller` needs DB). Commit: `feat(system_updater): order batch updates by dependency and add manual schema re-sync`.

## Phase 4: Docs + final verification (unit 4)

- [x] 4.1 Commit SDD artifacts: `docs(system_updater): record schema sync dependency safety SDD` (`openspec/changes/schema-sync-dependency-safety/`).
- [x] 4.2 VERIFY — Full plugin suite green: `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml`.
- [x] 4.3 VERIFY — Core suite not broken: `ddev exec php vendor/bin/phpunit --testsuite Base`.
- [x] 4.4 VERIFY — Zero core files modified: `git status` in core repo shows no changes outside `plugins/system_updater/`.