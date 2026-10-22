<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\ServersConfig;

/**
 * Loads servers.json and merges each node API key from servers.secrets.json in the same
 * directory, so servers.json can be versioned without credentials.
 *
 * @extends AbstractJsonConfigLoader<ServersConfig>
 */
final class ServersConfigLoader extends AbstractJsonConfigLoader
{
    public const SERVERS_FILE = 'servers.json';

    public const SECRETS_FILE = 'servers.secrets.json';

    private const SECRET_FIELDS = ['api_key'];

    /** No fallback by default: running with zero servers is never useful, so callers must opt in. */
    public function __construct(bool $fallbackOnFailure = false)
    {
        parent::__construct($fallbackOnFailure);
    }

    protected function fileName(): string
    {
        return self::SERVERS_FILE;
    }

    protected function problemType(): string
    {
        return 'servers_config_invalid';
    }

    /** Servers are the one section the app cannot run without. */
    protected function isRequiredToRun(): bool
    {
        return true;
    }

    protected function defaultSection(): ServersConfig
    {
        return ServersConfig::empty();
    }

    protected function parse(array $config, string $filePath): ServersConfig
    {
        // Taken before rejected servers are dropped, so their secrets are not reported as orphaned.
        $definedServers = array_map('strval', array_keys($config));
        $servers = $this->withoutServersWithInlineApiKey($config);
        $apiKeys = $this->loadApiKeys(dirname($filePath) . '/' . self::SECRETS_FILE);
        $servers = $this->mergeApiKeys($servers, $apiKeys, $definedServers);
        $this->reportServerProblems($servers);

        return ServersConfig::fromArray($servers);
    }

    /**
     * Drop servers that still carry their key in servers.json: a key there would end up in
     * whatever repository versions the config.
     *
     * @param array<string,mixed> $servers
     *
     * @return array<string,mixed>
     */
    private function withoutServersWithInlineApiKey(array $servers): array
    {
        foreach ($servers as $serverName => $serverConfig) {
            if (str_starts_with((string) $serverName, '_') || !is_array($serverConfig) || !array_key_exists('api_key', $serverConfig)) {
                continue;
            }
            $this->reportInvalidEntry(
                "Server '{$serverName}' sets api_key in " . self::SERVERS_FILE . '; move it to ' . self::SECRETS_FILE,
                self::SERVERS_FILE,
            );
            unset($servers[$serverName]);
        }

        return $servers;
    }

    /**
     * Read the node API keys keyed by server name. A missing file yields no keys, so every
     * enabled server then reports its missing key; an unreadable or malformed file fails the
     * whole load like a broken servers.json.
     *
     * @return array<string,string>
     */
    private function loadApiKeys(string $secretsPath): array
    {
        if (!file_exists($secretsPath)) {
            return [];
        }

        $raw = $this->readJsonArray($secretsPath);

        $apiKeys = [];
        foreach ($raw as $serverName => $entry) {
            $serverName = (string) $serverName;
            if (str_starts_with($serverName, '_')) {
                continue;
            }
            $error = $this->secretEntryError($serverName, $entry);
            if ($error !== null) {
                $this->reportInvalidEntry($error, self::SECRETS_FILE);

                continue;
            }
            $apiKeys[$serverName] = $entry['api_key'];
        }

        return $apiKeys;
    }

    private function secretEntryError(string $serverName, mixed $entry): ?string
    {
        $file = self::SECRETS_FILE;
        if (!is_array($entry)) {
            return "{$file} entry '{$serverName}' must be an object";
        }
        $unknown = array_diff(array_map('strval', array_keys($entry)), self::SECRET_FIELDS);
        if ($unknown !== []) {
            return "{$file} entry '{$serverName}' has unknown field: " . implode(', ', $unknown);
        }
        if (!is_string($entry['api_key'] ?? null) || trim($entry['api_key']) === '') {
            return "{$file} entry '{$serverName}' api_key must be a non-empty string";
        }

        return null;
    }

    /**
     * @param array<string,mixed>  $servers
     * @param array<string,string> $apiKeys
     * @param list<string>         $definedServers server names as written in servers.json, before rejected entries were dropped
     *
     * @return array<string,mixed>
     */
    private function mergeApiKeys(array $servers, array $apiKeys, array $definedServers): array
    {
        foreach ($apiKeys as $serverName => $apiKey) {
            if (!in_array($serverName, $definedServers, true)) {
                $this->reportInvalidEntry(
                    self::SECRETS_FILE . " has a key for server '{$serverName}', which " . self::SERVERS_FILE . ' does not define',
                    self::SECRETS_FILE,
                );

                continue;
            }
            if (is_array($servers[$serverName] ?? null)) {
                $servers[$serverName]['api_key'] = $apiKey;
            }
        }

        return $servers;
    }

    /** @param array<string,mixed> $servers with API keys merged */
    private function reportServerProblems(array $servers): void
    {
        $visible = array_filter(
            $servers,
            static fn ($key): bool => !str_starts_with((string) $key, '_'),
            ARRAY_FILTER_USE_KEY,
        );
        foreach (ServersConfig::validationProblems($visible) as $problem) {
            $this->reportInvalidEntry(
                $problem['message'],
                $problem['field'] === 'api_key' ? self::SECRETS_FILE : self::SERVERS_FILE,
            );
        }
        foreach (ServersConfig::allowedHttpWarnings($visible) as $warning) {
            $this->report('http_connection_allowed', $warning);
        }
    }
}
