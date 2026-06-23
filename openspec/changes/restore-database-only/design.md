# Design: Restaurar solo base de datos

## Contexto

`backup_manager` ya implementa `restore_database()`, `restore_files()` y `restore_complete()`. `process_restore.php` acepta `type=database`. La plantilla admin oculta los botones parciales cuando `group.complete` existe.

## Resolución de archivo

Nuevo método público `resolve_database_backup_source(string $backupFile)`:

```
.sql.gz existente → { path, temp_dir: null }
_complete.zip     → extraer a temp_db_restore_* → database/*.sql.gz → { path, temp_dir }
otro              → error
```

`restore_database()` usa el `path` resuelto y elimina `temp_dir` al finalizar (éxito o error).

## UI admin

Cuando `group.complete` existe:

- Mantener botón restaurar completo (warning)
- Añadir botón restaurar BD:
  - `data-file`: `group.database.name` si existe, si no `group.complete.name`
  - `data-action`: `restore_database`

El JS existente ya maneja `restore_database` con confirmación específica.

## Recovery

Añadir botón "Restaurar solo BD" junto a "Restaurar Todo", con la misma resolución de archivo.

## Compatibilidad

Sin cambios de API externa. Acciones GET legacy (`action=restore_database`) siguen funcionando vía `actionRestore()`.
