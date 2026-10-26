<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Alarm;

use DateTimeImmutable;
use Mata\Cli\CliInput;
use Mata\Cli\CliOutput;
use Mata\Cli\Command\AbstractResultCommand;
use Mata\Cli\EventPresenter;
use Mata\Cli\Provenance;
use Mata\Database\PdoDatabase;
use Mata\Models\Alarm\AlarmRepository;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Alarms, newest first: the events that were notified, when they were acknowledged and what
 * happened to each delivery. Events that did not become alarms are in events list.
 */
#[AsCommand(name: 'alarms:list', description: 'List alarms with their acknowledgement and deliveries, newest first')]
final class AlarmsListCommand extends AbstractResultCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Only alarms of this server (servers.json name)')
            ->addOption('unacknowledged', null, InputOption::VALUE_NONE, 'Only alarms nobody acknowledged')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of alarms', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = CliInput::limit($input);
        $server = CliInput::stringOption($input, 'server');
        $unacknowledged = $input->getOption('unacknowledged') === true;
        $filters = [];
        if ($server !== null) {
            $filters['server'] = $server;
        }
        if ($unacknowledged) {
            $filters['unacknowledged'] = true;
        }

        $repository = new AlarmRepository(new PdoDatabase($this->cli->load()->database));
        // One more row than asked tells whether the list is complete.
        $rows = $repository->getLatest($limit + 1, $unacknowledged, $server);
        $alarms = array_slice($rows, 0, $limit);
        $data = Provenance::fromDatabase('alarms', new DateTimeImmutable()) + [
            // An object even when empty, so `filters` keeps one JSON type.
            'filters' => (object) $filters,
            'truncated' => count($rows) > $limit,
            'alarms' => $alarms,
        ];

        return $this->report($input, $output, StatusLevel::Ok, $data, fn () => $this->writeHuman($output, $alarms, $data['truncated']));
    }

    /** @param array<int,array<string,mixed>> $alarms */
    private function writeHuman(OutputInterface $output, array $alarms, bool $truncated): void
    {
        if ($alarms === []) {
            $output->writeln('No matching alarms.');

            return;
        }

        foreach ($alarms as $alarm) {
            $acknowledged = $alarm['acknowledged_at'] === null
                ? '<comment>unacknowledged</comment>'
                : 'ack ' . CliOutput::value($alarm['acknowledged_by']);
            $output->writeln(sprintf(
                '<info>%s</info> %-8s %s (%s) %s: %s',
                CliOutput::value($alarm['created_at']),
                CliOutput::value($alarm['level']),
                CliOutput::value($alarm['type']),
                CliOutput::location($alarm['server'], $alarm['app']),
                $acknowledged,
                CliOutput::value($alarm['message']),
            ));

            $deliveries = [];
            foreach ((array) $alarm['deliveries'] as $channel => $delivery) {
                $deliveries[] = EventPresenter::deliveryLine((string) $channel, $delivery);
            }
            $deliveries = $deliveries === [] ? '-' : implode(', ', $deliveries);
            $output->writeln("    deliveries: {$deliveries} · event {$alarm['event_id']}");
        }
        if ($truncated) {
            CliOutput::truncated($output, 'alarms', count($alarms));
        }
    }
}
