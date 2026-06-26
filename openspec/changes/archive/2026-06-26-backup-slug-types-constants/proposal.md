# Proposal: Tipos y constantes para slug de host

## Intent

Añadir declaraciones de tipo a los helpers `slugify_host()` y `resolve_host_slug()` en `lib/backup_manager.php`, y reemplazar los números mágicos `63` (límite de etiqueta DNS) y `253` (longitud máxima RFC 1035) por constantes de clase `HOST_SLUG_MAX_LEN` y `HOST_HEADER_MAX_LEN` para autodocumentar el código.

## Scope

### In Scope
- Type hints en las firmas de los dos helpers (`string $raw`, `: string`)
- Dos constantes de clase: `const HOST_SLUG_MAX_LEN = 63;` y `const HOST_HEADER_MAX_LEN = 253;`
- Reemplazo de las literales `63` y `253` en los cuerpos de los helpers

### Out of Scope
- Cambios en otros métodos de `backup_manager.php`
- Añadir `declare(strict_types=1)` al archivo
- Tests nuevos o modificados
- Cambios en la lógica de backup/restore

## Approach

| # | Cambio | Dónde |
|---|--------|-------|
| 1 | Firmas con type hints `string` | `lib/backup_manager.php` L289, L321 |
| 2 | Constantes `HOST_SLUG_MAX_LEN`, `HOST_HEADER_MAX_LEN` | `lib/backup_manager.php` junto a `BACKUP_DIR` |
| 3 | Reemplazar literales `63` y `253` por constantes | cuerpo de los dos helpers |
| 4 | Suite PHPUnit sigue verde | `tests/BackupManagerFilenameTest.php` |

## Success Criteria

- [ ] `slugify_host()` firma `private static function slugify_host(string $raw): string`
- [ ] `resolve_host_slug()` firma `private function resolve_host_slug(): string`
- [ ] Existen `const HOST_SLUG_MAX_LEN = 63;` y `const HOST_HEADER_MAX_LEN = 253;`
- [ ] Las literales `63` y `253` ya no aparecen en los cuerpos de los helpers
- [ ] Suite PHPUnit completa sigue pasando: 90 tests, 147 assertions, 0 failures, 0 errors
