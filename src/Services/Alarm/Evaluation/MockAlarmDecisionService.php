<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Evaluation;

use Mata\Services\Alarm\Evaluation\Dto\AlarmDecision;
use Mata\Services\Alarm\Evaluation\Dto\ResolvedNotificationRule;

final class MockAlarmDecisionService implements AlarmDecisionServiceInterface
{
    /** @var array<int,AlarmDecision> */
    private array $byEventId = [];
    private AlarmDecision $defaultDecision;

    public function __construct(?AlarmDecision $defaultDecision = null)
    {
        $this->defaultDecision = $defaultDecision
            ?? new AlarmDecision(false, [], 100, false, 'mock_default');
    }

    public function setDecisionForEventId(int $eventId, AlarmDecision $decision): void
    {
        $this->byEventId[$eventId] = $decision;
    }

    /**
     * @param array<string,mixed> $event
     * @param array<int,string>   $candidateChannels
     */
    public function decide(
        array $event,
        array $candidateChannels = ['email'],
        ?ResolvedNotificationRule $notificationRule = null,
    ): AlarmDecision {
        $eventId = (int) ($event['id'] ?? 0);

        return $this->byEventId[$eventId] ?? $this->defaultDecision;
    }

    public function getBatchLimit(): int
    {
        return 100;
    }
}
