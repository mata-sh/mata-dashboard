<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

interface AlarmCreatorInterface
{
    /**
     * @param array<string>       $readyChannels
     * @param array<string,mixed> $triggerEvidence
     */
    public function create(int $eventId, array $readyChannels, int $priority, array $triggerEvidence = []): int;
}
