<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

interface MonitoringConfigLoaderInterface
{
    public function key(): string;

    /** @return array<string,mixed> */
    public function load(string $configRoot): array;

    /** @return array<string,mixed> */
    public function getDefaultConfig(): array;

    public function shouldFallback(): bool;
}
