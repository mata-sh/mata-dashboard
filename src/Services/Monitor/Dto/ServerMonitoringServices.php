<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Monitor\AppMonitorInterface;
use Mata\Services\Monitor\NodeHealthMonitorInterface;

/**
 * Monitoring dependencies belonging to one configured server.
 */
final readonly class ServerMonitoringServices
{
    public function __construct(
        public ServerDataCollectorInterface $collector,
        public AppMonitorInterface $appMonitor,
        public NodeHealthMonitorInterface $healthMonitor,
    ) {}
}
