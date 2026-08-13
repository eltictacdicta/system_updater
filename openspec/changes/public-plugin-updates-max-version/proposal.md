# Proposal: actualización de plugins públicos y max_version

## Problema

- `admin_updater` solo detecta actualizaciones de plugins **privados**; los del catálogo público no aparecen aunque exista versión remota más nueva.
- No hay acción de actualización independiente en la tienda para plugins públicos instalados.
- Al actualizar el core no se advierte si un plugin activo declara `max_version` inferior a la versión destino.

## Solución

1. Detectar y listar actualizaciones de plugins **públicos** (catálogo remoto/local) igual que las privadas.
2. Reutilizar `plugin_downloader::download()` para sobrescribir e reactivar si estaba activo.
3. Campo opcional `max_version` en `fsframework.ini`; el core marca incompatible al activar; `system_updater` muestra advertencias antes de actualizar el núcleo.
4. Botón para actualizar todos los plugins públicos pendientes desde el panel del actualizador.

## Alcance

- Plugin: `system_updater` (lib, controllers, vistas, tests).
- Core mínimo: `fs_plugin_manager` lee y aplica `max_version` en compatibilidad.

## Fuera de alcance

- Actualización automática encadenada post-core (solo UI manual/batch).
- Plugins que no estén en el catálogo público.
