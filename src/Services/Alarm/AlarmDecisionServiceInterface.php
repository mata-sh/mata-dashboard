<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use Mata\Services\Alarm\Dto\AlarmDecision;

interface AlarmDecisionServiceInterface
{
    /**
     * @param array<string,mixed> $event
     * @param array<int,string>   $supportedChannels
     */
    public function decide(array $event, array $supportedChannels = ['email']): AlarmDecision;

    public function getBatchLimit(): int;
}
