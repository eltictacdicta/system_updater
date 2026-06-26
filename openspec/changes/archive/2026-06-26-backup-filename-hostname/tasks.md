# Tasks: Hostname en nombre de archivo de backup

## Test scaffold (red)

- [x] 0.1 Crear `plugins/system_updater/tests/BackupManagerFilenameTest.php` con namespace `Tests\SystemUpdater`, `#[CoversClass(backup_manager::class)]`, `setUp` que limpia `$_SERVER['HTTP_HOST']`/`SERVER_NAME` y stubea `gethostname()`, y data providers (todos los casos en rojo) para: `slugify_host` (normal, subdominio, IP, puerto, mayúsculas, guiones repetidos, trim, vacío, cap 63, sanitización `[^a-z0-9-]`, vacío→`unknown`); `resolve_host_slug` (cadena `HTTP_HOST`→`SERVER_NAME`→`gethostname()`→`unknown`, regex guard `[a-zA-Z0-9.\-:]{1,253}`, host injection `../../etc/passwd`→`unknown`); default name de `create_backup_with_progress` con `customName=''`→`<slug>_<timestamp>` y override con `customName` no vacío; defaults de `create_database_backup` y `create_files_backup`; listado de archivo legacy `backup_YYYY-MM-DD_*` en `list_backups_grouped`.

## Helpers (green)

- [x] 1.1 Añadir `private static function slugify_host(string $raw): string` a `plugins/system_updater/lib/backup_manager.php`: lowercase, `[^a-z0-9-]`→`-`, colapso de dashes repetidos, trim en ambos extremos, cap a 63 chars, vacío→`unknown`.
- [x] 1.2 Añadir `private function resolve_host_slug(): string` a `plugins/system_updater/lib/backup_manager.php` con cadena `HTTP_HOST`→`SERVER_NAME`→`gethostname()`→`unknown`, aplicando regex guard `/^[a-zA-Z0-9.\-:]{1,253}$/` y `slugify_host`; cualquier valor fuera de regex o vacío cae al siguiente eslabón o a `unknown`.

## Integration (green)

- [x] 2.1 En `create_backup_with_progress` (≈L547 de `lib/backup_manager.php`), cambiar `$baseName = $customName ? $customName : 'backup_' . $timestamp;` por `$baseName = $customName ?: resolve_host_slug() . '_' . $timestamp;` para que el base del `_complete.zip` lleve el slug.
- [x] 2.2 En `create_database_backup` (≈L714) de `lib/backup_manager.php`, reemplazar el default `'db_backup_' . $timestamp` por `resolve_host_slug() . '_' . $timestamp` (el sufijo `.sql.gz` lo añade el método).
- [x] 2.3 En `create_files_backup` (≈L1172) de `lib/backup_manager.php`, reemplazar el default `'files_backup_' . $timestamp` por `resolve_host_slug() . '_' . $timestamp` (el sufijo `.zip` lo añade el método).

## Hardening

- [x] 3.1 Añadir test de contrato en `BackupManagerFilenameTest.php` que cree un fixture con nombre legacy `backup_YYYY-MM-DD_HH-MM-SS_complete.zip` en `backupPath` y confirme que `list_backups_grouped()` lo agrupa correctamente y que `restore_database()` lo resuelve sin error (regresión: backups antiguos siguen listados y restaurables).

## Verification

- [x] 4.1 Ejecutar `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` y confirmar que toda la suite queda en verde, incluyendo el caso de regresión de archivos legacy.
