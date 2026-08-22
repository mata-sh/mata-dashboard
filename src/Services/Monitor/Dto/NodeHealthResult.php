<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

use Mata\Constants\NodeHealthState;

/** Canonical result of a node health probe. */
final readonly class NodeHealthResult
{
    /**
     * @param null|array<string,mixed> $checks
     */
    private function __construct(
        public NodeHealthState $status,
        public ?int $httpCode,
        public ?bool $maintenanceMode,
        public ?array $checks,
        public mixed $nodeTimestamp,
        public ?int $latencyMs,
        public ?string $error,
        public ?string $failureKind,
        public ?int $curlErrno,
        public int $timeoutMs,
        public int $attempts,
        public int $checkedAt,
        public NodeHealthState $state,
    ) {}

    /** @param array<int|string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $status = NodeHealthState::fromNodePayload(is_string($data['status'] ?? null) ? $data['status'] : null);
        if ($status !== NodeHealthState::Healthy
            && $status !== NodeHealthState::Unhealthy
            && $status !== NodeHealthState::Unreachable
        ) {
            $status = NodeHealthState::Unreachable;
        }

        $maintenanceMode = is_bool($data['maintenance_mode'] ?? null) ? $data['maintenance_mode'] : null;

        return new self(
            status: $status,
            httpCode: is_int($data['httpCode'] ?? null) ? $data['httpCode'] : null,
            maintenanceMode: $maintenanceMode,
            checks: self::normalizeChecks($data['checks'] ?? null),
            nodeTimestamp: $data['node_timestamp'] ?? null,
            latencyMs: is_int($data['latency_ms'] ?? null) ? $data['latency_ms'] : null,
            error: is_string($data['error'] ?? null) ? $data['error'] : null,
            failureKind: is_string($data['failure_kind'] ?? null) ? $data['failure_kind'] : null,
            curlErrno: is_int($data['curl_errno'] ?? null) ? $data['curl_errno'] : null,
            timeoutMs: is_int($data['timeout_ms'] ?? null) ? $data['timeout_ms'] : 0,
            attempts: is_int($data['attempts'] ?? null) ? $data['attempts'] : 1,
            checkedAt: is_int($data['checked_at'] ?? null) ? $data['checked_at'] : time(),
            state: $maintenanceMode === true ? NodeHealthState::Maintenance : $status,
        );
    }

    public function withAttempts(int $attempts): self
    {
        return new self(
            status: $this->status,
            httpCode: $this->httpCode,
            maintenanceMode: $this->maintenanceMode,
            checks: $this->checks,
            nodeTimestamp: $this->nodeTimestamp,
            latencyMs: $this->latencyMs,
            error: $this->error,
            failureKind: $this->failureKind,
            curlErrno: $this->curlErrno,
            timeoutMs: $this->timeoutMs,
            attempts: $attempts,
            checkedAt: $this->checkedAt,
            state: $this->state,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'httpCode' => $this->httpCode,
            'maintenance_mode' => $this->maintenanceMode,
            'checks' => $this->checks,
            'node_timestamp' => $this->nodeTimestamp,
            'latency_ms' => $this->latencyMs,
            'error' => $this->error,
            'failure_kind' => $this->failureKind,
            'curl_errno' => $this->curlErrno,
            'timeout_ms' => $this->timeoutMs,
            'attempts' => $this->attempts,
            'checked_at' => $this->checkedAt,
            'state' => $this->state->value,
        ];
    }

    /** @return null|array<string,mixed> */
    private static function normalizeChecks(mixed $checks): ?array
    {
        if (!is_array($checks)) {
            return null;
        }

        $normalized = [];
        foreach ($checks as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
