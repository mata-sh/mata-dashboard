<?php

declare(strict_types=1);

namespace Mata\Cli;

/**
 * Event and alarm rows from the database as events list/show and alarms list print them, so
 * the commands agree on field names and wording.
 */
final class EventPresenter
{
    /**
     * The JSON fields of an event.
     *
     * @param array<string,mixed> $row an EventRepository row
     *
     * @return array<string,mixed>
     */
    public static function event(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'datetime' => $row['datetime'],
            'level' => $row['level'],
            'type' => $row['type'],
            'server' => $row['server'],
            'app' => $row['app'],
            'app_env' => $row['app_env'],
            'message' => $row['message'],
            'context' => self::decode($row['context'] ?? null),
            'evaluated_at' => $row['evaluated_at'],
        ];
    }

    /** "server/app", escaped. A missing name is null from EventRepository and '' from AlarmRepository. */
    public static function location(mixed $server, mixed $app): string
    {
        $location = CliOutput::value($server);

        return CliOutput::value($app) === '-' ? $location : $location . '/' . CliOutput::value($app);
    }

    /**
     * "email sent", with the attempts when there was more than one.
     *
     * @param array<string,mixed> $delivery an AlarmRepository delivery
     */
    public static function delivery(string $channel, array $delivery): string
    {
        $attempts = (int) ($delivery['attempts'] ?? 0);

        return CliOutput::value($channel) . ' ' . CliOutput::value($delivery['status'] ?? null)
            . ($attempts > 1 ? " after {$attempts} attempts" : '');
    }

    /** @return null|array<mixed> a JSON column, null when empty or not an object or list */
    public static function decode(mixed $json): ?array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : null;
    }
}
