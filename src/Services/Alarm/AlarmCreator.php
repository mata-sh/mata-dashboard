<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use Mata\Database\DatabaseInterface;
use Mata\Models\Alarm\AlarmDeliveryRepository;
use Mata\Models\Alarm\AlarmRepository;
use Throwable;

/** Atomically creates a logical alarm and its ready-channel deliveries. */
final class AlarmCreator implements AlarmCreatorInterface
{
    public function __construct(
        private DatabaseInterface $db,
        private AlarmRepository $alarms,
        private AlarmDeliveryRepository $deliveries
    ) {}

    /**
     * @param array<string>       $readyChannels
     * @param array<string,mixed> $triggerEvidence
     *
     * @throws Throwable
     */
    public function create(int $eventId, array $readyChannels, int $priority, array $triggerEvidence = []): int
    {
        $this->db->beginTransaction();

        try {
            $alarmId = $this->alarms->createForEvent($eventId, $triggerEvidence);
            foreach ($readyChannels as $channel) {
                if (trim($channel) !== '') {
                    $this->deliveries->enqueue($alarmId, $channel, $priority);
                }
            }
            $this->db->commit();

            return $alarmId;
        } catch (Throwable $exception) {
            $this->db->rollback();

            throw $exception;
        }
    }
}
