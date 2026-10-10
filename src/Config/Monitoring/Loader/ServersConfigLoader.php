<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Exception;
use Mata\Config\Monitoring\Section\ServersConfig;
use Mata\Config\Monitoring\ServerConfigState;
use Mata\Database\DatabaseInterface;
use Mata\Database\NullDatabase;
use Mata\Services\Event\EventManager;
use Mata\Services\Sync\ServerConfigDatabaseSync;
use Mata\Utils\TypeConverter;
use RuntimeException;

final class ServersConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(
        private EventManager $events,
        private ?DatabaseInterface $db = null,
        private ?ServerConfigDatabaseSync $serverConfigSync = null,
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
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException("servers.json file not found at {$filePath}", previous: $exception);
        }
        if ($raw === []) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException('servers.json invalid or empty');
        }

        $visible = array_filter(
            $raw,
            static fn ($key): bool => !str_starts_with((string) $key, '_'),
            ARRAY_FILTER_USE_KEY,
        );
        $validation = $this->validateServersConfig($visible);
        foreach ($validation['errors'] as $error) {
            $this->reportProblem($this->events, 'servers_config_validation_error', $error, 'warning');
        }
        $servers = ServersConfig::fromArray($raw);

        if ($this->db && $this->serverConfigSync && !($this->db instanceof NullDatabase)) {
            try {
                $this->serverConfigSync->syncIfNeeded($filePath, $servers->values);
            } catch (Exception $exception) {
                // Persisted event identifier; rename only with a migration.
                $this->reportProblem(
                    $this->events,
                    'settings_sync_error',
                    'Failed to sync server configuration to database: ' . $exception->getMessage(),
                    'warning',
                );
            }
        }

        return $servers;
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

    private function reportLoadFailure(string $filePath): void
    {
        $this->reportProblem(
            $this->events,
            type: 'servers_config_not_found',
            message: "Could not load {$filePath} -> file not found",
            level: 'critical',
            forceAlarm: true,
        );
    }
}
