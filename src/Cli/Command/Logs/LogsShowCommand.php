<?php

declare(strict_types=1);

namespace Mata\Cli\Command\Logs;

use DateTimeImmutable;
use Mata\Cli\Command\AbstractNodeEvidenceCommand;
use Mata\Cli\Evidence;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
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
final class LogsShowCommand extends AbstractNodeEvidenceCommand
{
    /** Node-side filters, keyed by the --type that maps to them. */
    private const NODE_FILTERS = ['error' => 'errors', 'warning' => 'warnings'];

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('app', InputArgument::REQUIRED, 'Application name, see apps list')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Only entries of this type, e.g. error, warning, deprecated')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Only entries of this log file, as listed in `files`')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only entries this recent: 2h, 1d, 1w (node local time)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of entries; with --live also per file from the node', '50');
    }

    protected function dataset(InputInterface $input): string
    {
        return 'logs';
    }

    protected function fetch(ServerDataCollectorInterface $collector, bool $live, InputInterface $input): ?array
    {
        $app = self::app($input);
        Evidence::since($input, new DateTimeImmutable());

        if (!$live) {
            return $collector->fetchAppLogs($app);
        }

        return $collector->fetchAppLogEntries($app, self::NODE_FILTERS[self::type($input)] ?? '', Evidence::limit($input));
    }

    protected function extract(mixed $result, InputInterface $input): array
    {
        $limit = Evidence::limit($input);
        $type = self::type($input);
        $file = is_string($input->getOption('file')) ? $input->getOption('file') : null;
        $since = Evidence::since($input, new DateTimeImmutable())?->format('Y-m-d H:i:s');
        $groups = is_array($result) && is_array($result['entries'] ?? null) ? $result['entries'] : [];
        $readoutLimit = is_array($result) && is_int($result['readout_limit'] ?? null) ? $result['readout_limit'] : null;

        $entries = [];
        $files = [];
        foreach ($groups as $path => $group) {
            $group = array_values(array_filter(is_array($group) ? $group : [], 'is_array'));
            $timestamps = array_filter(array_map(static fn (array $entry): ?string => is_string($entry['timestamp'] ?? null) ? $entry['timestamp'] : null, $group));
            $oldest = $timestamps === [] ? null : min($timestamps);
            // Capped: the node stopped at readout_limit, so older entries were cut off. Uncapped
            // proves less: the node also scans only the last lines of a file (errorlog_scan_line_limit).
            $capped = $readoutLimit !== null && count($group) >= $readoutLimit;
            $files[] = [
                'path' => (string) $path,
                'returned' => count($group),
                'oldest' => $oldest,
                'capped' => $capped,
                'covers_since' => match (true) {
                    $since === null => null,
                    $oldest !== null && $oldest <= $since => true,
                    $capped => false,
                    default => null,
                },
            ];
            if ($file !== null && $path !== $file) {
                continue;
            }
            foreach ($group as $entry) {
                $timestamp = is_string($entry['timestamp'] ?? null) ? $entry['timestamp'] : null;
                if (($type !== null && strtolower((string) ($entry['type'] ?? '')) !== $type)
                    || ($since !== null && ($timestamp === null || $timestamp < $since))) {
                    continue;
                }
                $entries[] = [
                    'timestamp' => $timestamp,
                    'type' => is_string($entry['type'] ?? null) ? $entry['type'] : null,
                    'file' => (string) $path,
                    'message' => is_string($entry['message'] ?? null) ? $entry['message'] : '',
                ];
            }
        }
        // Node timestamps are Y-m-d H:i:s, so they sort as strings; entries without one go last.
        usort($entries, static fn (array $a, array $b): int => strcmp((string) $b['timestamp'], (string) $a['timestamp']));

        return [
            'app' => (string) $input->getArgument('app'),
            'readout_limit' => $readoutLimit,
            'since' => $since,
            'files' => $files,
            'matched' => count($entries),
            'truncated' => count($entries) > $limit,
            'entries' => array_slice($entries, 0, $limit),
        ];
    }

    protected function writeHuman(OutputInterface $output, array $data): void
    {
        $entries = is_array($data['entries']) ? $data['entries'] : [];
        if ($entries === []) {
            $output->writeln('No log entries.');
        }
        foreach ($entries as $entry) {
            $output->writeln(sprintf(
                '<info>%s</info> %-10s %s: %s',
                $entry['timestamp'] ?? '-',
                $entry['type'] ?? '-',
                OutputFormatter::escape(basename($entry['file'])),
                OutputFormatter::escape($entry['message']),
            ));
        }
        if ($data['truncated'] === true) {
            $output->writeln(sprintf('%d of %d matching entries; raise --limit for more.', count($entries), $data['matched']));
        }
        $cut = [];
        foreach (is_array($data['files']) ? $data['files'] : [] as $file) {
            // With --since, only files whose readout ends inside the window miss entries.
            $missesEntries = $data['since'] !== null ? $file['covers_since'] === false : $file['capped'];
            if ($missesEntries) {
                $cut[] = basename($file['path']);
            }
        }
        if ($cut !== []) {
            $output->writeln(sprintf(
                '<comment>The node returned only the last %d entries of %s; older entries are not shown%s.</comment>',
                $data['readout_limit'],
                OutputFormatter::escape(implode(', ', $cut)),
                $data['since'] !== null ? ', so the --since window is not fully covered' : '',
            ));
        }
    }

    private static function type(InputInterface $input): ?string
    {
        $type = $input->getOption('type');

        return is_string($type) && $type !== '' ? strtolower($type) : null;
    }
}
