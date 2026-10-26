<?php

declare(strict_types=1);

namespace Mata\Cli;

use Symfony\Component\Console\Output\OutputInterface;

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
    public static function fields(array $row): array
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
            'context' => self::decodeJsonColumn($row['context'] ?? null),
            'evaluated_at' => $row['evaluated_at'],
        ];
    }

    /**
     * The JSON fields of an event with its alarm decision, as events show and evidence report it.
     *
     * @param array<string,mixed> $row an EventRepository::find() row
     *
     * @return array<string,mixed>
     */
    public static function detailFields(array $row): array
    {
        return self::fields($row) + ['alarm_decision' => self::decodeJsonColumn($row['alarm_decision'])];
    }

    /**
     * The alarm an event raised, without the event fields it repeats; null when there is none.
     *
     * @param null|array<string,mixed> $alarm an AlarmRepository::getLatest() row
     *
     * @return null|array<string,mixed>
     */
    public static function alarmFields(?array $alarm): ?array
    {
        if ($alarm === null) {
            return null;
        }

        return [
            'id' => $alarm['id'],
            'created_at' => $alarm['created_at'],
            'acknowledged_at' => $alarm['acknowledged_at'],
            'acknowledged_by' => $alarm['acknowledged_by'],
            'trigger_evidence' => $alarm['trigger_evidence'],
            'deliveries' => $alarm['deliveries'],
        ];
    }

    /**
     * One line per event, as events list prints them.
     *
     * @param array<string,mixed> $event from fields()
     */
    public static function line(array $event): string
    {
        return sprintf(
            '<info>%s</info> %-8s %s (%s): %s',
            CliOutput::value($event['datetime']),
            CliOutput::value($event['level']),
            CliOutput::value($event['type']),
            CliOutput::location($event['server'], $event['app']),
            CliOutput::value($event['message']),
        );
    }

    /**
     * An event in full, as events show prints it, with the alarm it raised.
     *
     * @param array<string,mixed>      $event from fields(), with `alarm_decision`
     * @param null|array<string,mixed> $alarm from alarmFields()
     */
    public static function writeDetail(OutputInterface $output, array $event, ?array $alarm): void
    {
        $output->writeln(sprintf(
            '<info>%s</info> %s %s (%s)',
            CliOutput::value($event['datetime']),
            CliOutput::value($event['level']),
            CliOutput::value($event['type']),
            CliOutput::location($event['server'], $event['app']),
        ));
        $output->writeln(CliOutput::value($event['message']));
        foreach (['context', 'alarm_decision'] as $field) {
            if ($event[$field] !== null) {
                $json = json_encode($event[$field], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $output->writeln("{$field}: " . CliOutput::value($json));
            }
        }
        if ($event['evaluated_at'] === null) {
            $output->writeln('Not evaluated for alarms yet.');
        }
        if ($alarm === null) {
            $output->writeln('No alarm.');

            return;
        }
        $acknowledged = $alarm['acknowledged_at'] === null
            ? 'unacknowledged'
            : sprintf('acknowledged by %s at %s', CliOutput::value($alarm['acknowledged_by']), CliOutput::value($alarm['acknowledged_at']));
        $output->writeln(sprintf('alarm %d at %s, %s', $alarm['id'], CliOutput::value($alarm['created_at']), $acknowledged));
        foreach ((array) $alarm['deliveries'] as $channel => $delivery) {
            $processed = CliOutput::value($delivery['processed_at'] ?? null);
            $output->writeln('    ' . self::deliveryLine((string) $channel, $delivery) . ", processed {$processed}");
        }
    }

    /**
     * "email sent", with the attempts when there was more than one.
     *
     * @param array<string,mixed> $delivery an AlarmRepository delivery
     */
    public static function deliveryLine(string $channel, array $delivery): string
    {
        $attempts = (int) ($delivery['attempts'] ?? 0);

        return CliOutput::value($channel) . ' ' . CliOutput::value($delivery['status'] ?? null)
            . ($attempts > 1 ? " after {$attempts} attempts" : '');
    }

    /** @return null|array<mixed> a JSON column, null when empty or not an object or list */
    private static function decodeJsonColumn(mixed $json): ?array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : null;
    }
}
