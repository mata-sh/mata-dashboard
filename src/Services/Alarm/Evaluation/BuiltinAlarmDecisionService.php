<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Evaluation;

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
        if (($event['level'] ?? '') === 'critical') {
            $priority = 10;
            $reason = 'builtin_critical_level';
        } elseif (($event['type'] ?? '') === 'fatal_errors_occurring') {
            $priority = 30;
            $reason = 'builtin_fatal_errors';
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
