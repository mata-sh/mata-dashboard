<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Events;

use DateTimeImmutable;
use InvalidArgumentException;
use Mata\Cli\CliInput;
use Mata\Cli\CliOutput;
use Mata\Cli\Command\AbstractResultCommand;
use Mata\Cli\EventPresenter;
use Mata\Cli\Provenance;
use Mata\Database\PdoDatabase;
use Mata\Models\Event\EventRepository;
use Mata\Services\Status\StatusLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recorded events from the database, newest first. Events are what monitoring observed and
 * recorded; whether one became an alarm is in alarm explain. Times are in the app's time zone.
 */
#[AsCommand(name: 'events:list', description: 'List recorded events, newest first')]
final class EventsListCommand extends AbstractResultCommand
{
    private const LEVELS = ['info', 'warning', 'error', 'critical'];

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Only events of this server (servers.json name)')
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'Only events of this application')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Only events of this type, e.g. ram_usage_too_high')
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Only events of this level: ' . implode(', ', self::LEVELS))
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only events this recent: ' . CliInput::DURATIONS)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of events', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = CliInput::limit($input);
        $level = CliInput::stringOption($input, 'level');
        if ($level !== null && !in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException('--level must be one of: ' . implode(', ', self::LEVELS) . '.');
        }
        $now = new DateTimeImmutable();
        $since = CliInput::since($input, $now);
        $filters = [
            'server' => CliInput::stringOption($input, 'server'),
            'app' => CliInput::stringOption($input, 'app'),
            'type' => CliInput::stringOption($input, 'type'),
            'level' => $level,
            'since' => $since?->format(DATE_ATOM),
        ];

        $repository = new EventRepository(new PdoDatabase($this->cli->load()->database));
        // One more row than asked tells whether the list is complete.
        $rows = $repository->findRecent($filters['server'], $filters['app'], $filters['type'], $level, $since, $limit + 1);

        $events = array_map(EventPresenter::fields(...), array_slice($rows, 0, $limit));
        $data = Provenance::fromDatabase('events', $now) + [
            // An object even when empty, so `filters` keeps one JSON type.
            'filters' => (object) array_filter($filters, static fn (?string $value): bool => $value !== null),
            'truncated' => count($rows) > $limit,
            'events' => $events,
        ];

        return $this->report($input, $output, StatusLevel::Ok, $data, fn () => $this->writeHuman($output, $events, $data['truncated']));
    }

    /** @param list<array<string,mixed>> $events */
    private function writeHuman(OutputInterface $output, array $events, bool $truncated): void
    {
        if ($events === []) {
            $output->writeln('No matching events.');

            return;
        }

        foreach ($events as $event) {
            $output->writeln(EventPresenter::line($event));
        }
        if ($truncated) {
            CliOutput::truncated($output, 'events', count($events));
        }
    }
}
