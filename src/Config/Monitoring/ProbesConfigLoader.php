<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

use Mata\Services\Event\EventRecorderService;
use Mata\Services\Monitor\Dto\ProbePolicy;
use RuntimeException;

final class ProbesConfigLoader extends AbstractJsonConfigLoader implements MonitoringConfigLoaderInterface
{
    public function __construct(
        private EventRecorderService $eventRecorder,
        private bool $fallbackOnFailure = true,
    ) {}

    public function key(): string
    {
        return 'probes';
    }

    public function load(string $configRoot): array
    {
        return $this->loadProbesConfig(rtrim($configRoot, '/') . '/probes.json');
    }

    /** @return array<string,mixed> */
    public function loadProbesConfig(string $filePath): array
    {
        try {
            $config = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException('probes.json invalid or empty', previous: $exception);
        }
        if ($config === []) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException('probes.json invalid or empty');
        }

        return ProbePolicy::fromArray(array_merge($this->getDefaultConfig(), $config))->toConfigArray();
    }

    public function shouldFallback(): bool
    {
        return $this->fallbackOnFailure;
    }

    /** @return array<string,mixed> */
    public function getDefaultConfig(): array
    {
        return ProbePolicy::default()->toConfigArray();
    }

    private function reportLoadFailure(string $filePath): void
    {
        $this->eventRecorder->recordEvent(
            type: 'probes_config_not_found',
            message: "Could not load {$filePath} -> fallback to Default Values",
            level: 'warning',
        );
    }
}
