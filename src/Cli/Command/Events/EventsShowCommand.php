<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Events;

use DateTimeImmutable;
use InvalidArgumentException;
use Mata\Cli\CliConfig;
use Mata\Cli\CliOutput;
use Mata\Cli\EventPresenter;
use Mata\Cli\Evidence;
use Mata\Database\PdoDatabase;
use Mata\Models\Alarm\AlarmRepository;
use Mata\Models\Event\EventRepository;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One event in full: its context, the alarm decision the evaluation cron stored, and the alarm
 * it raised with its deliveries. IDs come from events list or alarms list.
 */
#[AsCommand(name: 'events:show', description: 'Show one event with its alarm decision, alarm and deliveries')]
final class EventsShowCommand extends Command
{
    public function __construct(private readonly CliConfig $cli)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Event ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');
        if (preg_match('/^[1-9][0-9]{0,18}$/', $id) !== 1) {
            throw new InvalidArgumentException('The event ID must be a positive integer.');
        }

        $db = new PdoDatabase($this->cli->load()->database);
        $row = (new EventRepository($db))->find((int) $id);
        if ($row === null) {
            return CliOutput::fail($input, $output, "Event {$id} does not exist; retention may have deleted it.");
        }

        $event = EventPresenter::event($row) + ['alarm_decision' => EventPresenter::decode($row['alarm_decision'])];
        $alarm = self::alarm($db, $event['id']);

        if ($input->getOption('json')) {
            $data = Evidence::fromDatabase('events', new DateTimeImmutable()) + ['event' => $event, 'alarm' => $alarm];
            CliOutput::json($output, ['status' => StatusLevel::Ok->label()] + $data);
        } else {
            $this->writeHuman($output, $event, $alarm);
        }

        return StatusLevel::Ok->value;
    }

    /**
     * The alarm the event raised, without the event fields it repeats; null when there is none.
     *
     * @return null|array<string,mixed>
     */
    private static function alarm(PdoDatabase $db, int $eventId): ?array
    {
        $alarm = (new AlarmRepository($db))->getLatest(1, eventId: $eventId)[0] ?? null;
        if ($alarm === null) {
            return null;
        }

        return [
            'id' => $alarm['id'],
            'created_at' => $alarm['created_at'],
            'acknowledged_at' => $alarm['acknowledged_at'],
            'acknowledged_by' => $alarm['acknowledged_by'],
            'trigger_evidence' => $alarm['trigger_evidence'],
            'deliveries' => $alarm['deliveries'],
        ];
    }

    /**
     * @param array<string,mixed>      $event
     * @param null|array<string,mixed> $alarm
     */
    private function writeHuman(OutputInterface $output, array $event, ?array $alarm): void
    {
        $output->writeln(sprintf(
            '<info>%s</info> %s %s (%s)',
            CliOutput::value($event['datetime']),
            CliOutput::value($event['level']),
            CliOutput::value($event['type']),
            EventPresenter::location($event['server'], $event['app']),
        ));
        $output->writeln(CliOutput::value($event['message']));
        foreach (['context', 'alarm_decision'] as $field) {
            if ($event[$field] !== null) {
                $json = json_encode($event[$field], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $output->writeln("{$field}: " . CliOutput::value($json));
            }
        }
        if ($event['evaluated_at'] === null) {
            $output->writeln('Not evaluated for alarms yet.');
        }
        if ($alarm === null) {
            $output->writeln('No alarm.');

            return;
        }
        $acknowledged = $alarm['acknowledged_at'] === null
            ? 'unacknowledged'
            : sprintf('acknowledged by %s at %s', CliOutput::value($alarm['acknowledged_by']), CliOutput::value($alarm['acknowledged_at']));
        $output->writeln(sprintf('alarm %d at %s, %s', $alarm['id'], CliOutput::value($alarm['created_at']), $acknowledged));
        foreach ((array) $alarm['deliveries'] as $channel => $delivery) {
            $processed = CliOutput::value($delivery['processed_at'] ?? null);
            $output->writeln('    ' . EventPresenter::delivery((string) $channel, $delivery) . ", processed {$processed}");
        }
    }
}
