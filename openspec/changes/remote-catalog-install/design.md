# Design: remote-catalog-install

## CatalogPluginInstallProvider

El provider vive en `system_updater` y **no** bootstrapea el propio plugin. Solo:

1. Comprueba `isInstalled()`
2. Busca la entrada en el catálogo (`plugin_downloader->downloads()`)
3. Descarga si existe entrada válida

```php
public function installIfAvailable(string $pluginName): bool
{
    if ($this->isInstalled($pluginName)) {
        return true;
    }

    $entry = $this->findCatalogEntry($pluginName);
    if ($entry === null || empty($entry['id'])) {
        return false;
    }

    return $this->downloader->download($entry['id']) && $this->isInstalled($pluginName);
}
```

## Bootstrap de system_updater (core)

`PluginEnableOrchestrator` resuelve el provider sin instalar `system_updater` de antemano.

Solo cuando una dependencia **no** está instalada y **sí** figura en el catálogo público (JSON local o remoto) se ejecuta `PluginInstaller::installSystemUpdater()`.

Flujo:

```
enable(plugin)
  → resolver provider (registry o CatalogPluginInstallProvider si ya existe en disco)
  → para cada dependencia faltante:
      → installIfAvailable()
      → si falla y el plugin está en catálogo público:
          → instalar system_updater si falta
          → registrar CatalogPluginInstallProvider
          → reintentar installIfAvailable()
```

Comprobación ligera previa al bootstrap: `lib/public_catalog_lookup.php`.

## Registro

En `Init::init()`:

```php
PluginInstallProviderRegistry::register(new CatalogPluginInstallProvider());
```

Solo si la clase del registry existe (core actualizado).

## Rutas de actualización

- `admin_updater` → `action=update_plugin&plugin=<nombre>`
- `admin_plugin_store` → `action=update_public&plugin_id=<id>`

## Catálogo

- Buscar en `plugin_downloader->downloads()` (merge local + remoto)
- Match por campo `nombre` (case-sensitive, snake_case)
- Repo remoto canónico: `eltictacdicta/fs-cusmtom-plugins`

## Tests (TDD)

- Mock `plugin_downloader` con catálogo fixture
- Assert: nombre en catálogo → download llamado
- Assert: nombre fuera de catálogo → false + error
- Assert: isInstalled true → installIfAvailable no-op true
- Assert: orchestrator no bootstrapea system_updater si la dependencia no está en catálogo
