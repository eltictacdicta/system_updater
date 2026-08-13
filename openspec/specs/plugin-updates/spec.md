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

WHEN hay N plugins públicos con actualización  
THEN `admin_updater` MUST ofrecer acción para actualizarlos todos secuencialmente.

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
THEN MUST actualizar el core antes que el plugin.

WHEN el plugin remoto no es compatible con el core actual  
THEN MUST bloquear su actualización hasta resolver la compatibilidad del core.
