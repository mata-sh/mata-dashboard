<?php

declare(strict_types=1);

namespace Mata\Services\ServerMetrics\Dto;

/** Outcome of HTTP metrics-query validation. */
final readonly class MetricsValidationResult
{
    /** @param array<string,string> $errors */
    public function __construct(
        public array $errors,
        public string $normalizedMetric,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
