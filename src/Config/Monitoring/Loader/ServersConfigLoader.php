<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\ServersConfig;
use Mata\Config\Monitoring\ServerConfigState;
use Mata\Services\Event\EventManager;
use Mata\Utils\TypeConverter;
use RuntimeException;

/**
 * Loads servers.json and merges each node API key from servers.secrets.json in the same
 * directory, so servers.json can be versioned without credentials.
 */
final class ServersConfigLoader extends AbstractJsonConfigLoader
{
    public const SECRETS_FILE = 'servers.secrets.json';

    private const SECRET_KEYS = ['api_key'];

    public function __construct(
        private EventManager $events,
        private bool $fallbackOnFailure = false,
    ) {}

    public function load(string $configRoot): ServersConfig
    {
        try {
            return $this->loadServersConfig(rtrim($configRoot, '/') . '/servers.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return $this->getDefaultConfig();
        }
    }

    public function loadServersConfig(string $filePath): ServersConfig
    {
        try {
            $raw = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($exception->getMessage());

            throw new MonitoringConfigLoadException($exception->getMessage(), previous: $exception);
        }
        if ($raw === []) {
            $reason = "servers.json at {$filePath} is empty";
            $this->reportLoadFailure($reason);

            throw new MonitoringConfigLoadException($reason);
        }

        $secretsPath = dirname($filePath) . '/' . self::SECRETS_FILE;
        $definedServers = array_map('strval', array_keys($raw));
        $raw = $this->mergeSecrets($this->withoutInlineApiKeys($raw), $this->loadSecrets($secretsPath), $definedServers);

        $visible = array_filter(
            $raw,
            static fn ($key): bool => !str_starts_with((string) $key, '_'),
            ARRAY_FILTER_USE_KEY,
        );
        $validation = $this->validateServersConfig($visible);
        foreach ($validation['errors'] as $error) {
            $this->reportProblem($this->events, 'servers_config_validation_error', $error, 'warning');
        }

        return ServersConfig::fromArray($raw);
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
            $this->reportProblem(
                $this->events,
                'servers_config_validation_error',
                "Server '{$serverName}' sets api_key in servers.json; move it to " . self::SECRETS_FILE,
                'warning',
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

        try {
            $raw = $this->normalizeJson($this->readJsonObject($secretsPath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($exception->getMessage());

            throw new MonitoringConfigLoadException($exception->getMessage(), previous: $exception);
        }

        $keys = [];
        foreach ($raw as $serverName => $entry) {
            $serverName = (string) $serverName;
            if (str_starts_with($serverName, '_')) {
                continue;
            }
            $error = $this->secretEntryError($serverName, $entry);
            if ($error !== null) {
                $this->reportProblem($this->events, 'servers_config_validation_error', $error, 'warning');

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
                $this->reportProblem(
                    $this->events,
                    'servers_config_validation_error',
                    self::SECRETS_FILE . " has a key for server '{$serverName}', which servers.json does not define",
                    'warning',
                );

                continue;
            }
            if (is_array($raw[$serverName] ?? null)) {
                $raw[$serverName]['api_key'] = $apiKey;
            }
        }

        return $raw;
    }

    public function getDefaultConfig(): ServersConfig
    {
        return ServersConfig::empty();
    }

    /**
     * @param array<string,mixed> $config
     *
     * @return array{is_valid:bool,errors:list<string>}
     */
    public function validateServersConfig(array $config): array
    {
        $errors = ServersConfig::validationErrors($config);
        $this->reportAllowedHttp($config);

        return [
            'is_valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /** @param array<string,mixed> $servers */
    private function reportAllowedHttp(array $servers): void
    {
        foreach ($servers as $serverName => $config) {
            if (
                !is_array($config)
                || ServerConfigState::isDisabled($config)
                || !is_string($config['api_url'] ?? null)
                || parse_url($config['api_url'], PHP_URL_SCHEME) !== 'http'
                || !(TypeConverter::toBool($config['allow_http'] ?? null) ?? false)
                || (TypeConverter::toBool($config['suppress_http_warning'] ?? null) ?? false)
            ) {
                continue;
            }
            $this->reportProblem(
                $this->events,
                'http_connection_allowed',
                "Security warning: HTTP connection allowed for server '{$serverName}' (api_url: {$config['api_url']}). Recommendation: Use HTTPS for production environments.",
                'warning',
            );
        }
    }

    /** @param string $reason names the file and the cause: unreadable, invalid JSON, or empty */
    private function reportLoadFailure(string $reason): void
    {
        $this->reportProblem(
            $this->events,
            // Persisted event identifier, also used for malformed and empty files; rename only with a migration.
            type: 'servers_config_not_found',
            message: "Could not load servers: {$reason}",
            level: 'critical',
            forceAlarm: true,
        );
    }
}
