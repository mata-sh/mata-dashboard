<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/** Resolves sparse app monitoring settings; invalid values log and default to enabled. */
final readonly class AppMonitoringSettings
{
    /** @var list<string> The single source of truth for recognized setting names. */
    public const SETTINGS = ['loganalysis', 'logsize', 'sessions', 'reachability', 'alarms', 'events', 'libraryaudit'];

    public function __construct(
        public bool $loganalysis = true,
        public bool $logsize = true,
        public bool $sessions = true,
        public bool $reachability = true,
        public bool $alarms = true,
        public bool $events = true,
        public bool $libraryaudit = true,
    ) {}

    /** @param array<string,mixed> $appData */
    public static function fromAppPayload(array $appData, ?LoggerInterface $logger = null): self
    {
        if (!array_key_exists('monitoring', $appData)) {
            return self::allEnabled();
        }

        $monitoring = $appData['monitoring'];
        if (!is_array($monitoring)) {
            $logger?->warning('Ignoring non-object application monitoring block', [
                'type' => get_debug_type($monitoring),
            ]);

            return self::allEnabled();
        }

        $resolved = array_fill_keys(self::SETTINGS, true);
        foreach ($monitoring as $setting => $value) {
            if (!is_string($setting) || !in_array($setting, self::SETTINGS, true)) {
                $logger?->warning('Ignoring unknown application monitoring setting', [
                    'setting' => is_string($setting) ? $setting : get_debug_type($setting),
                ]);

                continue;
            }

            if (!is_bool($value)) {
                $logger?->warning('Ignoring non-boolean application monitoring setting', [
                    'setting' => $setting,
                    'type' => get_debug_type($value),
                ]);

                continue;
            }

            $resolved[$setting] = $value;
        }

        return new self(
            loganalysis: $resolved['loganalysis'],
            logsize: $resolved['logsize'],
            sessions: $resolved['sessions'],
            reachability: $resolved['reachability'],
            alarms: $resolved['alarms'],
            events: $resolved['events'],
            libraryaudit: $resolved['libraryaudit'],
        );
    }

    public static function allEnabled(): self
    {
        return new self();
    }

    /** @throws InvalidArgumentException When the setting name is not one of self::SETTINGS */
    public function isEnabled(string $setting): bool
    {
        return match ($setting) {
            'loganalysis' => $this->loganalysis,
            'logsize' => $this->logsize,
            'sessions' => $this->sessions,
            'reachability' => $this->reachability,
            'alarms' => $this->alarms,
            'events' => $this->events,
            'libraryaudit' => $this->libraryaudit,
            default => throw new InvalidArgumentException("Unknown monitoring setting: {$setting}"),
        };
    }
}
