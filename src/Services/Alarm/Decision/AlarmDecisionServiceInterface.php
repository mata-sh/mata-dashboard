<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Decision;

use Mata\Services\Alarm\Dto\AlarmDecision;
use Mata\Services\Alarm\Dto\ResolvedNotificationRule;

interface AlarmDecisionServiceInterface
{
    /**
     * @param array<string,mixed> $event
     * @param array<int,string>   $candidateChannels
     */
    public function decide(
        array $event,
        array $candidateChannels = ['email'],
        ?ResolvedNotificationRule $notificationRule = null,
    ): AlarmDecision;

    public function getBatchLimit(): int;
}
