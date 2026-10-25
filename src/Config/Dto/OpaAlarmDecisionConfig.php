<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use InvalidArgumentException;

final readonly class OpaAlarmDecisionConfig
{
    /** Decision of the shipped policy/alarms.rego. */
    public const SHIPPED_DECISION_PATH = '/v1/data/alarms/decision';

    /** Decisions of local overrides in config/policy/, which declare packages under `local`. */
    public const LOCAL_DECISION_PREFIX = '/v1/data/local/';

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

    public function queriesLocalPolicy(): bool
    {
        return str_starts_with($this->decisionPath, self::LOCAL_DECISION_PREFIX);
    }
}
