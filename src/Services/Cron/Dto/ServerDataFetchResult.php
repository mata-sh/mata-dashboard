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
    ) {}
}
