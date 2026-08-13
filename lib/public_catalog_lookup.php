<?php
/**
 * Comprobación ligera de pertenencia al catálogo público (sin descargar plugins).
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * @param mixed $entries
 */
function system_updater_catalog_entries_contain_plugin($entries, string $pluginName): bool
{
    if (!is_array($entries)) {
        return false;
    }

    foreach ($entries as $entry) {
        if (is_array($entry) && isset($entry['nombre']) && (string) $entry['nombre'] === $pluginName) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function system_updater_public_catalog_json_urls(): array
{
    return [
        'https://raw.githubusercontent.com/eltictacdicta/fs-cusmtom-plugins/main/custom_plugins.json',
        'https://raw.githubusercontent.com/eltictacdicta/fs-cusmtom-plugins/master/custom_plugins.json',
    ];
}

function system_updater_catalog_lists_plugin(string $pluginName, ?string $fsRoot = null): bool
{
    $pluginName = trim($pluginName);
    if ($pluginName === '') {
        return false;
    }

    $fsRoot = $fsRoot ?? (defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__, 3));
    $localPath = $fsRoot . '/plugins/system_updater/data/custom_plugins.json';
    if (is_file($localPath)) {
        $entries = json_decode((string) file_get_contents($localPath), true);
        if (system_updater_catalog_entries_contain_plugin($entries, $pluginName)) {
            return true;
        }
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'header' => "User-Agent: FSFramework-CatalogLookup/1.0\r\n",
        ],
    ]);

    foreach (system_updater_public_catalog_json_urls() as $url) {
        $json = @file_get_contents($url, false, $context);
        if ($json === false) {
            continue;
        }

        $entries = json_decode($json, true);
        if (system_updater_catalog_entries_contain_plugin($entries, $pluginName)) {
            return true;
        }
    }

    return false;
}
