<?php

declare(strict_types=1);

namespace Watcher\Services\Validation;

/**
 * Value object representing the outcome of metrics validation.
 *
 * Holds validation errors and the normalized metric key that should be used
 * when validation succeeds.
 */
final readonly class MetricsValidationResult
{
    /**
     * @param array<string,string> $errors           Validation errors (field => message)
     * @param string               $normalizedMetric Normalized metric key (empty when invalid)
     */
    public function __construct(
        public array $errors,
        public string $normalizedMetric
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
