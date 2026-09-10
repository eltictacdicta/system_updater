# Spec: plugin-updates

## ADDED Requirements

### PU-11 — Historial de versiones append-only por plugin

WHEN un plugin publica un historial de versiones
THEN el historial MUST ser append-only
AND MAY exponerse preferentemente como `releases.json` propio del plugin, o alternativamente como un arreglo `versions[]` dentro de la entrada del catálogo
AND cada entrada MUST representar exactamente un release.

WHEN el historial contiene la entrada de un release
THEN esa entrada MUST incluir `version`, `min_version`, `max_version` y una referencia de descarga (`zip_url` o un id de catálogo)
AND `min_version` y `max_version` MUST provenir del `fsframework.ini` de ese mismo tag
AND los límites de un release ya publicado MUST permanecer inmutables (no se reescriben de forma retroactiva).

#### Scenario: releases.json reconocido como historial

- GIVEN un plugin que publica `releases.json` con entradas completas (`version`, `min_version`, `max_version` y referencia de descarga)
- WHEN el actualizador carga el historial del plugin
- THEN MUST reconocer cada entrada como un release candidato.

#### Scenario: versions[] del catálogo como forma alternativa

- GIVEN una entrada de catálogo que incluye un arreglo `versions[]` con los mismos campos por release
- WHEN el actualizador carga el historial del plugin
- THEN MUST tratarlo como un historial equivalente a `releases.json`.

#### Scenario: límites inmutables por tag

- GIVEN un release ya publicado cuyos límites se derivaron de su propio `fsframework.ini`
- WHEN se publica un release posterior del mismo plugin
- THEN los límites del release anterior MUST NOT modificarse.

### PU-12 — Resolver puro de la última versión compatible

WHEN se invoca `plugin_compatibility_checker::resolveLatestCompatible(string $coreVersion, array $versions)` con el historial de releases de un plugin
THEN MUST devolver la entrada con la `version` más alta cuyos límites `min_version`/`max_version` admitan la `coreVersion` en ejecución
AND MUST devolver `null` cuando ninguna entrada cumpla las condiciones de compatibilidad y de novedad.

WHEN se resuelve sobre el historial de un plugin
THEN la versión instalada del plugin MUST formar parte del contexto de resolución
AND el resolver MUST NOT devolver una entrada cuya `version` sea menor o igual que la versión instalada
AND MUST NOT proponer una versión igual o inferior a la instalada (sin auto-downgrade).

WHEN el resolver se ejecuta
THEN MUST ser determinista (mismo `coreVersion` e historial producen el mismo resultado)
AND MUST NOT realizar I/O ni depender de estado global.

#### Scenario: elige la más alta compatible cuando la punta es incompatible

- GIVEN un historial con `1.8.1` (límites compatibles con el core en ejecución) y `1.9.0` (límites incompatibles con el core en ejecución)
- AND la versión instalada es `1.8.0`
- WHEN se resuelve contra el core en ejecución
- THEN MUST devolver la entrada `1.8.1`.

#### Scenario: devuelve null cuando ningún release es compatible

- GIVEN un historial cuyas entradas son todas incompatibles con el core en ejecución por `min_version` o `max_version`
- WHEN se resuelve
- THEN MUST devolver `null`.

#### Scenario: devuelve null cuando ningún release es más nuevo que el instalado

- GIVEN un historial compatible pero cuyas entradas no son estrictamente más nuevas que la versión instalada
- WHEN se resuelve
- THEN MUST devolver `null`.

#### Scenario: nunca devuelve una versión menor o igual a la instalada

- GIVEN un historial que solo contiene versiones compatibles menores o iguales que la versión instalada
- WHEN se resuelve
- THEN MUST NOT devolver ninguna de esas entradas
- AND el resultado MUST ser `null`.

#### Scenario: respeta el límite inferior (`min_version`)

- GIVEN una entrada cuyo `min_version` es igual a la `coreVersion` en ejecución
- WHEN se evalúa su compatibilidad
- THEN MUST considerarse compatible.
- GIVEN una entrada cuyo `min_version` es mayor que la `coreVersion` en ejecución
- WHEN se evalúa su compatibilidad
- THEN MUST considerarse incompatible.

#### Scenario: respeta el límite superior (`max_version`)

- GIVEN una entrada cuyo `max_version` es igual a la `coreVersion` en ejecución
- WHEN se evalúa su compatibilidad
- THEN MUST considerarse compatible.
- GIVEN una entrada cuyo `max_version` es menor que la `coreVersion` en ejecución
- WHEN se evalúa su compatibilidad
- THEN MUST considerarse incompatible.

#### Scenario: determinismo y ausencia de I/O

- GIVEN el mismo `coreVersion`, el mismo historial y la misma versión instalada
- WHEN se invoca el resolver dos veces
- THEN MUST devolver el mismo resultado en ambas invocaciones
- AND MUST NOT ejecutar operaciones de entrada/salida.

### PU-13 — Cableado del actualizador al resolver por historial

WHEN `plugin_downloader::getAvailableUpdates()` evalúa un plugin que dispone de historial de versiones
THEN MUST usar `plugin_compatibility_checker::resolveLatestCompatible` para determinar la versión objetivo, en lugar de la única versión de punta de rama
AND la entrada de actualización resultante MUST exponer los límites (`min_version`/`max_version`) del release resuelto
AND MUST NOT listar una actualización para el plugin cuando el resolver devuelve `null`.

#### Scenario: la entrada de actualización expone los límites del release resuelto

- GIVEN un plugin con historial cuyo release de punta es incompatible y cuyo release `1.8.1` es compatible
- WHEN se ejecuta `getAvailableUpdates()`
- THEN la entrada del plugin MUST tener `new_version` igual a la versión resuelta
- AND MUST exponer `min_version` y `max_version` correspondientes a ese release resuelto.

#### Scenario: sin release compatible no se lista actualización

- GIVEN un plugin con historial donde el resolver devuelve `null`
- WHEN se ejecuta `getAvailableUpdates()`
- THEN el plugin MUST NOT aparecer en la lista de actualizaciones.

### PU-14 — Fallback retrocompatible a la punta de rama

WHEN un plugin NO dispone de historial de versiones
THEN el comportamiento del actualizador MUST ser exactamente el actual: resolver contra la única versión de punta de rama (`fsframework.ini` de la rama) y su archivo de rama
AND los catálogos y plugins existentes MUST seguir funcionando sin cambios.

#### Scenario: regresión — sin historial se mantiene el comportamiento de punta de rama

- GIVEN un plugin sin `releases.json` ni `versions[]` en el catálogo, cuya versión de punta es más nueva que la instalada
- WHEN se ejecuta `getAvailableUpdates()`
- THEN el plugin MUST listarse con la versión de punta de rama y los límites vigentes de esa rama
- AND el resultado MUST ser idéntico al comportamiento anterior a este change.

### PU-15 — Sin auto-downgrade ni resolución del grafo de dependencias

WHEN se resuelve o se lista la actualización de un plugin
THEN el sistema MUST NOT ofrecer ni aplicar una versión inferior o igual a la instalada (sin auto-downgrade).

WHEN varios plugins declaran dependencias entre sí mediante `require`
THEN esta capacidad MUST limitarse a la resolución por plugin
AND MUST NOT resolver versiones mixtas del grafo `require` (limitación conocida documentada, fuera del alcance de este change).

#### Scenario: no se ofrece degradación a nivel del actualizador

- GIVEN un plugin cuya única release compatible es igual o anterior a la versión instalada
- WHEN se ejecuta `getAvailableUpdates()`
- THEN MUST NOT listar una actualización para ese plugin.

#### Scenario: el grafo `require` no se resuelve en este change

- GIVEN dos plugins con una dependencia `require` que, combinada, exigiría versiones mixtas
- WHEN cada plugin se resuelve por separado
- THEN cada uno MUST resolver su propia versión compatible sin coordinar el grafo
- AND el sistema MUST NOT intentar resolver el grafo completo.
