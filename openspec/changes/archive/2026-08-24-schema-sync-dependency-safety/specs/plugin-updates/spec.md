# Delta for plugin-updates

## MODIFIED Requirements

### PU-04 — Batch público

WHEN N public plugins have an available update  
THEN `admin_updater` MUST offer an action to update them all sequentially.

WHEN the batch update action runs  
THEN the system MUST order the batch dependencies-first, using topological order over the `require` graph (installed INI metadata first, catalog fallback)  
AND MUST include installed transitive dependencies in the update sequence even when they are not part of the batch  
AND MUST skip missing dependencies without blocking the batch.

WHEN the `require` graph of the batch contains a cycle  
THEN the system MUST NOT fail the batch  
AND MUST log a warning  
AND MUST keep the original order for the cycle members.

(Previously: offered sequential batch update of all public plugins with updates; no ordering guarantee and no dependency visibility)

### PU-08 — Orden en actualización conjunta core + plugin

WHEN a remote plugin requires a core version higher than the current one  
THEN the system MUST update the core before the plugin.

WHEN the remote plugin is not compatible with the current core  
THEN the system MUST block its update until core compatibility is resolved.

WHEN chained plugin updates run after a core update  
THEN the system MUST order the plugin batch dependencies-first with the same semantics as the public batch (topological order, installed transitive dependencies included, cycles warned and kept in original order, missing dependencies skipped).

(Previously: only core-before-plugin ordering; no dependency ordering for the chained plugin batch)

## ADDED Requirements

### PU-09 — Visibilidad de dependencias en memoria durante el sync de esquema

WHEN schema sync runs for a plugin via `applyPluginSchemaUpdates` (batch update, chained update, or manual resync)  
THEN the system MUST make the plugin and its installed dependencies visible to XML table lookup for the duration of the sync  
AND MUST snapshot `$GLOBALS['plugins']`, append the plugin and its installed transitive dependencies in-memory, and restore the snapshot afterward  
AND MUST NOT persist-enable any dependency.

WHEN the schema sync fails or throws while the snapshot is active  
THEN the system MUST restore the `$GLOBALS['plugins']` snapshot (try/finally).

WHEN an installed-but-disabled dependency is required by the plugin being synced  
THEN its `model/table/*.xml` files MUST resolve and its tables MUST be created (no `Archivo model/table/X.xml no encontrado` error).

### PU-10 — Re-sincronización manual de esquema

WHEN the administrator requests schema re-sync for all installed plugins, or for a single plugin via `&plugin=X`  
THEN the system MUST re-run `applyPluginSchemaUpdates` for each plugin in dependency order without re-downloading any plugin  
AND MUST require a valid CSRF token  
AND MUST return per-plugin JSON results as `{success, updated, failed, messages}`.

WHEN the re-sync runs for a plugin whose tables failed to be created in a previous update  
THEN the re-sync MUST be idempotent and create the missing tables (re-running heals previously-failed tables).