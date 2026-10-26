<?php

declare(strict_types=1);

namespace Mata\Cli;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Mata\Database\DatabaseId;
use Mata\Http\Security\RouteParameter;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use Symfony\Component\Console\Input\InputInterface;

/** Command-line arguments and options shared by the commands, parsed with errors that name the option. */
final class CliInput
{
    /** Durations that --since, evidence --around and `metrics series --range` accept, for help texts and errors. */
    public const DURATIONS = '24h, 7d, 2w, 3m (months), 1y';

    /** Upper bound of --limit; logs show --live passes it to the node per log file. */
    private const MAX_LIMIT = 999_999;

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
     * A database ID from the command line.
     *
     * @param string $name names the value in the error, e.g. 'The event ID' or '--event'
     *
     * @throws InvalidArgumentException naming the value
     */
    public static function id(string $value, string $name): int
    {
        if (!DatabaseId::isValid($value)) {
            throw new InvalidArgumentException("{$name} must be a positive integer.");
        }

        return (int) $value;
    }

    /**
     * An application name from the command line, checked because it becomes a node URL path segment.
     *
     * @throws InvalidArgumentException for a name outside the route charset or starting with a dot
     */
    public static function appName(string $value): string
    {
        RouteParameter::assertSafe($value, "Invalid application name '{$value}'.");
        if (str_starts_with($value, '.')) {
            throw new InvalidArgumentException("Invalid application name '{$value}'.");
        }

        return $value;
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
     * --since as the start of the window; null without the option.
     *
     * @throws InvalidArgumentException naming the option
     */
    public static function since(InputInterface $input, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $since = self::duration($input, 'since');

        return $since === null ? null : $now->sub($since);
    }

    /**
     * A duration option in the same units as `metrics series --range` (see DURATIONS); null
     * without the option.
     *
     * @throws InvalidArgumentException naming the option
     */
    public static function duration(InputInterface $input, string $name): ?DateInterval
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }
        $range = is_string($value) ? (new MetricQueryPolicy())->parseRange($value) : null;
        if ($range === null) {
            throw new InvalidArgumentException("--{$name} must be a duration such as " . self::DURATIONS . '.');
        }
        $unit = ['h' => 'PT%dH', 'd' => 'P%dD', 'w' => 'P%dW', 'm' => 'P%dM', 'y' => 'P%dY'][$range['unit']];

        return new DateInterval(sprintf($unit, $range['value']));
    }
}
