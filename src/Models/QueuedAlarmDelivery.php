<?php

declare(strict_types=1);

namespace Mata\Models;

use DateTimeImmutable;

/** A channel-specific delivery queued for a logical alarm. */
final readonly class QueuedAlarmDelivery
{
    public function __construct(
        public int $id,
        public int $alarmId,
        public DateTimeImmutable $alarmCreatedAt,
        public int $eventId,
        public DateTimeImmutable $eventOccurredAt,
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
        public ?string $appEnvironment,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $previousAlarmCreatedAt = null,
        /** @var array<string,mixed> */
        public array $triggerEvidence = [],
    ) {}

    public function getServerKey(): string
    {
        $server = strtolower(trim((string) $this->serverName));

        return $server !== '' ? $server : 'global';
    }
}
