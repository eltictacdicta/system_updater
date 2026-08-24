# Spec: plugin-updates (canonical)

## Requirements

### PU-01 — Detección de actualizaciones públicas

WHEN un plugin del catálogo público está instalado AND la versión remota es mayor que la local  
THEN `admin_updater` MUST listarlo en `updates.plugins` con `source: public`.

### PU-02 — Actualización individual

WHEN el administrador pulsa actualizar en un plugin público listado  
THEN el sistema MUST descargar, validar, crear backup si existe instalación previa, sobrescribir de forma recuperable y reactivar si estaba activo.

WHEN la operación falla tras crear backup  
THEN MUST restaurar el backup.

### PU-03 — Actualización desde tienda

WHEN un plugin público instalado tiene actualización disponible  
THEN `admin_plugin_store` MUST mostrar botón Actualizar además de Instalado/Activo.

### PU-04 — Batch público

WHEN hay N plugins públicos con actualización disponible  
THEN `admin_updater` MUST ofrecer una acción para actualizarlos todos secuencialmente.

WHEN la acción de actualización en batch se ejecuta  
THEN el sistema MUST ordenar el batch dependencias-primero, usando orden topológico sobre el grafo `require` (metadatos del INI instalado primero, fallback a catálogo)  
AND MUST incluir en la secuencia de actualización las dependencias transitivas instaladas aunque no formen parte del batch  
AND MUST omitir las dependencias faltantes sin bloquear el batch.

WHEN el grafo `require` del batch contiene un ciclo  
THEN el sistema MUST NOT fallar el batch  
AND MUST registrar una advertencia  
AND MUST mantener el orden original para los miembros del ciclo.

### PU-05 — max_version en ini

WHEN `fsframework.ini` define `max_version` opcional  
THEN `fs_plugin_manager` MUST marcar el plugin incompatible si la versión del core es superior.

### PU-06 — Advertencia pre-core-update

WHEN el usuario inicia actualización del core a versión V AND un plugin activo tiene `max_version < V` en su ini local AND no hay actualización remota compatible  
THEN el modal de advertencias MUST incluir aviso de posible conflicto.

WHEN hay actualización remota pendiente compatible con V  
THEN MUST NOT advertir por ese plugin.

### PU-07 — Validación al actualizar plugin

WHEN se actualiza un plugin desde catálogo  
THEN MUST validar el ini remoto contra la versión actual del core.

### PU-08 — Orden en actualización conjunta core + plugin

WHEN un plugin remoto exige una versión de core superior a la actual  
THEN el sistema MUST actualizar el core antes que el plugin.

WHEN el plugin remoto no es compatible con el core actual  
THEN el sistema MUST bloquear su actualización hasta resolver la compatibilidad del core.

WHEN las actualizaciones encadenadas de plugins se ejecutan tras una actualización del core  
THEN el sistema MUST ordenar el batch de plugins dependencias-primero con la misma semántica que el batch público (orden topológico, dependencias transitivas instaladas incluidas, ciclos advertidos y mantenidos en orden original, dependencias faltantes omitidas).

### PU-09 — Visibilidad de dependencias en memoria durante el sync de esquema

WHEN el sync de esquema se ejecuta para un plugin vía `applyPluginSchemaUpdates` (actualización en batch, actualización encadenada o re-sync manual)  
THEN el sistema MUST hacer visible el plugin y sus dependencias instaladas para la búsqueda de tablas XML durante la duración del sync  
AND MUST tomar un snapshot de `$GLOBALS['plugins']`, añadir en memoria el plugin y sus dependencias transitivas instaladas y restaurar el snapshot al final  
AND MUST NOT habilitar de forma persistente ninguna dependencia.

WHEN el sync de esquema falla o lanza una excepción mientras el snapshot está activo  
THEN el sistema MUST restaurar el snapshot de `$GLOBALS['plugins']` (try/finally).

WHEN una dependencia instalada pero deshabilitada es requerida por el plugin que se está sincronizando  
THEN sus archivos `model/table/*.xml` MUST resolverse y sus tablas MUST crearse (sin error `Archivo model/table/X.xml no encontrado`).

### PU-10 — Re-sincronización manual de esquema

WHEN el administrador solicita el re-sync de esquema para todos los plugins instalados, o para un solo plugin vía `&plugin=X`  
THEN el sistema MUST re-ejecutar `applyPluginSchemaUpdates` para cada plugin en orden de dependencias sin re-descargar ningún plugin  
AND MUST requerir un token CSRF válido  
AND MUST devolver resultados JSON por plugin como `{success, updated, failed, messages}`.

WHEN el re-sync se ejecuta para un plugin cuyas tablas fallaron al crearse en una actualización previa  
THEN el re-sync MUST ser idempotente y crear las tablas faltantes (re-ejecutar repara las tablas que fallaron anteriormente).
