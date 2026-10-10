<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Delivery\Dto;

use DateTimeImmutable;
use Mata\Services\Alarm\Dto\Alarm;

/** Presentation data for one alarm delivery, independent of its channel. */
final readonly class AlarmDeliveryView
{
    public function __construct(
        public Alarm $alarm,
        public int $alarmId,
        public DateTimeImmutable $alarmCreatedAt,
        public int $eventId,
        public DateTimeImmutable $eventOccurredAt,
        public ?DateTimeImmutable $previousAlarmCreatedAt = null,
        public ?string $appEnvironment = null,
    ) {}
}
