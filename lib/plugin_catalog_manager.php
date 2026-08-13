<?php
/**
 * Gestor del catálogo público de plugins (fs-cusmtom-plugins).
 *
 * Permite editar localmente custom_plugins.json desde system_updater
 * y publicarlo en GitHub.
 *
 * @author Javier Trujillo
 * @license LGPL-3.0-or-later
 */

class plugin_catalog_manager
{
    public const DEFAULT_REPO = 'eltictacdicta/fs-cusmtom-plugins';
    public const DEFAULT_BRANCH = 'main';
    public const DEFAULT_FILE = 'custom_plugins.json';
    public const CONFIG_KEY = 'plugin_catalog_config';

    /** @var string */
    private $fsRoot;

    /** @var array<string, mixed> */
    private $config;

    /** @var array<int, string> */
    private $errors = [];

    /** @var array<int, string> */
    private $messages = [];

    public function __construct(?string $fsRoot = null)
    {
        $this->fsRoot = $fsRoot ?? (defined('FS_FOLDER') ? FS_FOLDER : dirname(__DIR__, 3));
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        if (isset($this->config)) {
            return $this->config;
        }

        $defaults = [
            'github_token' => '',
            'catalog_repo' => self::DEFAULT_REPO,
            'catalog_branch' => self::DEFAULT_BRANCH,
            'catalog_file' => self::DEFAULT_FILE,
            'enabled' => false,
        ];

        $this->config = $defaults;

        if (file_exists($this->fsRoot . '/model/fs_var.php')) {
            require_once $this->fsRoot . '/model/fs_var.php';
            $fsVar = new fs_var();
            $saved = $fsVar->simple_get(self::CONFIG_KEY);
            if ($saved) {
                $decoded = json_decode($saved, true);
                if (is_array($decoded)) {
                    $this->config = array_merge($defaults, $decoded);
                }
            }
        }

        $this->decryptToken();

        if ($this->config['github_token'] === '' && file_exists($this->fsRoot . '/plugins/system_updater/lib/plugin_downloader.php')) {
            require_once $this->fsRoot . '/plugins/system_updater/lib/plugin_downloader.php';
            $downloader = new plugin_downloader();
            $privateConfig = $downloader->get_private_config();
            if (!empty($privateConfig['github_token'])) {
                $this->config['github_token'] = $privateConfig['github_token'];
            }
        }

        $this->config['enabled'] = $this->config['github_token'] !== '';

        return $this->config;
    }

    public function saveConfig(string $githubToken, string $catalogRepo, string $catalogBranch, string $catalogFile): bool
    {
        if (!file_exists($this->fsRoot . '/model/fs_var.php')) {
            $this->errors[] = 'No se puede guardar la configuración sin fs_var.';
            return false;
        }

        require_once $this->fsRoot . '/model/fs_var.php';
        $fsVar = new fs_var();

        $rawToken = trim($githubToken);
        if ($rawToken === '') {
            $existing = $this->getConfig();
            $rawToken = trim((string) ($existing['github_token'] ?? ''));
        }

        $this->config = [
            'github_token' => $this->encryptToken($rawToken),
            'catalog_repo' => $this->sanitizeRepo($catalogRepo),
            'catalog_branch' => trim($catalogBranch) !== '' ? trim($catalogBranch) : self::DEFAULT_BRANCH,
            'catalog_file' => trim($catalogFile) !== '' ? trim($catalogFile) : self::DEFAULT_FILE,
            'enabled' => $rawToken !== '',
        ];

        $result = $fsVar->simple_save(self::CONFIG_KEY, json_encode($this->config));
        $this->config['github_token'] = $rawToken;

        return (bool) $result;
    }

    public function deleteConfig(): bool
    {
        if (!file_exists($this->fsRoot . '/model/fs_var.php')) {
            return false;
        }

        require_once $this->fsRoot . '/model/fs_var.php';
        $fsVar = new fs_var();
        $this->config = null;

        return (bool) $fsVar->simple_delete(self::CONFIG_KEY);
    }

    public function getLocalCatalogPath(): string
    {
        return $this->fsRoot . '/plugins/system_updater/data/custom_plugins.json';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadLocalCatalog(): array
    {
        $path = $this->getLocalCatalogPath();
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    public function saveLocalCatalog(array $entries): bool
    {
        if (!$this->validateCatalog($entries)) {
            return false;
        }

        $path = $this->getLocalCatalogPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $this->errors[] = 'No se pudo crear el directorio del catálogo local.';
            return false;
        }

        $json = json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->errors[] = 'No se pudo serializar el catálogo.';
            return false;
        }

        $json .= "\n";

        if (@file_put_contents($path, $json) === false) {
            $this->errors[] = 'No se pudo guardar el catálogo local.';
            return false;
        }

        $this->messages[] = 'Catálogo guardado localmente.';
        $this->clearDownloaderCache();

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function fetchRemoteCatalog(): ?array
    {
        $config = $this->getConfig();
        $repo = $config['catalog_repo'] ?: self::DEFAULT_REPO;
        $branch = $config['catalog_branch'] ?: self::DEFAULT_BRANCH;
        $file = $config['catalog_file'] ?: self::DEFAULT_FILE;

        $rawUrl = 'https://raw.githubusercontent.com/' . $repo . '/' . $branch . '/' . $file;

        $json = function_exists('fs_file_get_contents')
            ? @fs_file_get_contents($rawUrl, 15)
            : @file_get_contents($rawUrl);

        if (!$json || $json === 'ERROR') {
            $this->errors[] = 'No se pudo descargar el catálogo remoto.';
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $this->errors[] = 'El catálogo remoto no es un JSON válido.';
            return null;
        }

        return $decoded;
    }

    public function syncFromRemote(): bool
    {
        $remote = $this->fetchRemoteCatalog();
        if ($remote === null) {
            return false;
        }

        if (!$this->saveLocalCatalog($remote)) {
            return false;
        }

        $this->messages[] = 'Catálogo sincronizado desde GitHub (' . count($remote) . ' plugins).';
        return true;
    }

    public function publishToRemote(?string $commitMessage = null): bool
    {
        $config = $this->getConfig();
        if ($config['github_token'] === '') {
            $this->errors[] = 'Configura un token de GitHub con permisos de escritura en el repositorio del catálogo.';
            return false;
        }

        $entries = $this->loadLocalCatalog();
        if (!$this->validateCatalog($entries)) {
            return false;
        }

        $repo = $config['catalog_repo'] ?: self::DEFAULT_REPO;
        $branch = $config['catalog_branch'] ?: self::DEFAULT_BRANCH;
        $file = $config['catalog_file'] ?: self::DEFAULT_FILE;
        $message = $commitMessage ?: 'Actualizar catálogo de plugins desde system_updater';

        $json = json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->errors[] = 'No se pudo serializar el catálogo.';
            return false;
        }
        $json .= "\n";

        $sha = $this->fetchRemoteFileSha($repo, $branch, $file, $config['github_token']);
        if ($sha === false) {
            return false;
        }

        $payload = [
            'message' => $message,
            'content' => base64_encode($json),
            'branch' => $branch,
        ];
        if ($sha !== null) {
            $payload['sha'] = $sha;
        }

        $apiUrl = 'https://api.github.com/repos/' . $repo . '/contents/' . rawurlencode($file);
        $response = $this->githubApiRequest('PUT', $apiUrl, $config['github_token'], $payload);
        if ($response === null || $response['http_code'] < 200 || $response['http_code'] >= 300) {
            return false;
        }

        $this->messages[] = 'Catálogo publicado en GitHub (' . $repo . ').';
        $this->clearDownloaderCache();
        return true;
    }

    public function getLocalCatalogJson(): string
    {
        $entries = $this->loadLocalCatalog();
        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ($json === false ? '[]' : $json) . "\n";
    }

    /**
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<int, string>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getRemoteRawUrl(): string
    {
        $config = $this->getConfig();
        $repo = $config['catalog_repo'] ?: self::DEFAULT_REPO;
        $branch = $config['catalog_branch'] ?: self::DEFAULT_BRANCH;
        $file = $config['catalog_file'] ?: self::DEFAULT_FILE;

        return 'https://raw.githubusercontent.com/' . $repo . '/' . $branch . '/' . $file;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    public function validateCatalog(array $entries): bool
    {
        if ($entries === []) {
            $this->errors[] = 'El catálogo no puede estar vacío.';
            return false;
        }

        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                $this->errors[] = 'Entrada inválida en la posición ' . $index . '.';
                return false;
            }

            foreach (['nombre', 'link', 'zip_link'] as $required) {
                if (empty($entry[$required])) {
                    $this->errors[] = 'Falta el campo obligatorio "' . $required . '" en la entrada ' . $index . '.';
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $json
     */
    public function parseCatalogJson(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $this->errors[] = 'JSON inválido: ' . json_last_error_msg();
            return null;
        }

        if (!$this->validateCatalog($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function clearDownloaderCache(): void
    {
        if (!file_exists($this->fsRoot . '/plugins/system_updater/lib/plugin_downloader.php')) {
            return;
        }

        require_once $this->fsRoot . '/plugins/system_updater/lib/plugin_downloader.php';
        $downloader = new plugin_downloader();
        $downloader->refresh();
    }

    private function decryptToken(): void
    {
        $token = trim((string) ($this->config['github_token'] ?? ''));
        if ($token === '' || !class_exists(\FSFramework\Security\EncryptionService::class)) {
            return;
        }

        try {
            $decrypted = \FSFramework\Security\EncryptionService::decrypt($token);
            if ($decrypted !== false) {
                $this->config['github_token'] = $decrypted;
            }
        } catch (\Throwable) {
        }
    }

    private function encryptToken(string $token): string
    {
        if ($token === '' || !class_exists(\FSFramework\Security\EncryptionService::class) || !class_exists(\FSFramework\Security\SecretManager::class)) {
            return $token;
        }

        try {
            return \FSFramework\Security\EncryptionService::encrypt($token, \FSFramework\Security\SecretManager::getSecret());
        } catch (\Throwable) {
            return $token;
        }
    }

    private function sanitizeRepo(string $repo): string
    {
        $repo = trim(str_replace('https://github.com/', '', trim($repo)), '/');
        $repo = preg_replace('/\.git$/', '', $repo);

        if (!preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', (string) $repo)) {
            $this->errors[] = 'El repositorio del catálogo debe tener el formato "owner/repo".';
            return self::DEFAULT_REPO;
        }

        return $repo;
    }

    /**
     * @return string|null SHA del archivo remoto, null si no existe todavía
     */
    private function fetchRemoteFileSha(string $repo, string $branch, string $file, string $token): string|null|false
    {
        $apiUrl = 'https://api.github.com/repos/' . $repo . '/contents/' . rawurlencode($file) . '?ref=' . rawurlencode($branch);
        $response = $this->githubApiRequest('GET', $apiUrl, $token, null, true);

        if ($response === null) {
            return false;
        }

        if ($response['http_code'] === 404) {
            return null;
        }

        if ($response['http_code'] !== 200 || !is_array($response['body']) || empty($response['body']['sha'])) {
            $this->errors[] = 'No se pudo obtener el SHA del archivo remoto.';
            return false;
        }

        return (string) $response['body']['sha'];
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array{http_code:int, body:mixed}|null
     */
    private function githubApiRequest(string $method, string $url, string $token, ?array $payload = null, bool $allowNotFound = false): ?array
    {
        if (!function_exists('curl_init')) {
            $this->errors[] = 'cURL no está disponible en el servidor.';
            return null;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'FSFramework-PluginCatalog/1.0');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

        $headers = [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'X-GitHub-Api-Version: 2022-11-28',
        ];

        if ($payload !== null) {
            $json = json_encode($payload);
            if ($json === false) {
                $this->errors[] = 'No se pudo serializar la petición a GitHub.';
                curl_close($ch);
                return null;
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (function_exists('fs_curl_set_ssl')) {
            fs_curl_set_ssl($ch);
        }

        $raw = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        $httpCode = (int) ($info['http_code'] ?? 0);
        $body = json_decode((string) $raw, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['http_code' => $httpCode, 'body' => $body];
        }

        if ($allowNotFound && $httpCode === 404) {
            return ['http_code' => $httpCode, 'body' => $body];
        }

        $message = is_array($body) && isset($body['message']) ? (string) $body['message'] : 'Error HTTP ' . $httpCode;
        $this->errors[] = 'GitHub API: ' . $message;

        return ['http_code' => $httpCode, 'body' => $body];
    }
}
