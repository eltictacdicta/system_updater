# Spec: plugin-updates

## ADDED Requirements

### PU-01 — Detección de actualizaciones públicas

WHEN un plugin del catálogo público está instalado AND la versión remota es mayor que la local  
THEN `admin_updater` MUST listarlo en `updates.plugins` con `source: public`.

### PU-02 — Actualización individual

WHEN el administrador pulsa actualizar en un plugin público listado  
THEN el sistema MUST descargar la versión del catálogo en staging, validar la extracción, crear backup si el directorio ya existe, sobrescribir de forma recuperable y reactivar si estaba activo.

WHEN la descarga, extracción o promoción al directorio final falla después de crear backup  
THEN MUST restaurar el backup y MUST NOT dejar el plugin ausente o a medias.

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

WHEN el usuario inicia actualización del core a versión V AND un plugin activo tiene `max_version < V` en su ini **local**  
AND no existe actualización remota pendiente compatible con V  
THEN el modal de advertencias MUST incluir aviso de posible conflicto.

WHEN existe actualización remota pendiente cuyo `fsframework.ini` es compatible con V  
THEN MUST NOT emitir advertencia por ese plugin (se resolverá con la actualización conjunta).

### PU-07 — Validación al actualizar plugin

WHEN se actualiza un plugin desde catálogo  
THEN MUST validar min_version/max_version del **ini remoto** contra la versión **actual** del core antes de descargar.

### PU-08 — Orden en actualización conjunta core + plugin

WHEN el core actual es C, el destino es V y un plugin activo tiene actualización remota cuyo `min_version` exige V  
THEN MUST actualizar primero el core a V y después el plugin.

WHEN el plugin remoto no es compatible con C pero sí con V  
THEN MUST bloquear la actualización del plugin hasta completar (o planificar) la actualización del core.

WHEN falla uno de los dos pasos en una actualización conjunta  
THEN MUST dejar constancia del paso fallido y MUST NOT marcar el conjunto como completado.
