<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Delivery;

use Mata\Services\Alarm\Delivery\Dto\AlarmDeliveryView;
use Throwable;

/** Delivers one alarm through a single channel. */
interface AlarmChannelSenderInterface
{
    /**
     * Failures must throw; the dispatcher records the message and schedules a retry.
     * Messages are persisted, so they must never contain channel secrets.
     *
     * @throws Throwable
     */
    public function send(AlarmDeliveryView $view): void;
}
