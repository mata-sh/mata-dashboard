<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Evaluation;

use Mata\Services\Advisory\AdvisoryPollWatchdog;
use Mata\Services\Advisory\AdvisorySourcePoller;
use Mata\Services\Alarm\Evaluation\Dto\AlarmDecision;
use Mata\Services\Alarm\Evaluation\Dto\ResolvedNotificationRule;

final class BuiltinAlarmDecisionService implements AlarmDecisionServiceInterface
{
    public function __construct(private int $batchLimit = 100) {}

    public function decide(
        array $event,
        array $candidateChannels = ['email'],
        ?ResolvedNotificationRule $notificationRule = null,
    ): AlarmDecision {
        $type = $event['type'] ?? '';
        if ($type === AdvisorySourcePoller::EVENT_MATCHED) {
            // advisories.json filters decide relevance; every level alarms, like in policy/alarms.rego.
            $priority = match ($event['level'] ?? '') {
                'critical' => 10,
                'warning' => 30,
                default => 60,
            };
            $reason = 'builtin_security_advisory';
        } elseif (($event['level'] ?? '') === 'critical') {
            $priority = 10;
            $reason = 'builtin_critical_level';
        } elseif ($type === 'fatal_errors_occurring') {
            $priority = 30;
            $reason = 'builtin_fatal_errors';
        } elseif (in_array($type, [AdvisorySourcePoller::EVENT_SOURCE_FAILING, AdvisoryPollWatchdog::EVENT_POLL_STALLED], true)) {
            // A silent poller must not look like "no new advisories".
            $priority = 60;
            $reason = 'builtin_advisory_poller';
        } else {
            return new AlarmDecision(false, [], 100, false, 'builtin_default_deny');
        }

        return new AlarmDecision(true, $this->normalizeChannels($candidateChannels), $priority, false, $reason);
    }

    public function getBatchLimit(): int
    {
        return $this->batchLimit;
    }

    /**
     * @param array<int,string> $channels
     *
     * @return list<string>
     */
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
