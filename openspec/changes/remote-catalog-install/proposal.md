# Proposal: Instalación desde catálogo público (system_updater)

## Intent

Extender `system_updater` para implementar `PluginInstallProvider` del core y descargar plugins ausentes desde `data/custom_plugins.json` / [fs-cusmtom-plugins](https://github.com/eltictacdicta/fs-cusmtom-plugins) antes de la activación en cascada.

## Decisiones acordadas

- Bootstrap: si hace falta descargar del catálogo y `system_updater` no está en disco → usar `PluginInstaller` del core primero
- Solo plugins del **catálogo público** (no privados como `api_base`, `tarifario`)
- Deps privadas **ya instaladas** satisfacen el requisito (core las activa; no descarga)

## Scope

### In Scope

- `CatalogPluginInstallProvider` implements `FSFramework\Core\Plugin\PluginInstallProvider`
- Registro en `Init.php` al cargar el plugin
- Reutilizar `plugin_downloader::download()` por nombre/id del catálogo
- Lookup por `nombre` en catálogo local + remoto (merge existente)
- Bootstrap `system_updater` vía `PluginInstaller` si provider necesita downloader pero plugin ausente
- Tests con catálogo fixture (sin red)

### Out of Scope

- Descarga de plugins privados (pestaña privada existente)
- Modificar catálogo JSON (change anterior ya hecho)

## Affected Areas

| File | Impact |
|------|--------|
| `lib/CatalogPluginInstallProvider.php` | New |
| `Init.php` | Register provider |
| `tests/CatalogPluginInstallProviderTest.php` | New |

## Success Criteria

- [ ] Orchestrator puede descargar `catalogo_core` desde catálogo mock e instalar en `plugins/`
- [ ] `api_base` no en catálogo → `installIfAvailable` returns false
- [ ] Sin system_updater en disco: `PluginInstaller` lo instala antes del primer download
- [ ] Provider registrado solo cuando system_updater está cargado

## Dependencies

- Requires: core change `plugin-cascade-activation` (interface + registry)
