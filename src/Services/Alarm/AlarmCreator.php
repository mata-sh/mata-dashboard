<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use Mata\Core\DatabaseInterface;
use Mata\Models\AlarmDeliveryRepository;
use Mata\Models\AlarmRepository;
use Throwable;

/** Atomically creates a logical alarm and its requested channel deliveries. */
final class AlarmCreator
{
    public function __construct(
        private DatabaseInterface $db,
        private AlarmRepository $alarms,
        private AlarmDeliveryRepository $deliveries
    ) {}

    /**
     * @param array<string> $readyChannels
     *
     * @throws Throwable
     */
    public function create(int $eventId, array $readyChannels, int $priority): int
    {
        $this->db->beginTransaction();

        try {
            $alarmId = $this->alarms->createForEvent($eventId);
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
