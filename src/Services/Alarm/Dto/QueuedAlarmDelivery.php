<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Dto;

use DateTimeImmutable;

/** A channel-specific delivery queued for a logical alarm. */
final readonly class QueuedAlarmDelivery
{
    public function __construct(
        public int $id,
        public int $alarmId,
        public DateTimeImmutable $alarmCreatedAt,
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
        public ?string $appEnvironment,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $previousAlarmCreatedAt = null,
    ) {}

    public function getServerKey(): string
    {
        $server = strtolower(trim((string) $this->serverName));

        return $server !== '' ? $server : 'global';
    }
}
