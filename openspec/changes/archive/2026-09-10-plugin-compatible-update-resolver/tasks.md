# Tasks: plugin-compatible-update-resolver

Scope: `plugins/system_updater/` únicamente. Sin cambios en el core (`base/`, `src/`,
`controller/`, `model/` raíz). TDD estricto: cada método nuevo/alterado tiene su test RED
antes de su GREEN.

Runner: `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml`

## Wave 1 — Resolver puro (PU-12 / PU-15)

- [x] **T1** (RED) Crear `tests/PluginCompatibilityCheckerResolverTest.php` y cubrir todos los
  escenarios de PU-12/PU-15 sobre `plugin_compatibility_checker::resolveLatestCompatible()`:
  elige la más alta compatible cuando la punta es incompatible; `null` cuando ninguna es
  compatible; `null` cuando ninguna es más nueva que la instalada; nunca devuelve ≤ instalada;
  límite `min` igual → compatible / `min` mayor → incompatible; límite `max` igual → compatible
  / `max` menor → incompatible; límites vacíos → sin restricción (unbounded); comparación semver
  normalizada (`1.9` vs `1.10.0` → elige `1.10.0`); ignora entradas malformadas (no-array o sin
  `version`); determinista en dos invocaciones; `installed == ''` actúa como selector puro.
  El método aún no existe → el archivo falla (RED confirmado).
- [x] **T2** (GREEN) Implementar en `lib/plugin_compatibility_checker.php`
  `public static function resolveLatestCompatible(string $coreVersion, array $versions, string $installedVersion = ''): ?array`
  (Opción A del design). Reutilizar `evaluateCoreAgainstPlugin()` para compatibilidad de límites,
  `isRemoteVersionNewer()` para el filtro estricto de novedad (anti-downgrade) y
  `normalizeVersion()` + `version_compare(..., '>')` para el máximo. Sin I/O ni estado global;
  devuelve la entrada original completa o `null`. Dejar `PluginCompatibilityCheckerResolverTest` en verde.

## Wave 2 — Parser de historial (PU-11)

- [x] **T3** (RED) Extender `tests/PluginCompatibilityCheckerTest.php` con tests de
  `normalizeReleaseHistory()`: descarta elementos no-array y entradas sin `version` o que
  normalizan a `''`; conserva la referencia de descarga (`zip_url` / `catalog_id`); ante
  `min_version`/`max_version` ausentes devuelve `''`; historial inválido/no-array → `[]`;
  nunca lanza excepción. El método aún no existe → RED confirmado.
- [x] **T4** (GREEN) Implementar en `lib/plugin_compatibility_checker.php`
  `public static function normalizeReleaseHistory(array $raw): array` puro y defensivo:
  lista canónica de entradas válidas, normaliza límites con semántica `normalizeBounds()`
  (ausente → `''`), preserva los campos originales y omite entradas inválidas sin lanzar.
  Dejar los tests de `normalizeReleaseHistory` en verde.

## Wave 3 — Cableado del downloader (PU-13 / PU-14)

- [x] **T5** (RED) Extender `tests/PluginDownloaderUpdatesTest.php` (mockeando `downloads()` y
  `private_downloads()`): la entrada de actualización expone los límites (`min_version` /
  `max_version`) y `zip_link` del release resuelto con `resolved_from_history === true`;
  `versions[]` en la entrada del catálogo produce el mismo resultado que `releases`;
  no se lista actualización cuando el resolver devuelve `null`; no hay downgrade cuando la
  única release compatible es ≤ instalada; **regresión PU-14**: sin historial la salida es
  byte-idéntica a la actual (versión y límites de rama, sin claves nuevas como `zip_link`
  ni `resolved_from_history`). Si se necesita `hydrateDownloadList()`, acceder por subclase de
  test o reflexión: es `protected` (línea 201), no API pública.
- [x] **T6** (RED) Extender `tests/PluginDownloaderTest.php` con tests del nuevo loader
  `get_remote_plugin_releases()`: repo público vía raw content devuelve el historial parseado;
  404, error de red, JSON inválido, no-array o historial vacío → `[]`. Usar un doble que
  reemplace `fetchRemoteContents()` / el acceso remoto para mantener el test hermético.
- [x] **T7** (GREEN) En `lib/plugin_downloader.php`:
  - nuevo `protected function get_remote_plugin_releases(array $plugin_data, ?string $token = null): array`
    (público vía `raw.githubusercontent.com`; privado vía API con token; tolera 404/error/JSON
    inválido/no-array → `[]`; reutiliza `parseRepositoryUrl()`, rama por defecto `master`);
  - adjuntar `entry['releases']` en la hidratación del catálogo (`hydrateDownloadList()`,
    `protected`, línea 201) y en el bucle de hidratación de `private_downloads()`, cacheado con
    el mismo TTL;
  - agregar `string $coreVersion = ''` a la firma de
    `getAvailableUpdates(array $installedPlugins, string $coreVersion = '')` e implementar la rama
    de historial: detectar `entry['releases']`/`entry['versions']` → `normalizeReleaseHistory()`
    → `resolveLatestCompatible()`; exponer `new_version`, `min_version`, `max_version`, `zip_link`
    y `resolved_from_history = true`; `null` ⇒ no listar; sin historial ⇒ camino de punta de rama
    sin cambios. Dejar T5 y T6 en verde.

## Wave 4 — Propagación del ZIP resuelto al camino de descarga (riesgo crítico del design)

Sin esta wave, un release histórico resuelto se listaría pero se descargaría la punta de rama.

- [x] **T8** (RED) Agregar tests para la propagación: `download()` usa el override cuando es no
  vacío y conserva `$item['zip_link']` cuando es `null`/`''` (aserción sobre la URL efectivamente
  seleccionada con un doble que intercepte la descarga); y `findPublicUpdateByName()` — método
  **NUEVO** — devuelve la entrada resuelta para el plugin o `null`. RED confirmado.
- [x] **T9** (GREEN) Modificar `lib/plugin_downloader.php`:
  - cambiar la firma actual `public function download($plugin_id)` (línea 281) a
    `public function download($plugin_id, ?string $zipUrlOverride = null)`: usar el override
    cuando sea no vacío; con `null`/`''` mantener el `$item['zip_link']` de hoy (fallback intacto);
  - agregar el método **NUEVO**
    `public function findPublicUpdateByName(string $name, array $installedPlugins, string $coreVersion = ''): ?array`
    que devuelve la entrada de `getAvailableUpdates()` para ese plugin o `null`. Dejar T8 en verde.
- [x] **T10** (GREEN) En `controller/admin_updater.php`:
  - pasar `(string) $this->plugin_manager->version` como `$coreVersion` en los llamados a
    `getAvailableUpdates()` (líneas ~421, ~474, ~726, ~1087);
  - en `updateInstalledPlugin()` (línea ~951): tras ubicar `$publicEntry`, obtener la entrada
    resuelta; si `resolved_from_history === true` y hay `zip_link`, pasar el override a
    `download()`; en cualquier otro caso mantener `download((int) $publicEntry['id'])` como hoy.

## Wave 5 — Ajuste de tests existentes

- [x] **T11** (verde) En `tests/PluginDownloaderTest.php`, stubear `get_remote_plugin_releases()`
  para que devuelva `[]` en las pruebas de hidratación existentes: al hidratar ahora se realiza
  una petición adicional y el test debe permanecer hermético. Confirmar que los dos tests de
  hidratación siguen pasando.

## Wave 6 — Verify

- [x] **T12** Ejecutar `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml`
  y confirmar todo en verde (incluida la regresión PU-14). → 199 tests / 391 assertions / 0
  failures (2 warnings / 12 deprecations / 1 skip = baseline). VERDICT: FAIL — ver
  `verify-report.md` (CRITICAL de coherencia en el glue del controlador).
- [x] **T13** Producir `verify-report.md` con el mapeo PU-11..PU-15 → tests, evidencia RED→GREEN
  por wave y el resultado explícito de la regresión de fallback (PU-14).

## Notas de compatibilidad

- La adaptación es **por plugin y opcional**: publicar historial es aditivo y no rompe
  actualizadores viejos (que lo ignoran y usan la rama).
- Un plugin sin historial (`releases.json` ni `versions[]`) mantiene el camino de punta de rama
  exactamente como hoy (PU-14); los catálogos y plugins existentes no requieren cambios.
- Un `releases.json` ausente o malformado equivale a "sin historial" y cae al fallback; nunca es
  fatal.
- Límites por release inmutables: se derivan del `fsframework.ini` de su propio tag y no se
  reescriben retroactivamente (regla de proceso, skill `fsframework-milestone-release`).
- Sin auto-downgrade y sin resolución del grafo `require` entre plugins (limitación conocida,
  fuera de alcance).

## Review Workload Forecast

- Estimated changed lines (code + tests + docs): ~600–750 (código ~230, tests ~430, docs ~60).
- Files touched:
  - `plugins/system_updater/lib/plugin_compatibility_checker.php`
  - `plugins/system_updater/lib/plugin_downloader.php`
  - `plugins/system_updater/controller/admin_updater.php`
  - `plugins/system_updater/tests/PluginCompatibilityCheckerResolverTest.php` (nuevo)
  - `plugins/system_updater/tests/PluginCompatibilityCheckerTest.php`
  - `plugins/system_updater/tests/PluginDownloaderUpdatesTest.php`
  - `plugins/system_updater/tests/PluginDownloaderTest.php`
  - `plugins/system_updater/openspec/changes/plugin-compatible-update-resolver/verify-report.md` (nuevo)
- `Chained PRs recommended: Yes`
- `400-line budget risk: High`
- `Decision needed before apply: Yes`

Propuesta de slicing por work unit (si se encadena): (1) resolver puro + tests; (2) parser de
historial + tests; (3) cableado del downloader + tests de updates (incluye regresión PU-14);
(4) propagación del ZIP + tests y ajuste de tests de hidratación; (5) verify-report.
