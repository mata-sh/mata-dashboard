<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use InvalidArgumentException;

final readonly class AlarmDecisionConfig
{
    public string $driver;
    public int $batchLimit;

    public function __construct(string $driver, int $batchLimit, public OpaAlarmDecisionConfig $opa)
    {
        if ($batchLimit < 1) {
            throw new InvalidArgumentException('alarm_decision.batch_limit must be at least 1.');
        }

        $this->driver = strtolower(trim($driver));
        $this->batchLimit = $batchLimit;
    }
}
