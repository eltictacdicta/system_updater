# Proposal: resolución de la última versión compatible en actualizaciones de plugins

## Problema

El actualizador resuelve hoy la actualización de un plugin contra una única versión
remota: la punta de la rama. `plugin_downloader::get_remote_plugin_ini()` obtiene
`fsframework.ini` con `?ref=<branch>` (por defecto `master`) y `zip_link` apunta al
archivo de esa rama. `getAvailableUpdates()` compara la versión instalada con esa única
versión de punta y traslada sus límites `min_version`/`max_version`.

Consecuencia: no existe historial de versiones contra el cual resolver. Si el release de
punta de rama declara límites incompatibles con el core en ejecución, la actualización se
bloquea sin más; no hay mecanismo para retroceder al release más reciente que sí sea
compatible. El propio core tampoco resuelve por tag: solo consulta el último GitHub
Release (`fs_github_latest_release_version`) pero descarga la punta de rama
(`master.zip`/`main.zip`).

El spec canónico `plugins/system_updater/openspec/specs/plugin-updates/spec.md`
(PU-01..PU-10) ya cubre detección pública, techo `max_version`, advertencias
pre-core-update, validación remota contra el core actual, ordenamiento encadenado
core+plugin y re-sync de esquema. Este change NO duplica esos requisitos: agrega la
capacidad faltante de resolver y aplicar el release más nuevo que sea compatible a partir
de un historial de versiones.

Nota de higiene: existe un change activo obsoleto `public-plugin-updates-max-version`
cuyo contenido ya está mergeado en el spec canónico (PU-01..PU-08) pero nunca fue
verificado/archivado. Este change es una capacidad distinta y no debe solaparse con él.

## Solución

Diseño híbrido "resolver + manifiesto versionado":

1. Cada plugin publica metadatos por release. Forma preferida: un `releases.json`
   append-only (alternativamente un array `versions[]` dentro de la entrada del catálogo)
   generado al momento de tag/release, con una entrada por versión que contiene `version`,
   `min_version`, `max_version` y una referencia de descarga (`zip_url` o id de catálogo).
   El `fsframework.ini` de cada tag es la fuente inmutable de esos límites.
2. Se agrega a `plugin_compatibility_checker` un resolver puro, por ejemplo
   `resolveLatestCompatible(string $coreVersion, array $versions): ?array`, que devuelve
   la `version` más alta cuyos límites admiten el core en ejecución y que sea estrictamente
   más nueva que la instalada. Debe ser un método estático puro y testeable, consistente
   con el estilo de `evaluateCoreAgainstPlugin` / `isRemoteVersionNewer`.
3. `plugin_downloader::getAvailableUpdates()` usa el resolver en lugar de la única versión
   de punta de rama cuando hay historial de versiones disponible.
4. Compatibilidad hacia atrás: cuando no hay historial de versiones, el comportamiento cae
   EXACTAMENTE al actual (punta de rama). Los catálogos y plugins existentes siguen
   funcionando sin cambios.

La generación del historial se describe como enfoque a nivel de release (append de la
entrada con los límites del propio tag); no se especifica en detalle la automatización de
CI en esta propuesta.

## Alcance

- Resolver puro en `plugin_compatibility_checker`, con cobertura unitaria.
- Definición de la forma de datos del historial de versiones y cómo la consume el
  actualizador, con fallback retrocompatible.
- Cableado de `getAvailableUpdates()` y del camino de actualización al resolver.
- Producción/publicación del historial al momento de release, descrita como enfoque.
- Todo el change vive en `plugins/system_updater/`; no modifica archivos fuera del plugin.

## Fuera de alcance

- Auto-downgrade: nunca se ofrece una versión inferior a la instalada.
- Resolución completa del grafo de dependencias (`require` entre plugins puede mezclar
  versiones incompatibles; es un problema de nivel Composer). Se documenta como
  limitación/riesgo conocido, no se resuelve aquí.
- Cambios en el core: el core solo necesita seguir exponiendo su `VERSION` (ya lo hace).

## Riesgos conocidos

- Límites de tasa de la API de GitHub (60/hora sin autenticación) si se resuelve listando
  tags en vivo; por eso se prefiere un manifiesto versionado.
- Los límites por release deben permanecer inmutables; editar el historial de forma
  retroactiva rompe la reproducibilidad.
- Interacción con el grafo de dependencias `require`: el resolver opera por plugin, no
  resuelve versiones mixtas entre plugins.
