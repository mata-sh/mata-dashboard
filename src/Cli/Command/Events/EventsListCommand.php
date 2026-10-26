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
use Mata\Models\Event\EventRepository;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only events this recent: ' . Evidence::DURATIONS)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of events', '50')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = Evidence::limit($input);
        $level = Evidence::stringOption($input, 'level');
        if ($level !== null && !in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException('--level must be one of: ' . implode(', ', self::LEVELS) . '.');
        }
        $now = new DateTimeImmutable();
        $since = Evidence::since($input, $now);
        $filters = [
            'server' => Evidence::stringOption($input, 'server'),
            'app' => Evidence::stringOption($input, 'app'),
            'type' => Evidence::stringOption($input, 'type'),
            'level' => $level,
            'since' => $since?->format(DATE_ATOM),
        ];

        $repository = new EventRepository(new PdoDatabase($this->cli->load()->database));
        // One more row than asked tells whether the list is complete.
        $rows = $repository->findRecent($filters['server'], $filters['app'], $filters['type'], $level, $since, $limit + 1);

        $events = array_map(EventPresenter::event(...), array_slice($rows, 0, $limit));
        $data = Evidence::fromDatabase('events', $now) + [
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

    /** @param list<array<string,mixed>> $events */
    private function writeHuman(OutputInterface $output, array $events, bool $truncated): void
    {
        if ($events === []) {
            $output->writeln('No matching events.');

            return;
        }

        foreach ($events as $event) {
            $output->writeln(sprintf(
                '<info>%s</info> %-8s %s (%s): %s',
                CliOutput::value($event['datetime']),
                CliOutput::value($event['level']),
                CliOutput::value($event['type']),
                EventPresenter::location($event['server'], $event['app']),
                CliOutput::value($event['message']),
            ));
        }
        if ($truncated) {
            CliOutput::truncated($output, 'events', count($events));
        }
    }
}
