<?php

declare(strict_types=1);

namespace Watcher\Tests\Helpers;

use Watcher\Config\Dto\ApplicationConfig;
use Watcher\Config\Dto\CliSecurityConfig;
use Watcher\Config\Dto\DashboardConfig;
use Watcher\Config\Dto\DatabaseConfig;
use Watcher\Config\Dto\EnvironmentConfig;
use Watcher\Config\Dto\GeneralConfig;
use Watcher\Config\Dto\LoggingConfig;
use Watcher\Config\Dto\MailConfig;
use Watcher\Config\Dto\PhpConfig;
use Watcher\Config\Dto\RateLimitingConfig;
use Watcher\Config\Dto\SecretsConfig;
use Watcher\Config\Dto\SmtpDevConfig;
use Watcher\Config\Dto\TemplateConfig;

/**
 * Factory for creating test instances of ApplicationConfig.
 *
 * Since ApplicationConfig and nested DTOs are declared final, they cannot be mocked.
 * This factory creates real instances with sensible test defaults.
 */
class ApplicationConfigFactory
{
    /**
     * Create an ApplicationConfig instance for testing with default values.
     *
     * All parameters are optional and will use sensible defaults if not provided.
     */
    public static function create(
        ?EnvironmentConfig $environment = null,
        ?GeneralConfig $general = null,
        ?DashboardConfig $dashboard = null,
        ?TemplateConfig $template = null,
        ?PhpConfig $php = null,
        ?LoggingConfig $logging = null,
        ?DatabaseConfig $database = null,
        ?MailConfig $mail = null,
        ?SecretsConfig $secrets = null,
        ?CliSecurityConfig $cliSecurity = null,
        ?RateLimitingConfig $rateLimiting = null,
    ): ApplicationConfig {
        return new ApplicationConfig(
            environment: $environment ?? self::createEnvironmentConfig(),
            general: $general ?? self::createGeneralConfig(),
            dashboard: $dashboard ?? self::createDashboardConfig(),
            template: $template ?? self::createTemplateConfig(),
            php: $php ?? self::createPhpConfig(),
            logging: $logging ?? self::createLoggingConfig(),
            database: $database ?? self::createDatabaseConfig(),
            mail: $mail ?? self::createMailConfig(),
            secrets: $secrets ?? self::createSecretsConfig(),
            cliSecurity: $cliSecurity ?? self::createCliSecurityConfig(),
            rateLimiting: $rateLimiting ?? self::createRateLimitingConfig(),
        );
    }

    public static function createEnvironmentConfig(
        string $env = 'test',
        string $domain = 'localhost',
        string $dir = '',
    ): EnvironmentConfig {
        return new EnvironmentConfig($env, $domain, $dir);
    }

    public static function createGeneralConfig(
        string $organizationName = 'MATA Dashboard',
        bool $maintenanceModeEnabled = false,
        bool $ipWhitelistEnabled = false,
        array $allowedIps = [],
        bool $alarmsEnabled = true,
        bool $eventsEnabled = true,
    ): GeneralConfig {
        return new GeneralConfig(
            $organizationName,
            $maintenanceModeEnabled,
            $ipWhitelistEnabled,
            $allowedIps,
            $alarmsEnabled,
            $eventsEnabled,
        );
    }

    public static function createDashboardConfig(
        int $serverColumns = 2,
        int $containerWidth = 1344,
    ): DashboardConfig {
        return new DashboardConfig($serverColumns, $containerWidth);
    }

    public static function createTemplateConfig(
        bool $debugEnabled = false,
    ): TemplateConfig {
        return new TemplateConfig($debugEnabled);
    }

    public static function createPhpConfig(
        int $displayErrors = 0,
        string $logLevel = 'notices',
    ): PhpConfig {
        return new PhpConfig($displayErrors, $logLevel);
    }

    public static function createLoggingConfig(
        array $helperImportant = ['ERROR', 'SECURITY'],
        bool $helperEnabled = false,
        array $methods = ['error_log'],
        string $helperFile = 'test.log',
    ): LoggingConfig {
        return new LoggingConfig($helperImportant, $helperEnabled, $methods, $helperFile);
    }

    public static function createDatabaseConfig(
        string $host = 'localhost',
        string $username = 'test_user',
        string $password = 'test_password',
        string $database = 'test_db',
        string $port = '3306',
    ): DatabaseConfig {
        return new DatabaseConfig($host, $username, $password, $database, $port);
    }

    public static function createMailConfig(
        ?string $dsn = null,
        ?string $fromEmail = null,
        ?string $fromName = null,
        array $alertRecipients = [],
        array $testRecipients = [],
        array $devRecipients = [],
        string $mailerApp = 'sendgrid',
        string $mailerDev = 'phpmailer',
        ?SmtpDevConfig $smtpDev = null,
        string $charset = 'utf-8',
        int $wordWrap = 50,
    ): MailConfig {
        return new MailConfig(
            $dsn,
            $fromEmail,
            $fromName,
            $alertRecipients,
            $testRecipients,
            $devRecipients,
            $mailerApp,
            $mailerDev,
            $smtpDev ?? self::createSmtpDevConfig(),
            $charset,
            $wordWrap,
        );
    }

    public static function createSmtpDevConfig(
        ?string $host = null,
        int $port = 587,
        ?string $user = null,
        ?string $password = null,
        string $secure = 'tls',
        ?string $from = null,
        ?string $fromName = null,
    ): SmtpDevConfig {
        return new SmtpDevConfig($host, $port, $user, $password, $secure, $from, $fromName);
    }

    public static function createSecretsConfig(
        ?string $watcherEncryptionSecret = 'test-encryption-secret-key-12345',
    ): SecretsConfig {
        return new SecretsConfig($watcherEncryptionSecret);
    }

    public static function createCliSecurityConfig(
        bool $strictUserCheck = false,
        array $allowedUsers = ['root', 'http'],
    ): CliSecurityConfig {
        return new CliSecurityConfig($strictUserCheck, $allowedUsers);
    }

    public static function createRateLimitingConfig(
        bool $enabled = false,
        string $storageType = 'file',
        ?string $filePath = '/tmp/rate_limit_test.json',
        ?string $redisHost = null,
        int $redisPort = 6379,
        ?string $redisPassword = null,
        int $redisDatabase = 0,
        int $loginMaxRequests = 5,
        int $loginWindowSeconds = 300,
        int $dashboardMaxRequests = 60,
        int $dashboardWindowSeconds = 60,
        int $apiMaxRequests = 100,
        int $apiWindowSeconds = 60,
    ): RateLimitingConfig {
        return new RateLimitingConfig(
            $enabled,
            $storageType,
            $filePath,
            $redisHost,
            $redisPort,
            $redisPassword,
            $redisDatabase,
            $loginMaxRequests,
            $loginWindowSeconds,
            $dashboardMaxRequests,
            $dashboardWindowSeconds,
            $apiMaxRequests,
            $apiWindowSeconds,
        );
    }
}
