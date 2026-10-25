<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Events;

use DateTimeImmutable;
use InvalidArgumentException;
use Mata\Cli\CliConfig;
use Mata\Cli\CliOutput;
use Mata\Cli\Evidence;
use Mata\Database\PdoDatabase;
use Mata\Models\Event\EventRepository;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recorded events from the database, newest first. Events are what monitoring observed and
 * recorded; whether one became an alarm is in alarm explain. Times are in the app's time zone.
 */
#[AsCommand(name: 'events:list', description: 'List recorded events, newest first')]
final class EventsListCommand extends Command
{
    private const LEVELS = ['info', 'warning', 'error', 'critical'];

    public function __construct(private readonly CliConfig $cli)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Only events of this server (servers.json name)')
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'Only events of this application')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Only events of this type, e.g. ram_usage_too_high')
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Only events of this level: ' . implode(', ', self::LEVELS))
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only events this recent: 24h, 7d, 2w, 3m (months), 1y')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of events', '50')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = Evidence::limit($input);
        $level = self::stringOption($input, 'level');
        if ($level !== null && !in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException('--level must be one of: ' . implode(', ', self::LEVELS) . '.');
        }
        $since = Evidence::since($input, new DateTimeImmutable());
        $filters = [
            'server' => self::stringOption($input, 'server'),
            'app' => self::stringOption($input, 'app'),
            'type' => self::stringOption($input, 'type'),
            'level' => $level,
            'since' => $since?->format(DATE_ATOM),
        ];

        $repository = new EventRepository(new PdoDatabase($this->cli->load()->database));
        // One more row than asked tells whether the list is complete.
        $rows = $repository->findRecent($filters['server'], $filters['app'], $filters['type'], $level, $since, $limit + 1);

        $events = array_map(self::event(...), array_slice($rows, 0, $limit));
        $data = Evidence::fromDatabase('events', time()) + [
            // An object even when empty, so `filters` keeps one JSON type.
            'filters' => (object) array_filter($filters, static fn (?string $value): bool => $value !== null),
            'truncated' => count($rows) > $limit,
            'events' => $events,
        ];

        if ($input->getOption('json')) {
            CliOutput::json($output, ['status' => StatusLevel::Ok->label()] + $data);
        } else {
            $this->writeHuman($output, $events, $data['truncated']);
        }

        return StatusLevel::Ok->value;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private static function event(array $row): array
    {
        $context = is_string($row['context'] ?? null) ? json_decode($row['context'], true) : null;

        return [
            'id' => (int) $row['id'],
            'datetime' => $row['datetime'],
            'level' => $row['level'],
            'type' => $row['type'],
            'server' => $row['server'],
            'app' => $row['app'],
            'app_env' => $row['app_env'],
            'message' => $row['message'],
            'context' => is_array($context) ? $context : null,
            'evaluated_at' => $row['evaluated_at'],
        ];
    }

    /** @param list<array<string,mixed>> $events */
    private function writeHuman(OutputInterface $output, array $events, bool $truncated): void
    {
        if ($events === []) {
            $output->writeln('No matching events.');
        }
        foreach ($events as $event) {
            $where = ($event['server'] ?? '-') . ($event['app'] !== null ? "/{$event['app']}" : '');
            $output->writeln(sprintf(
                '<info>%s</info> %-8s %s (%s): %s',
                $event['datetime'],
                $event['level'],
                $event['type'],
                OutputFormatter::escape($where),
                OutputFormatter::escape((string) $event['message']),
            ));
        }
        if ($truncated) {
            $output->writeln('More events match; narrow the filters or raise --limit.');
        }
    }

    private static function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
