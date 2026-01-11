<?php

declare(strict_types=1);

namespace Mata\Services\Alert\Dto;

final class OpaAlertDecisionConfig
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
        $baseUri = getenv('OPA_ALERT_BASE_URI') ?: 'http://127.0.0.1:1234';
        $decisionPath = getenv('OPA_ALERT_DECISION_PATH') ?: '/v1/data/alerts/decision';
        $timeout = (float) (getenv('OPA_ALERT_TIMEOUT_SECONDS') ?: 5);
        $batchLimit = (int) (getenv('OPA_ALERT_BATCH_LIMIT') ?: 100);

        return new self($baseUri, $decisionPath, $timeout, $batchLimit);
    }
}
