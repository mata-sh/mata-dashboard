<?php

declare(strict_types=1);

namespace Watcher\Services\Foundation;

use Watcher\Config\Dto\AlertLimitsConfig;

/**
 * In-memory helper to enforce per-server/channel alert limits during dispatch.
 */
final class AlertRateLimiter
{
    private AlertLimitsConfig $limitsConfig;

    /**
     * @var array<string,array<string,array{minute:int,hour:int,day:int}>>
     */
    private array $counters = [];

    public function __construct(AlertLimitsConfig $limitsConfig)
    {
        $this->limitsConfig = $limitsConfig;
    }

    /**
     * Preload counters with historical dispatch counts.
     *
     * @param array<string,array<string,array{minute:int,hour:int,day:int}>> $counts
     */
    public function preload(array $counts): void
    {
        $this->counters = $counts;
    }

    public function allow(string $serverKey, string $channel): bool
    {
        $serverKey = strtolower($serverKey);
        $channel = strtolower($channel);

        $counters = $this->counters[$serverKey][$channel] ?? ['minute' => 0, 'hour' => 0, 'day' => 0];
        $limit = $this->limitsConfig->resolve($serverKey, $channel);

        if ($limit->getPerMinute() > 0 && $counters['minute'] >= $limit->getPerMinute()) {
            return false;
        }

        if ($limit->getPerHour() > 0 && $counters['hour'] >= $limit->getPerHour()) {
            return false;
        }

        if ($limit->getPerDay() > 0 && $counters['day'] >= $limit->getPerDay()) {
            return false;
        }

        return true;
    }

    public function recordSend(string $serverKey, string $channel): void
    {
        $serverKey = strtolower($serverKey);
        $channel = strtolower($channel);

        $this->ensureCounter($serverKey, $channel);

        ++$this->counters[$serverKey][$channel]['minute'];
        ++$this->counters[$serverKey][$channel]['hour'];
        ++$this->counters[$serverKey][$channel]['day'];
    }

    /**
     * @return array<string,array<string,array{minute:int,hour:int,day:int}>>
     */
    public function getCounters(): array
    {
        return $this->counters;
    }

    private function ensureCounter(string $serverKey, string $channel): void
    {
        if (!isset($this->counters[$serverKey][$channel])) {
            $this->counters[$serverKey][$channel] = ['minute' => 0, 'hour' => 0, 'day' => 0];
        }
    }
}
