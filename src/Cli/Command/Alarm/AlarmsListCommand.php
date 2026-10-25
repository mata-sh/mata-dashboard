<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Alarm;

use Mata\Cli\CliConfig;
use Mata\Cli\CliOutput;
use Mata\Cli\Evidence;
use Mata\Database\PdoDatabase;
use Mata\Models\Alarm\AlarmRepository;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Alarms, newest first: the events that were notified, when they were acknowledged and what
 * happened to each delivery. Events that did not become alarms are in events list.
 */
#[AsCommand(name: 'alarms:list', description: 'List alarms with their acknowledgement and deliveries, newest first')]
final class AlarmsListCommand extends Command
{
    public function __construct(private readonly CliConfig $cli)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Only alarms of this server (servers.json name)')
            ->addOption('unacknowledged', null, InputOption::VALUE_NONE, 'Only alarms nobody acknowledged')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of alarms', '50')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = Evidence::limit($input);
        $server = $input->getOption('server');
        $server = is_string($server) && trim($server) !== '' ? trim($server) : null;
        $unacknowledged = $input->getOption('unacknowledged') === true;

        $repository = new AlarmRepository(new PdoDatabase($this->cli->load()->database));
        // One more row than asked tells whether the list is complete.
        $rows = $repository->getLatest($limit + 1, $unacknowledged, $server);
        $alarms = array_slice($rows, 0, $limit);

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => StatusLevel::Ok->label()] + Evidence::fromDatabase('alarms', time()) + [
                'filters' => (object) array_filter(['server' => $server, 'unacknowledged' => $unacknowledged ?: null], static fn (bool|string|null $value): bool => $value !== null),
                'truncated' => count($rows) > $limit,
                'alarms' => $alarms,
            ]);
        } else {
            $this->writeHuman($output, $alarms, count($rows) > $limit);
        }

        return StatusLevel::Ok->value;
    }

    /** @param array<int,array<string,mixed>> $alarms */
    private function writeHuman(OutputInterface $output, array $alarms, bool $truncated): void
    {
        if ($alarms === []) {
            $output->writeln('No matching alarms.');
        }
        foreach ($alarms as $alarm) {
            $where = ($alarm['server'] !== '' ? $alarm['server'] : '-') . ($alarm['app'] !== '' ? "/{$alarm['app']}" : '');
            $acknowledged = $alarm['acknowledged_at'] !== null ? "ack {$alarm['acknowledged_by']}" : '<comment>unacknowledged</comment>';
            $output->writeln(sprintf(
                '<info>%s</info> %-8s %s (%s) %s: %s',
                $alarm['created_at'],
                $alarm['level'],
                $alarm['type'],
                OutputFormatter::escape($where),
                $acknowledged,
                OutputFormatter::escape((string) $alarm['message']),
            ));
            $deliveries = [];
            foreach ((array) $alarm['deliveries'] as $channel => $delivery) {
                $deliveries[] = "{$channel} {$delivery['status']}" . ($delivery['attempts'] > 1 ? " ({$delivery['attempts']} attempts)" : '');
            }
            $output->writeln('    deliveries: ' . ($deliveries === [] ? '-' : implode(', ', $deliveries)) . " · event {$alarm['event_id']}");
        }
        if ($truncated) {
            $output->writeln('More alarms match; narrow the filters or raise --limit.');
        }
    }
}
