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

    /**
     * Fallback TTLs, also merged under ttl.json so a partial config still resolves.
     *
     * Must cover every CacheType::ttlConfigKeys() entry: a missing key resolves to 0,
     * which disables caching for that type instead of falling back to a sane value.
     * TtlConfigKeysAreAlignedTest enforces that.
     *
     * @return array<string,int>
     */
    public function getDefaultConfig(): array
    {
        return [
            'serverData' => 10,
            'serverInfo' => 86400,
            'serverProcesses' => 30,
            'serverMetricsChart' => 60,
            'apps' => 60,
            'libraries' => 600,
            'appLogs' => 30,
            'sessions' => 900,
            'ping' => 360,
            'health' => 240,
            'readme' => 300,
            'databases' => 10,
            'cronjobs' => 300,
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
