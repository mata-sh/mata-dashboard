<?php

declare(strict_types=1);

namespace Mata\Config;

use Dotenv\Dotenv;
use Dotenv\Exception\ExceptionInterface;
use InvalidArgumentException;
use Mata\Config\Dto\AlarmDecisionConfig;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Dto\CliSecurityConfig;
use Mata\Config\Dto\DashboardConfig;
use Mata\Config\Dto\DatabaseConfig;
use Mata\Config\Dto\DemoConfig;
use Mata\Config\Dto\EnvironmentConfig;
use Mata\Config\Dto\GeneralConfig;
use Mata\Config\Dto\LoggingConfig;
use Mata\Config\Dto\MailConfig;
use Mata\Config\Dto\NodeApiConfig;
use Mata\Config\Dto\OpaAlarmDecisionConfig;
use Mata\Config\Dto\PhpConfig;
use Mata\Config\Dto\RateLimitingConfig;
use Mata\Config\Dto\TemplateConfig;
use Mata\Http\Security\IpRangeMatcher;

/**
 * Configuration file loader supporting multiple .env formats.
 *
 * Handles comprehensive configuration loading with:
 * - Multiple .env file support
 * - Environment variable validation
 * - Type-safe configuration parsing via DTOs
 * - Structured configuration sections (app, database, mail, etc.)
 * - Default value handling
 * - Comma-separated value parsing
 * - Boolean value normalization
 */
final class EnvConfigLoader
{
    /** The env files of an instance in config/; the first file that sets a variable wins. */
    public const INSTANCE_FILES = ['config.env', 'db.config.env', 'mail.config.env'];

    /** @var array<string,string> env file that set each variable in the last load, e.g. ['DOMAIN' => 'config.env'] */
    private array $fileOf = [];

    /**
     * Resolve the application-visible input, not Dotenv's repository (which also reads $_SERVER).
     * Use the same loader that loaded the DTO, before changing the environment.
     */
    public function sourceOf(string $variable): ConfigSource
    {
        if (!array_key_exists($variable, $_ENV)) {
            return getenv($variable) === false ? ConfigSource::missing($variable) : ConfigSource::process($variable);
        }
        $file = $this->fileOf[$variable] ?? null;

        return $file === null ? ConfigSource::process($variable) : ConfigSource::file($file, $variable);
    }

    /**
     * Load and parse configuration from multiple .env files.
     *
     * @param array<int,string> $envFiles   List of .env file names to load
     * @param string            $configPath Directory path containing configuration files
     *
     * @return ApplicationConfig Type-safe application configuration DTO
     *
     * @throws EnvConfigException If an env file cannot be parsed or a variable is missing or invalid
     */
    public function load(array $envFiles, string $configPath): ApplicationConfig
    {
        $this->fileOf = [];

        foreach ($envFiles as $file) {
            if (file_exists($configPath . '/' . $file)) {
                try {
                    $assignments = Dotenv::createImmutable($configPath, $file)->load();
                } catch (ExceptionInterface $exception) {
                    throw new EnvConfigException($exception->getMessage(), ConfigSource::file($file), $exception);
                }
                foreach ($assignments as $variable => $value) {
                    if ($value === null) {
                        unset($this->fileOf[$variable]);
                    } else {
                        $this->fileOf[$variable] = $file;
                    }
                }
            }
        }

        return new ApplicationConfig(
            environment: $this->loadEnvironmentConfig(),
            general: $this->loadGeneralConfig(),
            dashboard: $this->loadDashboardConfig(),
            template: $this->loadTemplateConfig(),
            php: $this->loadPhpConfig(),
            logging: $this->loadLoggingConfig(),
            database: $this->loadDatabaseConfig(),
            mail: $this->loadMailConfig(),
            cliSecurity: $this->loadCliSecurityConfig(),
            rateLimiting: $this->loadRateLimitingConfig(),
            alarmDecision: $this->loadAlarmDecisionConfig(),
            nodeApi: $this->loadNodeApiConfig(),
            demo: $this->loadDemoConfig(),
        );
    }

    private function loadAlarmDecisionConfig(): AlarmDecisionConfig
    {
        $driver = $this->getEnv('ALARM_DECISION_DRIVER', 'opa');

        return new AlarmDecisionConfig(
            driver: $driver === '' ? 'opa' : (string) $driver,
            batchLimit: $this->getEnvInteger('ALARM_DECISION_BATCH_LIMIT', 100, minimum: 1),
            opa: new OpaAlarmDecisionConfig(
                baseUri: (string) $this->getEnv('OPA_ALARM_BASE_URI', 'http://127.0.0.1:3320'),
                decisionPath: (string) $this->getEnv('OPA_ALARM_DECISION_PATH', OpaAlarmDecisionConfig::SHIPPED_DECISION_PATH),
                timeoutSeconds: $this->getEnvDecimal('OPA_ALARM_TIMEOUT_SECONDS', 5, minimum: 1),
            ),
        );
    }

    private function loadNodeApiConfig(): NodeApiConfig
    {
        return new NodeApiConfig(
            timeoutSeconds: $this->getEnvInteger(
                'NODE_API_TIMEOUT_SECONDS',
                NodeApiConfig::DEFAULT_TIMEOUT_SECONDS,
                minimum: 1,
            ),
        );
    }

    private function loadDemoConfig(): DemoConfig
    {
        $seed = $this->normalizeOptionalEnvValue($this->getEnv('DEMO_SEED'));

        try {
            $parsedSeed = $seed === null ? null : ConfigValue::integerString($seed, 'env.DEMO_SEED', minimum: 0);
        } catch (InvalidArgumentException $exception) {
            throw new EnvConfigException($exception->getMessage(), $this->sourceOf('DEMO_SEED'), $exception);
        }

        return new DemoConfig(
            seed: $parsedSeed,
            loginUsername: $this->normalizeOptionalEnvValue($this->getEnv('DEMO_LOGIN_USERNAME')),
            loginPassword: $this->normalizeOptionalEnvValue($this->getEnv('DEMO_LOGIN_PASSWORD')),
        );
    }

    private function loadDatabaseConfig(): DatabaseConfig
    {
        return new DatabaseConfig(
            host: $this->requireEnv('DB_HOST'),
            username: $this->requireEnv('DB_USERNAME'),
            password: $this->requireEnv('DB_PASSWORD'),
            database: $this->requireEnv('DB_DATABASE'),
            port: $this->requireEnv('DB_PORT'),
        );
    }

    private function loadEnvironmentConfig(): EnvironmentConfig
    {
        return new EnvironmentConfig(
            env: $this->getEnv('APP_ENV', 'prod'),
            domain: $this->getEnv('DOMAIN', ''),
            dir: $this->getEnv('DIR', ''),
            tempDir: $this->getEnv('TEMP_DIR', ''),
        );
    }

    private function loadGeneralConfig(): GeneralConfig
    {
        return new GeneralConfig(
            organizationName: $this->getEnv('ORGANIZATION_TITLE', ''),
            maintenanceModeEnabled: $this->getEnvBool('MAINTENANCE_MODE_ENABLED', false),
            ipWhitelistEnabled: $this->getEnvBool('IP_WHITELIST_ENABLED', false),
            allowedIps: $this->parseCommaSeparated($this->getEnv('ALLOWED_IPS', '')),
            alarmsEnabled: $this->getEnvBool('ALARMS_ENABLED', true),
            eventsEnabled: $this->getEnvBool('EVENTS_ENABLED', true),
            appName: $this->getEnv('APP_NAME', 'MATA'),
            securityHeadersEnabled: $this->getEnvBool('SECURITY_HEADERS_ENABLED', true),
            trustedProxies: $this->parseCommaSeparated($this->getEnv('TRUSTED_PROXIES', '')),
            hstsEnabled: $this->getEnvBool('HSTS_ENABLED', false),
        );
    }

    private function loadDashboardConfig(): DashboardConfig
    {
        return new DashboardConfig(
            serverColumns: $this->getEnvInteger('DASHBOARD_SERVER_COLUMNS', 4, minimum: 1),
            containerWidth: $this->getEnvInteger('DASHBOARD_CONTAINER_WIDTH', 2560, minimum: 320),
            autoRefreshSeconds: $this->getEnvInteger('DASHBOARD_AUTO_REFRESH_SECONDS', 60, minimum: 0),
        );
    }

    private function loadTemplateConfig(): TemplateConfig
    {
        return new TemplateConfig(
            debugEnabled: $this->getEnvBool('TEMPLATE_DEBUG_ENABLED', false),
        );
    }

    private function loadPhpConfig(): PhpConfig
    {
        return new PhpConfig(
            displayErrors: $this->getEnvInteger('DISPLAY_ERRORS', 0, minimum: 0, maximum: 1),
            logLevel: $this->getEnv('LOG_LEVEL', 'notices'),
        );
    }

    private function loadLoggingConfig(): LoggingConfig
    {
        return new LoggingConfig(
            filePath: $this->getEnv('APP_LOG_PATH', 'logs/app.log'),
            level: $this->getEnv('MONOLOG_LEVEL', 'debug'),
            cronHeartbeatMaxBytes: $this->getEnvInteger(
                'CRON_HEARTBEAT_MAX_BYTES',
                LoggingConfig::DEFAULT_CRON_HEARTBEAT_MAX_BYTES,
                minimum: LoggingConfig::CRON_HEARTBEAT_MAX_BYTES_LOWER_BOUND,
                maximum: LoggingConfig::CRON_HEARTBEAT_MAX_BYTES_UPPER_BOUND,
            ),
        );
    }

    private function loadMailConfig(): MailConfig
    {
        return new MailConfig(
            dsn: $this->getEnv('SYM_MAILER_DSN'),
            fromEmail: $this->getEnv('SYM_FROM_EMAIL'),
            fromName: $this->getEnv('SYM_FROM_NAME'),
            alarmRecipients: $this->parseCommaSeparated($this->getEnv('MAIL_ALARM_RECIPIENTS', '')),
            charset: $this->getEnv('MAIL_CHARSET', 'utf-8'),
            dashboardUrl: $this->normalizeOptionalEnvValue($this->getEnv('MAIL_DASHBOARD_URL')),
        );
    }

    private function loadCliSecurityConfig(): CliSecurityConfig
    {
        $strictUserCheck = $this->getEnvBool('CLI_STRICT_USER_CHECK', false);
        $allowedUsersString = $this->getEnv('CLI_ALLOWED_USERS', 'root,http');
        $allowedUsers = $this->parseCommaSeparated($allowedUsersString);

        return new CliSecurityConfig(
            strictUserCheck: $strictUserCheck,
            allowedUsers: $allowedUsers,
        );
    }

    private function loadRateLimitingConfig(): RateLimitingConfig
    {
        $enabled = $this->getEnvBool('RATE_LIMIT_ENABLED', true);
        $storageType = strtolower($this->getEnv('RATE_LIMIT_STORAGE', 'file'));

        // Validate storage type
        if (!in_array($storageType, ['file', 'redis', 'apcu'], true)) {
            $storageType = 'file';
        }

        return new RateLimitingConfig(
            enabled: $enabled,
            storageType: $storageType,
            filePath: $this->getEnv('RATE_LIMIT_FILE_PATH'),
            redisHost: $this->getEnv('RATE_LIMIT_REDIS_HOST', '127.0.0.1'),
            redisPort: $this->getEnvInteger('RATE_LIMIT_REDIS_PORT', 6379, minimum: 1, maximum: 65535),
            redisPassword: $this->getEnv('RATE_LIMIT_REDIS_PASSWORD'),
            redisDatabase: $this->getEnvInteger('RATE_LIMIT_REDIS_DATABASE', 0, minimum: 0),
            loginMaxRequests: $this->getEnvInteger('RATE_LIMIT_LOGIN_MAX_REQUESTS', 10, minimum: 1),
            loginWindowSeconds: $this->getEnvInteger('RATE_LIMIT_LOGIN_WINDOW_SECONDS', 60, minimum: 1),
            dashboardMaxRequests: $this->getEnvInteger('RATE_LIMIT_DASHBOARD_MAX_REQUESTS', 100, minimum: 1),
            dashboardWindowSeconds: $this->getEnvInteger('RATE_LIMIT_DASHBOARD_WINDOW_SECONDS', 60, minimum: 1),
            apiMaxRequests: $this->getEnvInteger('RATE_LIMIT_API_MAX_REQUESTS', 200, minimum: 1),
            apiWindowSeconds: $this->getEnvInteger('RATE_LIMIT_API_WINDOW_SECONDS', 60, minimum: 1),
        );
    }

    private function getEnvInteger(
        string $key,
        int $default,
        ?int $minimum = null,
        ?int $maximum = null,
    ): int {
        try {
            return ConfigValue::integerString(
                $this->readRawEnv($key) ?? (string) $default,
                "env.{$key}",
                $minimum,
                $maximum,
            );
        } catch (InvalidArgumentException $exception) {
            throw new EnvConfigException($exception->getMessage(), $this->sourceOf($key), $exception);
        }
    }

    private function getEnvDecimal(
        string $key,
        float $default,
        ?float $minimum = null,
        ?float $maximum = null,
    ): float {
        try {
            return ConfigValue::decimalString(
                $this->readRawEnv($key) ?? (string) $default,
                "env.{$key}",
                $minimum,
                $maximum,
            );
        } catch (InvalidArgumentException $exception) {
            throw new EnvConfigException($exception->getMessage(), $this->sourceOf($key), $exception);
        }
    }

    private function readRawEnv(string $key): mixed
    {
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        $value = getenv($key);

        return $value === false ? null : $value;
    }

    /**
     * Get environment variable as boolean.
     */
    private function getEnvBool(string $key, bool $default = false): bool
    {
        $value = $this->getEnv($key, $default ? 'ON' : 'OFF');

        return in_array(strtoupper($value), ['ON', 'TRUE', '1', 'YES'], true);
    }

    /**
     * Parse comma-separated values into array.
     *
     * @return list<string>
     */
    private function parseCommaSeparated(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /**
     * Get environment variable with optional default.
     */
    private function getEnv(string $key, ?string $default = null): ?string
    {
        return $this->readEnv($key) ?? $default;
    }

    private function readEnv(string $key): ?string
    {
        if (array_key_exists($key, $_ENV)) {
            return is_string($_ENV[$key]) ? $_ENV[$key] : null;
        }

        $value = getenv($key);

        return is_string($value) ? $value : null;
    }

    private function normalizeOptionalEnvValue(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Get required environment variable or throw exception.
     *
     * @throws EnvConfigException If environment variable is missing
     */
    private function requireEnv(string $key): string
    {
        $value = $this->readEnv($key);
        if ($value === null || $value === '') {
            throw new EnvConfigException("Required environment variable {$key} is missing", $this->sourceOf($key));
        }

        return $value;
    }

    /**
     * Validate loaded configuration and return validation results.
     *
     * @param ApplicationConfig $config Loaded configuration DTO
     *
     * @return array{
     *     critical_errors: list<array{type: string, variables: list<string>, message: string}>,
     *     warnings: list<array{type: string, variables: list<string>, message: string}>,
     *     is_valid: bool,
     * }
     */
    public function validateConfig(ApplicationConfig $config): array
    {
        $criticalErrors = [];
        $warnings = [];

        // Critical validation - DOMAIN
        if (empty($config->environment->domain)) {
            $criticalErrors[] = [
                'type' => 'config.domain.missing',
                'variables' => ['DOMAIN'],
                'message' => 'DOMAIN is required but missing from configuration',
            ];
        }

        foreach ($config->general->trustedProxies as $trustedProxy) {
            if (!IpRangeMatcher::isValidRange($trustedProxy)) {
                $criticalErrors[] = [
                    'type' => 'config.trusted_proxy.invalid',
                    'variables' => ['TRUSTED_PROXIES'],
                    'message' => "TRUSTED_PROXIES contains an invalid IP address or CIDR range: {$trustedProxy}",
                ];
            }
        }

        // Warning validations - detect missing but non-critical configurations
        $missingDbFields = [];
        if (empty($config->database->host)) {
            $missingDbFields[] = 'host';
        }

        if (empty($config->database->username)) {
            $missingDbFields[] = 'username';
        }

        if (empty($config->database->password)) {
            $missingDbFields[] = 'password';
        }

        if (empty($config->database->database)) {
            $missingDbFields[] = 'database';
        }

        if (!empty($missingDbFields)) {
            $warnings[] = [
                'type' => 'config.database.missing',
                'variables' => array_map(static fn (string $field): string => 'DB_' . strtoupper($field), $missingDbFields),
                'message' => 'Database configuration incomplete - missing: ' . implode(', ', $missingDbFields),
            ];
        }

        // Logging configuration warnings
        if (empty($config->logging->filePath)) {
            $warnings[] = [
                'type' => 'config.logging.file.missing',
                'variables' => ['APP_LOG_PATH'],
                'message' => 'Log file path not configured - logs may not be written to file',
            ];
        }

        if (empty($config->logging->level)) {
            $warnings[] = [
                'type' => 'config.logging.level.missing',
                'variables' => ['MONOLOG_LEVEL'],
                'message' => 'Monolog level not configured - default log level may be used',
            ];
        }

        // The mailer is initialized during application bootstrap.
        if (empty($config->mail->dsn)) {
            $criticalErrors[] = [
                'type' => 'config.mail.dsn.missing',
                'variables' => ['SYM_MAILER_DSN'],
                'message' => 'Mail DSN is required but missing from configuration',
            ];
        }
        if (empty($config->mail->fromEmail)) {
            $warnings[] = [
                'type' => 'config.mail.from_email.missing',
                'variables' => ['SYM_FROM_EMAIL'],
                'message' => 'Mail from_email not configured - emails may not send properly',
            ];
        }
        if ($config->mail->dashboardUrl !== null && $config->mail->dashboardLink() === null) {
            $warnings[] = [
                'type' => 'config.mail.dashboard_url.invalid',
                'variables' => ['MAIL_DASHBOARD_URL'],
                'message' => 'MAIL_DASHBOARD_URL must be an absolute http or https URL without userinfo - mails and reports omit the dashboard link',
            ];
        }

        return [
            'critical_errors' => $criticalErrors,
            'warnings' => $warnings,
            'is_valid' => empty($criticalErrors),
        ];
    }
}
