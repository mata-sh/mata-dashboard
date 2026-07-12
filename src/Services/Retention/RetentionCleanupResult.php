<?php

declare(strict_types=1);

namespace Mata\Services\Retention;

/**
 * Outcome of one bounded retention cleanup run.
 *
 * Deleted counts are totals across all delete batches. Alarm counts exclude
 * alarm_deliveries removed by database cascades.
 */
final readonly class RetentionCleanupResult
{
    /**
     * @param array<string,int>                                $metricsDeleted Deleted rows by metric table
     * @param array<int,array{category:string,message:string}> $failures       Cleanup failures by category/table
     * @param array<string,string>                             $cutoffs        Applied category/tier cutoffs
     */
    public function __construct(
        public int $alarmsDeleted,
        public int $eventsDeleted,
        public array $metricsDeleted,
        public array $failures,
        public array $cutoffs
    ) {}

    /** @return array<string,int> */
    public function deletedCounts(): array
    {
        return [
            'alarms' => $this->alarmsDeleted,
            'events' => $this->eventsDeleted,
            ...$this->metricsDeleted,
        ];
    }

    public function metricDeleted(string $table): int
    {
        return $this->metricsDeleted[$table] ?? 0;
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }
}
