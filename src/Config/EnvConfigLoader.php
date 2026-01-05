<?php

declare(strict_types=1);

namespace Watcher\Config;

use Dotenv\Dotenv;
use InvalidArgumentException;
use Watcher\Config\Dto\ApplicationConfig;
use Watcher\Config\Dto\CliSecurityConfig;
use Watcher\Config\Dto\DashboardConfig;
use Watcher\Config\Dto\DatabaseConfig;
use Watcher\Config\Dto\EnvironmentConfig;
use Watcher\Config\Dto\GeneralConfig;
use Watcher\Config\Dto\LoggingConfig;
use Watcher\Config\Dto\MailConfig;
use Watcher\Config\Dto\PhpConfig;
use Watcher\Config\Dto\SecretsConfig;
use Watcher\Config\Dto\SmtpDevConfig;
use Watcher\Config\Dto\TemplateConfig;

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
            secrets: $this->loadSecretsConfig(),
            cliSecurity: $this->loadCliSecurityConfig(),
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
            helperImportant: $this->parseCommaSeparated($this->getEnv('LOG_HELPER_IMPORTANT', 'ERROR,SECURITY')),
            helperEnabled: $this->getEnvBool('LOG_HELPER', false),
            methods: $this->parseCommaSeparated($this->getEnv('LOG_METHODS', 'error_log')),
            helperFile: $this->getEnv('LOG_HELPER_FILE', 'info.log'),
        );
    }

    private function loadMailConfig(): MailConfig
    {
        $smtpDev = new SmtpDevConfig(
            host: $this->getEnv('SMTP_DEV_HOST'),
            port: (int) $this->getEnv('SMTP_DEV_PORT', '587'),
            user: $this->getEnv('SMTP_DEV_USER'),
            password: $this->getEnv('SMTP_DEV_PASSWORD'),
            secure: $this->getEnv('SMTP_DEV_SECURE', 'tls'),
            from: $this->getEnv('SMTP_DEV_FROM'),
            fromName: $this->getEnv('SMTP_DEV_FROM_NAME'),
        );

        return new MailConfig(
            dsn: $this->getEnv('SYM_MAILER_DSN'),
            fromEmail: $this->getEnv('SYM_FROM_EMAIL'),
            fromName: $this->getEnv('SYM_FROM_NAME'),
            alertRecipients: $this->parseCommaSeparated($this->getEnv('MAIL_ALERT_RECIPIENTS', '')),
            testRecipients: $this->parseCommaSeparated($this->getEnv('MAIL_TEST_RECIPIENTS', '')),
            devRecipients: $this->parseCommaSeparated($this->getEnv('MAIL_DEV_RECIPIENTS', '')),
            mailerApp: $this->getEnv('MAILER_APP', 'sendgrid'),
            mailerDev: $this->getEnv('MAILER_DEV', 'phpmailer'),
            smtpDev: $smtpDev,
            charset: $this->getEnv('MAIL_CHARSET', 'utf-8'),
            wordWrap: (int) $this->getEnv('MAIL_WORD_WRAP', '50'),
        );
    }

    private function loadSecretsConfig(): SecretsConfig
    {
        return new SecretsConfig(
            watcherEncryptionSecret: $this->getEnv('WATCHER_ENCRYPTION_SECRET'),
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
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }

    /**
     * Get required environment variable or throw exception.
     *
     * @throws InvalidArgumentException If environment variable is missing
     */
    private function requireEnv(string $key): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === '') {
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
        if (empty($config->logging->methods)) {
            $warnings[] = [
                'identifier' => 'config.logging.methods.missing',
                'message' => 'Logging methods not configured - application events may not be logged',
            ];
        }
        if (empty($config->logging->helperFile)) {
            $warnings[] = [
                'identifier' => 'config.logging.file.missing',
                'message' => 'Log file path not configured - logs may not be written to file',
            ];
        }

        // Mail configuration warnings
        if (empty($config->mail->dsn)) {
            $warnings[] = [
                'identifier' => 'config.mail.dsn.missing',
                'message' => 'Mail DSN not configured - email notifications will not work',
            ];
        }
        if (empty($config->mail->fromEmail)) {
            $warnings[] = [
                'identifier' => 'config.mail.from_email.missing',
                'message' => 'Mail from_email not configured - emails may not send properly',
            ];
        }

        // Security configuration warnings
        if (empty($config->secrets->watcherEncryptionSecret)) {
            $warnings[] = [
                'identifier' => 'config.secrets.encryption.missing',
                'message' => 'Encryption secret not configured - API communication may fail',
            ];
        }

        return [
            'critical_errors' => $criticalErrors,
            'warnings' => $warnings,
            'is_valid' => empty($criticalErrors),
        ];
    }
}
