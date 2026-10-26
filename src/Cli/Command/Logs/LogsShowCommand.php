<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Logs;

use DateTimeImmutable;
use Mata\Cli\CliInput;
use Mata\Cli\CliOutput;
use Mata\Cli\Command\AbstractNodeDatasetCommand;
use Mata\Cli\LogEntries;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Parsed log entries of one application, newest first, across all its configured log files.
 * The node returns at most `readout_limit` entries per file; `files[].capped` says that older
 * entries were cut off, and `covers_since` whether the --since window is covered (null: unknown).
 * --live asks the node for --limit entries per file, so a larger --limit reaches further back.
 */
#[AsCommand(name: 'logs:show', description: 'Show the parsed log entries of an application, newest first')]
final class LogsShowCommand extends AbstractNodeDatasetCommand
{
    /** Node-side filters, keyed by the --type that maps to them. */
    private const NODE_FILTERS = ['error' => 'errors', 'warning' => 'warnings'];

    protected function requiresApp(): bool
    {
        return true;
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Only entries of this type, e.g. error, warning, deprecated')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Only entries of this log file, as listed in `files`')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only entries this recent: ' . CliInput::DURATIONS . '; node local time')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of entries; with --live also per file from the node', '50');
    }

    protected function datasetName(InputInterface $input): string
    {
        return 'logs';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        $app = self::appArgument($input);
        if (!$live) {
            return $collector->fetchAppLogs($app);
        }

        return $collector->fetchAppLogEntries($app, self::NODE_FILTERS[self::type($input)] ?? '', CliInput::limit($input));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        $limit = CliInput::limit($input);
        $since = CliInput::since($input, new DateTimeImmutable())?->format('Y-m-d H:i:s');
        $filtered = LogEntries::filter($result, self::type($input), CliInput::stringOption($input, 'file'), $since);
        $entries = $filtered['entries'];

        return [
            'readout_limit' => $filtered['readout_limit'],
            'since' => $since,
            'files' => $filtered['files'],
            'matched' => count($entries),
            'truncated' => count($entries) > $limit,
            'entries' => array_slice($entries, 0, $limit),
        ];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        $entries = is_array($data['entries']) ? $data['entries'] : [];
        // No early return: the note about cut-off files below matters most when nothing matched.
        if ($entries === []) {
            $output->writeln('No log entries.');
        }
        foreach ($entries as $entry) {
            $output->writeln(LogEntries::line($entry));
        }
        if ($data['truncated'] === true) {
            CliOutput::truncated($output, 'entries', count($entries), (int) $data['matched']);
        }
        $cut = LogEntries::filesMissingEntries(is_array($data['files']) ? $data['files'] : [], $data['since'] !== null);
        if ($cut !== []) {
            $output->writeln(sprintf(
                '<comment>The node returned only the last %d entries of %s; older entries are not shown%s.</comment>',
                $data['readout_limit'],
                CliOutput::value($cut),
                $data['since'] !== null ? ', so the --since window is not fully covered' : '',
            ));
        }
    }

    private static function type(InputInterface $input): ?string
    {
        $type = CliInput::stringOption($input, 'type');

        return $type === null ? null : strtolower($type);
    }
}
