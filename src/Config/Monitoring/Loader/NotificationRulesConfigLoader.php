<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use InvalidArgumentException;
use Mata\Config\Monitoring\Loader\Dto\ConfigLoadResult;
use Mata\Config\Monitoring\Section\NotificationRulesConfig;
use RuntimeException;

final class NotificationRulesConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(
        private bool $fallbackOnFailure = true,
    ) {}

    /** @return ConfigLoadResult<NotificationRulesConfig> */
    public function load(string $configRoot): ConfigLoadResult
    {
        try {
            return $this->loadNotificationRulesConfig(rtrim($configRoot, '/') . '/notification_rules.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return new ConfigLoadResult($this->getDefaultConfig(), $exception->problems);
        }
    }

    /** @return ConfigLoadResult<NotificationRulesConfig> */
    public function loadNotificationRulesConfig(string $filePath): ConfigLoadResult
    {
        if (!file_exists($filePath)) {
            return $this->fail("notification_rules.json file not found at {$filePath}");
        }

        try {
            return $this->result(NotificationRulesConfig::fromObject($this->readJsonObject($filePath)));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->report(
                'notification_rules_config_invalid',
                "Could not load {$filePath}; falling back to defaults: {$exception->getMessage()}",
            );

            throw $this->rejected('notification rules invalid', $exception);
        }
    }

    public function getDefaultConfig(): NotificationRulesConfig
    {
        return NotificationRulesConfig::default();
    }

    private function fail(string $message): never
    {
        throw $this->rejected($message);
    }
}
