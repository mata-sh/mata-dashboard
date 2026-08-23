<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

use Exception;
use Mata\Database\DatabaseInterface;
use Mata\Database\NullDatabase;
use Mata\Services\Alarm\AlarmDirectProcessor;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmLevel;
use Mata\Services\Event\EventRecorderService;
use Mata\Services\Sync\SettingsDatabaseSync;
use Mata\Utils\TypeConverter;
use RuntimeException;

final class ServersConfigLoader extends AbstractJsonConfigLoader implements MonitoringConfigLoaderInterface
{
    private const OPTIONAL_BOOLEAN_SETTINGS = [
        'error_monitoring_enabled',
        'logsize_monitoring_enabled',
    ];

    public function __construct(
        private EventRecorderService $eventRecorder,
        private ?AlarmDirectProcessor $alarmDirectProcessor = null,
        private ?DatabaseInterface $db = null,
        private ?SettingsDatabaseSync $settingsSync = null,
        private bool $fallbackOnFailure = false,
    ) {}

    public function key(): string
    {
        return 'servers';
    }

    public function load(string $configRoot): array
    {
        return $this->loadServersConfig(rtrim($configRoot, '/') . '/servers.json');
    }

    /**
     * @return array<string,mixed>
     *
     * @throws MonitoringConfigLoadException
     */
    public function loadServersConfig(string $filePath): array
    {
        try {
            $servers = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException("servers.json file not found at {$filePath}", previous: $exception);
        }
        if ($servers === []) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException('servers.json invalid or empty');
        }

        $servers = array_filter($servers, static fn ($key): bool => !str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY);
        $validation = $this->validateServersConfig($servers);
        if (!$validation['is_valid']) {
            foreach ($validation['errors'] as $error) {
                $this->recordConfigurationEvent('servers_config_validation_error', $error, 'warning');
            }
        }
        $servers = array_filter($servers, $this->shouldLoadServer(...));

        if ($this->db && $this->settingsSync && !($this->db instanceof NullDatabase)) {
            try {
                $this->settingsSync->syncIfNeeded($filePath, $servers);
            } catch (Exception $exception) {
                $this->recordConfigurationEvent(
                    'settings_sync_error',
                    'Failed to sync servers settings to database: ' . $exception->getMessage(),
                    'warning',
                );
            }
        }

        return $servers;
    }

    /** @return array<string,mixed> */
    public function getDefaultConfig(): array
    {
        return [];
    }

    public function shouldFallback(): bool
    {
        return $this->fallbackOnFailure;
    }

    /**
     * @param array<string,mixed> $config
     *
     * @return array{is_valid:bool,errors:array<int,string>}
     */
    public function validateServersConfig(array $config): array
    {
        if ($config === []) {
            return ['is_valid' => false, 'errors' => ['Server configuration is empty']];
        }

        $errors = [];
        foreach ($config as $serverName => $serverConfig) {
            $errors = array_merge($errors, $this->validateServerConfig((string) $serverName, $serverConfig));
        }

        return ['is_valid' => $errors === [], 'errors' => $errors];
    }

    private function shouldLoadServer(mixed $config): bool
    {
        if (!is_array($config)) {
            return true;
        }
        if (array_key_exists('disabled', $config) && !is_bool($config['disabled'])) {
            return false;
        }

        return !ServerConfigState::isDisabled($config);
    }

    /** @return array<int,string> */
    private function validateServerConfig(string $serverName, mixed $serverConfig): array
    {
        if (!is_array($serverConfig)) {
            return ["Server '{$serverName}' configuration must be an array"];
        }
        if (array_key_exists('disabled', $serverConfig) && !is_bool($serverConfig['disabled'])) {
            return ["Server '{$serverName}' disabled must be a boolean"];
        }
        foreach (self::OPTIONAL_BOOLEAN_SETTINGS as $setting) {
            if (array_key_exists($setting, $serverConfig) && !is_bool($serverConfig[$setting])) {
                return ["Server '{$serverName}' {$setting} must be a boolean"];
            }
        }
        if (ServerConfigState::isDisabled($serverConfig)) {
            return [];
        }

        return array_merge(
            $this->validateRequiredFields($serverName, $serverConfig),
            $this->validateApiUrl($serverName, $serverConfig),
            $this->validateColor($serverName, $serverConfig),
            $this->validateEnvironment($serverName, $serverConfig),
        );
    }

    /**
     * @param array<string,mixed> $config
     *
     * @return array<int,string>
     */
    private function validateRequiredFields(string $serverName, array $config): array
    {
        $errors = [];
        foreach (['api_url', 'api_key', 'title'] as $field) {
            if (empty($config[$field])) {
                $errors[] = "Server '{$serverName}' missing required field: {$field}";
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $config
     *
     * @return array<int,string>
     */
    private function validateApiUrl(string $serverName, array $config): array
    {
        if (empty($config['api_url'])) {
            return [];
        }
        if (!filter_var($config['api_url'], FILTER_VALIDATE_URL)) {
            return ["Server '{$serverName}' has invalid API URL format"];
        }
        $scheme = parse_url($config['api_url'], PHP_URL_SCHEME);
        if ($scheme === 'https') {
            return [];
        }
        if ($scheme !== 'http') {
            return ["Server '{$serverName}' API URL must use HTTPS protocol (found: {$scheme}://)"];
        }

        return $this->validateHttpAllowed($serverName, $config);
    }

    /**
     * @param array<string,mixed> $config
     *
     * @return array<int,string>
     */
    private function validateHttpAllowed(string $serverName, array $config): array
    {
        if (!(TypeConverter::toBool($config['allow_http'] ?? null) ?? false)) {
            return ["Server '{$serverName}' API URL must use HTTPS protocol (found: http://). To allow HTTP connections, set 'allow_http': \"on\" in configuration."];
        }
        if (!(TypeConverter::toBool($config['suppress_http_warning'] ?? null) ?? false)) {
            $this->recordConfigurationEvent(
                'http_connection_allowed',
                "Security warning: HTTP connection allowed for server '{$serverName}' (api_url: {$config['api_url']}). Recommendation: Use HTTPS for production environments.",
                'warning',
            );
        }

        return [];
    }

    /**
     * @param array<string,mixed> $config
     *
     * @return array<int,string>
     */
    private function validateColor(string $serverName, array $config): array
    {
        if (!isset($config['primary_color'])) {
            return [];
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $config['primary_color'])) {
            return ["Server '{$serverName}' has invalid primary_color format (should be #RRGGBB)"];
        }

        return [];
    }

    /**
     * @param array<string,mixed> $config
     *
     * @return array<int,string>
     */
    private function validateEnvironment(string $serverName, array $config): array
    {
        if (!isset($config['env'])) {
            return [];
        }
        $environment = $config['env'];
        if (is_string($environment) && in_array(strtolower(trim($environment)), ['prod', 'live', 'test', 'dev', 'staging'], true)) {
            return [];
        }

        return ["Server '{$serverName}' env must be one of: prod, live, test, dev, staging"];
    }

    private function reportLoadFailure(string $filePath): void
    {
        $this->recordConfigurationEvent(
            type: 'servers_config_not_found',
            message: "Could not load {$filePath} -> file not found",
            level: 'critical',
            forceAlarm: true,
        );
    }

    private function recordConfigurationEvent(string $type, string $message, string $level = 'info', bool $forceAlarm = false): void
    {
        $recordResult = $this->eventRecorder->recordEvent(type: $type, message: $message, level: $level, forceAlarm: $forceAlarm);
        if ($forceAlarm && $this->alarmDirectProcessor !== null) {
            $this->alarmDirectProcessor->process(new Alarm(
                type: $type,
                message: $message,
                level: AlarmLevel::fromString($level),
                server: $recordResult->serverLabel,
                app: $recordResult->appLabel,
                channels: ['email'],
            ), $recordResult);
        }
    }
}
