<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

/** Outcome of building monitoring services for every configured server. */
final readonly class ServerMonitoringResult
{
    /**
     * @param array<string,ServerMonitoringServices> $servicesByServer keyed by server name
     * @param array<string,string>                   $failures server name => reason
     */
    public function __construct(
        public array $servicesByServer,
        public array $failures,
    ) {}
}
