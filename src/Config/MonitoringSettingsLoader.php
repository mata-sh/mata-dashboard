<?php

declare(strict_types=1);

namespace Watcher\Config;

use Exception;
use RuntimeException;
use Watcher\Core\DatabaseInterface;
use Watcher\Core\NullDatabase;
use Watcher\Models\EventRecorder;
use Watcher\Services\Foundation\AlertManager;
use Watcher\Services\Foundation\Dto\Alert;
use Watcher\Services\Foundation\Dto\AlertLevel;
use Watcher\Services\Foundation\SettingsDatabaseSync;
use Watcher\Utils\TypeConverter;

/**
 * Monitoring settings loader for servers, thresholds, and TTL settings.
 *
 * Handles comprehensive monitoring settings loading with:
 * - Server configuration with validation and event logging
 * - TTL configuration with fallback defaults
 * - Threshold configuration for alerts and monitoring
 * - Structured error handling and validation
 * - Event logging integration for configuration failures
 * - Automatic database synchronization when config files change
 *
 * Note: "Settings" refers to JSON-based configuration (vs. "Config" which uses DTOs from .env files)
 */
final class MonitoringSettingsLoader
{
    private EventRecorder $eventRecorder;
    private ?AlertManager $alertManager;
    private ?DatabaseInterface $db;
    private ?SettingsDatabaseSync $settingsSync;

    /**
     * @param EventRecorder             $eventRecorder Event recorder for logging configuration failures
     * @param null|AlertManager         $alertManager  Optional alert manager for critical notifications
     * @param null|DatabaseInterface    $db            Optional database connection for settings sync
     * @param null|SettingsDatabaseSync $settingsSync  Optional settings sync service
     */
    public function __construct(
        EventRecorder $eventRecorder,
        ?AlertManager $alertManager = null,
        ?DatabaseInterface $db = null,
        ?SettingsDatabaseSync $settingsSync = null
    ) {
        $this->eventRecorder = $eventRecorder;
        $this->alertManager = $alertManager;
        $this->db = $db;
        $this->settingsSync = $settingsSync;
    }

    /**
     * Load and validate server configuration from JSON file.
     *
     * Loads the servers.json configuration file containing monitored server definitions.
     * Automatically logs critical events and triggers alarms if configuration loading fails.
     *
     * @param string $serversConfigFile Path to servers.json configuration file
     *
     * @return array<string,mixed> Server configuration array with endpoints, credentials, and settings
     *
     * @throws Exception If configuration file is invalid, empty, or cannot be read
     */
    public function loadServersConfig(string $serversConfigFile): array
    {
        $serversConfig = $this->loadJsonConfig(
            filePath: $serversConfigFile,
            configType: 'servers',
            defaultConfig: null,
            throwOnError: true,
            exceptionClass: Exception::class
        );

        // Remove entries where array key begins with underscore
        $serversConfig = array_filter(
            $serversConfig,
            fn ($key) => !str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY
        );

        $validation = $this->validateServersConfig($serversConfig);
        if (!$validation['is_valid']) {
            foreach ($validation['errors'] as $error) {
                $this->recordConfigurationEvent(
                    type: 'servers_config_validation_error',
                    message: $error,
                    level: 'warning',
                    isAlarm: false
                );
            }
        }

        // Sync servers to database if settings was modified (only if DB and sync service available)
        if ($this->db && $this->settingsSync && !($this->db instanceof NullDatabase)) {
            try {
                $this->settingsSync->syncIfNeeded($serversConfigFile, $serversConfig);
            } catch (Exception $e) {
                $this->recordConfigurationEvent(
                    type: 'settings_sync_error',
                    message: 'Failed to sync servers settings to database: ' . $e->getMessage(),
                    level: 'warning',
                    isAlarm: false
                );
            }
        }

        return $serversConfig;
    }

    /**
     * Load TTL configuration for caching.
     *
     * Loads the ttl.json configuration file containing cache timeout settings.
     * Falls back to default values and logs warnings if configuration loading fails.
     *
     * @param string $ttlConfigFile Path to TTL configuration file (typically ttl.json)
     *
     * @return array<string,mixed> TTL configuration with cache timeouts for various operations
     */
    public function loadTtlConfig(string $ttlConfigFile): array
    {
        $ttlConfig = $this->loadJsonConfig(
            filePath: $ttlConfigFile,
            configType: 'ttl',
            defaultConfig: $this->getDefaultTtlConfig(),
            throwOnError: false
        );

        return array_merge($this->getDefaultTtlConfig(), $ttlConfig);
    }

    /**
     * Load threshold configuration for monitoring alerts.
     *
     * Loads the thresholds.json configuration file containing monitoring thresholds.
     *
     * @param string $thresholdsConfigFile Path to thresholds.json configuration file
     *
     * @return array<string,mixed> Threshold configuration with server_data, app_data, and alarms sections
     *
     * @throws RuntimeException If configuration file is missing or contains invalid JSON
     */
    public function loadThresholdsConfig(string $thresholdsConfigFile): array
    {
        return $this->loadJsonConfig(
            filePath: $thresholdsConfigFile,
            configType: 'thresholds',
            defaultConfig: null,
            throwOnError: true,
            exceptionClass: RuntimeException::class
        );
    }

    /**
     * Load data retention configuration for server metrics storage.
     *
     * Loads the data_retention.json configuration file containing retention and aggregation settings.
     * Falls back to default values and logs warnings if configuration loading fails.
     *
     * @param string $dataRetentionConfigFile Path to data retention configuration file (typically data_retention.json)
     *
     * @return array<string,mixed> Data retention configuration with retention days, aggregation, and pruning settings
     */
    public function loadDataRetentionConfig(string $dataRetentionConfigFile): array
    {
        $dataRetentionConfig = $this->loadJsonConfig(
            filePath: $dataRetentionConfigFile,
            configType: 'data_retention',
            defaultConfig: $this->getDefaultDataRetentionConfig(),
            throwOnError: false
        );

        return array_merge($this->getDefaultDataRetentionConfig(), $dataRetentionConfig);
    }

    /**
     * Load intervals configuration for event and alarm cooldowns.
     *
     * Loads the intervals.json configuration file containing cooldown intervals for events and alarms.
     * Falls back to default values and logs warnings if configuration loading fails.
     *
     * @param string $intervalsConfigFile Path to intervals.json configuration file
     *
     * @return array<string,mixed> Intervals configuration with events and alarms sections containing min_*_interval_minutes
     */
    public function loadIntervalsConfig(string $intervalsConfigFile): array
    {
        $intervalsConfig = $this->loadJsonConfig(
            filePath: $intervalsConfigFile,
            configType: 'intervals',
            defaultConfig: $this->getDefaultIntervalsConfig(),
            throwOnError: false
        );

        return array_merge($this->getDefaultIntervalsConfig(), $intervalsConfig);
    }

    /**
     * Get default intervals configuration.
     *
     * @return array<string,mixed> Default interval values for events and alarms in minutes
     */
    private function getDefaultIntervalsConfig(): array
    {
        return [
            'events' => [
                'min_event_interval_minutes' => [
                    '_default' => 60,
                    'app_unreachable' => 15,
                    'orders_export_stale' => 30,
                    'cronjob_heartbeat_missing' => 30,
                    'filecount_too_large' => 72,
                    'logfile_too_large' => 12,
                    'too_many_sql_connections' => 72,
                    'servers_config_not_found' => 18,
                    'remaining_disk_space_too_low' => 60,
                    'fatal_errors_occuring' => 72,
                ],
            ],
            'alarms' => [
                'min_alarm_interval_minutes' => [
                    '_default' => 1440,
                    'app_unreachable' => 60,
                    'orders_export_stale' => 60,
                    'cronjob_heartbeat_missing' => 360,
                    'filecount_too_large' => 60,
                    'logfile_too_large' => 720,
                    'too_many_sql_connections' => 60,
                    'servers_config_not_found' => 1440,
                    'remaining_disk_space_too_low' => 180,
                    'fatal_errors_occuring' => 1440,
                ],
            ],
        ];
    }

    /**
     * Validate server configuration structure and content.
     *
     * @param array<string,mixed> $config Server configuration array
     *
     * @return array{is_valid: bool, errors: array<int,string>} Validation results
     */
    public function validateServersConfig(array $config): array
    {
        $errors = [];

        if (empty($config)) {
            return ['is_valid' => false, 'errors' => ['Server configuration is empty']];
        }

        foreach ($config as $serverName => $serverConfig) {
            $errors = array_merge($errors, $this->validateServerConfig($serverName, $serverConfig));
        }

        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate individual server configuration.
     *
     * @param string $serverName   Server identifier
     * @param mixed  $serverConfig Server configuration data
     *
     * @return array<int,string> Validation errors
     */
    private function validateServerConfig(string $serverName, mixed $serverConfig): array
    {
        $errors = [];

        if (!is_array($serverConfig)) {
            return ["Server '{$serverName}' configuration must be an array"];
        }

        $errors = array_merge($errors, $this->validateRequiredFields($serverName, $serverConfig));
        $errors = array_merge($errors, $this->validateApiUrl($serverName, $serverConfig));

        return array_merge($errors, $this->validateColor($serverName, $serverConfig));
    }

    /**
     * Validate required configuration fields are present.
     *
     * @param string              $serverName   Server identifier
     * @param array<string,mixed> $serverConfig Server configuration
     *
     * @return array<int,string> Validation errors for missing fields
     */
    private function validateRequiredFields(string $serverName, array $serverConfig): array
    {
        $errors = [];
        $requiredFields = ['api_url', 'api_key', 'title'];

        foreach ($requiredFields as $field) {
            if (empty($serverConfig[$field])) {
                $errors[] = "Server '{$serverName}' missing required field: {$field}";
            }
        }

        return $errors;
    }

    /**
     * Validate API URL format and protocol security.
     *
     * @param string              $serverName   Server identifier
     * @param array<string,mixed> $serverConfig Server configuration
     *
     * @return array<int,string> Validation errors for invalid or insecure URLs
     */
    private function validateApiUrl(string $serverName, array $serverConfig): array
    {
        if (empty($serverConfig['api_url'])) {
            return [];
        }

        if (!filter_var($serverConfig['api_url'], FILTER_VALIDATE_URL)) {
            return ["Server '{$serverName}' has invalid API URL format"];
        }

        $scheme = parse_url($serverConfig['api_url'], PHP_URL_SCHEME);

        if ($scheme === 'https') {
            return [];
        }

        if ($scheme !== 'http') {
            return ["Server '{$serverName}' API URL must use HTTPS protocol (found: {$scheme}://)"];
        }

        return $this->validateHttpAllowed($serverName, $serverConfig);
    }

    /**
     * Validate HTTP connection permission and log security warnings.
     *
     * @param string              $serverName   Server identifier
     * @param array<string,mixed> $serverConfig Server configuration
     *
     * @return array<int,string> Validation errors if HTTP not explicitly allowed
     */
    private function validateHttpAllowed(string $serverName, array $serverConfig): array
    {
        $allowHttp = TypeConverter::toBool($serverConfig['allow_http'] ?? null) ?? false;

        if (!$allowHttp) {
            return [
                "Server '{$serverName}' API URL must use HTTPS protocol (found: http://). "
                . "To allow HTTP connections, set 'allow_http': \"on\" in configuration.",
            ];
        }

        $suppressWarning = TypeConverter::toBool($serverConfig['suppress_http_warning'] ?? null) ?? false;

        if (!$suppressWarning) {
            $this->recordConfigurationEvent(
                type: 'http_connection_allowed',
                message: "Security warning: HTTP connection allowed for server '{$serverName}' "
                    . "(api_url: {$serverConfig['api_url']}). "
                    . 'Recommendation: Use HTTPS for production environments.',
                level: 'warning',
                isAlarm: false
            );
        }

        return [];
    }

    /**
     * Validate primary color format.
     *
     * @param string              $serverName   Server identifier
     * @param array<string,mixed> $serverConfig Server configuration
     *
     * @return array<int,string> Validation errors for invalid color format
     */
    private function validateColor(string $serverName, array $serverConfig): array
    {
        if (!isset($serverConfig['primary_color'])) {
            return [];
        }

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $serverConfig['primary_color'])) {
            return ["Server '{$serverName}' has invalid primary_color format (should be #RRGGBB)"];
        }

        return [];
    }

    /**
     * Get default TTL configuration values.
     *
     * @return array<string,int> Default TTL values in seconds for various cache operations
     */
    public function getDefaultTtlConfig(): array
    {
        return [
            'ping' => 360,              // 6 minutes
            'servers' => 720,           // 12 minutes
            'apps' => 60,               // 1 minute
            'app' => 30,                // 30 seconds
            'serverData' => 10,         // 10 seconds
            'databases' => 10,          // 10 seconds
            'libraries' => 600,         // 10 minutes
            'appLogs' => 30,           // 30 seconds
            'serverProcesses' => 30,    // 30 seconds
            'readme' => 300,           // 5 minutes
            'errorlog' => 60,          // 1 minute
            'serverMetricsChart' => 60, // 1 minute
        ];
    }

    /**
     * Get all monitoring configuration in one call with environment support.
     *
     * Convenience method to load all monitoring configurations at once.
     * Loads configuration files from environment-specific subdirectories.
     *
     * @param string $configPath  Base configuration directory path
     * @param string $environment Environment name (dev, demo, prod)
     *
     * @return array<string,mixed> Complete monitoring configuration with servers, ttl, thresholds, and data_retention
     */
    public function loadAllConfigs(string $configPath, string $environment = 'prod'): array
    {
        $envConfigPath = $this->getEnvironmentConfigPath($configPath, $environment);

        $serversFile = $envConfigPath . '/servers.json';
        $ttlFile = $envConfigPath . '/ttl.json';
        $intervalsFile = $envConfigPath . '/intervals.json';
        $thresholdsFile = $envConfigPath . '/thresholds.json';
        $dataRetentionFile = $envConfigPath . '/data_retention.json';

        $config = [
            'servers' => $this->loadServersConfig($serversFile),
            'ttl' => $this->loadTtlConfig($ttlFile),
            'data_retention' => $this->loadDataRetentionConfig($dataRetentionFile),
            'intervals' => $this->loadIntervalsConfig($intervalsFile),
            'environment' => $environment,
            'charts' => [],
        ];

        // Load thresholds with fallback
        try {
            $config['thresholds'] = $this->loadThresholdsConfig($thresholdsFile);
        } catch (RuntimeException $e) {
            $this->recordConfigurationEvent(
                type: 'thresholds_config_error',
                message: 'Could not load thresholds config: ' . $e->getMessage(),
                level: 'warning'
            );
            $config['thresholds'] = $this->getDefaultThresholdsConfig();
        }

        return $config;
    }

    /**
     * Get environment-specific configuration path.
     *
     * @param string $basePath    Base configuration directory
     * @param string $environment Environment name
     *
     * @return string Environment-specific configuration path
     *
     * @throws RuntimeException If environment directory doesn't exist
     */
    private function getEnvironmentConfigPath(string $basePath, string $environment): string
    {
        $envPath = $basePath . '/' . $environment;

        if (!is_dir($envPath)) {
            // Fallback to prod if environment directory doesn't exist
            $fallbackPath = $basePath . '/prod';
            if (!is_dir($fallbackPath)) {
                throw new RuntimeException(
                    "Configuration environment directory not found: {$envPath} "
                    . "and fallback {$fallbackPath} also missing"
                );
            }

            $this->recordConfigurationEvent(
                type: 'config_environment_fallback',
                message: "Environment '{$environment}' not found, falling back to 'prod'",
                level: 'warning'
            );

            return $fallbackPath;
        }

        return $envPath;
    }

    /**
     * Get default thresholds configuration.
     *
     * @return array<string,mixed> Default threshold values for monitoring
     */
    private function getDefaultThresholdsConfig(): array
    {
        return [
            'server_data' => [
                'cpu_usage' => ['info' => 50, 'warning' => 70, 'error' => 85, 'alarm' => 95],
                'disk_space_usage_percent' => ['info' => 60, 'warning' => 75, 'error' => 85, 'alarm' => 95],
                'total_system_filecount' => [
                    'info' => 100000,
                    'warning' => 500000,
                    'error' => 1000000,
                    'alarm' => 2000000,
                ],
                'log_filesize_kb' => ['info' => 1000, 'warning' => 5000, 'error' => 10000, 'alarm' => 50000],
            ],
            'app_data' => [
                'session_count' => ['info' => 50, 'warning' => 100, 'error' => 200, 'alarm' => 500],
                'error_count' => ['info' => 1, 'warning' => 5, 'error' => 10, 'alarm' => 25],
            ],
            'alarms' => [
                'fatal_errors_occuring' => [
                    'alarm_threshold_event_count' => 1,
                    'min_alarm_interval_minutes' => 1440,
                ],
                'logfile_too_large' => [
                    'alarm_threshold_event_count' => 1,
                    'min_alarm_interval_minutes' => 1440,
                ],
            ],
        ];
    }

    /**
     * Get default data retention configuration.
     *
     * @return array<string,mixed> Default data retention values for server metrics
     */
    private function getDefaultDataRetentionConfig(): array
    {
        return [
            'raw_retention_days' => 30,
            'aggregation' => [
                'hourly' => [
                    'lag_minutes' => 5,
                    'catch_up_hours' => 24,
                ],
                'daily' => [
                    'lag_minutes' => 30,
                ],
            ],
            'pruning' => [
                'batch_limit' => 5000,
            ],
        ];
    }

    /**
     * Load event cooldown configuration for monitoring events.
     *
     * @param string $eventsConfigFile Path to events.json configuration file
     *
     * @return array<string,array<string,int>> Normalized event cooldown configuration
     */
    public function loadEventsConfig(string $eventsConfigFile): array
    {
        $defaults = $this->getDefaultEventsConfig();

        $eventsConfig = $this->loadJsonConfig(
            filePath: $eventsConfigFile,
            configType: 'events',
            defaultConfig: $defaults,
            throwOnError: false
        );

        // If we got defaults back due to error, return immediately
        if ($eventsConfig === $defaults) {
            return $defaults;
        }

        $normalized = [];

        foreach ($eventsConfig as $eventType => $settings) {
            if ($eventType === '') {
                continue;
            }

            if (is_array($settings) && array_key_exists('min_event_interval_minutes', $settings)) {
                $normalized[$eventType] = [
                    'min_event_interval_minutes' => max(0, (int) $settings['min_event_interval_minutes']),
                ];

                continue;
            }

            if (is_numeric($settings)) {
                $normalized[$eventType] = [
                    'min_event_interval_minutes' => max(0, (int) $settings),
                ];
            }
        }

        return array_replace($defaults, $normalized);
    }

    /**
     * Get default event cooldown configuration.
     *
     * Note: The '_default' key specifies the fallback interval for unconfigured event types.
     *
     * @return array<string,array<string,int>> Default event cooldown intervals
     */
    public function getDefaultEventsConfig(): array
    {
        return [
            '_default' => ['min_event_interval_minutes' => 60],
            'app_unreachable' => ['min_event_interval_minutes' => 15],
            'orders_export_stale' => ['min_event_interval_minutes' => 30],
            'cronjob_heartbeat_missing' => ['min_event_interval_minutes' => 30],
            'filecount_too_large' => ['min_event_interval_minutes' => 72],
            'logfile_too_large' => ['min_event_interval_minutes' => 12],
            'too_many_sql_connections' => ['min_event_interval_minutes' => 72],
            'servers_config_not_found' => ['min_event_interval_minutes' => 18],
            'remaining_disk_space_too_low' => ['min_event_interval_minutes' => 60],
            'fatal_errors_occuring' => ['min_event_interval_minutes' => 72],
        ];
    }

    /**
     * Load and parse JSON configuration file with consistent error handling.
     *
     * @param string                   $filePath       Path to JSON configuration file
     * @param string                   $configType     Configuration type for error messages (e.g., 'ttl', 'events', 'servers')
     * @param null|array<string,mixed> $defaultConfig  Default configuration to return on failure
     * @param bool                     $throwOnError   If true, throws exceptions; if false, returns defaults and logs warnings
     * @param string                   $exceptionClass Exception class to throw (Exception or RuntimeException)
     *
     * @return array<string,mixed> Parsed configuration array
     *
     * @throws Exception|RuntimeException If file loading fails and throwOnError is true
     */
    private function loadJsonConfig(
        string $filePath,
        string $configType,
        ?array $defaultConfig = null,
        bool $throwOnError = false,
        string $exceptionClass = Exception::class
    ): array {
        // File existence check
        if (!file_exists($filePath)) {
            $type = "{$configType}_config_not_found";
            $message = "Could not load {$filePath} -> "
                       . ($throwOnError ? 'file not found' : 'fallback to Default Values');
            $level = $throwOnError ? 'critical' : 'warning';

            $this->recordConfigurationEvent(
                type: $type,
                message: $message,
                level: $level,
                isAlarm: $throwOnError
            );

            if ($throwOnError) {
                throw new $exceptionClass("{$configType}.json file not found at {$filePath}");
            }

            return $defaultConfig ?? [];
        }

        // File read check
        $content = file_get_contents($filePath);
        if ($content === false) {
            $type = "{$configType}_config_read_error";
            $message = "Could not read {$filePath} -> "
                       . ($throwOnError ? 'permission denied or file error' : 'fallback to Default Values');
            $level = $throwOnError ? 'critical' : 'warning';

            $this->recordConfigurationEvent(
                type: $type,
                message: $message,
                level: $level,
                isAlarm: $throwOnError
            );

            if ($throwOnError) {
                throw new $exceptionClass("Cannot read {$configType}.json file");
            }

            return $defaultConfig ?? [];
        }

        // JSON parsing check
        $config = json_decode($content, true);
        if (!$config || json_last_error() !== JSON_ERROR_NONE) {
            $type = $configType === 'events' ? "{$configType}_config_invalid" : "{$configType}_config_not_found";
            $message = 'Could not ' . ($configType === 'events' ? 'parse' : 'load') . " {$filePath} -> "
                       . ($throwOnError ? 'no Server Watching (' : 'fallback to Default Values (')
                       . 'JSON error: ' . json_last_error_msg() . ')';
            $level = $throwOnError ? 'critical' : 'warning';

            $this->recordConfigurationEvent(
                type: $type,
                message: $message,
                level: $level,
                isAlarm: $throwOnError
            );

            if ($throwOnError) {
                throw new $exceptionClass("{$configType}.json invalid or empty");
            }

            return $defaultConfig ?? [];
        }

        return $config;
    }

    /**
     * Persist configuration events and optionally dispatch alerts.
     *
     * @param string $type    Event type identifier
     * @param string $message Human readable message
     * @param string $level   Severity level (info, warning, critical)
     * @param bool   $isAlarm Whether to trigger alert delivery
     */
    private function recordConfigurationEvent(
        string $type,
        string $message,
        string $level = 'info',
        bool $isAlarm = false
    ): void {
        $recordResult = $this->eventRecorder->recordEvent(
            type: $type,
            message: $message,
            level: $level,
            isAlarm: $isAlarm
        );

        if ($isAlarm && $this->alertManager !== null) {
            $alert = new Alert(
                type: $type,
                message: $message,
                level: AlertLevel::fromString($level),
                server: $recordResult->serverLabel,
                app: $recordResult->appLabel,
                channels: ['email']
            );
            $this->alertManager->handleAlert($alert, $recordResult);
        }
    }
}
