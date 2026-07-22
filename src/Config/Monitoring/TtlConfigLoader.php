<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

use Mata\Services\Event\EventRecorderService;
use RuntimeException;

final class TtlConfigLoader extends AbstractJsonConfigLoader implements MonitoringConfigLoaderInterface
{
    public function __construct(
        private EventRecorderService $eventRecorder,
        private bool $fallbackOnFailure = true,
    ) {}

    public function key(): string
    {
        return 'ttl';
    }

    public function load(string $configRoot): array
    {
        return $this->loadTtlConfig(rtrim($configRoot, '/') . '/ttl.json');
    }

    /** @return array<string,mixed> */
    public function loadTtlConfig(string $filePath): array
    {
        try {
            $config = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException('ttl.json invalid or empty', previous: $exception);
        }
        if ($config === []) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException('ttl.json invalid or empty');
        }

        return array_merge($this->getDefaultConfig(), $config);
    }

    public function shouldFallback(): bool
    {
        return $this->fallbackOnFailure;
    }

    /** @return array<string,int> */
    public function getDefaultConfig(): array
    {
        return [
            'ping' => 360,
            'servers' => 720,
            'apps' => 60,
            'app' => 30,
            'serverData' => 10,
            'serverInfo' => 86400,
            'databases' => 10,
            'libraries' => 600,
            'appLogs' => 30,
            'serverProcesses' => 30,
            'readme' => 300,
            'errorlog' => 60,
            'serverMetricsChart' => 60,
            'audit' => 86400,
        ];
    }

    private function reportLoadFailure(string $filePath): void
    {
        $this->eventRecorder->recordEvent(
            type: 'ttl_config_not_found',
            message: "Could not load {$filePath} -> fallback to Default Values",
            level: 'warning',
        );
    }
}
