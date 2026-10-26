<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Events;

use DateTimeImmutable;
use Mata\Cli\CliInput;
use Mata\Cli\CliOutput;
use Mata\Cli\Command\AbstractResultCommand;
use Mata\Cli\EventPresenter;
use Mata\Cli\Provenance;
use Mata\Database\PdoDatabase;
use Mata\Models\Alarm\AlarmRepository;
use Mata\Models\Event\EventRepository;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One event in full: its context, the alarm decision the evaluation cron stored, and the alarm
 * it raised with its deliveries. IDs come from events list or alarms list.
 */
#[AsCommand(name: 'events:show', description: 'Show one event with its alarm decision, alarm and deliveries')]
final class EventsShowCommand extends AbstractResultCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('id', InputArgument::REQUIRED, 'Event ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = CliInput::id((string) $input->getArgument('id'), 'The event ID');

        $db = new PdoDatabase($this->cli->load()->database);
        $row = (new EventRepository($db))->find($id);
        if ($row === null) {
            return CliOutput::fail($input, $output, "Event {$id} does not exist; retention may have deleted it.");
        }

        $event = EventPresenter::detailFields($row);
        $alarm = EventPresenter::alarmFields((new AlarmRepository($db))->getLatest(1, eventId: $event['id'])[0] ?? null);
        $data = Provenance::fromDatabase('events', new DateTimeImmutable()) + ['event' => $event, 'alarm' => $alarm];

        return $this->report($input, $output, StatusLevel::Ok, $data, fn () => EventPresenter::writeDetail($output, $event, $alarm));
    }
}
