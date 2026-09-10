# Design: plugin-compatible-update-resolver

## Contexto y objetivo

El actualizador resuelve hoy cada plugin contra una sola versión remota: la punta de
rama (`plugin_downloader::get_remote_plugin_ini()` + `zip_link` de la rama). Si el
release de punta declara `min_version`/`max_version` incompatibles con el core en
ejecución, la actualización se bloquea y no hay forma de retroceder al release más
nuevo que sí sea compatible.

Este change agrega un **resolver puro de la última versión compatible** sobre un
historial de releases por plugin (PU-11..PU-15) y lo cablea en
`plugin_downloader::getAvailableUpdates()`, manteniendo el camino de punta de rama
como *fallback* de primer nivel para todo plugin sin historial.

Alcance cerrado: sin auto-downgrade, sin resolución del grafo `require`, sin cambios
en el core. Todo el código vive en `plugins/system_updater/`.

## Componentes

| Componente | Responsabilidad | Cambia |
|------------|-----------------|--------|
| `plugin_compatibility_checker` | `resolveLatestCompatible()` (puro) y `normalizeReleaseHistory()` (parseo defensivo puro) | Sí |
| `plugin_downloader` | Hidratar el historial en cada entrada del catálogo; consumirlo en `getAvailableUpdates()`; `download()` con override de ZIP | Sí |
| `admin_updater` | Pasar la versión del core a `getAvailableUpdates()`; propagar el ZIP del release resuelto al descargar | Sí (acotado) |
| `fsframework-milestone-release` (skill) | Producir `releases.json` en tiempo de tag/release | No (referencia) |

No se toca `base/`, `src/`, `controller/` del core ni `model/` raíz.

## Modelo de datos del historial (PU-11)

### Forma preferida: `releases.json`

Archivo propio del plugin, en la raíz de su repositorio, junto a `fsframework.ini`.
Es un **arreglo JSON append-only**; cada elemento representa exactamente un release.

| Campo | Tipo | Obligatorio | Semántica |
|-------|------|-------------|-----------|
| `version` | string | Sí | Versión del release. Se normaliza con `normalizeVersion` (`v`/`1`/`1.1` → semver). |
| `min_version` | string | No | Límite inferior congelado del `fsframework.ini` de ese tag. Vacío/ausente = sin límite. |
| `max_version` | string | No | Límite superior congelado del `fsframework.ini` de ese tag. Vacío/ausente = sin límite. |
| `zip_url` | string | Sí\* | URL de descarga del archivo de ese tag (ej. `.../archive/refs/tags/v1.8.1.zip`). |
| `catalog_id` | int/string | No | Referencia alternativa a una fila de catálogo versionada. |

\* `zip_url` **o** `catalog_id`: la entrada debe tener al menos una referencia de
descarga utilizable para poder ofrecerse. Si no la tiene, el resolver puede
seleccionarla, pero el cableado no la lista (ver "Resolución de la referencia de
descarga").

```json
[
  {
    "version": "1.8.1",
    "min_version": "0.13",
    "max_version": "0.16",
    "zip_url": "https://github.com/eltictacdicta/tpvmod/archive/refs/tags/v1.8.1.zip"
  },
  {
    "version": "1.9.0",
    "min_version": "0.17",
    "max_version": "",
    "zip_url": "https://github.com/eltictacdicta/tpvmod/archive/refs/tags/v1.9.0.zip"
  }
]
```

Los límites de un release publicado son **inmutables**: se derivan del
`fsframework.ini` de su propio tag y no se reescriben retroactivamente.

### Forma alternativa: `versions[]` en la entrada del catálogo

La fila del catálogo (`custom_plugins.json` o catálogo privado) puede incluir un
arreglo `versions[]` con exactamente los mismos campos por release. Es un historial
equivalente a `releases.json`, sin I/O adicional porque ya viene en el JSON.

```json
{
  "id": 97,
  "nombre": "tpvmod",
  "version": "1.9.0",
  "zip_link": "https://github.com/eltictacdicta/tpvmod/archive/master.zip",
  "versions": [
    { "version": "1.8.1", "min_version": "0.13", "max_version": "0.16",
      "zip_url": "https://github.com/eltictacdicta/tpvmod/archive/refs/tags/v1.8.1.zip" },
    { "version": "1.9.0", "min_version": "0.17", "max_version": "",
      "zip_url": "https://github.com/eltictacdicta/tpvmod/archive/refs/tags/v1.9.0.zip" }
  ]
}
```

### Parseo defensivo y detección

`plugin_compatibility_checker::normalizeReleaseHistory(array $raw): array` es puro y
devuelve una lista canónica de entradas válidas:

- Descarta elementos que no sean arrays.
- Descarta entradas sin `version` o con `version` que normaliza a `''`.
- Normaliza `min_version`/`max_version` a string recortada; ausente → `''` (sin límite),
  consistente con `normalizeBounds()`.
- Normaliza `zip_url`/`catalog_id` a string/`''`; conserva los campos originales.
- Nunca lanza excepción: entrada inválida se omite, historial inválido devuelve `[]`.

Detección de historial en la entrada del catálogo, en `plugin_downloader`:

1. Si `entry['releases']` (de `releases.json`) es un arreglo válido no vacío → historial.
2. Si no, si `entry['versions']` es un arreglo válido no vacío → historial.
3. Si ninguno → **sin historial** → camino de punta de rama (fallback).

Precedencia: `releases.json` es la forma preferida y autoritativa cuando existe y
parsea; `versions[]` es el equivalente alternativo. La carga de `releases.json` ocurre
en la hidratación del catálogo (ver "Hidratación del historial"), de modo que
`getAvailableUpdates()` solo lee `entry['releases']`/`entry['versions']` ya presentes y
permanece sin I/O.

### Hidratación del historial

En `plugin_downloader::hydrateDownloadList()` (público) y en el bucle de hidratación de
`private_downloads()`:

- `entry['versions']` ya llega en el JSON del catálogo → no requiere red.
- `releases.json` se obtiene best-effort mediante un método nuevo
  `get_remote_plugin_releases(array $plugin_data, ?string $token = null): array`, que:
  - reutiliza `parseRepositoryUrl()` para derivar `user`, `repo` y `branch`
    (`branch` por defecto `master`);
  - para públicos usa raw content (`https://raw.githubusercontent.com/{user}/{repo}/{branch}/releases.json`);
  - para privados usa el endpoint de contenidos de la API con token, igual que
    `get_remote_plugin_ini()`;
  - devuelve `[]` ante 404, error de red, JSON inválido, no-array o historial vacío.
- El resultado se adjunta como `entry['releases']` y se cachea junto con la lista
  (mismo TTL, 180 s para públicos).

Tolerancia a fallos: un `releases.json` malformado o ausente equivale a "sin
historial" y cae al fallback. Nunca es fatal.

## Resolver puro (PU-12, PU-15)

### Firma y contrato — Decisión #1

**Opción elegida: A.**

```php
public static function resolveLatestCompatible(
    string $coreVersion,
    array $versions,
    string $installedVersion = ''
): ?array
```

La versión instalada es parte del contexto de resolución y se agrega como **tercer
parámetro opcional**, preservando la forma literal de dos argumentos de PU-12.

**Por qué A y no B.** PU-12 exige que el resolver "MUST NOT devolver una entrada cuya
`version` sea menor o igual que la versión instalada" y PU-15 refuerza que el sistema
no ofrezca una versión inferior o igual. Eso ubica el guardia de no-downgrade **dentro
del resolver**, no en el consumidor. La opción B (resolver de dos argumentos + guardia
en `getAvailableUpdates()`) dejaría escenarios de PU-12 ("devuelve null cuando ningún
release es más nuevo que el instalado", "nunca devuelve una versión menor o igual")
no testeables en el resolver puro y duplicaría la lógica de novedad en el downloader,
con riesgo de deriva. La opción A mantiene un único dueño de la decisión, sigue siendo
`static`, pura, determinista y sin I/O, y permite cubrir todos los escenarios de PU-12
con tests unitarios directos.

Semántica del parámetro:

- `$installedVersion !== ''`: se aplica el filtro estricto de novedad
  (`isRemoteVersionNewer`), descartando entradas iguales o inferiores.
- `$installedVersion === ''`: modo selector puro "más alta compatible", sin filtro de
  novedad (es la lectura literal de PU-12 sin instalada). En producción,
  `getAvailableUpdates()` **siempre** pasa la versión instalada, por lo que PU-15 nunca
  se relaja en el camino real.

Devuelve la **entrada original completa** (con su `zip_url`/`catalog_id`) o `null`.

### Algoritmo

```
resolveLatestCompatible(coreVersion, versions, installedVersion = ''):
    best     = null
    bestNorm = null
    for raw in versions:
        if raw is not array: continue
        version = trim((string)(raw['version'] ?? ''))
        norm = normalizeVersion(version)
        if norm === '': continue

        eval = evaluateCoreAgainstPlugin(
            coreVersion,
            (string)(raw['min_version'] ?? ''),
            (string)(raw['max_version'] ?? '')
        )
        if not eval['compatible']: continue

        if installedVersion !== '' and not isRemoteVersionNewer(version, installedVersion):
            continue

        if bestNorm === null or version_compare(norm, bestNorm, '>'):
            best     = raw
            bestNorm = norm

    return best
```

Puntos de implementación:

- **Compatibilidad de límites**: se reutiliza `evaluateCoreAgainstPlugin()`, que ya
  normaliza los extremos, trata vacío como sin límite y trata `coreVersion === ''` como
  "compatible" (semántica existente). No se reimplementa la comparación.
- **Novedad**: se reutiliza `isRemoteVersionNewer()`, que normaliza y compara con
  `version_compare(..., '>')`; por definición rechaza igual o inferior (anti-downgrade).
- **Selección del máximo**: `version_compare()` sobre la versión normalizada, nunca
  comparación de strings. En empate de versión normalizada gana la primera aparición
  (orden append-only ⇒ resultado determinista).
- **Sin I/O ni estado global**: el método no lee archivos, red, constantes mutables ni
  singletons; solo recibe datos y devuelve datos.

### Referencia de descarga

El resolver no interpreta la referencia de descarga; solo devuelve la entrada. La
resolución de la referencia la hace el cableado:

- `zip_url` no vacío → se usa directamente.
- si no, `catalog_id` presente → se busca la fila del catálogo por id y se usa su
  `zip_link` (solo es correcto si esa fila está fijada a la versión; en el catálogo
  actual, de una fila por plugin, lo práctico es `zip_url`).
- si no hay referencia utilizable → la entrada **no se ofrece** (evita descargar la
  punta de rama para un release histórico resuelto).

## Cableado del actualizador (PU-13)

### `getAvailableUpdates()` consume el resolver

Se agrega un parámetro opcional de versión de core para poder resolver contra el core
en ejecución:

```php
public function getAvailableUpdates(array $installedPlugins, string $coreVersion = ''): array
```

Los llamadores de producción (`admin_updater`) pasan
`(string) $this->plugin_manager->version`. Si llega `''`, los límites se evalúan con la
semántica existente de core desconocido (compatible), y el resultado sigue siendo
determinista.

Flujo por entrada pública (idéntico para privadas, con `private_downloads()`):

```
para cada entry de downloads() con instalado == true:
    installedVersion = version local de installedPlugins[name]
    history = normalizeReleaseHistory(entry['releases'] ?? entry['versions'] ?? [])

    si history != []:
        resolved = plugin_compatibility_checker::resolveLatestCompatible(
            coreVersion, history, installedVersion
        )
        si resolved === null: continue        # PU-13 / PU-15: no listar
        updateEntry = {
            name, description, source, id,
            current_version: normalizeVersion(installedVersion),
            new_version:     normalizeVersion(resolved['version']),
            min_version:     (string)(resolved['min_version'] ?? ''),
            max_version:     (string)(resolved['max_version'] ?? ''),
            zip_link:        <referencia de descarga>,
            resolved_from_history: true,
        }
        si <referencia de descarga> no es utilizable: continue
    si no:
        # Camino de punta de rama EXISTENTE, sin cambios (PU-14)
        ...lógica actual con entry['version'] / min / max...

    agregar updateEntry
```

Forma de la entrada de actualización:

| Campo | Camino historial | Camino fallback |
|-------|------------------|-----------------|
| `name`, `description`, `source`, `id` | Igual que hoy | Igual que hoy |
| `current_version` | `normalizeVersion(instalada)` | Igual que hoy |
| `new_version` | `normalizeVersion(resolved.version)` | `entry['version']` (rama) |
| `min_version` / `max_version` | Límites del release resuelto | Límites vigentes de la rama |
| `zip_link` | ZIP del release resuelto | **No se agrega** |
| `resolved_from_history` | `true` | **No se agrega** |

La clave `zip_link`/`resolved_from_history` **solo** se agrega en el camino de
historial, de modo que la salida del fallback es idéntica a la actual (PU-14).

### Propagación al camino de descarga

Para no descargar la punta de rama cuando se listó un release histórico resuelto:

- `download($plugin_id, ?string $zipUrlOverride = null)`: si el override es no vacío, se
  usa como URL del ZIP; si es `null`/`''`, se mantiene `$item['zip_link']` de hoy
  (fallback intacto).
- `plugin_downloader::findPublicUpdateByName(string $name, array $installedPlugins, string $coreVersion = ''): ?array`
  devuelve la entrada de `getAvailableUpdates()` para ese plugin (o `null`).
- `admin_updater::updateInstalledPlugin()`: tras ubicar `$publicEntry`, obtiene la
  entrada resuelta; si `resolved_from_history === true` y hay `zip_link`, pasa el
  override a `download()`. En cualquier otro caso llama `download((int) $publicEntry['id'])`
  como hoy.

El camino privado mantiene su comportamiento actual; la extensión de la propagación a
privados queda simétrica pero acotada a pasar el override cuando exista.

## Fallback retrocompatible (PU-14) — restricción de primer nivel

El fallback es una **rama explícita y única**, con esta prioridad en cada entrada:

1. Si hay historial válido → resolver; `null` ⇒ no listar.
2. Si no hay historial → camino de punta de rama **sin cambios**.

Garantías de diseño:

- La rama de fallback ejecuta el mismo código que hoy: usa `entry['version']`,
  `entry['min_version']`, `entry['max_version']` y `entry['zip_link']` de la rama, y
  produce exactamente la misma entrada de actualización que antes del change (sin
  `zip_link`/`resolved_from_history` añadidos).
- La hidratación de historial es aditiva: un plugin sin `releases.json` ni `versions[]`
  nunca entra al camino de resolución. Un `releases.json` malformado equivale a sin
  historial.
- Los catálogos y plugins existentes (todas las filas actuales de
  `custom_plugins.json`) no declaran historial y siguen funcionando igual.

**Test de regresión (escenario PU-14)**: `PluginDownloaderUpdatesTest` — entrada sin
`releases` ni `versions`, versión de punta más nueva que la instalada; se verifica que
el plugin se lista con la versión y los límites de la rama y que el arreglo resultante
es idéntico al comportamiento previo (mismos campos, sin claves nuevas). Este test debe
pasar sin tocar el camino de fallback.

## Producción del historial en release (enfoque) — Decisión #6

El historial lo produce el **momento de tag/release**, no el actualizador en runtime:

- Al cerrar un milestone, el skill `fsframework-milestone-release` ya contempla el
  append de la entrada del release al historial del plugin adaptado.
- La entrada se deriva del `fsframework.ini` de ese mismo tag: `version`, `min_version`,
  `max_version` congelados, y `zip_url` apuntando al archivo del tag
  (`.../archive/refs/tags/vX.Y.Z.zip`).
- Reglas: append-only e inmutable; una entrada publicada no se reescribe; el
  `fsframework.ini` del tag sigue siendo la fuente de verdad.
- La adaptación es por plugin y opcional; publicar historial es aditivo y no rompe
  actualizadores viejos (que lo ignoran y usan la rama).

No se especifica automatización de CI en este change; el enfoque es "el script de
release hace el append", nivel de proceso.

## Testing (strict TDD) — Decisión #7

Framework: PHPUnit 11, sin DB ni boot. Métodos puros estáticos, entradas armadas en el
test, sin red (los stubs reemplazan el I/O de hidratación).

Runner: `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml`

### Tests del resolver puro (`tests/PluginCompatibilityCheckerResolverTest.php`, nuevo)

| Test | Escenario PU | Aserción clave |
|------|--------------|----------------|
| `picksHighestCompatibleWhenTipIsIncompatible` | PU-12 | instalada `1.8.0`, core `0.14`; historial `1.8.1` (0.13–0.16) y `1.9.0` (0.17+) → devuelve `1.8.1` |
| `returnsNullWhenNoReleaseIsCompatible` | PU-12 | todas incompatibles por `min` o `max` → `null` |
| `returnsNullWhenNoReleaseIsNewerThanInstalled` | PU-12 | compatibles pero ≤ instalada → `null` |
| `neverReturnsVersionLowerOrEqualToInstalled` | PU-12/PU-15 | historial solo con ≤ instalada → `null` |
| `minBoundaryEqualIsCompatible` / `minGreaterIsIncompatible` | PU-12 | `min == core` compatible, `min > core` incompatible |
| `maxBoundaryEqualIsCompatible` / `maxLowerIsIncompatible` | PU-12 | `max == core` compatible, `max < core` incompatible |
| `emptyBoundsAreUnbounded` | PU-11/PU-12 | `min`/`max` vacíos no restringen |
| `comparesNormalizedSemverNotStrings` | PU-12 | `1.9` vs `1.10.0` → elige `1.10.0` (`normalizeVersion` + `version_compare`) |
| `ignoresMalformedEntries` | PU-11 | entradas no-array o sin `version` se omiten |
| `deterministicAcrossInvocations` | PU-12 | dos llamadas, mismo resultado |
| `emptyInstalledActsAsPureSelector` | PU-12 | sin instalada → mayor compatible |

### Tests de normalización del historial (`tests/PluginCompatibilityCheckerTest.php`, extender)

| Test | Escenario PU | Aserción |
|------|--------------|----------|
| `normalizeHistoryDropsInvalidEntries` | PU-11 | lista canónica solo con entradas válidas |
| `normalizeHistoryPreservesDownloadReference` | PU-11 | `zip_url`/`catalog_id` conservados |
| `normalizeHistoryDefaultsMissingBoundsToEmpty` | PU-11 | sin `min`/`max` → `''` |

### Tests de cableado del downloader (`tests/PluginDownloaderUpdatesTest.php`, extender)

| Test | Escenario PU | Patrón |
|------|--------------|--------|
| `updateEntryExposesResolvedReleaseBounds` | PU-13 | `downloads()` mock con `releases`; `new_version`/`min_version`/`max_version` del release resuelto y `zip_link` del release |
| `catalogVersionsArrayActsAsEquivalentHistory` | PU-11/PU-13 | `versions[]` en la entrada ⇒ mismo resultado que `releases` |
| `noUpdateListedWhenResolverReturnsNull` | PU-13 | historial sin release compatible ⇒ plugin ausente |
| `noDowngradeAtDownloaderLevel` | PU-15 | única compatible ≤ instalada ⇒ no listado |
| `regressionNoHistoryKeepsBranchTipBehavior` | PU-14 | sin historial ⇒ salida idéntica a la actual (versión/límites de rama, sin claves nuevas) |

### Ajustes a tests existentes

`PluginDownloaderTest` hidrata catálogo y hoy no espera el fetch de `releases.json`.
Las dos pruebas de hidratación deben stubear `get_remote_plugin_releases()` para
devolver `[]`, manteniendo el test hermético. `PluginDownloaderUpdatesTest` mockea
`downloads()` directamente, por lo que no se ve afectado.

### Orden RED→GREEN (estricto)

1. RED: tests del resolver (PU-12) → GREEN: `resolveLatestCompatible`.
2. RED: tests de `normalizeReleaseHistory` (PU-11) → GREEN: parser puro.
3. RED: tests de cableado y no-downgrade en `getAvailableUpdates` (PU-13/PU-15) → GREEN:
   rama de historial en el downloader.
4. RED: test de regresión de fallback (PU-14) → debe seguir GREEN sin tocar el fallback.
5. GREEN: override de `download()` y propagación en `admin_updater`.

## Decisiones cerradas (resumen)

| # | Decisión | Resolución |
|---|----------|------------|
| 1 | Firma del resolver y ubicación del guardia | **Opción A**: `resolveLatestCompatible(core, versions, installed = '')`; el guardia de no-downgrade vive en el resolver puro |
| 2 | Forma del historial | `releases.json` (preferida, autoritativa) o `versions[]` del catálogo; parseo defensivo puro; ausente/malformado ⇒ fallback |
| 3 | Algoritmo | Filtrar por compatibilidad (`evaluateCoreAgainstPlugin`) → filtrar estricto-más-nueva (`isRemoteVersionNewer`) → máximo por semver normalizado (`version_compare`); devolver entrada o `null` |
| 4 | Cableado | `getAvailableUpdates()` resuelve cuando hay historial, expone versión/límites/ZIP del release resuelto; `null` ⇒ no listar; `download()` acepta override de ZIP |
| 5 | Fallback | Rama explícita y única; salida idéntica a la actual; test de regresión PU-14 |
| 6 | Producción del historial | Append en tag/release desde el `fsframework.ini` del tag, vía skill `fsframework-milestone-release` |
| 7 | Testing | TDD estricto; tests puros del resolver/parser + tests de cableado con mocks; runner del plugin |

## Riesgos y limitaciones

- **I/O adicional**: hidratar `releases.json` agrega una petición de raw content por
  entrada del catálogo (cacheada 180 s). Es best-effort y tolera 404; no usa la API de
  GitHub para públicos, evitando el límite de 60/h.
- **Inmutabilidad del historial**: reescribir una entrada publicada rompe la
  reproducibilidad. Es una regla de proceso (skill de release), no algo que el resolver
  pueda imponer.
- **Grafo `require` no resuelto**: la resolución es por plugin. Combinaciones de
  versiones mixtas entre plugins quedan fuera de alcance (documentado en el proposal y
  en PU-15).
- **Override de descarga**: si no se propaga `zip_link` al descargar, se listaría un
  release histórico pero se bajaría la punta de rama. La propagación en `download()` +
  `admin_updater` es obligatoria para la coherencia; los tests de cableado cubren la
  exposición, y corresponde a tasks cubrir la propagación.
- **Core desconocido**: si un llamador no pasa `coreVersion`, la compatibilidad se
  evalúa como "compatible" (semántica existente). Los llamadores de producción pasan la
  versión real; los tests la pasan explícita.
- **Presupuesto de revisión (≈400 líneas)**: el resolver/parser y el cableado entran
  holgados, pero la cobertura TDD puede acercarse al límite. Si se excede, priorizar los
  escenarios PU-12/PU-13/PU-14 y diferir variantes paramétricas no exigidas por el spec.
