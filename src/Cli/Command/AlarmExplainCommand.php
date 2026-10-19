<?php

declare(strict_types=1);

namespace Mata\Cli\Command;

use Mata\Cli\CliConfig;
use Mata\Cli\CliOutput;
use Mata\Database\PdoDatabase;
use Mata\Models\Event\EventRepository;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Shows why recent events did or did not become alarms, from the decision trace the alarm
 * evaluation cron stores on each event: policy gate, threshold gate, trigger evidence, channels.
 */
#[AsCommand(name: 'alarm:explain', description: 'Explain the alarm decisions for recent events')]
final class AlarmExplainCommand extends Command
{
    public function __construct(private readonly CliConfig $cli)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::OPTIONAL, 'Only events of this server (servers.json name)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of events', '20')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON')
            ->setHelp('Use it after changing thresholds.json or notification_rules.json: the next evaluation run records new decisions.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $server = $input->getArgument('server');
        $limit = (int) $input->getOption('limit');

        try {
            $config = $this->cli->load();
            $rows = (new EventRepository(new PdoDatabase($config->database)))->getRecentAlarmDecisions(is_string($server) ? $server : null, $limit);
        } catch (Throwable $exception) {
            return CliOutput::fail($input, $output, CliOutput::withWebUserHint($exception->getMessage()));
        }

        $decisions = [];
        foreach ($rows as $row) {
            $decision = json_decode((string) ($row['alarm_decision'] ?? ''), true);
            $decisions[] = [
                'event_id' => (int) $row['id'],
                'datetime' => $row['datetime'],
                'type' => $row['type'],
                'level' => $row['level'],
                'server' => $row['server'],
                'app' => $row['app'],
                'message' => $row['message'],
                'decision' => is_array($decision) ? $decision : null,
            ];
        }

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => 'ok', 'events' => $decisions]);

            return StatusLevel::Ok->value;
        }
        if ($decisions === []) {
            $output->writeln('No evaluated events' . (is_string($server) ? " for '{$server}'" : '') . '.');
        }
        foreach ($decisions as $event) {
            $d = $event['decision'] ?? [];
            $where = trim(($event['server'] ?? '-') . ($event['app'] !== null ? "/{$event['app']}" : ''));
            $output->writeln("<info>{$event['datetime']}</info> {$event['level']} {$event['type']} ({$where})");
            $output->writeln('    outcome: ' . self::text($d['outcome'] ?? null)
                . ' | policy: ' . self::text($d['policy_gate'] ?? null) . ' (' . self::text($d['policy_reason'] ?? null) . ')'
                . ' | threshold: ' . self::text($d['threshold_gate'] ?? null));
            $evidence = is_array($d['trigger_evidence'] ?? null) ? self::text($d['trigger_evidence']['label'] ?? null) : '-';
            $channels = is_array($d['ready_channels'] ?? null) ? implode(', ', array_map(self::text(...), $d['ready_channels'])) : '-';
            $unavailable = is_array($d['unavailable_channels'] ?? null) ? array_map(static fn (mixed $c): string => is_array($c) ? self::text($c['channel'] ?? null) . ': ' . self::text($c['reason'] ?? null) : self::text($c), $d['unavailable_channels']) : [];
            $output->writeln("    evidence: {$evidence} | channels: " . ($channels === '' ? '-' : $channels) . ($unavailable !== [] ? ' | unavailable: ' . implode(', ', $unavailable) : ''));
        }

        return StatusLevel::Ok->value;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '-';
    }
}
