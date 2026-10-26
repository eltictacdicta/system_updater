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

### PU-16 — Una actualización del core MUST NOT escribir el árbol de planificación del core en la instalación

Después de una extracción correcta de una actualización del core, el actualizador MUST eliminar
los directorios no operativos que acaba de escribir, a partir de una **lista blanca explícita**.
La lista blanca MUST ser exactamente:

`openspec/`, `docs/`, `tools/`, `extras/`, `odd/`, `dev-tools/`, `managed_context/`,
`test_suite_analysis/`

La eliminación MUST estar gobernada por esa lista literal — **nunca un comodín, nunca un patrón y
nunca "todo lo que no esté en una lista de conservación"**. Una regla expresada como "borrar lo que
no sea operativo" eliminaría el próximo directorio que añada el core antes de que nadie haya
decidido que es prescindible; la lista blanca es la única forma que falla en cerrado.

Los ocho nombres MUST declararse en un único lugar del plugin y ser consumidos por la eliminación,
de modo que la lista y el código no puedan divergir.

**La poda pertenece a `core_updater::extractZipSafe()`**

La poda MUST ejecutarse dentro de `core_updater::extractZipSafe()`, inmediatamente después de que
`$zip->extractTo($destination)` devuelva `true`, y antes de que el método retorne. MUST ser un
único helper compartido, para que la segunda rama de origen quede cubierta por la misma lista y las
mismas reglas: `core_updater::prepareCoreSource()` también devuelve un árbol completo cuando
`cloneGitRepository()` tiene éxito, y un clon de `master` arrastra los mismos ocho directorios.
Ambas ramas de origen MUST podarse antes de que `copyDirectorySelective()` escriba nada en la
instalación. Una poda sólo en la rama ZIP dejaría la propiedad falsa en la rama que el actualizador
prefiere cuando `git` está disponible. Se permite —sin exigirse— añadir los ocho nombres a
`coreRootCopyExcludes()` como defensa en profundidad.

**El directorio base es el que se copia, no el destino exterior.** Un archivo de rama se extrae en
su propio directorio de nivel superior (`fs-framework-master/`), un nivel por debajo del
`$destination` que recibe `extractZipSafe()`; `findFirstDirectory()` existe precisamente para
encontrarlo, y es lo que devuelve `prepareCoreSource()` y lo que lee la copia. Podar sólo el
`$destination` exterior no encontraría ninguno de los ocho, reportaría éxito y no cambiaría nada —
una implementación vacua que satisface a un test descuidado y deja el árbol en su sitio. La
propiedad exigida se enuncia sobre el directorio que se entrega a `copyDirectorySelective()`:
después de que el actualizador prepare un origen, **ese** directorio no contiene ninguno de los
ocho. Una implementación MUST por tanto resolver la raíz efectiva del origen y podar allí, y MUST
cubrir las dos formas de la rama ZIP (un archivo de rama con su propio directorio de nivel superior,
y un ZIP subido cuya raíz ya es el árbol del core).

**La poda MUST NOT tocar datos operativos ni del usuario**

La lista blanca es cerrada. Nada fuera de ella MUST eliminarse. En particular la poda MUST NOT
tocar `vendor/`, `themes/`, `view/`, `plugins/`, `src/`, `base/`, `model/`, `controller/`,
`images/`, `imgs/`, `tmp/`, `backups/`, `apk/`, `translations/`, `config/`, `config.php`,
`.git/`, `.htaccess`, ni ningún directorio que contenga un backup creado por este plugin.

El `openspec/` propio de un plugin vive en `plugins/<plugin>/openspec/` y por tanto **no está en
riesgo**: cuelga de `plugins/`, que es un único segmento de ruta que nunca está en la lista blanca,
y la eliminación nunca recursa en un directorio que no se le indicó. Los artefactos SDD de los
plugins — incluido el `plugins/system_updater/openspec/` de este mismo change — MUST sobrevivir a
toda actualización del core. Lo mismo se aplica a un directorio `docs/` o `tools/` anidado dentro de
un plugin o un tema: sólo el directorio de **nivel superior** nombrado en la lista blanca es
candidato.

**La eliminación MUST estar confinada y MUST NOT seguir enlaces simbólicos hacia fuera**

Cada eliminación MUST quedar confinada al directorio de staging que pobló
`prepareCoreSource()` (`<root>/tmp/core_update` y su único hijo de nivel superior). La poda MUST NOT
invocarse nunca sobre `$this->rootPath` (la instalación).

Para cada nombre, el helper MUST resolver el candidato y rechazarlo salvo que resuelva **dentro** del
directorio de staging: una ruta que resuelva fuera MUST rechazarse y reportarse, y nada fuera MUST
eliminarse. La eliminación MUST NOT seguir un enlace simbólico fuera del directorio de staging y
MUST NOT borrar a través de un enlace: un candidato que sea un enlace simbólico se rechaza o se
desenlaza como enlace, nunca se atraviesa.

**Idempotencia: un directorio que no se puede eliminar se reporta, no es fatal**

Un directorio ausente **no es un error**: la poda es idempotente y ejecutarla dos veces sobre el
mismo árbol MUST producir ningún error nuevo, ningún mensaje nuevo y ningún cambio de resultado.

Un directorio que no se puede eliminar (permisos, un montaje de sólo lectura, un archivo bloqueado en
el host) MUST reportarse **sin hacer fallar la actualización**. El operador MUST poder verlo: el
resultado de la actualización MUST nombrar el directorio que sobrevivió, de modo que "el árbol sigue
ahí" sea visible en el resultado y no se infiera desde el servidor web. Una poda parcial MUST dejar
que se intenten los nombres restantes, y la actualización MUST completarse con `success` sin cambios
por el resultado de la poda.

**La poda sólo se ejecuta tras una extracción correcta**

La poda MUST ejecutarse sólo después de una extracción **correcta** (`extractTo()` devolvió `true`,
o el directorio del clon existe y está completo). MUST NOT ejecutarse sobre una extracción fallida,
rechazada o parcial: una extracción fallida ya se reporta y se limpia como una unidad, y borrar de un
árbol a medio escribir convierte un fallo en un segundo fallo no reportado.

**Dos puntos de aplicación, una sola decisión — y un test que lo demuestre**

La lista blanca del plugin y el bloque de denegación de `nginx-sample.conf` del core son **dos
puntos de aplicación de una sola decisión**: el sistema de archivos (no escribirlo nunca) y el borde
(negarse a servirlo). MUST NOT divergir. Un nombre añadido a uno y no al otro es un defecto de este
requisito, en cualquiera de las dos direcciones.

Esa divergencia MUST detectarla un test, no la revisión. El test MUST afirmar que la lista blanca del
plugin es igual al conjunto de nombres de directorio de nivel superior que la muestra del core deniega
en su bloque "Planning, documentation and analysis trees" — los ocho nombres anteriores y ningún
otro. La comparación MUST NOT absorber las demás denegaciones de la muestra (`/vendor/`, `/tmp/`,
`/backups/`, `/.git/`) ni sus denegaciones a nivel de archivo: son las rutas operativas y de operador
que este requisito MUST NOT tocar, y tratarlas como miembros del conjunto ensancharía la lista blanca
en silencio.

La muestra del core vive en `../../nginx-sample.conf` respecto del directorio del plugin
(`plugins/system_updater` → la raíz del repositorio del core), que es `dirname(__DIR__, 3)` desde un
archivo bajo `plugins/system_updater/tests/`. El test PUEDE leer ese archivo. Como este plugin también
se distribuye como repositorio independiente, donde la muestra del core no existe, el test MUST
degradar con elegancia cuando falte — y MUST decirlo en lugar de pasar de forma vacua:

- la afirmación **siempre ejecutable** MUST ejecutarse igualmente: fija la lista blanca a los ocho
  nombres literales que este requisito enuncia, de modo que una eliminación o adición silenciosa es
  RED con y sin el repositorio del core presente;
- la afirmación de divergencia MUST omitirse de forma **visible**, con un mensaje que nombre la ruta
  resuelta que buscó y declare que la comparación de las dos listas **no** se ejecutó. Una suite en
  verde con un skip no advertido es el pase vacuo que esta cláusula existe para prohibir.

**No sustituye al borde, ni es la misma decisión que `coreRootCopyExcludes()`**

Este requisito no exige a ningún operador desplegar la muestra de nginx, configurar un proxy inverso
ni cambiar un servidor. Elimina la necesidad de que esa decisión sea la *única*.

Añadir los ocho nombres a `coreRootCopyExcludes()` PUEDE hacerse como defensa en profundidad, pero
MUST NOT ser el único mecanismo: la exclusión en la copia por sí sola deja igualmente el árbol
completo dentro del webroot, bajo `tmp/core_update`, durante toda la actualización, porque ese
directorio sólo se elimina después de la copia.

#### Scenario: una actualización nueva elimina los ocho y deja intactos los directorios operativos

- GIVEN un directorio de staging que contiene los ocho directorios de planificación más `vendor/`,
  `themes/`, `view/`, `plugins/`, `src/`, `base/`, `model/`, `controller/`, `images/`, `imgs/`,
  `tmp/`, `backups/`, `config.php` y `.htaccess` — en las dos formas: directamente en la raíz (un ZIP
  subido) y un nivel por debajo de `<repo>-<branch>/` (un archivo de rama)
- WHEN una actualización del core extrae correctamente
- THEN ninguno de los ocho directorios de planificación existe en el directorio desde el que la
  actualización copia después
- AND toda entrada operativa y de datos de usuario enumerada arriba sigue presente y sin modificar
- AND la raíz de la instalación resultante no contiene ninguno de los ocho

#### Scenario: una segunda actualización no hace nada

- GIVEN un directorio de staging del que los ocho directorios ya se eliminaron
- WHEN la poda se ejecuta otra vez
- THEN no elimina nada
- AND no registra ningún error ni ningún mensaje
- AND el resultado de la actualización es idéntico al de la primera ejecución

#### Scenario: un directorio ausente no hace fallar la actualización

- GIVEN un árbol de origen que nunca contuvo uno de los ocho nombres
- WHEN la poda se ejecuta
- THEN el nombre ausente no es un error
- AND la actualización se completa con `success` verdadero

#### Scenario: un directorio que no se puede eliminar se reporta y la actualización se completa

- GIVEN que uno de los ocho directorios no se puede eliminar
- WHEN la actualización termina
- THEN el resultado de la actualización nombra ese directorio como no eliminado
- AND la actualización se completa igualmente con `success` verdadero
- AND el directorio superviviente es el único que el resultado nombra

#### Scenario: una ruta que resuelve fuera del staging se rechaza

- GIVEN un candidato dentro del directorio de staging que resuelve fuera de él (un enlace simbólico
  que apunta fuera del árbol, o una ruta resuelta que no comparte el prefijo del staging)
- WHEN la poda lo evalúa
- THEN se rechaza y se reporta
- AND nada fuera del directorio de staging se elimina
- AND el enlace simbólico no se atraviesa

#### Scenario: los artefactos SDD de los plugins sobreviven

- GIVEN una instalación con `plugins/system_updater/openspec/` y el `plugins/<name>/openspec/` de
  otro plugin
- WHEN una actualización del core termina
- THEN ambos directorios y sus archivos siguen presentes

#### Scenario: una extracción fallida nunca se poda

- GIVEN una extracción que devuelve `false` (un nombre de entrada rechazado, un archivo ilegible, un
  `ZipArchive` ausente)
- WHEN la actualización aborta
- THEN la poda no se ejecutó
- AND ningún directorio de planificación se eliminó del árbol parcial
- AND el fallo reportado es el fallo de extracción, sin cambios

#### Scenario: la rama de clonado por git también se poda, y la raíz de la instalación nunca

- GIVEN un origen preparado por `prepareCoreSource()` mediante `cloneGitRepository()`
- WHEN la actualización continúa hacia la copia
- THEN los ocho directorios se eliminan del directorio del clon
- AND la poda nunca se invocó sobre la raíz de la instalación
- AND un `openspec/` preexistente en la raíz de la instalación no fue creado ni eliminado por la poda

#### Scenario: la lista blanca y la muestra del core no pueden divergir

- GIVEN la lista blanca declarada por el plugin
- WHEN el test enumera los nombres de directorio de nivel superior denegados en el bloque de
  planificación de la muestra del core
- THEN los dos conjuntos son idénticos y contienen los ocho nombres anteriores
- AND cuando la muestra del core está ausente, la afirmación de los ocho nombres se ejecuta igualmente
  y la comparación se reporta como omitida con la ruta resuelta que buscó

**Consecuencias registradas (nombradas, no ocultas)**

| # | Consecuencia | Por qué se acepta / qué significa |
|---|---|---|
| 1 | `docs/` y `extras/` desaparecen de una instalación en la **primera** actualización posterior a este cambio; los otros seis sólo si nunca se escribieron | `cleanupRootDirectories()` ya borra `docs/` y `extras/` de la raíz antes de la copia y hoy la copia los restaura; una vez que el origen ya no los lleva, la restauración no puede ocurrir |
| 2 | Las instalaciones que ya recibieron el árbol conservan los seis directorios restantes hasta que un operador los elimine | Este requisito no elimina nada que no acabe de escribir: la poda está confinada al directorio de staging y tiene prohibido explícitamente ejecutarse contra la raíz de la instalación. Esas rutas siguen cubiertas por la denegación de borde (`nginx-sample.conf`), que es el segundo punto de aplicación |
| 3 | Un desarrollador que trabaja sobre un **checkout git** y ejecuta el actualizador pierde estos directorios del árbol de trabajo | El actualizador ya borra `docs/`, `extras/`, `vendor/` y más de un checkout (`cleanupRootDirectories()`); la ruta de actualización no es la ruta de desarrollo. Un `git checkout -- .` los restaura y ningún comportamiento operativo depende de ellos |
| 4 | `tools/phpstan-plugins.php` y `dev-tools/` dejan de estar presentes en una instalación actualizada | Son herramientas de desarrollo propiedad del core que se invocan con `composer phpstan` / `composer phpstan:plugins`, comandos de desarrollo, no operativos. La puerta de PHPStan no se ve afectada porque se ejecuta en un clon, no en una instalación actualizada |
| 5 | Las instalaciones ZIP de plugins (`plugin_downloader::extractZipSafe()` y `updater_manager::extractZipSafe()`) **no** se tocan | Extraen dentro de `plugins/`, así que el `openspec/` propio de un plugin aterriza bajo `plugins/<name>/` — el directorio que este requisito protege. Extender la poda a ellos eliminaría artefactos SDD de plugins |
| 6 | Un árbol de origen de terceros o hecho a mano (un ZIP subido por el operador) se poda con la misma lista | La ruta de subida (`prepareCoreSource()`) pasa por el mismo `extractZipSafe()`. Un ZIP de proveedor que legítimamente incluya un directorio `docs/` lo pierde; es la misma decisión aplicada a todo origen |
