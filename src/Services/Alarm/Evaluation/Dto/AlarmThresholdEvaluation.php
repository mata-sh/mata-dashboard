<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Evaluation\Dto;

/** Immutable result of an alarm threshold evaluation. */
final readonly class AlarmThresholdEvaluation
{
    /** @param array<string,mixed> $evidence */
    public function __construct(public bool $passed, public array $evidence) {}
}
