# Design: public-plugin-updates-max-version

## Componentes

| Componente | Responsabilidad |
|------------|-----------------|
| `plugin_compatibility_checker` | Normalizar versiones; evaluar min/max; warnings pre-core-update |
| `plugin_downloader` | `getAvailableUpdates()`, `findPublicEntryByName()`, hidratar min/max del ini remoto |
| `admin_updater` | Merge public+private en `checkUpdates()`; `actionUpdatePlugin` público; batch update |
| `admin_plugin_store` | Botón Actualizar en listado público |
| `maintenance_mode_compat` | Delegar warnings de compatibilidad core |
| `fs_plugin_manager` (core) | `max_version` opcional en `applyPluginCompatibility` |

## Flujo actualización plugin público

```
checkUpdates → plugin_downloader::getAvailableUpdates()
admin_updater / store → action=update_plugin&plugin=name
  → maintenance ON
  → download(catalog id)  // sobrescribe directorio
  → enable() si estaba activo
  → maintenance OFF
```

## max_version

```ini
; fsframework.ini
min_version = "0.13"
max_version = "0.15"   ; opcional — core > max_version → incompatible / warning
```

Comparación con `version_compare()` tras normalizar prefijo `v`.

### Reglas de evaluación

| Escenario | Ini usado | Versión del core |
|-----------|-----------|------------------|
| Advertencia pre-actualización del núcleo | Local del plugin activo | Versión **destino** del core |
| Resolución con actualización pendiente | Si local falla, se consulta el **remoto** (catálogo / fsframework.ini remoto) | Versión destino |
| Actualización individual de plugin | **Remoto** a instalar | Versión **actual** del core |

Si el plugin local declara `max_version` inferior al núcleo destino pero hay una actualización remota pendiente cuyo `fsframework.ini` sí es compatible con ese núcleo, **no se emite advertencia** (actualización conjunta asumida).

`GET action=operation_warnings&context=core_update&target_core_version=X`

Recorre plugins **activos**, lee ini local, emite warning si `target > max_version`.
