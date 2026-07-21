<?php

declare(strict_types=1);

namespace Mata\Config;

use Exception;
use Mata\Core\DatabaseInterface;
use Mata\Core\NullDatabase;
use Mata\Services\Alarm\AlarmDirectProcessor;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmLevel;
use Mata\Services\Event\EventRecorderService;
use Mata\Services\Sync\SettingsDatabaseSync;
use Mata\Utils\TypeConverter;
use RuntimeException;
use Throwable;

/**
 * Monitoring settings loader for servers, thresholds, and TTL settings.
 *
 * Handles comprehensive monitoring settings loading with:
 * - Server configuration with validation and event logging
 * - TTL configuration with fallback defaults
 * - Threshold configuration for alarms and monitoring
 * - Structured error handling and validation
 * - Event logging integration for configuration failures
 * - Automatic database synchronization when config files change
 *
 * Note: "Settings" refers to JSON-based configuration (vs. "Config" which uses DTOs from .env files)
 */
final class MonitoringSettingsLoader
{
    private EventRecorderService $eventRecorder;
    private ?AlarmDirectProcessor $alarmDirectProcessor;
    private ?DatabaseInterface $db;
    private ?SettingsDatabaseSync $settingsSync;

    /**
     * @param EventRecorderService      $eventRecorder        Event recorder for logging configuration failures
     * @param null|AlarmDirectProcessor $alarmDirectProcessor Optional direct alarm processor for critical notifications
     * @param null|DatabaseInterface    $db                   Optional database connection for settings sync
     * @param null|SettingsDatabaseSync $settingsSync         Optional settings sync service
     */
    public function __construct(
        EventRecorderService $eventRecorder,
        ?AlarmDirectProcessor $alarmDirectProcessor = null,
        ?DatabaseInterface $db = null,
        ?SettingsDatabaseSync $settingsSync = null
    ) {
        $this->eventRecorder = $eventRecorder;
        $this->alarmDirectProcessor = $alarmDirectProcessor;
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
            throwAs: Exception::class
        );

        // Remove entries where array key begins with underscore
        $serversConfig = array_filter(
            $serversConfig,
            static fn ($key): bool => !str_starts_with((string) $key, '_'),
            ARRAY_FILTER_USE_KEY
        );

        // Disabled servers are excluded before validation, synchronization, or monitoring.
        $configuredServerCount = count($serversConfig);
        $serversConfig = array_filter(
            $serversConfig,
            static fn (mixed $config): bool => !is_array($config) || ($config['disabled'] ?? false) !== true
        );
        $allServersDisabled = $configuredServerCount > 0 && $serversConfig === [];

        $validation = $allServersDisabled
            ? ['is_valid' => true, 'errors' => []]
            : $this->validateServersConfig($serversConfig);
        if (!$validation['is_valid']) {
            foreach ($validation['errors'] as $error) {
                $this->recordConfigurationEvent(
                    type: 'servers_config_validation_error',
                    message: $error,
                    level: 'warning',
                    forceAlarm: false
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
                    forceAlarm: false
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
            defaultConfig: $this->getDefaultTtlConfig()
        );

        return array_merge($this->getDefaultTtlConfig(), $ttlConfig);
    }

    /**
     * Load threshold configuration for monitoring alarms.
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
            throwAs: RuntimeException::class
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
            defaultConfig: $this->getDefaultDataRetentionConfig()
        );

        return $this->normalizeDataRetentionConfig(
            array_replace_recursive($this->getDefaultDataRetentionConfig(), $dataRetentionConfig)
        );
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
            defaultConfig: $this->getDefaultIntervalsConfig()
        );

        return array_merge($this->getDefaultIntervalsConfig(), $intervalsConfig);
    }

    /**
     * Load probe retry/timeout configuration for the availability and health monitors.
     *
     * Falls back to default values (preserving historical monitor behavior) and logs a
     * warning if the file is missing or invalid, so an absent probes.json changes nothing.
     *
     * @param string $probesConfigFile Path to probes.json configuration file
     *
     * @return array<string,mixed> Probe policy with timeout, retry count, backoff bounds, and retryable kinds
     */
    public function loadProbesConfig(string $probesConfigFile): array
    {
        $probesConfig = $this->loadJsonConfig(
            filePath: $probesConfigFile,
            configType: 'probes',
            defaultConfig: $this->getDefaultProbesConfig()
        );

        return array_merge($this->getDefaultProbesConfig(), $probesConfig);
    }

    /**
     * Get default probe policy configuration.
     *
     * Mirrors {@see \Mata\Services\Monitor\Dto\ProbePolicy::default()}.
     *
     * @return array<string,mixed> Default probe timeout, retry, and backoff settings
     */
    public function getDefaultProbesConfig(): array
    {
        return [
            'timeout_seconds' => 5,
            'max_retries' => 1,
            'backoff_base_ms' => 500,
            'backoff_max_ms' => 1500,
            'retryable_kinds' => ['dns', 'connect', 'tls', 'timeout'],
        ];
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
                    'cronjobs_failing' => 30,
                    'filecount_too_large' => 72,
                    'logfile_too_large' => 12,
                    'too_many_sql_connections' => 72,
                    'remaining_diskspace_too_low' => 60,
                    'server_metrics.ingestion_run_empty' => 5,
                    'server_metrics.ingestion_stalled' => 15,
                    'cpu_usage_too_high' => 30,
                    'ram_usage_too_high' => 30,
                    'fatal_errors_occurring' => 72,
                ],
            ],
            'alarms' => [
                'min_alarm_interval_minutes' => [
                    '_default' => 1440,
                    'app_unreachable' => 60,
                    'cronjobs_failing' => 360,
                    'filecount_too_large' => 60,
                    'logfile_too_large' => 720,
                    'too_many_sql_connections' => 60,
                    'remaining_diskspace_too_low' => 180,
                    'server_metrics.ingestion_run_empty' => 30,
                    'server_metrics.ingestion_stalled' => 60,
                    'cpu_usage_too_high' => 180,
                    'ram_usage_too_high' => 180,
                    'fatal_errors_occurring' => 1440,
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

        if (array_key_exists('disabled', $serverConfig) && !is_bool($serverConfig['disabled'])) {
            return ["Server '{$serverName}' disabled must be a boolean"];
        }

        if (($serverConfig['disabled'] ?? false) === true) {
            return [];
        }

        return array_merge(
            $errors,
            $this->validateRequiredFields($serverName, $serverConfig),
            $this->validateApiUrl($serverName, $serverConfig),
            $this->validateColor($serverName, $serverConfig),
            $this->validateEnvironment($serverName, $serverConfig),
        );
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
                forceAlarm: false
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
     * Validate optional server environment fallback used in alarm emails.
     *
     * @param array<string,mixed> $serverConfig
     *
     * @return array<int,string>
     */
    private function validateEnvironment(string $serverName, array $serverConfig): array
    {
        if (!isset($serverConfig['env'])) {
            return [];
        }

        $environment = $serverConfig['env'];
        if (is_string($environment) && in_array(strtolower(trim($environment)), ['prod', 'live', 'test', 'dev', 'staging'], true)) {
            return [];
        }

        return ["Server '{$serverName}' env must be one of: prod, test, dev, staging"];
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
            'serverInfo' => 86400,      // 24 hours
            'databases' => 10,          // 10 seconds
            'libraries' => 600,         // 10 minutes
            'appLogs' => 30,           // 30 seconds
            'serverProcesses' => 30,    // 30 seconds
            'readme' => 300,           // 5 minutes
            'errorlog' => 60,          // 1 minute
            'serverMetricsChart' => 60, // 1 minute
            'audit' => 86400,           // 24 hours; refreshed explicitly by Run audit
        ];
    }

    /**
     * Get all monitoring configuration in one call.
     *
     * Runtime monitoring configuration is global to the dashboard. APP_ENV
     * identifies the dashboard runtime only; it does not select monitored targets.
     *
     * @param string $configPath     Base configuration directory path
     * @param string $appEnvironment Dashboard runtime environment
     *
     * @return array<string,mixed> Complete monitoring configuration with servers, ttl, thresholds, and data_retention
     */
    public function loadAllConfigs(string $configPath, string $appEnvironment = 'prod'): array
    {
        $configPath = rtrim($configPath, '/');

        $serversFile = $configPath . '/servers.json';
        $ttlFile = $configPath . '/ttl.json';
        $intervalsFile = $configPath . '/intervals.json';
        $thresholdsFile = $configPath . '/thresholds.json';
        $dataRetentionFile = $configPath . '/data_retention.json';
        $probesFile = $configPath . '/probes.json';

        $config = [
            'servers' => $this->loadServersConfig($serversFile),
            'ttl' => $this->loadTtlConfig($ttlFile),
            'data_retention' => $this->loadDataRetentionConfig($dataRetentionFile),
            'intervals' => $this->loadIntervalsConfig($intervalsFile),
            'probes' => $this->loadProbesConfig($probesFile),
            'environment' => $appEnvironment,
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
                'cronjobs_failed_count' => ['good' => -1, 'alarm' => 0],
            ],
            'app_data' => [
                'session_count' => ['info' => 50, 'warning' => 100, 'error' => 200, 'alarm' => 500],
                'error_count' => ['info' => 1, 'warning' => 5, 'error' => 10, 'alarm' => 25],
            ],
            'alarms' => [
                'app_unreachable' => [
                    'strategy' => 'consecutive_failures',
                    'required_count' => 2,
                    'window_minutes' => 10,
                ],
                'server_metrics.ingestion_run_empty' => [
                    'alarm_threshold_event_count' => 3,
                    'alarm_threshold_window_minutes' => 30,
                ],
                'server_metrics.ingestion_stalled' => [
                    'alarm_threshold_event_count' => 1,
                    'alarm_threshold_window_minutes' => 60,
                ],
                'fatal_errors_occurring' => [
                    'alarm_threshold_event_count' => 1,
                    'alarm_threshold_window_minutes' => 1440,
                ],
                'logfile_too_large' => [
                    'alarm_threshold_event_count' => 1,
                    'alarm_threshold_window_minutes' => 1440,
                ],
                'cpu_usage_too_high' => [
                    'alarm_threshold_event_count' => 2,
                    'alarm_threshold_window_minutes' => 30,
                ],
                'ram_usage_too_high' => [
                    'alarm_threshold_event_count' => 2,
                    'alarm_threshold_window_minutes' => 30,
                ],
            ],
        ];
    }

    /**
     * Get default data retention configuration.
     *
     * @return array<string,mixed> Default data retention values for alarms, events, and metrics
     */
    private function getDefaultDataRetentionConfig(): array
    {
        return [
            'metrics' => [
                'raw_retention_days' => 30,
                'hourly_retention_days' => 90,
                'daily_retention_days' => 365,
            ],
            'events' => [
                'retention_days' => 180,
            ],
            'alarms' => [
                'retention_days' => 365,
            ],
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
            'ingestion_health' => [
                'stall_minutes' => 15,
                'log_rotate_megabytes' => 5,
                'log_rotate_files' => 3,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $config
     *
     * @return array<string,mixed>
     */
    private function normalizeDataRetentionConfig(array $config): array
    {
        $defaults = $this->getDefaultDataRetentionConfig();

        foreach (
            [
                ['metrics', 'raw_retention_days'],
                ['metrics', 'hourly_retention_days'],
                ['metrics', 'daily_retention_days'],
                ['events', 'retention_days'],
                ['alarms', 'retention_days'],
            ] as [$section, $key]
        ) {
            if (!is_array($config[$section] ?? null)) {
                $config[$section] = $defaults[$section];
            }

            $config[$section][$key] = max(0, (int) ($config[$section][$key] ?? 0));
        }

        return $config;
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
            defaultConfig: $defaults
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
            'cronjobs_failing' => ['min_event_interval_minutes' => 30],
            'filecount_too_large' => ['min_event_interval_minutes' => 72],
            'logfile_too_large' => ['min_event_interval_minutes' => 12],
            'too_many_sql_connections' => ['min_event_interval_minutes' => 72],
            'remaining_diskspace_too_low' => ['min_event_interval_minutes' => 60],
            'cpu_usage_too_high' => ['min_event_interval_minutes' => 30],
            'ram_usage_too_high' => ['min_event_interval_minutes' => 30],
            'fatal_errors_occurring' => ['min_event_interval_minutes' => 72],
        ];
    }

    /**
     * Load and parse JSON configuration file with consistent error handling.
     *
     * @param string                       $filePath      Path to JSON configuration file
     * @param string                       $configType    Configuration type for error messages (e.g., 'ttl', 'events', 'servers')
     * @param null|array<string,mixed>     $defaultConfig Default configuration to return on failure
     * @param null|class-string<Throwable> $throwAs       If set, failures throw this exception class; if null, failures log a warning and return the defaults
     *
     * @return array<string,mixed> Parsed configuration array
     *
     * @throws Exception|RuntimeException If file loading fails and throwAs is set
     */
    private function loadJsonConfig(
        string $filePath,
        string $configType,
        ?array $defaultConfig = null,
        ?string $throwAs = null
    ): array {
        $throwOnError = $throwAs !== null;

        if (!file_exists($filePath)) {
            return $this->failConfigLoad(
                eventType: "{$configType}_config_not_found",
                message: "Could not load {$filePath} -> "
                    . ($throwOnError ? 'file not found' : 'fallback to Default Values'),
                exceptionMessage: "{$configType}.json file not found at {$filePath}",
                throwAs: $throwAs,
                defaultConfig: $defaultConfig
            );
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return $this->failConfigLoad(
                eventType: "{$configType}_config_read_error",
                message: "Could not read {$filePath} -> "
                    . ($throwOnError ? 'permission denied or file error' : 'fallback to Default Values'),
                exceptionMessage: "Cannot read {$configType}.json file",
                throwAs: $throwAs,
                defaultConfig: $defaultConfig
            );
        }

        $config = json_decode($content, true);
        if (!$config || json_last_error() !== JSON_ERROR_NONE) {
            // Events settings report their own event type; other types reuse
            // "_config_not_found" because alarm cooldowns are keyed on it.
            $isEventsConfig = $configType === 'events';
            $consequence = $throwOnError ? 'no Server Watching' : 'fallback to Default Values';

            return $this->failConfigLoad(
                eventType: $isEventsConfig ? "{$configType}_config_invalid" : "{$configType}_config_not_found",
                message: 'Could not ' . ($isEventsConfig ? 'parse' : 'load') . " {$filePath} -> "
                    . "{$consequence} (JSON error: " . json_last_error_msg() . ')',
                exceptionMessage: "{$configType}.json invalid or empty",
                throwAs: $throwAs,
                defaultConfig: $defaultConfig
            );
        }

        return $config;
    }

    /**
     * Record a configuration load failure, then either throw or return the fallback config.
     *
     * @param string                       $eventType        Event type identifier for the recorded event
     * @param string                       $message          Human readable failure message
     * @param string                       $exceptionMessage Message for the exception when throwAs is set
     * @param null|class-string<Throwable> $throwAs          If set, records a critical event and throws this class; if null, warns and returns defaults
     * @param null|array<string,mixed>     $defaultConfig    Fallback configuration returned when not throwing
     *
     * @return array<string,mixed> The fallback configuration (empty array if none given)
     *
     * @throws Exception|RuntimeException If throwAs is set
     */
    private function failConfigLoad(
        string $eventType,
        string $message,
        string $exceptionMessage,
        ?string $throwAs,
        ?array $defaultConfig
    ): array {
        $this->recordConfigurationEvent(
            type: $eventType,
            message: $message,
            level: $throwAs !== null ? 'critical' : 'warning',
            forceAlarm: $throwAs !== null
        );

        if ($throwAs !== null) {
            throw new $throwAs($exceptionMessage);
        }

        return $defaultConfig ?? [];
    }

    /**
     * Persist configuration events and optionally dispatch alarms.
     *
     * @param string $type    Event type identifier
     * @param string $message Human readable message
     * @param string $level   Severity level (info, warning, critical)
     */
    private function recordConfigurationEvent(
        string $type,
        string $message,
        string $level = 'info',
        bool $forceAlarm = false
    ): void {
        $recordResult = $this->eventRecorder->recordEvent(
            type: $type,
            message: $message,
            level: $level,
            forceAlarm: $forceAlarm
        );

        if ($forceAlarm && $this->alarmDirectProcessor !== null) {
            $alarm = new Alarm(
                type: $type,
                message: $message,
                level: AlarmLevel::fromString($level),
                server: $recordResult->serverLabel,
                app: $recordResult->appLabel,
                channels: ['email']
            );
            $this->alarmDirectProcessor->process($alarm, $recordResult);
        }
    }
}
