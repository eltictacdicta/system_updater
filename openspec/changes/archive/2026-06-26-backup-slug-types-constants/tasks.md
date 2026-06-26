# Tasks: Tipos y constantes para slug de host

## Test scaffold (red)

- [x] 0.1 Añadir un test method a `plugins/system_updater/tests/BackupManagerFilenameTest.php` (namespace `Tests\SystemUpdater`) que aserta `backup_manager::HOST_SLUG_MAX_LEN === 63` y `backup_manager::HOST_HEADER_MAX_LEN === 253`. Confirmar que falla con "undefined class constant" antes del cambio de producción.

## Constants (green)

- [x] 1.1 En `plugins/system_updater/lib/backup_manager.php`, añadir `const HOST_SLUG_MAX_LEN = 63;` y `const HOST_HEADER_MAX_LEN = 253;` justo después de `const VERSION = '2.3.1';` (L213), antes del docblock de `$fsRoot` (L215). Re-ejecutar el test de 0.1 y confirmar que pasa.

## Type hints (green)

- [x] 2.1 Cambiar la firma de `slugify_host` (L289) a `private static function slugify_host(string $raw): string` y la de `resolve_host_slug` (L321) a `private function resolve_host_slug(): string` en `lib/backup_manager.php`. Mantener los PHPDoc `@param string $raw` y `@return string` por consistencia con el resto del archivo (no se eliminan).

## Magic numbers (green)

- [x] 3.1 En `slugify_host` (L304-305) de `lib/backup_manager.php`, reemplazar el literal `63` por `self::HOST_SLUG_MAX_LEN` (en `strlen($slug) > 63` y `substr($slug, 0, 63)`). En `resolve_host_slug` (L330), interpolar `self::HOST_HEADER_MAX_LEN` dentro del regex: `'/^[a-zA-Z0-9.\-:]{1,' . self::HOST_HEADER_MAX_LEN . '}$/'`. Confirmar que no quedan literales `63` ni `253` en los cuerpos de los helpers.

## Verification

- [x] 4.1 Ejecutar `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` y confirmar que la suite completa sigue en verde: 90 tests, 147 assertions, 0 failures, 0 errors, 1 skip pre-existente. Sin cambio en el conteo de tests.
