<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\ServersConfig;
use Mata\Config\Monitoring\ServerConfigState;
use Mata\Services\Event\EventManager;
use Mata\Utils\TypeConverter;
use RuntimeException;

final class ServersConfigLoader extends AbstractJsonConfigLoader
{
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
