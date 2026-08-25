<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable DTO representing complete application configuration.
 *
 * Aggregates all configuration sections into a single cohesive object
 * that represents the entire application configuration state.
 */
final class ApplicationConfig
{
    public function __construct(
        public readonly EnvironmentConfig $environment,
        public readonly GeneralConfig $general,
        public readonly DashboardConfig $dashboard,
        public readonly TemplateConfig $template,
        public readonly PhpConfig $php,
        public readonly LoggingConfig $logging,
        public readonly DatabaseConfig $database,
        public readonly MailConfig $mail,
        public readonly CliSecurityConfig $cliSecurity,
        public readonly RateLimitingConfig $rateLimiting,
        public readonly AlarmDecisionConfig $alarmDecision,
        public readonly NodeApiConfig $nodeApi,
    ) {}
}
