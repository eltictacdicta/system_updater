<?php
/**
 * Re-sincronización de esquema BD con visibilidad de dependencias en memoria.
 *
 * Resuelve el fallo de "Archivo model/table/X.xml no encontrado": durante el
 * sync de esquema de un plugin, sus dependencias instaladas (aunque estén
 * desactivadas) deben ser visibles para la resolución de XMLs de tablas.
 *
 * La visibilidad se aplica SOLO en memoria: se hace snapshot de
 * $GLOBALS['plugins'], se añade el plugin y sus dependencias instaladas, se
 * ejecuta el callback y se restaura el snapshot en un bloque finally. Ninguna
 * dependencia se habilita de forma persistente.
 *
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/PluginUpdateOrderer.php';

final class PluginSchemaResyncer
{
    /**
     * Re-ejecuta applyPluginSchemaUpdates para los plugins instalados (o uno
     * concreto vía $only) en orden de dependencias, sin descargar nada.
     *
     * @param callable(string): list<string>|null $requirementsFn
     * @param callable(string): bool|null $isInstalledFn
     *
     * @return array{
     *     success: bool,
     *     updated: list<string>,
     *     failed: list<string>,
     *     messages: list<string>,
     *     results: array<string, array{success: bool, changes: list<string>, errors: list<string>}>
     * }
     */
    public static function resyncInstalled(
        \fs_plugin_manager $manager,
        ?string $only = null,
        ?callable $requirementsFn = null,
        ?callable $isInstalledFn = null
    ): array {
        $installed = [];
        foreach ($manager->installed() as $plugin) {
            $name = (string) ($plugin['name'] ?? '');
            if ($name !== '') {
                $installed[] = $name;
            }
        }

        $targets = $only !== null && $only !== ''
            ? PluginUpdateOrderer::order([$only], $requirementsFn, $isInstalledFn)
            : PluginUpdateOrderer::order($installed, $requirementsFn, $isInstalledFn);

        $snapshot = $GLOBALS['plugins'] ?? [];
        $updated = [];
        $failed = [];
        $messages = [];
        $results = [];

        try {
            foreach ($targets as $name) {
                self::withDependencyVisibility(
                    $name,
                    static function () use ($manager, $name, &$results, &$updated, &$failed, &$messages): void {
                        $result = $manager->applyPluginSchemaUpdates($name);
                        $results[$name] = $result;

                        [$status, $message] = self::classifyResult($name, $result);
                        if ($status === 'updated') {
                            $updated[] = $name;

                            return;
                        }

                        $failed[] = $name;
                        $messages[] = $message;
                    },
                    $requirementsFn,
                    $isInstalledFn
                );
            }
        } finally {
            $GLOBALS['plugins'] = $snapshot;
        }

        return [
            'success' => $failed === [],
            'updated' => $updated,
            'failed' => $failed,
            'messages' => $messages,
            'results' => $results,
        ];
    }

    /**
     * Añade en memoria el plugin y sus dependencias instaladas a
     * $GLOBALS['plugins'], ejecuta el callback y restaura el snapshot.
     *
     * Si el callback lanza una excepción, el snapshot se restaura igualmente
     * (finally) y la excepción se re-lanza.
     *
     * @param callable(): mixed $callback
     * @param callable(string): list<string>|null $requirementsFn
     * @param callable(string): bool|null $isInstalledFn
     *
     * @return mixed Resultado del callback.
     */
    public static function withDependencyVisibility(
        string $pluginName,
        callable $callback,
        ?callable $requirementsFn = null,
        ?callable $isInstalledFn = null
    ): mixed {
        $snapshot = $GLOBALS['plugins'] ?? [];

        $visible = PluginUpdateOrderer::order([$pluginName], $requirementsFn, $isInstalledFn);
        $GLOBALS['plugins'] = array_values(array_unique(array_merge($snapshot, $visible)));

        try {
            return $callback();
        } finally {
            $GLOBALS['plugins'] = $snapshot;
        }
    }

    /**
     * Clasifica el resultado de un sync y genera el mensaje asociado.
     *
     * @param array{success: bool, changes: list<string>, errors: list<string>} $result
     *
     * @return array{0: string, 1: string} ['updated'|'failed', mensaje]
     */
    private static function classifyResult(string $name, array $result): array
    {
        if ($result['success']) {
            return ['updated', ''];
        }

        $errors = array_values($result['errors'] ?? []);
        $message = 'Error al sincronizar el esquema de ' . $name
            . ($errors !== [] ? ': ' . implode('; ', $errors) : '.');

        return ['failed', $message];
    }
}