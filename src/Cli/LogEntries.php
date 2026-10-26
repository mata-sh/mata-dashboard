<?php

declare(strict_types=1);

namespace Mata\Cli;

/**
 * The parsed log entries of one application as a node returns them, grouped by file, flattened
 * and filtered for logs show and evidence. Node timestamps are Y-m-d H:i:s in the node's local
 * time, so they compare and sort as strings; $since and $until use the same format.
 */
final class LogEntries
{
    /**
     * Files report what the node returned for each, whatever the filters; entries are the
     * matching ones, newest first and not limited.
     *
     * @return array{
     *     readout_limit: null|int,
     *     files: list<array{path:string,returned:int,oldest:null|string,capped:bool,covers_since:null|bool}>,
     *     entries: list<array{timestamp:null|string,type:null|string,file:string,message:string}>
     * }
     */
    public static function filter(mixed $result, ?string $type, ?string $file, ?string $since, ?string $until = null): array
    {
        $groups = is_array($result) && is_array($result['entries'] ?? null) ? $result['entries'] : [];
        $readoutLimit = is_array($result) && is_int($result['readout_limit'] ?? null) ? $result['readout_limit'] : null;

        $entries = [];
        $files = [];
        foreach ($groups as $path => $group) {
            $group = array_values(array_filter(is_array($group) ? $group : [], 'is_array'));
            $timestamps = [];
            foreach ($group as $entry) {
                if (is_string($entry['timestamp'] ?? null)) {
                    $timestamps[] = $entry['timestamp'];
                }
            }
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
                if (!self::matches($entry, $timestamp, $type, $since, $until)) {
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

        return ['readout_limit' => $readoutLimit, 'files' => $files, 'entries' => self::newestFirst($entries)];
    }

    /**
     * Entries without a timestamp go last.
     *
     * @template T of array{timestamp:null|string}
     *
     * @param list<T> $entries
     *
     * @return list<T>
     */
    public static function newestFirst(array $entries): array
    {
        usort($entries, static fn (array $a, array $b): int => strcmp((string) $b['timestamp'], (string) $a['timestamp']));

        return $entries;
    }

    /**
     * One line per entry, as logs show prints them; with the app when the entry has one.
     *
     * @param array<string,mixed> $entry
     */
    public static function line(array $entry): string
    {
        return sprintf(
            '<info>%s</info> %-10s %s: %s',
            CliOutput::value($entry['timestamp']),
            CliOutput::value($entry['type']),
            CliOutput::value(self::fileName($entry['file'], $entry['app'] ?? null)),
            CliOutput::value($entry['message']),
        );
    }

    /**
     * The files that miss entries: with a window start, those whose readout ends inside it;
     * without one, those the node cut off.
     *
     * @param array<mixed> $files from filter(), optionally with an `app`
     *
     * @return list<string> file names, with the app when the file has one
     */
    public static function filesMissingEntries(array $files, bool $windowed): array
    {
        $cut = [];
        foreach ($files as $file) {
            $missesEntries = $windowed ? $file['covers_since'] === false : $file['capped'] === true;
            if ($missesEntries) {
                $cut[] = self::fileName($file['path'], $file['app'] ?? null);
            }
        }

        return $cut;
    }

    /** "shop/error.log", or "error.log" without an app. */
    private static function fileName(mixed $path, mixed $app): string
    {
        $file = basename((string) $path);

        return is_string($app) ? "{$app}/{$file}" : $file;
    }

    /** @param array<mixed> $entry */
    private static function matches(array $entry, ?string $timestamp, ?string $type, ?string $since, ?string $until): bool
    {
        if ($type !== null && strtolower((string) ($entry['type'] ?? '')) !== $type) {
            return false;
        }
        if ($since === null && $until === null) {
            return true;
        }

        return $timestamp !== null
            && ($since === null || $timestamp >= $since)
            && ($until === null || $timestamp <= $until);
    }
}
