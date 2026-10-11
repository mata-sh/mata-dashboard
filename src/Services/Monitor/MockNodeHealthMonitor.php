<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use GuzzleHttp\Promise\PromiseInterface;
use Mata\Services\Cache\CacheType;
use Mata\Services\Monitor\Dto\NodeHealthResult;
use Mata\Services\Monitor\Dto\NodeHealthState;

/**
 * Demo node health monitor: every node is healthy, one is in maintenance.
 */
class MockNodeHealthMonitor implements NodeHealthMonitorInterface
{
    private const MAINTENANCE_SERVER = 'staging-server';

    /**
     * @param array<string,array<string,mixed>> $variants Health results keyed by server name
     */
    public function __construct(private array $variants = []) {}

    public function checkSynchronously(
        string $serverName,
        CacheType $cacheTtlType = CacheType::Health,
        bool $forceRefresh = false,
    ): NodeHealthResult {
        if (isset($this->variants[$serverName])) {
            return NodeHealthResult::fromArray($this->variants[$serverName]);
        }

        if ($serverName === self::MAINTENANCE_SERVER) {
            return NodeHealthResult::fromArray([
                'status' => NodeHealthState::Healthy->value,
                'httpCode' => 200,
                'maintenance_mode' => true,
                'checks' => ['logs_writable' => true, 'cache_writable' => true, 'config_exists' => true],
                'node_timestamp' => gmdate(DATE_ATOM),
                'latency_ms' => 42,
                'error' => null,
                'checked_at' => time(),
            ]);
        }

        return NodeHealthResult::fromArray([
            'status' => NodeHealthState::Healthy->value,
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
        CacheType $cacheTtlType = CacheType::Health,
        bool $forceRefresh = false,
    ): NodeHealthResult|PromiseInterface {
        return $this->checkSynchronously($serverName, $cacheTtlType, $forceRefresh);
    }
}
