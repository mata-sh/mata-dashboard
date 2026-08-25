<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use InvalidArgumentException;

final readonly class OpaAlarmDecisionConfig
{
    public string $baseUri;
    public string $decisionPath;
    public float $timeoutSeconds;

    public function __construct(
        string $baseUri,
        string $decisionPath,
        float $timeoutSeconds,
    ) {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds < 1) {
            throw new InvalidArgumentException('alarm_decision.opa.timeout_seconds must be at least 1.');
        }

        $this->baseUri = rtrim($baseUri, '/') . '/';
        $this->decisionPath = '/' . ltrim($decisionPath, '/');
        $this->timeoutSeconds = $timeoutSeconds;
    }
}
