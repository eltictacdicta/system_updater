<?php
/**
 * Comprobaciones de compatibilidad de plugins con la versión del core.
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

class plugin_compatibility_checker
{
    /**
     * @return array{compatible: bool, violation: ?string, message: ?string}
     */
    public static function evaluateCoreAgainstPlugin(
        string $coreVersion,
        string $minVersion = '',
        string $maxVersion = ''
    ): array {
        $core = self::normalizeVersion($coreVersion);
        if ($core === '') {
            return [
                'compatible' => true,
                'violation' => null,
                'message' => null,
            ];
        }

        $min = $minVersion !== '' ? self::normalizeVersion($minVersion) : '';
        $max = $maxVersion !== '' ? self::normalizeVersion($maxVersion) : '';

        if ($min !== '' && version_compare($core, $min, '<')) {
            return [
                'compatible' => false,
                'violation' => 'min',
                'message' => 'Requiere FSFramework ' . $minVersion,
            ];
        }

        if ($max !== '' && version_compare($core, $max, '>')) {
            return [
                'compatible' => false,
                'violation' => 'max',
                'message' => 'Compatible hasta FSFramework ' . $maxVersion,
            ];
        }

        return [
            'compatible' => true,
            'violation' => null,
            'message' => null,
        ];
    }

    /**
     * @param array{min_version?: string, max_version?: string} $bounds
     *
     * @return array{min_version: string, max_version: string}
     */
    public static function normalizeBounds(array $bounds): array
    {
        return [
            'min_version' => self::stringOrEmpty($bounds['min_version'] ?? ''),
            'max_version' => self::stringOrEmpty($bounds['max_version'] ?? ''),
        ];
    }

    /**
     * @param array{min_version?: string, max_version?: string} $catalogEntry
     *
     * @return array{min_version: string, max_version: string}
     */
    public static function boundsFromCatalogEntry(array $catalogEntry): array
    {
        return self::normalizeBounds($catalogEntry);
    }

    /**
     * Decide si hay que advertir antes de actualizar el core.
     *
     * Si el plugin local no cumple max_version pero hay una actualización remota
     * cuyo fsframework.ini sí es compatible con la versión destino del core,
     * no se emite advertencia (se asume actualización conjunta).
     *
     * @param array{min_version?: string, max_version?: string} $localBounds
     * @param array{min_version?: string, max_version?: string}|null $pendingRemoteBounds
     */
    public static function shouldWarnCoreUpdateForPlugin(
        string $targetCoreVersion,
        array $localBounds,
        ?array $pendingRemoteBounds = null
    ): bool {
        $local = self::normalizeBounds($localBounds);
        $localEval = self::evaluateCoreAgainstPlugin(
            $targetCoreVersion,
            $local['min_version'],
            $local['max_version']
        );

        if ($localEval['compatible']) {
            return false;
        }

        if ($pendingRemoteBounds !== null) {
            $remote = self::normalizeBounds($pendingRemoteBounds);
            $remoteEval = self::evaluateCoreAgainstPlugin(
                $targetCoreVersion,
                $remote['min_version'],
                $remote['max_version']
            );

            if ($remoteEval['compatible']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $enabledPluginNames
     * @param array<string, array{min_version?: string, max_version?: string}> $pendingRemoteBoundsByPlugin
     *
     * @return list<array{type: string, level: string, message: string, plugin?: string, max_version?: string}>
     */
    public static function getCoreUpdateWarnings(
        string $targetCoreVersion,
        array $enabledPluginNames,
        ?string $fsRoot = null,
        array $pendingRemoteBoundsByPlugin = []
    ): array {
        $root = $fsRoot ?? (defined('FS_FOLDER') ? FS_FOLDER : '.');
        $warnings = [];

        foreach ($enabledPluginNames as $pluginName) {
            if (!is_string($pluginName) || $pluginName === '' || str_ends_with($pluginName, '_back')) {
                continue;
            }

            $localBounds = self::readPluginVersionBounds($pluginName, $root);
            $pendingRemote = $pendingRemoteBoundsByPlugin[$pluginName] ?? null;

            if (!self::shouldWarnCoreUpdateForPlugin($targetCoreVersion, $localBounds, $pendingRemote)) {
                continue;
            }

            $localEval = self::evaluateCoreAgainstPlugin(
                $targetCoreVersion,
                $localBounds['min_version'],
                $localBounds['max_version']
            );

            $message = $localEval['message'] ?? ('Posible incompatibilidad con FSFramework ' . self::normalizeVersion($targetCoreVersion));
            if ($pendingRemote !== null) {
                $message .= ' La versión remota pendiente tampoco declara compatibilidad con el núcleo destino.';
            } else {
                $message .= ' No hay actualización remota pendiente en el catálogo; actualiza el plugin o desactívalo antes de continuar.';
            }

            $warnings[] = [
                'type' => $localEval['violation'] === 'min' ? 'plugin_min_version' : 'plugin_max_version',
                'level' => 'warning',
                'plugin' => $pluginName,
                'max_version' => $localBounds['max_version'],
                'message' => 'El plugin activo "' . $pluginName . '": ' . $message,
            ];
        }

        return $warnings;
    }

    /**
     * Valida si la versión remota de un plugin es compatible con el core actual/destino.
     *
     * @param array{min_version?: string, max_version?: string} $remoteBounds
     *
     * @return array{compatible: bool, violation: ?string, message: ?string}
     */
    public static function validateRemotePluginForCore(string $coreVersion, array $remoteBounds): array
    {
        $remote = self::normalizeBounds($remoteBounds);

        return self::evaluateCoreAgainstPlugin(
            $coreVersion,
            $remote['min_version'],
            $remote['max_version']
        );
    }

    /**
     * Decide si una entrada de actualización puede aplicarse contra el núcleo en ejecución.
     *
     * Las entradas resueltas desde el historial (`resolved_from_history === true`)
     * se validan con los límites del release resuelto, de modo que una punta de
     * rama más nueva pero incompatible con el core no bloquee un release
     * histórico que sí es compatible. Las entradas de fallback (sin historial)
     * se validan con los límites de la punta de rama, exactamente como hoy (PU-14).
     *
     * Puro: no realiza I/O ni depende de estado global.
     *
     * @param array<string, mixed> $updateEntry Entrada de getAvailableUpdates()
     * @param array<string, mixed> $branchTipEntry Fila del catálogo en la punta de rama
     *
     * @return array{compatible: bool, violation: ?string, message: ?string}
     */
    public static function evaluateUpdateEntryForCore(
        string $coreVersion,
        array $updateEntry,
        array $branchTipEntry
    ): array {
        $boundsSource = (($updateEntry['resolved_from_history'] ?? false) === true)
            ? $updateEntry
            : $branchTipEntry;

        return self::validateRemotePluginForCore(
            $coreVersion,
            self::boundsFromCatalogEntry($boundsSource)
        );
    }

    /**
     * Clasifica una actualización remota respecto al núcleo actual y al destino (PU-08).
     *
     * @param array{min_version?: string, max_version?: string} $remoteBounds
     *
     * @return array{
     *     compatible_with_current_core: bool,
     *     compatible_with_target_core: bool,
     *     blocked_by_core: bool,
     *     incompatible: bool,
     *     required_core_version: string,
     *     message: ?string,
     *     update_status: 'ready'|'blocked_by_core'|'incompatible'
     * }
     */
    public static function classifyPluginUpdateAgainstCore(
        string $currentCoreVersion,
        ?string $targetCoreVersion,
        array $remoteBounds
    ): array {
        $bounds = self::normalizeBounds($remoteBounds);
        $currentEval = self::validateRemotePluginForCore($currentCoreVersion, $bounds);

        $targetCore = trim((string) $targetCoreVersion);
        if ($targetCore === '') {
            $targetCore = $currentCoreVersion;
        }

        $targetEval = self::validateRemotePluginForCore($targetCore, $bounds);
        $blockedByCore = !$currentEval['compatible'] && $targetEval['compatible'];
        $incompatible = !$currentEval['compatible'] && !$targetEval['compatible'];

        $requiredCore = '';
        if ($blockedByCore && ($currentEval['violation'] ?? null) === 'min') {
            $requiredCore = $bounds['min_version'];
        } elseif ($blockedByCore && $targetCore !== '') {
            $requiredCore = $targetCore;
        }

        $updateStatus = $currentEval['compatible']
            ? 'ready'
            : ($blockedByCore ? 'blocked_by_core' : 'incompatible');

        return [
            'compatible_with_current_core' => $currentEval['compatible'],
            'compatible_with_target_core' => $targetEval['compatible'],
            'blocked_by_core' => $blockedByCore,
            'incompatible' => $incompatible,
            'required_core_version' => $requiredCore,
            'message' => $currentEval['message'],
            'update_status' => $updateStatus,
        ];
    }

    /**
     * @param list<array<string, mixed>> $pluginUpdates
     *
     * @return list<array<string, mixed>>
     */
    public static function enrichPluginUpdatesWithCoreCompatibility(
        array $pluginUpdates,
        string $currentCoreVersion,
        string $targetCoreVersion = ''
    ): array {
        $enriched = [];

        foreach ($pluginUpdates as $plugin) {
            if (!is_array($plugin)) {
                continue;
            }

            $classification = self::classifyPluginUpdateAgainstCore(
                $currentCoreVersion,
                $targetCoreVersion !== '' ? $targetCoreVersion : null,
                self::normalizeBounds($plugin)
            );

            $enriched[] = array_merge($plugin, $classification);
        }

        return $enriched;
    }

    /**
     * @return array{min_version: string, max_version: string}
     */
    public static function readPluginVersionBounds(string $pluginName, string $fsRoot): array
    {
        $bounds = [
            'min_version' => '',
            'max_version' => '',
        ];

        $iniPath = rtrim($fsRoot, '/') . '/plugins/' . $pluginName . '/fsframework.ini';
        if (!is_file($iniPath)) {
            return $bounds;
        }

        $ini = @parse_ini_file($iniPath, true);
        if (!is_array($ini)) {
            return $bounds;
        }

        $section = $ini;
        if (isset($ini['plugin']) && is_array($ini['plugin'])) {
            $section = $ini['plugin'];
        }

        return self::normalizeBounds($section);
    }

    /**
     * Resuelve la entrada de release más alta compatible con el core en ejecución.
     *
     * Puro: no realiza I/O ni depende de estado global. Devuelve la entrada
     * original completa (con su referencia de descarga) o null.
     *
     * @param list<array<string, mixed>> $versions Historial de releases del plugin.
     * @param string $installedVersion Versión instalada; '' desactiva el filtro de novedad.
     *
     * @return array<string, mixed>|null
     */
    public static function resolveLatestCompatible(
        string $coreVersion,
        array $versions,
        string $installedVersion = ''
    ): ?array {
        $best = null;
        $bestNorm = null;

        foreach ($versions as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $version = self::stringOrEmpty($raw['version'] ?? '');
            $norm = self::normalizeVersion($version);
            if ($norm === '') {
                continue;
            }

            $eval = self::evaluateCoreAgainstPlugin(
                $coreVersion,
                self::stringOrEmpty($raw['min_version'] ?? ''),
                self::stringOrEmpty($raw['max_version'] ?? '')
            );
            if (!$eval['compatible']) {
                continue;
            }

            if ($installedVersion !== '' && !self::isRemoteVersionNewer($version, $installedVersion)) {
                continue;
            }

            if ($bestNorm === null || version_compare($norm, $bestNorm, '>')) {
                $best = $raw;
                $bestNorm = $norm;
            }
        }

        return $best;
    }

    /**
     * Normaliza un historial crudo de releases a una lista canónica de entradas válidas.
     *
     * Puro y defensivo: descarta elementos no-array y entradas sin `version`
     * (o cuya versión normaliza a ''). Normaliza `version` y los límites
     * `min_version`/`max_version` (ausentes → ''), preservando el resto de los
     * campos originales (referencia de descarga incluida). Nunca lanza.
     *
     * @param array<mixed> $raw
     *
     * @return list<array<string, mixed>>
     */
    public static function normalizeReleaseHistory(array $raw): array
    {
        $normalized = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $version = self::stringOrEmpty($entry['version'] ?? '');
            if (self::normalizeVersion($version) === '') {
                continue;
            }

            $bounds = self::normalizeBounds($entry);
            $entry['version'] = $version;
            $entry['min_version'] = $bounds['min_version'];
            $entry['max_version'] = $bounds['max_version'];

            $normalized[] = $entry;
        }

        return $normalized;
    }

    public static function normalizeVersion(string $version): string
    {
        if (function_exists('fs_normalize_plugin_version')) {
            return fs_normalize_plugin_version($version);
        }

        $version = trim($version);
        if ($version === '') {
            return '';
        }

        if (preg_match('/^v/i', $version)) {
            $version = ltrim(substr($version, 1));
        }

        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $matches)) {
            return $matches[1] . '.' . $matches[2] . '.' . $matches[3];
        }

        if (preg_match('/^(\d+)\.(\d+)$/', $version, $matches)) {
            return $matches[1] . '.' . $matches[2] . '.0';
        }

        if (preg_match('/^(\d+)$/', $version, $matches)) {
            return $matches[1] . '.0.0';
        }

        if (preg_match('/v?(\d+(?:\.\d+)+)/i', $version, $matches)) {
            return $matches[1];
        }

        return $version;
    }

    public static function isRemoteVersionNewer(string $remoteVersion, string $localVersion): bool
    {
        $remote = self::normalizeVersion($remoteVersion);
        $local = self::normalizeVersion($localVersion);

        if ($remote === '' || $local === '') {
            return false;
        }

        return version_compare($remote, $local, '>');
    }

    /**
     * Convierte un valor arbitrario a string recortado; los no escalares (y null)
     * se consideran ausentes y devuelven ''.
     */
    private static function stringOrEmpty(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
