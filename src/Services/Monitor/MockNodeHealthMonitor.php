<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use GuzzleHttp\Promise\PromiseInterface;
use Mata\Constants\CacheTypes;

/**
 * Demo node health monitor with stable, varied health states.
 */
class MockNodeHealthMonitor implements NodeHealthMonitorInterface
{
    /**
     * @param array<string,array<string,mixed>> $variants Health results keyed by server name
     */
    public function __construct(private array $variants = []) {}

    public function checkOrWait(
        string $serverName,
        string $cacheType = CacheTypes::HEALTH,
        bool $forceRefresh = false,
    ): array {
        if (isset($this->variants[$serverName])) {
            return $this->withState($this->variants[$serverName]);
        }

        $variant = crc32($serverName) % 3;
        if ($variant === 1) {
            return $this->withState([
                'status' => 'healthy',
                'httpCode' => 200,
                'maintenance_mode' => true,
                'checks' => ['logs_writable' => true, 'cache_writable' => true, 'config_exists' => true],
                'node_timestamp' => gmdate(DATE_ATOM),
                'latency_ms' => 42,
                'error' => null,
                'checked_at' => time(),
            ]);
        }

        if ($variant === 2) {
            return $this->withState([
                'status' => 'unreachable',
                'httpCode' => null,
                'maintenance_mode' => null,
                'checks' => null,
                'node_timestamp' => null,
                'latency_ms' => null,
                'error' => 'Mock health check timeout',
                'checked_at' => time(),
            ]);
        }

        return $this->withState([
            'status' => 'healthy',
            'httpCode' => 200,
            'maintenance_mode' => false,
            'checks' => ['logs_writable' => true, 'cache_writable' => true, 'config_exists' => true],
            'node_timestamp' => gmdate(DATE_ATOM),
            'latency_ms' => 25,
            'error' => null,
            'checked_at' => time(),
        ]);
    }

    public function checkAsync(
        string $serverName,
        string $cacheType = CacheTypes::HEALTH,
        bool $forceRefresh = false,
    ): array|PromiseInterface {
        return $this->checkOrWait($serverName, $cacheType, $forceRefresh);
    }

    /**
     * @param array<string,mixed> $result
     *
     * @return array<string,mixed>
     */
    private function withState(array $result): array
    {
        $result['state'] = NodeHealthStateResolver::resolve($result);

        return $result;
    }
}
