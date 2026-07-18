<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

interface AlarmCreatorInterface
{
    /**
     * @param array<string> $readyChannels
     */
    public function create(int $eventId, array $readyChannels, int $priority): int;
}
