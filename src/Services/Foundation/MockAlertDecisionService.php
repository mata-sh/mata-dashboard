<?php

declare(strict_types=1);

namespace Watcher\Services\Foundation;

use Watcher\Services\Foundation\Dto\AlertDecision;

final class MockAlertDecisionService implements AlertDecisionServiceInterface
{
    /** @var array<int,AlertDecision> */
    private array $byEventId = [];
    private AlertDecision $defaultDecision;

    public function __construct(?AlertDecision $defaultDecision = null)
    {
        $this->defaultDecision = $defaultDecision
            ?? new AlertDecision(false, [], 100, false, 'mock_default');
    }

    public function setDecisionForEventId(int $eventId, AlertDecision $decision): void
    {
        $this->byEventId[$eventId] = $decision;
    }

    /**
     * @param array<string,mixed> $event
     * @param array<int,string>   $supportedChannels
     */
    public function decide(array $event, array $supportedChannels = ['email']): AlertDecision
    {
        $eventId = (int) ($event['id'] ?? 0);

        return $this->byEventId[$eventId] ?? $this->defaultDecision;
    }

    public function getBatchLimit(): int
    {
        return 100;
    }
}
