<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\NotificationRulesConfig;

/**
 * Loads the optional notification_rules.json: alarm delivery channel selection rules.
 *
 * @extends AbstractJsonConfigLoader<NotificationRulesConfig>
 */
final class NotificationRulesConfigLoader extends AbstractJsonConfigLoader
{
    protected function fileName(): string
    {
        return 'notification_rules.json';
    }

    protected function loadFailureType(): string
    {
        return 'notification_rules_config_invalid';
    }

    protected function isOptional(): bool
    {
        return true;
    }

    protected function defaultSection(): NotificationRulesConfig
    {
        return NotificationRulesConfig::default();
    }

    protected function parse(array $config, string $filePath): NotificationRulesConfig
    {
        return NotificationRulesConfig::fromArray($config);
    }
}
