<?php

declare(strict_types=1);

namespace Mata\Services\Event\Dto;

/**
 * Complete request to record an event.
 *
 * @param array<string,mixed> $context Additional event metadata
 */
final readonly class EventCommand
{
    /**
     * @param array<string,mixed> $context Additional event metadata
     */
    public function __construct(
        public string $type,
        public string $message,
        public string $level = 'info',
        public int|string|null $server = null,
        public int|string|null $app = null,
        public bool $forceAlarm = false,
        public array $context = [],
        public ?EventMetric $metric = null,
    ) {}

    /** Return the complete context persisted with the event. */
    public function eventContext(): EventContext
    {
        return EventContext::fromArray(array_replace(
            $this->context,
            $this->metric?->toContextFields() ?? [],
        ));
    }
}
