<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Decision;

use Mata\Services\Alarm\Dto\AlarmDecision;

final class BuiltinAlarmDecisionService implements AlarmDecisionServiceInterface
{
    public function __construct(private int $batchLimit = 100) {}

    public function decide(array $event, array $supportedChannels = ['email']): AlarmDecision
    {
        if (($event['level'] ?? '') === 'critical') {
            $priority = 10;
            $reason = 'builtin_critical_level';
        } elseif (($event['type'] ?? '') === 'fatal_errors_occurring') {
            $priority = 30;
            $reason = 'builtin_fatal_errors';
        } elseif (($event['type'] ?? '') === 'library_outdated_security') {
            $priority = 30;
            $reason = 'builtin_security_type';
        } else {
            return new AlarmDecision(false, [], 100, false, 'builtin_default_deny');
        }

        $channels = array_values(array_intersect(['email'], $this->normalizeChannels($supportedChannels)));
        if ($channels === []) {
            return new AlarmDecision(false, [], $priority, false, 'no_supported_channels');
        }

        return new AlarmDecision(true, $channels, $priority, false, $reason);
    }

    public function getBatchLimit(): int
    {
        return $this->batchLimit;
    }

    private function normalizeChannels(array $channels): array
    {
        $normalized = [];
        foreach ($channels as $channel) {
            if (!is_string($channel)) {
                continue;
            }

            $channel = strtolower(trim($channel));
            if ($channel !== '') {
                $normalized[] = $channel;
            }
        }

        return array_values(array_unique($normalized));
    }
}
