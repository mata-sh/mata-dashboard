<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use Mata\Services\Alarm\Dto\ResolvedNotificationRule;

/** Resolves notification rules into delivery-routing constraints. */
final class NotificationRuleResolver
{
    /**
     * @param array{
     *     defaults:array{enabled:bool,channels:list<string>},
     *     event_types:array<string,array{enabled?:bool,channels?:list<string>}>,
     *     application_overrides:array<string,array<string,array{enabled?:bool,channels?:list<string>}>>
     * } $config
     */
    public function __construct(private array $config) {}

    public function resolveFor(string $eventType, ?string $appCode): ResolvedNotificationRule
    {
        $resolved = $this->config['defaults'];
        $suppressionReason = $resolved['enabled'] ? null : 'default_disabled';

        if (isset($this->config['event_types'][$eventType])) {
            $eventRule = $this->config['event_types'][$eventType];
            $resolved = $this->overlay($resolved, $eventRule);
            if (array_key_exists('enabled', $eventRule)) {
                $suppressionReason = $eventRule['enabled'] ? null : 'event_type_disabled';
            }
        }

        if ($appCode !== null && isset($this->config['application_overrides'][$appCode][$eventType])) {
            $applicationRule = $this->config['application_overrides'][$appCode][$eventType];
            $resolved = $this->overlay($resolved, $applicationRule);
            if (array_key_exists('enabled', $applicationRule)) {
                $suppressionReason = $applicationRule['enabled'] ? null : 'application_override_disabled';
            }
        }

        return new ResolvedNotificationRule(
            $resolved['enabled'],
            $resolved['channels'],
            $suppressionReason,
        );
    }

    /**
     * @param array{enabled:bool,channels:list<string>}   $base
     * @param array{enabled?:bool,channels?:list<string>} $override
     *
     * @return array{enabled:bool,channels:list<string>}
     */
    private function overlay(array $base, array $override): array
    {
        if (array_key_exists('enabled', $override)) {
            $base['enabled'] = $override['enabled'];
        }
        if (array_key_exists('channels', $override)) {
            $base['channels'] = array_values($override['channels']);
        }

        return $base;
    }
}
