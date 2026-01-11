<?php

declare(strict_types=1);

namespace Mata\Services\Alert\Dto;

use DateTimeImmutable;

/**
 * Represents a queued alert ready for dispatch processing.
 */
final readonly class QueuedAlert
{
    public function __construct(
        public int $id,
        public int $eventId,
        public string $channel,
        public int $priority,
        public int $attempts,
        public ?DateTimeImmutable $nextRetryAt,
        public string $type,
        public string $message,
        public string $level,
        public ?string $context,
        public ?string $serverName,
        public ?string $appName,
        public DateTimeImmutable $createdAt
    ) {}

    public function getServerKey(): string
    {
        $server = strtolower(trim((string) $this->serverName));

        return $server !== '' ? $server : 'global';
    }
}
