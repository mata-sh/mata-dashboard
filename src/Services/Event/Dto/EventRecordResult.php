<?php

declare(strict_types=1);

namespace Mata\Services\Event\Dto;

/**
 * Event recording result containing IDs and resolved context.
 *
 * Returned by EventRecorderService::recordEvent() to provide:
 * - Database IDs for created event/alarm records
 * - Resolved server/app context (both ID and label)
 */
final readonly class EventRecordResult
{
    /**
     * @param int         $eventId     Database ID of created event record
     * @param null|int    $alarmId     Database ID of created alarm record (null if not an alarm)
     * @param null|int    $serverId    Resolved server database ID
     * @param null|int    $appId       Resolved application database ID
     * @param null|string $serverLabel Resolved server name/label
     * @param null|string $appLabel    Resolved application name/label
     * @param null|string $serverKey   Original server identifier passed by caller
     */
    public function __construct(
        public int $eventId,
        public ?int $alarmId,
        public ?int $serverId,
        public ?int $appId,
        public ?string $serverLabel,
        public ?string $appLabel,
        public ?string $serverKey = null
    ) {}

    /**
     * Check if an alarm was created alongside the event.
     */
    public function hasAlarm(): bool
    {
        return $this->alarmId !== null;
    }

    /**
     * Check if event was successfully recorded (non-zero ID).
     */
    public function wasRecorded(): bool
    {
        return $this->eventId > 0;
    }

    /**
     * Create from legacy array format (for backward compatibility).
     *
     * @param array{
     *     eventId: int,
     *     alarmId: ?int,
     *     serverId: ?int,
     *     appId: ?int,
     *     serverLabel: ?string,
     *     appLabel: ?string,
     *     serverKey: ?string
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            eventId: $data['eventId'],
            alarmId: $data['alarmId'],
            serverId: $data['serverId'],
            appId: $data['appId'],
            serverLabel: $data['serverLabel'],
            appLabel: $data['appLabel'],
            serverKey: $data['serverKey'] ?? null
        );
    }

    /**
     * Convert to array format (for backward compatibility).
     *
     * @return array{
     *     eventId: int,
     *     alarmId: ?int,
     *     serverId: ?int,
     *     appId: ?int,
     *     serverLabel: ?string,
     *     appLabel: ?string,
     *     serverKey: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'eventId' => $this->eventId,
            'alarmId' => $this->alarmId,
            'serverId' => $this->serverId,
            'appId' => $this->appId,
            'serverLabel' => $this->serverLabel,
            'appLabel' => $this->appLabel,
            'serverKey' => $this->serverKey,
        ];
    }
}
