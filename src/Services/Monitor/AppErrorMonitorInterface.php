<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Services\Monitor\Dto\AppErrorMonitoringResult;

interface AppErrorMonitorInterface
{
    /** @param array<string,array<string,mixed>> $apps */
    public function monitor(string $server, array $apps): AppErrorMonitoringResult;
}
