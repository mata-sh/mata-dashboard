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
     *     entries: list<array{timestamp:null|string,type:null|string,file:string,message:string,count:int,first_seen:null|string}>
     * }
     */
    public static function filter(mixed $result, ?string $type, ?string $file, ?string $since, ?string $until = null): array
    {
        $groups = is_array($result) && is_array($result['entries'] ?? null) ? $result['entries'] : [];
        $readoutLimit = is_array($result) && is_int($result['readout_limit'] ?? null) ? $result['readout_limit'] : null;

        $entries = [];
        $files = [];
        foreach ($groups as $path => $nodeEntries) {
            $group = [];
            $firstSeen = [];
            foreach (is_array($nodeEntries) ? $nodeEntries : [] as $nodeEntry) {
                if (!is_array($nodeEntry)) {
                    continue;
                }
                $entry = self::entry($nodeEntry, (string) $path);
                $group[] = $entry;
                if ($entry['first_seen'] !== null) {
                    $firstSeen[] = $entry['first_seen'];
                }
            }
            $oldest = $firstSeen === [] ? null : min($firstSeen);
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
                if (self::matches($entry, $type, $since, $until)) {
                    $entries[] = $entry;
                }
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
     * One line per entry, as logs show prints them; with the app when the entry has one, and the
     * number of occurrences when the message repeated.
     *
     * @param array<string,mixed> $entry
     */
    public static function line(array $entry): string
    {
        $count = is_int($entry['count'] ?? null) ? $entry['count'] : 1;

        return sprintf(
            '<info>%s</info> %-10s %s: %s%s',
            CliOutput::value($entry['timestamp']),
            CliOutput::value($entry['type']),
            CliOutput::value(self::fileName($entry['file'], $entry['app'] ?? null)),
            CliOutput::value($entry['message']),
            $count > 1 ? " <comment>(×{$count})</comment>" : '',
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

    /**
     * An entry as logs show and evidence return it. Nodes that group repeats send `count` and
     * `first_seen`, with `timestamp` as the last time; from older nodes each entry occurred once.
     *
     * @param array<mixed> $entry
     *
     * @return array{timestamp:null|string,type:null|string,file:string,message:string,count:int,first_seen:null|string}
     */
    private static function entry(array $entry, string $file): array
    {
        $timestamp = is_string($entry['timestamp'] ?? null) ? $entry['timestamp'] : null;

        return [
            'timestamp' => $timestamp,
            'type' => is_string($entry['type'] ?? null) ? $entry['type'] : null,
            'file' => $file,
            'message' => is_string($entry['message'] ?? null) ? $entry['message'] : '',
            'count' => is_int($entry['count'] ?? null) && $entry['count'] > 0 ? $entry['count'] : 1,
            'first_seen' => is_string($entry['first_seen'] ?? null) ? $entry['first_seen'] : $timestamp,
        ];
    }

    /**
     * A grouped entry matches a window it overlaps: last seen after its start, first seen before its end.
     *
     * @param array{timestamp:null|string,type:null|string,first_seen:null|string} $entry
     */
    private static function matches(array $entry, ?string $type, ?string $since, ?string $until): bool
    {
        if ($type !== null && strtolower((string) $entry['type']) !== $type) {
            return false;
        }
        if ($since === null && $until === null) {
            return true;
        }

        return $entry['timestamp'] !== null
            && ($since === null || $entry['timestamp'] >= $since)
            && ($until === null || (string) $entry['first_seen'] <= $until);
    }
}
