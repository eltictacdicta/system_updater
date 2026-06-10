# Sistema CSRF Independiente - Plugin system_updater

## Resumen

El plugin `system_updater` implementa un sistema CSRF (Cross-Site Request Forgery) independiente del framework principal para proteger sus scripts SSE (Server-Sent Events) que ejecutan operaciones de larga duración.

## Problema Resuelto

### Contexto Original
El plugin `system_updater` ejecuta scripts PHP independientes (`process_backup.php`, `process_restore.php`, `process_core_update.php`) que:
- Bypassean el flujo normal `index.php → controller → CSRF`
- Usan Server-Sent Events (SSE) para reportar progreso en tiempo real
- Requieren validación CSRF para prevenir ataques

### Problemas con el Sistema Original
1. **Dependencia del framework**: El plugin dependía del `CsrfManager` del núcleo
2. **Incompatibilidad entre versiones**: 
   - Versión 0.14.7 usaba `NativeSessionCsrfStorage` → `$_SESSION['_csrf'][tokenId]`
   - Versión actual usa `SessionTokenStorage` → `$_SESSION['_sf2_attributes']['_csrf/...']`
3. **Conflicto de namespaces**: El namespace del token (`https-` vs `http-`) podía diferir entre el controller y el script SSE
4. **Múltiples storages activos**: Durante migraciones, podían coexistir múltiples storages de tokens
5. **Sesiones diferentes**: El controller usaba la sesión del framework (`FSSESS_*`) mientras que los scripts SSE no podían acceder correctamente a esa sesión

## Solución Implementada

### Sistema CSRF Independiente
El plugin ahora usa su propio sistema de tokens CSRF completamente aislado del framework:

#### Características
- **Sesión dedicada**: Usa `SU_SESS_*` en lugar de `FSSESS_*` del framework
- **Token format**: `bin2hex(random_bytes(32))` - 64 caracteres hexadecimales
- **Storage**: `$_SESSION['system_updater']['csrf_tokens'][$tokenId]`
- **Validación**: `hash_equals()` para comparación timing-safe
- **TTL**: 4 horas (configurable vía `SYSTEM_UPDATER_CSRF_TTL`)
- **Independencia**: No depende de la implementación interna del framework

#### Componentes

##### 1. `lib/csrf_token.php`
Sistema central de gestión de tokens:
- `system_updater_csrf_generate($tokenId)`: Genera y almacena un token
- `system_updater_csrf_validate($token, $tokenId)`: Valida un token con timing-safe comparison
- `system_updater_csrf_get_stored($tokenId)`: Obtiene el token almacenado
- `system_updater_csrf_field($tokenId)`: Genera input HTML hidden
- `system_updater_csrf_meta($tokenId)`: Genera meta tag HTML
- `system_updater_csrf_ensure_session()`: Asegura que la sesión SU_SESS esté activa

##### 2. `lib/csrf_guard.php`
Guard para scripts SSE:
- `ensure_request_csrf()`: Valida el token CSRF en scripts SSE
- `system_updater_csrf_read_from_request()`: Lee el token de GET/POST/header
- `system_updater_csrf_failure_response($message)`: Respuesta de error formateada

##### 3. `lib/session_auth.php`
Gestión de sesión independiente:
- `system_updater_resolve_session_name()`: Retorna `SU_SESS_*` (nombre único del plugin)
- `system_updater_native_session_start()`: Inicia la sesión del plugin con fallback a sesiones del framework
- `system_updater_resolve_session_names()`: Lista de sesiones a intentar (SU_SESS, FSSESS, PHPSESSID)

##### 4. `lib/twig_compat.php`
Integración con vistas:
- `system_updater_csrf_meta()`: Meta tag para JavaScript
- `system_updater_csrf_field()`: Input hidden para formularios
- `system_updater_prepare_view_compat($controller)`: Prepara variables para la vista

##### 5. `view/admin_updater.html.twig`
Uso en la vista:
```twig
{{ fsc.su_csrf_meta_html|raw }}

<script>
var suCsrfToken = $('meta[name="su-csrf-token"]').attr('content') || '';
var sseUrl = 'plugins/system_updater/process_backup.php?action=start&su_csrf_token=' + encodeURIComponent(suCsrfToken);
var backupEventSource = new EventSource(sseUrl, { withCredentials: true });
</script>
```

## Flujo de Operación

### 1. Generación del Token (Controller)
```
admin_updater.php
  ↓
system_updater_prepare_view_compat()
  ↓
system_updater_csrf_generate()
  ↓
system_updater_csrf_ensure_session() → Inicia SU_SESS
  ↓
Genera token (64 chars hex)
  ↓
Guarda en $_SESSION['system_updater']['csrf_tokens']['system_updater_csrf']
  ↓
Retorna token a la vista
```

**Nota:** La sesión se guardará automáticamente al final del request. No es necesario cerrar y reabrir explícitamente.

### 2. Validación del Token (Script SSE)
```
process_backup.php?action=start&su_csrf_token=...
  ↓
system_updater_process_init()
  ↓
system_updater_start_authenticated_session()
  ↓
system_updater_native_session_start() → Carga SU_SESS
  ↓
ensure_request_csrf()
  ↓
system_updater_csrf_read_from_request() → Lee de GET
  ↓
system_updater_csrf_validate()
  ↓
system_updater_csrf_ensure_session() → Carga SU_SESS
  ↓
hash_equals(stored_token, submitted_token)
  ↓
✓ Válido → session_write_close() → Continúa
✗ Inválido → system_updater_csrf_failure_response() → Exit
```

## Configuración

### Constantes Opcionales
```php
// TTL del token en segundos (default: 4 horas)
define('SYSTEM_UPDATER_CSRF_TTL', 4 * 3600);

// Nombre de sesión personalizado (default: SU_SESS_{hash})
define('SYSTEM_UPDATER_SESSION_NAME', 'SU_SESS_custom');
```

### Headers SSE
Los scripts SSE envían estos headers para prevenir buffering:
```php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('Content-Encoding: identity');
header('X-Content-Type-Options: nosniff');
```

## Seguridad

### Medidas Implementadas
1. **Timing-safe comparison**: `hash_equals()` previene timing attacks
2. **CSPRNG**: `random_bytes(32)` para generación criptográficamente segura
3. **TTL limitado**: Tokens expiran después de 4 horas
4. **Sesión aislada**: SU_SESS independiente del framework
5. **Session write close**: Previene bloqueos en SSE de larga duración
6. **withCredentials**: EventSource envía cookies de sesión

### Validaciones
- Token no vacío
- Estructura de datos válida en sesión
- TTL no expirado
- Comparación timing-safe

## Compatibilidad

### Versiones del Framework
- ✅ FSFramework 0.14.7 (NativeSessionCsrfStorage)
- ✅ FSFramework 0.14.x (SessionTokenStorage)
- ✅ Versiones futuras (independiente del framework)

### Navegadores
- ✅ Todos los navegadores modernos con soporte para EventSource
- ✅ Requiere `withCredentials: true` para EventSource

## Testing

### Tests Unitarios
```bash
ddev exec php vendor/bin/phpunit plugins/system_updater/tests/CsrfTokenTest.php
```

### Cobertura
- Generación de tokens
- Validación de tokens
- Expiración de tokens
- Tokens tamperizados
- Tokens vacíos
- Aislamiento de tokenId
- Auto-generación
- Salida HTML (field/meta)

## Troubleshooting

### Token no válido en SSE
**Síntoma**: Error "Token CSRF ausente" o "Token CSRF inválido"

**Causas posibles**:
1. Cookie SU_SESS no se envía en EventSource
2. Sesión SU_SESS no se inicializa correctamente
3. Token expiró (>4 horas)

**Soluciones**:
1. Verificar que EventSource usa `{ withCredentials: true }`
2. Verificar que la cookie SU_SESS existe en el navegador
3. Recargar la página para generar un nuevo token

### Sesión vacía en script SSE
**Síntoma**: `$_SESSION` está vacío en el script SSE

**Causa**: El script SSE está cargando una sesión diferente (FSSESS en lugar de SU_SESS)

**Solución**: Verificar que `system_updater_native_session_start()` está cargando SU_SESS primero

## Mantenimiento

### Actualización de Tokens
Los tokens se regeneran automáticamente cuando:
- Expiran (después de 4 horas)
- No existen en la sesión
- Se llama a `system_updater_csrf_generate()` explícitamente

### Limpieza de Tokens Expirados
Los tokens expirados se eliminan automáticamente durante la validación:
```php
if ($age > SYSTEM_UPDATER_CSRF_TTL) {
    unset($_SESSION['system_updater']['csrf_tokens'][$tokenId]);
    return false;
}
```

## Referencias

- [OWASP CSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html)
- [PHP session_write_close()](https://www.php.net/manual/en/function.session-write-close.php)
- [EventSource withCredentials](https://developer.mozilla.org/en-US/docs/Web/API/EventSource/EventSource#parameters)
- [Symfony CSRF Component](https://symfony.com/doc/current/security/csrf.html)

## Autor

- **Javier Trujillo** - [mistertekcom@gmail.com](mailto:mistertekcom@gmail.com)

## Licencia

LGPL-3.0-or-later
