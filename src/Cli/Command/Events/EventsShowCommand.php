<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Events;

use InvalidArgumentException;
use Mata\Cli\CliConfig;
use Mata\Cli\CliOutput;
use Mata\Cli\Evidence;
use Mata\Database\PdoDatabase;
use Mata\Models\Alarm\AlarmRepository;
use Mata\Models\Event\EventRepository;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
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

        $event = [
            'id' => (int) $row['id'],
            'datetime' => $row['datetime'],
            'level' => $row['level'],
            'type' => $row['type'],
            'server' => $row['server'],
            'app' => $row['app'],
            'app_env' => $row['app_env'],
            'message' => $row['message'],
            'context' => self::decode($row['context']),
            'evaluated_at' => $row['evaluated_at'],
            'alarm_decision' => self::decode($row['alarm_decision']),
        ];
        $alarm = (new AlarmRepository($db))->getLatest(1, eventId: $event['id'])[0] ?? null;
        $alarm = $alarm === null ? null : array_intersect_key($alarm, array_flip(['id', 'created_at', 'acknowledged_at', 'acknowledged_by', 'trigger_evidence', 'deliveries']));

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => StatusLevel::Ok->label()] + Evidence::fromDatabase('events', time()) + ['event' => $event, 'alarm' => $alarm]);
        } else {
            $this->writeHuman($output, $event, $alarm);
        }

        return StatusLevel::Ok->value;
    }

    /**
     * @param array<string,mixed>      $event
     * @param null|array<string,mixed> $alarm
     */
    private function writeHuman(OutputInterface $output, array $event, ?array $alarm): void
    {
        $where = ($event['server'] ?? '-') . ($event['app'] !== null ? "/{$event['app']}" : '');
        $output->writeln("<info>{$event['datetime']}</info> {$event['level']} {$event['type']} (" . OutputFormatter::escape($where) . ')');
        $output->writeln(OutputFormatter::escape((string) $event['message']));
        foreach (['context', 'alarm_decision'] as $field) {
            if ($event[$field] !== null) {
                $output->writeln("{$field}: " . OutputFormatter::escape((string) json_encode($event[$field], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
            }
        }
        if ($event['evaluated_at'] === null) {
            $output->writeln('Not evaluated for alarms yet.');
        }
        if ($alarm === null) {
            $output->writeln('No alarm.');

            return;
        }
        $output->writeln(sprintf(
            'alarm %d at %s, %s',
            $alarm['id'],
            $alarm['created_at'],
            $alarm['acknowledged_at'] !== null ? "acknowledged by {$alarm['acknowledged_by']} at {$alarm['acknowledged_at']}" : 'unacknowledged',
        ));
        foreach ((array) $alarm['deliveries'] as $channel => $delivery) {
            $output->writeln(sprintf('    %s: %s after %d attempt(s), processed %s', $channel, $delivery['status'], $delivery['attempts'], $delivery['processed_at'] ?? '-'));
        }
    }

    /** @return null|array<mixed> */
    private static function decode(mixed $json): ?array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : null;
    }
}
