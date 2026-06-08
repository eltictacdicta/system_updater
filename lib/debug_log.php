<?php
/**
 * Diagnostic logger for system_updater standalone scripts (transient triage).
 *
 * Writes one line per call to FS_FOLDER/tmp/system_updater_debug.log and
 * mirrors the same line to PHP error_log. A register_shutdown_function also
 * captures fatal errors (E_ERROR, E_PARSE, E_RECOVERABLE_ERROR, ...) that
 * try/catch cannot catch, so the 500 root cause is visible in the log.
 *
 * This file is added temporarily to capture the 500 root cause on production
 * for `process_core_update.php?action=start`. Remove after triage.
 */

if (!function_exists('system_updater_debug_log')) {
    function system_updater_debug_log(string $stage, string $message, array $context = []): void
    {
        $line = sprintf('[%s] %s %s', date('Y-m-d H:i:s'), $stage, $message);
        if ($context !== []) {
            $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                $encoded = var_export($context, true);
            }
            $line .= ' ' . $encoded;
        }
        $line .= "\n";

        $folder = defined('FS_FOLDER') ? (string) FS_FOLDER : dirname(dirname(dirname(__DIR__)));
        $logDir = $folder . '/tmp';
        $logFile = $logDir . '/system_updater_debug.log';
        if (is_dir($logDir) && is_writable($logDir)) {
            @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        }
    }
}

if (!function_exists('system_updater_debug_wrap')) {
    /**
     * Wrap a callable with a try/catch that logs and re-throws.
     * Used around the pre-try-block calls in process_core_update.php
     * to identify which call site throws the 500.
     */
    function system_updater_debug_wrap(string $name, callable $fn)
    {
        system_updater_debug_log('CALL', $name);
        try {
            $result = $fn();
            system_updater_debug_log('OK', $name);
            return $result;
        } catch (\Throwable $e) {
            system_updater_debug_log('THROW', $name, [
                'class' => get_class($e),
                'msg' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace_top' => implode(' <- ', array_slice(
                    array_map(
                        static function (array $f) {
                            return ($f['file'] ?? '?') . ':' . ($f['line'] ?? 0) . ' ' . ($f['function'] ?? '');
                        },
                        $e->getTrace()
                    ),
                    0,
                    6
                )),
            ]);
            throw $e;
        }
    }
}

if (!function_exists('system_updater_debug_install_shutdown')) {
    function system_updater_debug_install_shutdown(): void
    {
        register_shutdown_function(static function () {
            $err = error_get_last();
            if (!is_array($err)) {
                return;
            }
            $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_RECOVERABLE_ERROR];
            if (!in_array($err['type'] ?? 0, $fatalTypes, true)) {
                return;
            }
            system_updater_debug_log('FATAL', sprintf(
                '%s: %s in %s:%d',
                $err['type'],
                $err['message'],
                $err['file'] ?? '?',
                $err['line'] ?? 0
            ));
        });
    }
}
