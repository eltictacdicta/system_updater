<?php
/**
 * Proveedor de instalación desde el catálogo público de system_updater.
 *
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

use FSFramework\Core\Plugin\LocalPluginRequirementsReader;
use FSFramework\Core\Plugin\PluginInstallProvider;

require_once __DIR__ . '/plugin_downloader.php';

class CatalogPluginInstallProvider implements PluginInstallProvider
{
    private plugin_downloader $downloader;

    private LocalPluginRequirementsReader $localReader;

    private string $lastError = '';

    public function __construct(?plugin_downloader $downloader = null)
    {
        $root = defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__, 3);
        $this->downloader = $downloader ?? new plugin_downloader();
        $this->localReader = new LocalPluginRequirementsReader($root . '/plugins');
    }

    public function isInstalled(string $pluginName): bool
    {
        return $this->localReader->isInstalled($pluginName);
    }

    public function getDirectRequirements(string $pluginName): array
    {
        $local = $this->localReader->read($pluginName);
        if ($local !== []) {
            return $local;
        }

        $entry = $this->findCatalogEntry($pluginName);
        if ($entry === null || empty($entry['require']) || !is_string($entry['require'])) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $entry['require'])),
            static fn (string $item): bool => $item !== ''
        ));
    }

    public function installIfAvailable(string $pluginName): bool
    {
        if ($this->isInstalled($pluginName)) {
            return true;
        }

        $safeName = htmlspecialchars($pluginName, ENT_QUOTES, 'UTF-8');
        $entry = $this->findCatalogEntry($pluginName);
        if ($entry === null) {
            $this->lastError = 'Plugin <b>' . $safeName . '</b> no está en el catálogo público.';

            return false;
        }

        if (empty($entry['id'])) {
            $this->lastError = 'Entrada de catálogo inválida para <b>' . $safeName . '</b>.';

            return false;
        }

        if ($this->downloader->download($entry['id'])) {
            return $this->isInstalled($pluginName);
        }

        $errors = $this->downloader->get_errors();
        $this->lastError = $errors !== [] ? implode(', ', $errors) : 'No se pudo descargar <b>' . $safeName . '</b>.';

        return false;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCatalogEntry(string $pluginName): ?array
    {
        foreach ($this->downloader->downloads() as $entry) {
            if (!is_array($entry) || empty($entry['nombre'])) {
                continue;
            }

            if ((string) $entry['nombre'] === $pluginName) {
                return $entry;
            }
        }

        return null;
    }
}
