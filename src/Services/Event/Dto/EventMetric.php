<?php

declare(strict_types=1);

namespace Mata\Services\Event\Dto;

/**
 * Structured metric payload attached to an event context.
 *
 * The metric value and additional diagnostics are stored with the event but are
 * excluded from its cooldown identity.
 */
final readonly class EventMetric
{
    /**
     * @param array<string,mixed> $additional
     */
    public function __construct(
        public string $name,
        public float|int $value,
        public float|int|null $threshold = null,
        public array $additional = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toContextFields(): array
    {
        $fields = [
            'metric' => $this->name,
            'actual_value' => $this->value,
        ];

        if ($this->threshold !== null) {
            $fields['threshold'] = $this->threshold;
        }

        if ($this->additional !== []) {
            $fields['additional_data'] = $this->additional;
        }

        return $fields;
    }
}
