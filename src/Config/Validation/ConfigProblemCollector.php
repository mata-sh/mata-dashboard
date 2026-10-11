<?php

declare(strict_types=1);

namespace Mata\Config\Validation;

use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;

/**
 * Stands in for EventManager while config files are validated: the loaders report every
 * problem through handle(), and this keeps them in memory instead of recording events or
 * queueing alarms. No database, cooldown, or EVENTS_ENABLED applies.
 *
 * The parent constructor is skipped on purpose; loaders only call handle(), so any other
 * EventManager method fails loudly on its uninitialized state instead of touching a database.
 */
final class ConfigProblemCollector extends EventManager
{
    /** @var list<EventCommand> */
    private array $events = [];

    public function __construct() {}

    /** @return array{eventRecorded: bool, eventId:?int} */
    public function handle(EventCommand $event): array
    {
        $this->events[] = $event;

        return ['eventRecorded' => false, 'eventId' => null];
    }

    /**
     * Return and forget the problems collected since the last call.
     *
     * @return list<EventCommand>
     */
    public function take(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }
}
