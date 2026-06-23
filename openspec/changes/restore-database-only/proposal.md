# Proposal: Restaurar solo base de datos

## Intent

Exponer en la UI la restauración parcial de base de datos que ya existe en `backup_manager`, incluyendo backups completos (`_complete.zip`) donde hoy solo aparece "Restaurar todo".

## Scope

### In Scope
- Botón "Restaurar BD" en `admin_updater.html.twig` cuando exista backup completo
- Resolución de dump SQL desde `_db.sql.gz` o extracción desde `_complete.zip`
- Soporte equivalente en `recovery.php`
- Tests estáticos de contrato

### Out of Scope
- Restaurar solo archivos desde backup completo (ya parcialmente soportado)
- Cambios en la lógica de importación MySQL/PostgreSQL
- Nuevo flujo de subida de backups

## Approach

| # | Cambio | Dónde |
|---|--------|-------|
| 1 | `resolve_database_backup_source()` | `lib/backup_manager.php` |
| 2 | Usar resolución antes de `restore_database()` | `restore_database()`, `process_restore.php` |
| 3 | Botones UI admin + recovery | `view/admin_updater.html.twig`, `recovery.php` |
| 4 | Tests de contrato | `tests/BackupManagerRestoreTest.php` |

## Success Criteria

- [ ] Con backup completo visible, el admin puede restaurar solo BD
- [ ] Si solo existe `_complete.zip`, la restauración de BD extrae el dump interno
- [ ] `recovery.php` ofrece restauración solo BD
- [ ] Tests PHPUnit pasan
