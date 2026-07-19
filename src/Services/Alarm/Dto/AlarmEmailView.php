<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Dto;

use DateTimeImmutable;

/** Presentation data for an alarm email. */
final readonly class AlarmEmailView
{
    public function __construct(
        public Alarm $alarm,
        public int $alarmId,
        public DateTimeImmutable $alarmCreatedAt,
        public ?DateTimeImmutable $previousAlarmCreatedAt = null,
    ) {}
}
