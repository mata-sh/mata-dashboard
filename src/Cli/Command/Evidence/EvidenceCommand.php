<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Evidence;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Mata\Cli\CliInput;
use Mata\Cli\CliOutput;
use Mata\Cli\Command\AbstractResultCommand;
use Mata\Cli\EventPresenter;
use Mata\Cli\LogEntries;
use Mata\Cli\NodeDatasetReader;
use Mata\Cli\ProcessTable;
use Mata\Cli\Provenance;
use Mata\Cli\StoredMetrics;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Database\PdoDatabase;
use Mata\Models\Alarm\AlarmRepository;
use Mata\Models\Event\EventRepository;
use Mata\Services\Cache\CacheType;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use Mata\Services\Status\StatusLevel;
use Mata\Services\View\MetricsViewBuilder;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Everything around an event or a recent time window in one document: the event with its alarm,
 * the events, the log entries of the server's apps, its stored metrics and, when the window ends
 * now, its PHP processes. Each section carries its own provenance. A section that could not be
 * read has an `error`, or a `skipped` reason, and the others are still reported; an error or a
 * copy past its TTL makes the result a warning.
 */
#[AsCommand(name: 'evidence', description: 'Bundle events, logs, metrics and processes around an event or time window')]
final class EvidenceCommand extends AbstractResultCommand
{
    private const DEFAULT_WINDOW = 'PT1H';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('server', InputArgument::OPTIONAL, 'Server name from servers.json; not with --event')
            ->addOption('event', null, InputOption::VALUE_REQUIRED, 'Event ID to centre the window on; server and app come from the event')
            ->addOption('around', null, InputOption::VALUE_REQUIRED, 'With --event: how far before and after it, default 1h: ' . CliInput::DURATIONS)
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Without --event: the window up to now, default 1h: ' . CliInput::DURATIONS)
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'Without --event: only the logs of this application; all apps without it')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of events, of log entries and of processes', '50')
            ->addOption('live', null, InputOption::VALUE_NONE, 'Ask the node for logs and processes instead of reading the cache')
            ->setHelp(<<<'HELP'
                bin/mata evidence --event 4711 --around 2h
                bin/mata evidence web-01 --app shop --since 6h

                Events cover all apps of the server, and the whole fleet for an event without a server.
                Logs are those the node returned: with a window older than the node's readout, `covers_since`
                is false and the entries are missing. Processes are a snapshot of now, so they are left out
                when the window ends earlier. Event and log times are local, metrics UTC, `window` UTC.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Reject bad input before reading config or asking a node, as the node data commands do.
        $limit = CliInput::limit($input);
        $live = $input->getOption('live') === true;
        $now = new DateTimeImmutable();
        $eventId = CliInput::stringOption($input, 'event');
        if ($eventId !== null) {
            $eventRequest = self::eventRequest($input, $eventId);
        } else {
            $focus = self::windowFocus($input, $now);
        }

        $config = $this->cli->load();
        $reader = new NodeDatasetReader($this->cli, $config);
        $servers = $reader->servers();
        $db = new PdoDatabase($config->database);

        if ($eventId !== null) {
            $focus = self::eventFocus($db, $eventRequest['id'], $eventRequest['around'], $now);
            if ($focus === null) {
                return CliOutput::fail($input, $output, "Event {$eventId} does not exist; retention may have deleted it.");
            }
        } elseif (!isset($servers[$focus['server']])) {
            return CliOutput::fail($input, $output, "Server '{$focus['server']}' is not in servers.json.", payload: ['servers' => array_keys($servers)]);
        }
        ['server' => $server, 'app' => $app, 'from' => $from, 'to' => $to] = $focus;

        if ($server === null) {
            $metrics = $logs = $processes = ['skipped' => 'The event has no server.'];
        } elseif (!isset($servers[$server])) {
            // Removed from servers.json since the event: its metrics are stored, but there is no node to ask.
            $metrics = self::readMetrics($config, $servers, $server, $from, $to, $now);
            $logs = $processes = ['error' => "Server '{$server}' is not in servers.json."];
        } else {
            $metrics = self::readMetrics($config, $servers, $server, $from, $to, $now);
            $logs = self::readLogs($reader, $server, $servers[$server], $app, $live, $from, $to, $limit);
            $processes = $to < $now
                ? ['skipped' => 'Processes are a snapshot of now; the window ended at ' . $to->format('Y-m-d H:i:s') . '.']
                : self::readProcesses($reader, $server, $servers[$server], $live, $limit);
        }

        $data = [
            'server' => $server,
            'app' => $app,
            'window' => ['from' => Provenance::utc($from), 'to' => Provenance::utc($to), 'event_id' => $focus['event']['id'] ?? null],
            'event' => $focus['event'],
            'alarm' => $focus['alarm'],
            'events' => self::readEvents(new EventRepository($db), $server, $from, $to, $limit, $now),
            'logs' => $logs,
            'metrics' => $metrics,
            'processes' => $processes,
        ];

        return $this->report($input, $output, self::sectionsLevel($data), $data, fn () => $this->writeHuman($output, $data));
    }

    /**
     * --event and --around, checked without reading the event.
     *
     * @return array{id:int,around:DateInterval}
     *
     * @throws InvalidArgumentException for options that --event replaces, or an invalid ID or duration
     */
    private static function eventRequest(InputInterface $input, string $eventId): array
    {
        if ($input->getArgument('server') !== null || $input->getOption('app') !== null || $input->getOption('since') !== null) {
            throw new InvalidArgumentException('--event takes the server and app from the event; leave out <server>, --app and --since.');
        }

        return [
            'id' => CliInput::id($eventId, '--event'),
            'around' => CliInput::duration($input, 'around') ?? new DateInterval(self::DEFAULT_WINDOW),
        ];
    }

    /**
     * The window around an event; null when the event does not exist.
     *
     * @return null|array{server:null|string,app:null|string,from:DateTimeImmutable,to:DateTimeImmutable,event:array<string,mixed>,alarm:null|array<string,mixed>}
     */
    private static function eventFocus(PdoDatabase $db, int $id, DateInterval $around, DateTimeImmutable $now): ?array
    {
        $row = (new EventRepository($db))->find($id);
        if ($row === null) {
            return null;
        }
        $event = EventPresenter::detailFields($row);
        // Stored in the app's time zone, like the --since of events list.
        $time = new DateTimeImmutable((string) $event['datetime']);
        $end = $time->add($around);

        return [
            'server' => is_string($event['server']) && $event['server'] !== '' ? $event['server'] : null,
            'app' => is_string($event['app']) && $event['app'] !== '' ? $event['app'] : null,
            'from' => $time->sub($around),
            'to' => $end < $now ? $end : $now,
            'event' => $event,
            'alarm' => EventPresenter::alarmFields((new AlarmRepository($db))->getLatest(1, eventId: $id)[0] ?? null),
        ];
    }

    /**
     * The window up to now on a server.
     *
     * @return array{server:string,app:null|string,from:DateTimeImmutable,to:DateTimeImmutable,event:null,alarm:null}
     */
    private static function windowFocus(InputInterface $input, DateTimeImmutable $now): array
    {
        if ($input->getOption('around') !== null) {
            throw new InvalidArgumentException('--around needs --event; without it, --since sets the window.');
        }
        $server = $input->getArgument('server');
        if (!is_string($server)) {
            throw new InvalidArgumentException('Pass a server, or --event with an event ID.');
        }
        $app = CliInput::stringOption($input, 'app');
        if ($app !== null) {
            $app = CliInput::appName($app);
        }

        return [
            'server' => $server,
            'app' => $app,
            'from' => $now->sub(CliInput::duration($input, 'since') ?? new DateInterval(self::DEFAULT_WINDOW)),
            'to' => $now,
            'event' => null,
            'alarm' => null,
        ];
    }

    /**
     * The events of the server in the window; of the fleet without a server.
     *
     * @return array<string,mixed>
     */
    private static function readEvents(EventRepository $repository, ?string $server, DateTimeImmutable $from, DateTimeImmutable $to, int $limit, DateTimeImmutable $now): array
    {
        // One more row than asked tells whether the list is complete.
        $rows = $repository->findRecent($server, app: null, type: null, level: null, since: $from, limit: $limit + 1, until: $to);

        return Provenance::fromDatabase('events', $now) + [
            'truncated' => count($rows) > $limit,
            'events' => array_map(EventPresenter::fields(...), array_slice($rows, 0, $limit)),
        ];
    }

    /**
     * Log entries in the window, of $app or of every app on the server. `apps` holds each app's
     * provenance or error; one app failing does not hide the others.
     *
     * @param array<string,mixed> $serverConfig
     *
     * @return array<string,mixed>
     */
    private static function readLogs(
        NodeDatasetReader $reader,
        string $server,
        array $serverConfig,
        ?string $app,
        bool $live,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit,
    ): array {
        // Node local time, like the log timestamps.
        $since = $from->format('Y-m-d H:i:s');
        $until = $to->format('Y-m-d H:i:s');
        $apps = [$app];
        if ($app === null) {
            $dataset = $reader->read(
                $server,
                $serverConfig,
                $live,
                'apps',
                static fn (ServerDataCollectorInterface $collector): ?array => $collector->getApps($live ? CacheType::None : CacheType::Apps),
            );
            if ($dataset->error !== null) {
                return ['error' => "The apps could not be listed: {$dataset->error}"] + $dataset->fields();
            }
            $apps = array_map('strval', array_keys(is_array($dataset->result) ? $dataset->result : []));
        }

        $sources = [];
        $files = [];
        $entries = [];
        foreach ($apps as $name) {
            $dataset = $reader->read(
                $server,
                $serverConfig,
                $live,
                'logs',
                static fn (ServerDataCollectorInterface $collector): ?array => $live
                    ? $collector->fetchAppLogEntries($name, '', $limit)
                    : $collector->fetchAppLogs($name),
            );
            if ($dataset->error !== null) {
                $sources[] = ['app' => $name, 'error' => $dataset->error] + $dataset->fields();

                continue;
            }
            $filtered = LogEntries::filter($dataset->result, type: null, file: null, since: $since, until: $until);
            $sources[] = ['app' => $name] + $dataset->fields() + ['readout_limit' => $filtered['readout_limit']];
            foreach ($filtered['files'] as $file) {
                $files[] = ['app' => $name] + $file;
            }
            foreach ($filtered['entries'] as $entry) {
                $entries[] = ['app' => $name] + $entry;
            }
        }
        $entries = LogEntries::newestFirst($entries);

        return [
            'since' => $since,
            'until' => $until,
            'apps' => $sources,
            'files' => $files,
            'matched' => count($entries),
            'truncated' => count($entries) > $limit,
            'entries' => array_slice($entries, 0, $limit),
        ];
    }

    /**
     * Every stored metric of the server in the window, at the granularity metrics series
     * would pick for a range of that length.
     *
     * @param array<string,array<string,mixed>> $servers
     *
     * @return array<string,mixed>
     */
    private static function readMetrics(ApplicationConfig $config, array $servers, string $server, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $now): array
    {
        $hours = max(1, (int) ceil(($to->getTimestamp() - $from->getTimestamp()) / 3600));
        $range = $hours <= 24 ? "{$hours}h" : (int) ceil($hours / 24) . 'd';
        $granularity = (new MetricQueryPolicy())->resolveGranularity($range, 'auto');

        $builder = new MetricsViewBuilder(StoredMetrics::query($config, $servers), new NullLogger());
        $timeSeries = $builder->buildMultiMetricTimeSeriesBetween($server, (new MetricCatalog())->metrics(), $from, $to, $granularity);

        $series = [];
        foreach ($timeSeries['datasets'] as $dataset) {
            $points = [];
            foreach ($timeSeries['labels'] as $index => $label) {
                $points[] = ['time' => $label, 'value' => $dataset['data'][$index] ?? null];
            }
            $values = array_values(array_filter($dataset['data'], static fn (mixed $value): bool => $value !== null));
            $series[$dataset['metric']] = [
                'unit' => $dataset['unit'],
                'min' => $values === [] ? null : min($values),
                'max' => $values === [] ? null : max($values),
                'avg' => $values === [] ? null : round(array_sum($values) / count($values), 2),
                'points' => $points,
            ];
        }
        $demo = $config->environment->env === 'demo';

        return Provenance::fromDatabase("server_metrics_{$granularity}", $now, $demo) + [
            'granularity' => $granularity,
            'timezone' => 'UTC',
            // An object even when empty, so `series` keeps one JSON type.
            'series' => (object) $series,
        ];
    }

    /**
     * @param array<string,mixed> $serverConfig
     *
     * @return array<string,mixed>
     */
    private static function readProcesses(NodeDatasetReader $reader, string $server, array $serverConfig, bool $live, int $limit): array
    {
        $dataset = $reader->read(
            $server,
            $serverConfig,
            $live,
            'php processes',
            static fn (ServerDataCollectorInterface $collector): ?array => $collector->getServerPhpProcesses($live ? CacheType::None : CacheType::ServerProcesses),
        );
        if ($dataset->error !== null) {
            return ['error' => $dataset->error] + $dataset->fields();
        }
        $processes = ProcessTable::busiestFirst($dataset->result);

        return $dataset->fields() + [
            'matched' => count($processes),
            'truncated' => count($processes) > $limit,
            'processes' => array_slice($processes, 0, $limit),
        ];
    }

    /**
     * Warning when a node section or app failed or is a copy past its TTL; the data that was
     * read is still reported.
     *
     * @param array<string,mixed> $data
     */
    private static function sectionsLevel(array $data): StatusLevel
    {
        $sections = [$data['logs'], $data['processes'], ...($data['logs']['apps'] ?? [])];
        foreach ($sections as $section) {
            if (isset($section['error']) || ($section['cache']['stale'] ?? false) === true) {
                return StatusLevel::Warning;
            }
        }

        return StatusLevel::Ok;
    }

    /** @param array<string,mixed> $data */
    private function writeHuman(OutputInterface $output, array $data): void
    {
        $from = (new DateTimeImmutable($data['window']['from']))->setTimezone((new DateTimeImmutable())->getTimezone());
        $to = (new DateTimeImmutable($data['window']['to']))->setTimezone($from->getTimezone());
        $output->writeln(sprintf(
            '<info>Evidence for %s, %s to %s</info>',
            $data['event'] === null ? CliOutput::location($data['server'], $data['app']) : "event {$data['event']['id']}",
            $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s'),
        ));

        if ($data['event'] !== null) {
            $output->writeln('<info>== Event</info>');
            EventPresenter::writeDetail($output, $data['event'], $data['alarm']);
        }

        $output->writeln('<info>== Events</info>');
        $events = $data['events']['events'];
        if ($events === []) {
            $output->writeln('No events.');
        }
        foreach ($events as $event) {
            $output->writeln(EventPresenter::line($event));
        }
        if ($data['events']['truncated'] === true) {
            CliOutput::truncated($output, 'events', count($events));
        }

        self::writeLogs($output, $data['logs']);
        self::writeMetrics($output, $data['metrics']);
        self::writeProcesses($output, $data['processes']);
    }

    /** @param array<string,mixed> $logs */
    private static function writeLogs(OutputInterface $output, array $logs): void
    {
        $output->writeln('<info>== Logs</info>');
        if (self::writeIfUnavailable($output, $logs)) {
            return;
        }
        foreach ($logs['apps'] as $source) {
            $state = isset($source['error'])
                ? '<error>' . CliOutput::value($source['error']) . '</error>'
                : Provenance::describeCache($source['cache']);
            $output->writeln(CliOutput::value($source['app']) . ": {$state}");
        }
        if ($logs['entries'] === []) {
            $output->writeln('No log entries.');
        }
        foreach ($logs['entries'] as $entry) {
            $output->writeln(LogEntries::line($entry));
        }
        if ($logs['truncated'] === true) {
            CliOutput::truncated($output, 'entries', count($logs['entries']), $logs['matched']);
        }
        $cut = LogEntries::filesMissingEntries($logs['files'], windowed: true);
        if ($cut !== []) {
            $output->writeln('<comment>The node returned only the newest entries of ' . CliOutput::value($cut)
                . '; they do not reach back to the window start.</comment>');
        }
    }

    /** @param array<string,mixed> $metrics */
    private static function writeMetrics(OutputInterface $output, array $metrics): void
    {
        if (isset($metrics['skipped'])) {
            $output->writeln('<info>== Metrics</info>');
            self::writeIfUnavailable($output, $metrics);

            return;
        }
        $output->writeln("<info>== Metrics ({$metrics['granularity']}, UTC)</info>");
        $series = (array) $metrics['series'];
        if ($series === []) {
            $output->writeln('No stored metrics in the window. Check bin/mata metrics status.');
        }
        foreach ($series as $metric => $values) {
            $output->writeln(sprintf(
                '%-14s min %s, max %s, avg %s %s (%d points)',
                $metric,
                CliOutput::value($values['min']),
                CliOutput::value($values['max']),
                CliOutput::value($values['avg']),
                CliOutput::value($values['unit']),
                count($values['points']),
            ));
        }
    }

    /** @param array<string,mixed> $processes */
    private static function writeProcesses(OutputInterface $output, array $processes): void
    {
        $output->writeln('<info>== Processes</info>');
        if (self::writeIfUnavailable($output, $processes)) {
            return;
        }
        if ($processes['processes'] === []) {
            $output->writeln('No PHP processes.');
        } else {
            ProcessTable::write($output, $processes['processes']);
        }
        if ($processes['truncated'] === true) {
            CliOutput::truncated($output, 'processes', count($processes['processes']), $processes['matched']);
        }
        $output->writeln('<comment>' . Provenance::describeCache($processes['cache']) . '</comment>');
    }

    /**
     * Writes why a section has no data, when it has none; false when it has data.
     *
     * @param array<string,mixed> $section
     */
    private static function writeIfUnavailable(OutputInterface $output, array $section): bool
    {
        if (isset($section['error'])) {
            $output->writeln('<error>' . CliOutput::value($section['error']) . '</error>');

            return true;
        }
        if (isset($section['skipped'])) {
            $output->writeln('Skipped: ' . CliOutput::value($section['skipped']));

            return true;
        }

        return false;
    }
}
