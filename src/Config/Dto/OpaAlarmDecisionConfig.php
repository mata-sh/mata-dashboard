<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

use Mata\Config\Env;

final readonly class OpaAlarmDecisionConfig
{
    public string $baseUri;
    public string $decisionPath;
    public float $timeoutSeconds;
    public int $batchLimit;

    public function __construct(
        string $baseUri,
        string $decisionPath,
        float $timeoutSeconds,
        int $batchLimit
    ) {
        $this->baseUri = rtrim($baseUri, '/') . '/';
        $this->decisionPath = '/' . ltrim($decisionPath, '/');
        $this->timeoutSeconds = max(1, $timeoutSeconds);
        $this->batchLimit = max(1, $batchLimit);
    }

    public static function fromEnv(): self
    {
        $baseUri = Env::get('OPA_ALARM_BASE_URI', 'http://127.0.0.1:3320');
        $decisionPath = Env::get('OPA_ALARM_DECISION_PATH', '/v1/data/alarms/decision');
        $timeout = (float) Env::get('OPA_ALARM_TIMEOUT_SECONDS', '5');
        $batchLimit = (int) Env::get('ALARM_DECISION_BATCH_LIMIT', '100');

        return new self($baseUri, $decisionPath, $timeout, $batchLimit);
    }
}
