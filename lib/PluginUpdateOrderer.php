<?php
/**
 * Ordenador de plugins por dependencias para actualizaciones en lote.
 *
 * Aplica el algoritmo de Kahn sobre el grafo de dependencias de un lote de
 * plugins: las dependencias instaladas (directas y transitivas) se ordenan
 * antes que sus dependientes, incluso cuando no forman parte del lote.
 *
 * Semántica:
 * - Ciclos: no lanza excepción; los miembros del ciclo conservan su orden
 *   original en el lote y se registra una advertencia única en error_log.
 * - Auto-dependencias: se ignoran (no aportan restricción de orden).
 * - Dependencias no instaladas: se omiten sin bloquear el lote.
 * - Miembros del lote desconocidos (sin ini ni entrada de catálogo): se
 *   tratan como hojas y se ordenan normalmente.
 *
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

final class PluginUpdateOrderer
{
    /**
     * Ordena el lote de plugins con las dependencias primero.
     *
     * @param list<string> $pluginNames Nombres de plugins del lote.
     * @param callable(string): list<string>|null $requirementsFn Devuelve las
     *        dependencias directas de un plugin. Por defecto usa
     *        CatalogPluginInstallProvider::getDirectRequirements.
     * @param callable(string): bool|null $isInstalledFn Indica si una
     *        dependencia está instalada. Por defecto comprueba el directorio
     *        en FS_FOLDER/plugins (si FS_FOLDER no está definido, se asume
     *        que todo está instalado).
     *
     * @return list<string> Lote ∪ dependencias instaladas transitivas, con
     *         las dependencias antes que sus dependientes.
     */
    public static function order(array $pluginNames, ?callable $requirementsFn = null, ?callable $isInstalledFn = null): array
    {
        $pluginNames = self::normalizeNames($pluginNames);
        if ($pluginNames === []) {
            return [];
        }

        $requirements = $requirementsFn ?? static fn(string $name): array => self::defaultRequirements($name);
        $isInstalled = $isInstalledFn ?? static fn(string $name): bool => self::defaultInstalled($name);

        // Construir el grafo: lote ∪ dependencias instaladas transitivas.
        // $depsOf[$node] = lista de dependencias (prerequisitos) del nodo.
        $depsOf = [];
        $nodeOrder = [];
        $seen = [];
        $queue = $pluginNames;

        while ($queue !== []) {
            $node = array_shift($queue);
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            $nodeOrder[] = $node;

            $deps = [];
            foreach (self::normalizeNames($requirements($node)) as $dep) {
                if ($dep === $node) {
                    continue; // auto-dependencia ignorada
                }
                if (!$isInstalled($dep)) {
                    continue; // dependencia no instalada: se omite sin bloquear
                }
                $deps[] = $dep;
                if (!isset($seen[$dep])) {
                    $queue[] = $dep;
                }
            }
            $depsOf[$node] = $deps;
        }

        // Kahn: ordenar nodos con grado de entrada 0 primero.
        $indegree = [];
        $dependents = [];
        foreach ($nodeOrder as $node) {
            $indegree[$node] = count($depsOf[$node]);
            foreach ($depsOf[$node] as $dep) {
                $dependents[$dep][] = $node;
            }
        }

        $ready = [];
        foreach ($nodeOrder as $node) {
            if ($indegree[$node] === 0) {
                $ready[] = $node;
            }
        }

        $sorted = [];
        while ($ready !== []) {
            $node = array_shift($ready);
            $sorted[] = $node;

            foreach ($dependents[$node] ?? [] as $dependent) {
                if (--$indegree[$dependent] === 0) {
                    $ready[] = $dependent;
                }
            }
        }

        // Nodos restantes = miembros de ciclos: conservan su orden original.
        $cycleMembers = self::leftoverCycleMembers($nodeOrder, $indegree);

        if ($cycleMembers !== []) {
            error_log('system_updater: ciclo de dependencias detectado en plugins: ' . implode(', ', $cycleMembers));
        }

        return array_merge($sorted, $cycleMembers);
    }

    /**
     * Devuelve los nodos no procesados por Kahn (miembros de ciclos) en el
     * orden original de descubrimiento.
     *
     * @param list<string> $nodeOrder
     * @param array<string, int> $indegree
     *
     * @return list<string>
     */
    private static function leftoverCycleMembers(array $nodeOrder, array $indegree): array
    {
        $cycleMembers = [];
        foreach ($nodeOrder as $node) {
            if ($indegree[$node] > 0) {
                $cycleMembers[] = $node;
            }
        }

        return $cycleMembers;
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function normalizeNames(array $names): array
    {
        return array_values(array_filter(
            array_map(static fn($name): string => trim((string) $name), $names),
            static fn(string $name): bool => $name !== ''
        ));
    }

    /**
     * @return list<string>
     */
    private static function defaultRequirements(string $pluginName): array
    {
        if (!defined('FS_FOLDER')) {
            return [];
        }

        static $provider = null;
        if ($provider === null) {
            require_once __DIR__ . '/CatalogPluginInstallProvider.php';
            $provider = new CatalogPluginInstallProvider();
        }

        return $provider->getDirectRequirements($pluginName);
    }

    private static function defaultInstalled(string $pluginName): bool
    {
        if (!defined('FS_FOLDER')) {
            return true;
        }

        return is_dir(FS_FOLDER . '/plugins/' . $pluginName);
    }
}