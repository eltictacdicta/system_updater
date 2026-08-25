# Tasks: public-plugin-updates-max-version

## Wave 1 — TDD compatibilidad

- [ ] **T1** `tests/PluginCompatibilityCheckerTest.php` (RED)
- [ ] **T2** `lib/plugin_compatibility_checker.php` (GREEN)
- [ ] **T3** Core: `max_version` en `fs_plugin_manager::applyPluginCompatibility`

## Wave 2 — TDD actualizaciones públicas

- [ ] **T4** `tests/PluginDownloaderUpdatesTest.php` — detección por versión (RED/GREEN)
- [ ] **T5** `plugin_downloader::getAvailableUpdates()`, `findPublicEntryByName()`
- [ ] **T6** `admin_updater`: checkUpdates merge + actionUpdatePlugin público + batch

## Wave 3 — UI y warnings

- [ ] **T7** `admin_plugin_store`: botón Actualizar
- [ ] **T8** `operation_warnings` con contexto core + JS wizard
- [ ] **T9** Twig: tabla plugins, batch, tienda

## Wave 4 — Verify

- [ ] **T10** `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml`
- [ ] **T11** verify-report.md
