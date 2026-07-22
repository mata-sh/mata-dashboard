<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Services\Monitor\Dto\AppErrorMonitoringResult;

/** Demo monitoring is observational only and must not write events or state. */
final class MockAppErrorMonitor implements AppErrorMonitorInterface
{
    public function monitor(string $server, array $apps): AppErrorMonitoringResult
    {
        return AppErrorMonitoringResult::empty(count($apps), count($apps));
    }
}
