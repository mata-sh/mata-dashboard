<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

/** Canonical result of an application endpoint probe. */
final readonly class EndpointProbeResult
{
    private function __construct(
        public bool $reachable,
        public ?int $httpCode,
        public ?int $latencyMs,
        public ?string $error,
        public ?string $failureKind,
        public ?int $curlErrno,
        public int $timeoutMs,
        public int $attempts,
        public string $url,
        public bool $maintenanceMode,
        public int $checkedAt,
    ) {}

    /** @param array<int|string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            reachable: is_bool($data['reachable'] ?? null) ? $data['reachable'] : false,
            httpCode: is_int($data['httpCode'] ?? null) ? $data['httpCode'] : null,
            latencyMs: is_int($data['latency_ms'] ?? null) ? $data['latency_ms'] : null,
            error: is_string($data['error'] ?? null) ? $data['error'] : null,
            failureKind: is_string($data['failure_kind'] ?? null) ? $data['failure_kind'] : null,
            curlErrno: is_int($data['curl_errno'] ?? null) ? $data['curl_errno'] : null,
            timeoutMs: is_int($data['timeout_ms'] ?? null) ? $data['timeout_ms'] : 0,
            attempts: is_int($data['attempts'] ?? null) ? $data['attempts'] : 1,
            url: is_string($data['url'] ?? null) ? $data['url'] : '',
            maintenanceMode: is_bool($data['maintenance_mode'] ?? null) ? $data['maintenance_mode'] : false,
            checkedAt: is_int($data['checked_at'] ?? null) ? $data['checked_at'] : time(),
        );
    }

    public function withAttempts(int $attempts): self
    {
        return new self(
            reachable: $this->reachable,
            httpCode: $this->httpCode,
            latencyMs: $this->latencyMs,
            error: $this->error,
            failureKind: $this->failureKind,
            curlErrno: $this->curlErrno,
            timeoutMs: $this->timeoutMs,
            attempts: $attempts,
            url: $this->url,
            maintenanceMode: $this->maintenanceMode,
            checkedAt: $this->checkedAt,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'reachable' => $this->reachable,
            'httpCode' => $this->httpCode,
            'latency_ms' => $this->latencyMs,
            'error' => $this->error,
            'failure_kind' => $this->failureKind,
            'curl_errno' => $this->curlErrno,
            'timeout_ms' => $this->timeoutMs,
            'attempts' => $this->attempts,
            'url' => $this->url,
            'maintenance_mode' => $this->maintenanceMode,
            'checked_at' => $this->checkedAt,
        ];
    }
}
