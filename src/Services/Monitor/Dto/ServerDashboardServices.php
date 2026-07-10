<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Monitor\AppMonitorInterface;
use Mata\Services\Monitor\NodeHealthMonitorInterface;

/**
 * Dashboard dependencies belonging to one configured server.
 */
final readonly class ServerDashboardServices
{
    public function __construct(
        public ServerDataCollectorInterface $collector,
        public AppMonitorInterface $appMonitor,
        public NodeHealthMonitorInterface $healthMonitor,
    ) {}
}
