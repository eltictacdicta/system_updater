# Proposal: Hostname en nombre de archivo de backup

## Intent

Incluir el host slug en el nombre del paquete de backup para que el archivo sea identificable al moverlo entre dominios. El nombre es **informativo**: la lógica de restore no cambia.

## Scope

### In Scope

- Nombre de archivo: `<host-slug>_<YYYY-MM-DD>_<HH-MM-SS>_complete.zip` (y los auxiliares `_db.sql.gz` / `_files.zip` con el mismo base)
- Source del slug, en orden: `$_SERVER['HTTP_HOST']` → `$_SERVER['SERVER_NAME']` → `gethostname()` → `unknown`
- Sanitización: lowercase, no-`[a-z0-9-]` → `-`, colapso de dashes repetidos, trim en ambos extremos, cap a 63 chars (límite de label DNS); vacío tras sanear → `unknown`
- Helpers privados en `backup_manager`: `slugify_host(string $raw): string` y `resolve_host_slug(): string`
- `customName` no vacío sigue siendo override manual prioritario

### Out of Scope

- Cambios a la lógica de restore
- Leer host desde config de FSFramework o desde un nuevo setting del plugin
- Backfill / renombrado de archivos `backup_YYYY-MM-DD_*` ya existentes (siguen restaurables con su nombre original)
- Multi-host en un mismo backup (un backup = un host de origen)

## Approach

| # | Cambio | Dónde |
|---|--------|-------|
| 1 | `private static function slugify_host(string $raw): string` — aplica la sanitización descrita | `lib/backup_manager.php` |
| 2 | `private function resolve_host_slug(): string` — cadena `HTTP_HOST` → `SERVER_NAME` → `gethostname()` → `unknown`, con regex `[a-zA-Z0-9.\-:]+` y longitud 1..253 como guard de inyección de `Host` header | `lib/backup_manager.php` |
| 3 | Default name en `create_backup_with_progress` pasa de `'backup_' . $timestamp` a `slug . '_' . $timestamp` | `lib/backup_manager.php` (~L547) |
| 4 | Mismo default en `create_database_backup` y `create_files_backup` (coherente con el base del `_complete.zip`) | `lib/backup_manager.php` (~L714, ~L1172) |
| 5 | El regex del paso 2 rechaza cualquier host con caracteres fuera del set seguro, por lo que un `Host: ../../etc/passwd` se descarta y produce slug `unknown` | dentro de `resolve_host_slug` |
| 6 | Tests de contrato + casos parametrizados (subdominio, IP, puerto, `localhost`, vacío, host injection, cap 63, colapso, trim) | `tests/BackupManagerFilenameTest.php` |

## Success Criteria

- [ ] Archivo nuevo se llama `<host-slug>_<YYYY-MM-DD>_<HH-MM-SS>_complete.zip` y los auxiliares comparten el mismo base
- [ ] Slug cumple `[a-z0-9-]+` y respeta el cap de 63 chars
- [ ] Intento de host header injection produce slug `unknown` (no entra al nombre)
- [ ] Backups antiguos con nombre `backup_YYYY-MM-DD_*` siguen siendo listados y restaurables sin cambios
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` pasa
- [ ] Tests unitarios cubren: dominio normal, subdominio, IP literal, puerto, `localhost`, host vacío/inválido, intento de inyección, cap de longitud, colapso de dashes repetidos, trim leading/trailing

## Open Questions

- **Reverse proxy / `X-Forwarded-Host`**: `HTTP_HOST` puede ser el host interno del proxy. La cadena de fallback actual lo acepta tal cual. ¿Querés que el plugin considere `X-Forwarded-Host` como primera opción (con el mismo regex de guard) o lo dejamos fuera por simplicidad?
- **Slug en CLI puro**: hoy `create_backup_with_progress` no corre en CLI puro, pero si en el futuro alguien lo invoca desde cron, `gethostname()` devuelve el host del sistema (p.ej. `srv-prod-01`), no el dominio público. Confirmar que `gethostname()` es el fallback aceptable o si preferís dejar `unknown` en CLI.
- **Cachear el slug**: si una misma request genera varios backups (no es el caso hoy), resolver el slug por llamada es barato. ¿Lo dejamos per-call o lo guardamos en una propiedad estática?
