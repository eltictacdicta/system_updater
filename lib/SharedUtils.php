<?php
/**
 * Utilidades compartidas para el plugin system_updater.
 *
 * Centraliza funciones duplicadas en backup_manager, core_updater,
 * plugin_downloader, updater_manager y process_backup.php.
 */

class SystemUpdaterUtils
{
    /**
     * Verifica si las funciones de shell están disponibles.
     */
    public static function shellFunctionsAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $disabled = (string) ini_get('disable_functions');
        if ($disabled === '') {
            return true;
        }

        $disabledFunctions = array_map('trim', explode(',', $disabled));
        return !in_array('exec', $disabledFunctions, true);
    }

    /**
     * Verifica si git está disponible en el sistema.
     */
    public static function isGitAvailable(): bool
    {
        if (!self::shellFunctionsAvailable()) {
            return false;
        }

        $output = [];
        $returnVar = 1;
        @exec('git --version 2>&1', $output, $returnVar);

        return $returnVar === 0;
    }

    /**
     * Elimina recursivamente un directorio y todo su contenido.
     */
    public static function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return @unlink($dir) !== false;
        }

        $items = array_diff((array) scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? self::deleteDirectory($path) : @unlink($path);
        }

        return @rmdir($dir);
    }

    /**
     * Descarga un archivo remoto a una ruta local.
     *
     * @param string $url
     * @param string $destination
     * @param int $timeout Segundos de timeout
     * @return bool
     */
    public static function downloadFile(string $url, string $destination, int $timeout = 120): bool
    {
        if (function_exists('fs_file_download') && @fs_file_download($url, $destination)) {
            return file_exists($destination) && filesize($destination) > 0;
        }

        if (function_exists('curl_init')) {
            $handle = @fopen($destination, 'wb');
            if ($handle) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_FILE => $handle,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => 20,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $success = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                fclose($handle);

                if ($success && ($httpCode >= 200 && $httpCode < 400)) {
                    return file_exists($destination) && filesize($destination) > 0;
                }
            }
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'user_agent' => 'Mozilla/5.0 (compatible; FSFramework system_updater)',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);
        if ($data !== false) {
            return @file_put_contents($destination, $data) !== false;
        }

        return false;
    }

    /**
     * Clona un repositorio git.
     *
     * @param string $repositoryUrl
     * @param string $branch
     * @param string $destination
     * @return bool
     */
    public static function cloneGitRepository(string $repositoryUrl, string $branch, string $destination): bool
    {
        $command = sprintf(
            'git clone --depth 1 --branch %s %s %s 2>&1',
            escapeshellarg($branch),
            escapeshellarg($repositoryUrl),
            escapeshellarg($destination)
        );

        $output = [];
        $returnVar = 1;
        @exec($command, $output, $returnVar);

        return $returnVar === 0 && is_dir($destination . '/.git');
    }

    /**
     * Extrae un ZIP de forma segura a un directorio destino.
     *
     * @param string $zipPath
     * @param string $destination
     * @return bool
     */
    public static function extractZip(string $zipPath, string $destination): bool
    {
        if (!file_exists($zipPath)) {
            return false;
        }

        if (!is_dir($destination)) {
            @mkdir($destination, 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return false;
        }

        $success = $zip->extractTo($destination);
        $zip->close();

        return $success;
    }
}
