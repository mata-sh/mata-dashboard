<?php

declare(strict_types=1);

namespace Mata\Services\Cron\Dto;

use Mata\Services\Monitor\Dto\AppErrorMonitoringResult;

final readonly class ServerDataFetchResult
{
    public function __construct(
        public bool $appsFetched,
        public int $applicationsSynced,
        public int $availabilityTargets,
        public AppErrorMonitoringResult $errorMonitoring,
        public bool $nodeUnreachable = false,
    ) {}

    /**
     * The health probe already proved the node is unreachable, so the remaining
     * requests for this cycle were skipped instead of waiting for their timeouts.
     */
    public static function nodeUnreachable(): self
    {
        return new self(false, 0, 0, AppErrorMonitoringResult::empty(), nodeUnreachable: true);
    }
}
