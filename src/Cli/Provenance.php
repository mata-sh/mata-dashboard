<?php

declare(strict_types=1);

namespace Mata\Cli;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The provenance fields of the read-only data commands (logs show, events list, …): where the
 * data came from and when it was observed, so an agent can tell an old copy from a fresh one.
 */
final class Provenance
{
    /**
     * A node response as ServerDataCollector returns it. observed_at is when the dashboard
     * received the data, so a cached copy dates from its fetch, not from this command.
     * observed_at is in UTC whatever the time zone of $now.
     *
     * @param array<string,mixed> $response
     *
     * @return array{source:array<string,string>,observed_at:string,cache:array<string,bool|int>}
     */
    public static function fromNode(string $server, string $datasetName, array $response, bool $demo, DateTimeImmutable $now): array
    {
        $source = ['type' => $demo ? 'demo' : 'node', 'server' => $server, 'dataset' => $datasetName];
        $fromCache = ($response['from_cache'] ?? false) === true;
        $age = $fromCache ? self::intField($response, 'cache_age') : 0;

        return [
            'source' => $source,
            'observed_at' => self::utc($now->modify(sprintf('%+d seconds', -$age))),
            'cache' => [
                'from_cache' => $fromCache,
                'age_seconds' => $age,
                'ttl_seconds' => self::intField($response, 'cache_ttl'),
                'stale' => ($response['stale_cache'] ?? false) === true,
            ],
        ];
    }

    /** @param array<string,mixed> $response */
    private static function intField(array $response, string $key): int
    {
        $value = $response[$key] ?? null;

        return is_int($value) ? $value : 0;
    }

    /** e.g. 2026-10-25T08:55:02+00:00 */
    public static function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    /**
     * Data read from the dashboard database; in demo mode generated instead.
     *
     * @return array{source:array<string,string>,observed_at:string}
     */
    public static function fromDatabase(string $table, DateTimeImmutable $now, bool $demo = false): array
    {
        return [
            'source' => ['type' => $demo ? 'demo' : 'database', 'table' => $table],
            'observed_at' => self::utc($now),
        ];
    }

    /**
     * "cached 3m ago, past its TTL" for the footer of human output.
     *
     * @param array<string,mixed> $cache
     */
    public static function describeCache(array $cache): string
    {
        if (($cache['from_cache'] ?? false) !== true) {
            return 'fetched now';
        }

        $age = (int) ($cache['age_seconds'] ?? 0);
        $ago = match (true) {
            $age < 120 => "{$age}s",
            $age < 7200 => intdiv($age, 60) . 'm',
            $age < 172800 => intdiv($age, 3600) . 'h',
            default => intdiv($age, 86400) . 'd',
        };

        $stale = ($cache['stale'] ?? false) === true;

        return "cached {$ago} ago" . ($stale ? ', past its TTL' : '');
    }
}
