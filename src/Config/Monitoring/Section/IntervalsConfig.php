<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use InvalidArgumentException;
use Mata\Config\ConfigValueValidator;

/** Event and alarm cooldowns keyed by operator-defined event type. */
final readonly class IntervalsConfig
{
    /**
     * @param array<string,int> $eventMinimumMinutes
     * @param array<string,int> $alarmMinimumMinutes
     */
    private function __construct(
        public array $eventMinimumMinutes,
        public array $alarmMinimumMinutes,
    ) {}

    public static function empty(): self
    {
        return new self(eventMinimumMinutes: [], alarmMinimumMinutes: []);
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        foreach (['events', 'alarms'] as $section) {
            if (!array_key_exists($section, $raw)) {
                continue;
            }
            self::requireMap($raw[$section], "intervals.{$section}");
            $map = $section === 'events' ? 'min_event_interval_minutes' : 'min_alarm_interval_minutes';
            if (is_array($raw[$section]) && array_key_exists($map, $raw[$section])) {
                self::requireMap($raw[$section][$map], "intervals.{$section}.{$map}");
            }
        }

        $config = array_replace_recursive(self::defaults(), $raw);
        $events = $config['events'] ?? null;
        $alarms = $config['alarms'] ?? null;
        if (!is_array($events)) {
            throw new InvalidArgumentException('intervals.events must be an object.');
        }
        if (!is_array($alarms)) {
            throw new InvalidArgumentException('intervals.alarms must be an object.');
        }

        return new self(
            eventMinimumMinutes: self::intervalMap(
                $events['min_event_interval_minutes'] ?? null,
                'intervals.events.min_event_interval_minutes',
            ),
            alarmMinimumMinutes: self::intervalMap(
                $alarms['min_alarm_interval_minutes'] ?? null,
                'intervals.alarms.min_alarm_interval_minutes',
            ),
        );
    }

    public static function default(): self
    {
        return self::fromArray([]);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'events' => ['min_event_interval_minutes' => $this->eventMinimumMinutes],
            'alarms' => ['min_alarm_interval_minutes' => $this->alarmMinimumMinutes],
        ];
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'events' => ['min_event_interval_minutes' => [
                '_default' => 60,
                'app_unreachable' => 0,
                'server_apps_transport_unreachable' => 0,
                'cronjobs_failing' => 30,
                'filecount_too_large' => 72,
                'logfile_too_large' => 12,
                'database_connections_too_high' => 72,
                'remaining_diskspace_too_low' => 60,
                'server_metrics.ingestion_run_empty' => 5,
                'server_metrics.ingestion_stalled' => 15,
                'cpu_usage_too_high' => 30,
                'ram_usage_too_high' => 30,
                'fatal_errors_occurring' => 0,
            ]],
            'alarms' => ['min_alarm_interval_minutes' => [
                '_default' => 1440,
                'app_unreachable' => 60,
                'server_apps_transport_unreachable' => 60,
                'cronjobs_failing' => 360,
                'filecount_too_large' => 60,
                'logfile_too_large' => 720,
                'database_connections_too_high' => 60,
                'remaining_diskspace_too_low' => 180,
                'server_metrics.ingestion_run_empty' => 30,
                'server_metrics.ingestion_stalled' => 60,
                'cpu_usage_too_high' => 180,
                'ram_usage_too_high' => 180,
                'fatal_errors_occurring' => 1440,
            ]],
        ];
    }

    private static function requireMap(mixed $value, string $path): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("{$path} must be an object.");
        }
    }

    /** @return array<string,int> */
    private static function intervalMap(mixed $raw, string $path): array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new InvalidArgumentException("{$path} must be an object.");
        }

        $intervals = [];
        foreach ($raw as $type => $minutes) {
            $type = (string) $type;
            $intervals[$type] = ConfigValueValidator::integer($minutes, "{$path}.{$type}", minimum: 0);
        }

        return $intervals;
    }
}
