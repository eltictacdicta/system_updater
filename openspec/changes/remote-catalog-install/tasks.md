# Tasks: remote-catalog-install (system_updater)

## Wave 1 — Provider (TDD)

- [ ] **T1** `tests/CatalogPluginInstallProviderTest.php` — catalog hit/miss, isInstalled (RED)
- [ ] **T2** `lib/CatalogPluginInstallProvider.php` (GREEN)
- [ ] **T3** Test bootstrap system_updater with mocked PluginInstaller

## Wave 2 — Wiring

- [ ] **T4** Register in `Init.php` via `PluginInstallProviderRegistry`
- [ ] **T5** `plugin_downloader`: add `findByName(string $name): ?array` if needed

## Wave 3 — Integration

- [ ] **T6** E2E test: orchestrator + real provider + fixture catalog (temp plugins dir)
- [ ] **T7** `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml`
- [ ] **T8** Manual: activar `factura_pdf1` sin deps desde tienda
- [ ] **T9** verify-report.md

## Dependencies

- Requires: core `plugin-cascade-activation` Wave 1–2 (interfaces + registry)
