# Proposal: Descarga en cascada estilo WordPress + AJAX en tienda

## Intent

Al pulsar **Descargar** en la tienda, el sistema debe:

1. Calcular el plan transitivo de dependencias (`factura_pdf1` → `tpvmod` → …).
2. Descargar **todos** los plugins faltantes al disco (sin activar).
3. Preguntar al usuario si desea **activar** el plugin objetivo.
4. Activar en cascada vía AJAX (una petición por plugin) para evitar timeouts.

## Alcance

- `admin_plugin_store`: acciones AJAX `install_plan`, `download_step`, `activate_step`
- JS compartido `view/js/plugin_cascade_ajax.js`
- Botón **Activar** para plugins instalados pero inactivos
- `admin_home`: activar/desactivar/subida ZIP por AJAX (activación usa endpoints de la tienda)

## Core (change relacionado)

Extiende `plugin-cascade-activation` con API desacoplada:

- `inspectActivation()`
- `downloadPlugin()`
- `enablePluginStep()`
