<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Dto;

final class OpaAlarmDecisionConfig
{
    public function __construct(
        public string $baseUri,
        public string $decisionPath,
        public float $timeoutSeconds,
        public int $batchLimit
    ) {
        $this->baseUri = rtrim($this->baseUri, '/') . '/';
        $this->decisionPath = '/' . ltrim($this->decisionPath, '/');
        $this->timeoutSeconds = max(1, $this->timeoutSeconds);
        $this->batchLimit = max(1, $this->batchLimit);
    }

    public static function fromEnv(): self
    {
        $baseUri = getenv('OPA_ALARM_BASE_URI') ?: 'http://127.0.0.1:1234';
        $decisionPath = getenv('OPA_ALARM_DECISION_PATH') ?: '/v1/data/alarms/decision';
        $timeout = (float) (getenv('OPA_ALARM_TIMEOUT_SECONDS') ?: 5);
        $batchLimit = (int) (getenv('OPA_ALARM_BATCH_LIMIT') ?: 100);

        return new self($baseUri, $decisionPath, $timeout, $batchLimit);
    }
}
