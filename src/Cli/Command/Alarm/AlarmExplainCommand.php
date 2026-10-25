<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Alarm;

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
        $server = is_string($server) ? $server : null;
        $limit = (int) $input->getOption('limit');

        $events = new EventRepository(new PdoDatabase($this->cli->load()->database));
        $rows = $events->getRecentAlarmDecisions($server, $limit);

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
            CliOutput::json($output, ['status' => StatusLevel::Ok->label(), 'events' => $decisions]);
        } else {
            $this->writeHuman($output, $decisions, $server);
        }

        return StatusLevel::Ok->value;
    }

    /** @param list<array<string,mixed>> $decisions */
    private function writeHuman(OutputInterface $output, array $decisions, ?string $server): void
    {
        if ($decisions === []) {
            $output->writeln('No evaluated events' . ($server !== null ? " for '{$server}'" : '') . '.');
        }
        foreach ($decisions as $event) {
            $where = ($event['server'] ?? '-') . ($event['app'] !== null ? "/{$event['app']}" : '');
            $output->writeln("<info>{$event['datetime']}</info> {$event['level']} {$event['type']} ({$where})");
            foreach (self::describe($event['decision'] ?? []) as $line) {
                $output->writeln("    {$line}");
            }
        }
    }

    /**
     * The stored trace as two lines: the gates the event passed, then evidence and channels.
     * alarm_decision is stored JSON, so every field is read as optional.
     *
     * @param array<mixed> $trace
     *
     * @return array{string,string}
     */
    private static function describe(array $trace): array
    {
        $gates = 'outcome: ' . self::text($trace['outcome'] ?? null)
            . ' | policy: ' . self::text($trace['policy_gate'] ?? null) . ' (' . self::text($trace['policy_reason'] ?? null) . ')'
            . ' | threshold: ' . self::text($trace['threshold_gate'] ?? null);

        $evidence = is_array($trace['trigger_evidence'] ?? null) ? self::text($trace['trigger_evidence']['label'] ?? null) : '-';
        $ready = is_array($trace['ready_channels'] ?? null) ? array_map(self::text(...), $trace['ready_channels']) : [];
        $unavailable = [];
        foreach (is_array($trace['unavailable_channels'] ?? null) ? $trace['unavailable_channels'] : [] as $channel) {
            $unavailable[] = is_array($channel)
                ? self::text($channel['channel'] ?? null) . ': ' . self::text($channel['reason'] ?? null)
                : self::text($channel);
        }
        $delivery = "evidence: {$evidence} | channels: " . ($ready === [] ? '-' : implode(', ', $ready))
            . ($unavailable === [] ? '' : ' | unavailable: ' . implode(', ', $unavailable));

        return [$gates, $delivery];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '-';
    }
}
