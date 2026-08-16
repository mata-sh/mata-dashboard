<?php

declare(strict_types=1);

namespace Mata\Config;

use Dotenv\Dotenv;
use InvalidArgumentException;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Dto\CliSecurityConfig;
use Mata\Config\Dto\DashboardConfig;
use Mata\Config\Dto\DatabaseConfig;
use Mata\Config\Dto\EnvironmentConfig;
use Mata\Config\Dto\GeneralConfig;
use Mata\Config\Dto\LoggingConfig;
use Mata\Config\Dto\MailConfig;
use Mata\Config\Dto\PhpConfig;
use Mata\Config\Dto\RateLimitingConfig;
use Mata\Config\Dto\TemplateConfig;

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
    /**
     * Load and parse configuration from multiple .env files.
     *
     * @param array<int,string> $envFiles   List of .env file names to load
     * @param string            $configPath Directory path containing configuration files
     *
     * @return ApplicationConfig Type-safe application configuration DTO
     */
    public function load(array $envFiles, string $configPath): ApplicationConfig
    {
        foreach ($envFiles as $file) {
            $fullPath = $configPath . DIRECTORY_SEPARATOR . $file;
            if (file_exists($fullPath)) {
                Dotenv::createImmutable($configPath, $file)->load();
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
        );
    }

    private function loadDashboardConfig(): DashboardConfig
    {
        $rawColumns = $this->getEnv('DASHBOARD_SERVER_COLUMNS', '2');
        $columns = filter_var(
            $rawColumns,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'default' => 2,
                    'min_range' => 1,
                ],
            ]
        );

        $rawContainerWidth = $this->getEnv('DASHBOARD_CONTAINER_WIDTH', '1344');
        $containerWidth = filter_var(
            $rawContainerWidth,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'default' => 1344,
                    'min_range' => 320,
                ],
            ]
        );

        return new DashboardConfig(
            serverColumns: $columns,
            containerWidth: $containerWidth,
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
            displayErrors: (int) $this->getEnv('DISPLAY_ERRORS', '0'),
            logLevel: $this->getEnv('LOG_LEVEL', 'notices'),
        );
    }

    private function loadLoggingConfig(): LoggingConfig
    {
        return new LoggingConfig(
            path: $this->getEnv('APP_LOG_PATH', 'logs/app.log'),
            level: $this->getEnv('MONOLOG_LEVEL', 'debug'),
        );
    }

    private function loadMailConfig(): MailConfig
    {
        return new MailConfig(
            dsn: $this->getEnv('SYM_MAILER_DSN'),
            fromEmail: $this->getEnv('SYM_FROM_EMAIL'),
            fromName: $this->getEnv('SYM_FROM_NAME'),
            alarmRecipients: $this->parseCommaSeparated($this->getEnv('MAIL_ALARM_RECIPIENTS', '')),
            testRecipients: $this->parseCommaSeparated($this->getEnv('MAIL_TEST_RECIPIENTS', '')),
            devRecipients: $this->parseCommaSeparated($this->getEnv('MAIL_DEV_RECIPIENTS', '')),
            mailerApp: $this->getEnv('MAILER_APP', 'sendgrid'),
            mailerDev: $this->getEnv('MAILER_DEV', 'phpmailer'),
            charset: $this->getEnv('MAIL_CHARSET', 'utf-8'),
            wordWrap: (int) $this->getEnv('MAIL_WORD_WRAP', '50'),
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
            redisPort: (int) $this->getEnv('RATE_LIMIT_REDIS_PORT', '6379'),
            redisPassword: $this->getEnv('RATE_LIMIT_REDIS_PASSWORD'),
            redisDatabase: (int) $this->getEnv('RATE_LIMIT_REDIS_DATABASE', '0'),
            loginMaxRequests: (int) $this->getEnv('RATE_LIMIT_LOGIN_MAX_REQUESTS', '10'),
            loginWindowSeconds: (int) $this->getEnv('RATE_LIMIT_LOGIN_WINDOW_SECONDS', '60'),
            dashboardMaxRequests: (int) $this->getEnv('RATE_LIMIT_DASHBOARD_MAX_REQUESTS', '100'),
            dashboardWindowSeconds: (int) $this->getEnv('RATE_LIMIT_DASHBOARD_WINDOW_SECONDS', '60'),
            apiMaxRequests: (int) $this->getEnv('RATE_LIMIT_API_MAX_REQUESTS', '200'),
            apiWindowSeconds: (int) $this->getEnv('RATE_LIMIT_API_WINDOW_SECONDS', '60'),
        );
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
     * @return array<int,string>
     */
    private function parseCommaSeparated(string $value): array
    {
        return array_filter(array_map('trim', explode(',', $value)));
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
     * @throws InvalidArgumentException If environment variable is missing
     */
    private function requireEnv(string $key): string
    {
        $value = $this->readEnv($key);
        if ($value === null || $value === '') {
            throw new InvalidArgumentException("Required environment variable {$key} is missing");
        }

        return $value;
    }

    /**
     * Validate loaded configuration and return validation results.
     *
     * @param ApplicationConfig $config Loaded configuration DTO
     *
     * @return array<string,mixed> Validation results with critical_errors, warnings, and is_valid
     */
    public function validateConfig(ApplicationConfig $config): array
    {
        $criticalErrors = [];
        $warnings = [];

        // Critical validation - DOMAIN
        if (empty($config->environment->domain)) {
            $criticalErrors[] = [
                'identifier' => 'config.domain.missing',
                'message' => 'DOMAIN is required but missing from configuration',
            ];
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
                'identifier' => 'config.database.missing',
                'message' => 'Database configuration incomplete - missing: ' . implode(', ', $missingDbFields),
            ];
        }

        // Logging configuration warnings
        if (empty($config->logging->path)) {
            $warnings[] = [
                'identifier' => 'config.logging.file.missing',
                'message' => 'Log file path not configured - logs may not be written to file',
            ];
        }

        if (empty($config->logging->level)) {
            $warnings[] = [
                'identifier' => 'config.logging.level.missing',
                'message' => 'Monolog level not configured - default log level may be used',
            ];
        }

        // The mailer is initialized during application bootstrap.
        if (empty($config->mail->dsn)) {
            $criticalErrors[] = [
                'identifier' => 'config.mail.dsn.missing',
                'message' => 'Mail DSN is required but missing from configuration',
            ];
        }
        if (empty($config->mail->fromEmail)) {
            $warnings[] = [
                'identifier' => 'config.mail.from_email.missing',
                'message' => 'Mail from_email not configured - emails may not send properly',
            ];
        }

        return [
            'critical_errors' => $criticalErrors,
            'warnings' => $warnings,
            'is_valid' => empty($criticalErrors),
        ];
    }
}
