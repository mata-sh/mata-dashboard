<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use InvalidArgumentException;
use Mata\Config\Monitoring\Section\NotificationRulesConfig;
use Mata\Services\Event\EventRecorderService;
use RuntimeException;

final class NotificationRulesConfigLoader extends AbstractJsonConfigLoader
{
    public function __construct(
        private EventRecorderService $eventRecorder,
        private bool $fallbackOnFailure = true,
    ) {}

    public function load(string $configRoot): NotificationRulesConfig
    {
        try {
            return $this->loadNotificationRulesConfig(rtrim($configRoot, '/') . '/notification_rules.json');
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return $this->getDefaultConfig();
        }
    }

    public function loadNotificationRulesConfig(string $filePath): NotificationRulesConfig
    {
        if (!file_exists($filePath)) {
            return $this->fail("notification_rules.json file not found at {$filePath}");
        }

        try {
            return NotificationRulesConfig::fromObject($this->readJsonObject($filePath));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->eventRecorder->recordEvent(
                type: 'notification_rules_config_invalid',
                message: "Could not load {$filePath}; falling back to defaults: {$exception->getMessage()}",
                level: 'warning',
            );

            throw new MonitoringConfigLoadException('notification rules invalid', previous: $exception);
        }
    }

    public function getDefaultConfig(): NotificationRulesConfig
    {
        return NotificationRulesConfig::default();
    }

    private function fail(string $message): never
    {
        throw new MonitoringConfigLoadException($message);
    }
}
