<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\ServersConfig;
use Psr\Log\LogLevel;

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

    private const SECRET_KEYS = ['api_key'];

    /** No fallback by default: running with zero servers is never useful, so callers must opt in. */
    public function __construct(bool $fallbackOnFailure = false)
    {
        parent::__construct($fallbackOnFailure);
    }

    protected function fileName(): string
    {
        return self::SERVERS_FILE;
    }

    protected function loadFailureType(): string
    {
        return 'servers_config_invalid';
    }

    protected function defaultSection(): ServersConfig
    {
        return ServersConfig::empty();
    }

    protected function parse(array $config, string $filePath): ServersConfig
    {
        $secretsPath = dirname($filePath) . '/' . self::SECRETS_FILE;
        $definedServers = array_map('strval', array_keys($config));
        $config = $this->mergeSecrets($this->withoutInlineApiKeys($config), $this->loadSecrets($secretsPath), $definedServers);

        $visible = array_filter(
            $config,
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

        return ServersConfig::fromArray($config);
    }

    /** Servers are the one section the app cannot run without, so a rejected file always alarms. */
    protected function reportLoadFailure(string $reason, ?string $sourceFile = null): void
    {
        $this->report(
            type: $this->loadFailureType(),
            message: "Could not load servers: {$reason}",
            level: LogLevel::CRITICAL,
            forceAlarm: true,
            sourceFile: $sourceFile,
        );
    }

    /**
     * Drop servers that still carry their key in servers.json: a key there would end up in
     * whatever repository versions the config.
     *
     * @param array<string,mixed> $raw
     *
     * @return array<string,mixed>
     */
    private function withoutInlineApiKeys(array $raw): array
    {
        foreach ($raw as $serverName => $config) {
            if (str_starts_with((string) $serverName, '_') || !is_array($config) || !array_key_exists('api_key', $config)) {
                continue;
            }
            $this->reportInvalidEntry(
                "Server '{$serverName}' sets api_key in servers.json; move it to " . self::SECRETS_FILE,
                self::SERVERS_FILE,
            );
            unset($raw[$serverName]);
        }

        return $raw;
    }

    /**
     * Read the node API keys keyed by server name. A missing file yields no keys, so every
     * enabled server then reports its missing key; an unreadable or malformed file fails the
     * whole load like a broken servers.json.
     *
     * @return array<string,string>
     */
    private function loadSecrets(string $secretsPath): array
    {
        if (!file_exists($secretsPath)) {
            return [];
        }

        $raw = $this->readJsonArray($secretsPath);

        $keys = [];
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
            $keys[$serverName] = $entry['api_key'];
        }

        return $keys;
    }

    private function secretEntryError(string $serverName, mixed $entry): ?string
    {
        $file = self::SECRETS_FILE;
        if (!is_array($entry)) {
            return "{$file} entry '{$serverName}' must be an object";
        }
        $unknown = array_diff(array_map('strval', array_keys($entry)), self::SECRET_KEYS);
        if ($unknown !== []) {
            return "{$file} entry '{$serverName}' has unknown field: " . implode(', ', $unknown);
        }
        if (!is_string($entry['api_key'] ?? null) || trim($entry['api_key']) === '') {
            return "{$file} entry '{$serverName}' api_key must be a non-empty string";
        }

        return null;
    }

    /**
     * @param array<string,mixed>  $raw
     * @param array<string,string> $keys
     * @param list<string>         $definedServers server names as written in servers.json, before rejected entries were dropped
     *
     * @return array<string,mixed>
     */
    private function mergeSecrets(array $raw, array $keys, array $definedServers): array
    {
        foreach ($keys as $serverName => $apiKey) {
            if (!in_array($serverName, $definedServers, true)) {
                $this->reportInvalidEntry(
                    self::SECRETS_FILE . " has a key for server '{$serverName}', which servers.json does not define",
                    self::SECRETS_FILE,
                );

                continue;
            }
            if (is_array($raw[$serverName] ?? null)) {
                $raw[$serverName]['api_key'] = $apiKey;
            }
        }

        return $raw;
    }
}
