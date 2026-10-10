<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

use Mata\Services\Collect\ServerDataCollectorInterface;

/** Outcome of building monitoring services for every configured server. */
final readonly class ServerMonitoringResult
{
    /**
     * @param array<string,ServerMonitoringServices> $servicesByServer keyed by server name
     * @param array<string,string>                   $failures         server name => reason
     */
    public function __construct(
        public array $servicesByServer,
        public array $failures,
    ) {}

    /** @return array<string,ServerDataCollectorInterface> keyed by server name */
    public function collectors(): array
    {
        return array_map(
            static fn (ServerMonitoringServices $services): ServerDataCollectorInterface => $services->collector,
            $this->servicesByServer,
        );
    }
}
