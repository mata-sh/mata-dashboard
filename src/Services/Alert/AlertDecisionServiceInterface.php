<?php

declare(strict_types=1);

namespace Mata\Services\Alert;

use Mata\Services\Alert\Dto\AlertDecision;

interface AlertDecisionServiceInterface
{
    /**
     * @param array<string,mixed> $event
     * @param array<int,string>   $supportedChannels
     */
    public function decide(array $event, array $supportedChannels = ['email']): AlertDecision;

    public function getBatchLimit(): int;
}
