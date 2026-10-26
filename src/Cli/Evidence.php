<?php

declare(strict_types=1);

namespace Mata\Cli;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use Symfony\Component\Console\Input\InputInterface;

/**
 * The provenance fields of the read-only data commands (logs show, events list, …): where the
 * data came from and when it was observed, so an agent can tell an old copy from a fresh one.
 */
final class Evidence
{
    /** Durations that --since and `metrics series --range` accept, for help texts and errors. */
    public const DURATIONS = '24h, 7d, 2w, 3m (months), 1y';

    /** Upper bound of --limit; logs show --live passes it to the node per log file. */
    private const MAX_LIMIT = 999_999;

    /**
     * A node response as ServerDataCollector returns it. observed_at is when the dashboard
     * received the data, so a cached copy dates from its fetch, not from this command.
     * observed_at is in UTC whatever the time zone of $now.
     *
     * @param array<string,mixed> $response
     *
     * @return array{source:array<string,string>,observed_at:string,cache:array<string,bool|int>}
     */
    public static function fromNode(string $server, string $dataset, array $response, bool $demo, DateTimeImmutable $now): array
    {
        $source = ['type' => $demo ? 'demo' : 'node', 'server' => $server, 'dataset' => $dataset];
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

    private static function utc(DateTimeImmutable $time): string
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
     * --limit as a positive integer.
     *
     * @throws InvalidArgumentException naming the option
     */
    public static function limit(InputInterface $input): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::MAX_LIMIT],
        ]);
        if ($limit === false) {
            throw new InvalidArgumentException('--limit must be an integer from 1 to ' . self::MAX_LIMIT . '.');
        }

        return $limit;
    }

    /**
     * A text option, trimmed; null when it is missing or blank.
     */
    public static function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * --since as the start of the window, in the same units as `metrics series --range`
     * (see DURATIONS); null without the option.
     *
     * @throws InvalidArgumentException naming the option
     */
    public static function since(InputInterface $input, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $since = $input->getOption('since');
        if ($since === null) {
            return null;
        }
        $range = is_string($since) ? (new MetricQueryPolicy())->parseRange($since) : null;
        if ($range === null) {
            throw new InvalidArgumentException('--since must be a duration such as ' . self::DURATIONS . '.');
        }
        $unit = ['h' => 'PT%dH', 'd' => 'P%dD', 'w' => 'P%dW', 'm' => 'P%dM', 'y' => 'P%dY'][$range['unit']];

        return $now->sub(new DateInterval(sprintf($unit, $range['value'])));
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
