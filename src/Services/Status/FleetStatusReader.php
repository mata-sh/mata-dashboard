<?php

declare(strict_types=1);

namespace Mata\Services\Status;

use Mata\Config\Monitoring\Loader\Dto\ConfigLoadProblem;
use Mata\Config\Monitoring\Section\ServerConfig;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cron\CronHeartbeatReader;
use Mata\Services\Monitor\Dto\NodeHealthResult;
use Mata\Services\Monitor\Dto\NodeHealthState;
use Mata\Services\Status\Dto\FleetStatus;
use Mata\Services\Status\Dto\ServerStatus;

/**
 * Fleet status for scripts and checks, read only from what cron already wrote: the node health
 * cache warmed by servers_data_fetch and the cron heartbeats. It never contacts a node, records
 * an event, or writes the cache, so it is safe to run from monitoring systems at any rate.
 *
 * A health entry older than twice its TTL (plus a minute) is unknown, so a dead cron is never ok.
 */
final class FleetStatusReader
{
    private const STALE_GRACE_SECONDS = 60;

    public function __construct(
        private CacheStoreInterface $cache,
        private ApiCacheKeyFactory $keys,
        private CronHeartbeatReader $cron,
        private int $healthTtlSeconds,
    ) {}

    /**
     * @param array<string,ServerConfig> $servers        enabled servers from ServersConfig, keyed by name
     * @param list<ConfigLoadProblem>    $configProblems found while loading them; they count towards the level
     */
    public function read(array $servers, int $now, array $configProblems = []): FleetStatus
    {
        $serverStatuses = [];
        foreach ($servers as $name => $config) {
            $serverStatuses[] = $this->serverStatus((string) $name, $config, $now);
        }

        return new FleetStatus(
            servers: $serverStatuses,
            cron: $this->cron->read($now),
            generatedAt: $now,
            configProblems: $configProblems,
        );
    }

    private function serverStatus(string $name, ServerConfig $config, int $now): ServerStatus
    {
        $title = $config->title;
        $entry = $this->cache->getStale($this->keys->createApiRequestKey($config->apiUrl, 'GET', 'health'), -1);
        if ($entry === null) {
            return new ServerStatus(
                name: $name,
                title: $title,
                health: NodeHealthState::Unknown,
                level: StatusLevel::Unknown,
                error: 'No cached health result; has servers_data_fetch run?',
            );
        }

        $health = NodeHealthResult::fromArray($entry->value);
        $age = max(0, $now - $entry->storedAt);
        $stale = $age > 2 * $this->healthTtlSeconds + self::STALE_GRACE_SECONDS;

        return new ServerStatus(
            name: $name,
            title: $title,
            health: $health->state,
            level: $stale ? StatusLevel::Unknown : self::healthLevel($health->state),
            checkedAt: $entry->storedAt,
            ageSeconds: $age,
            error: $stale ? "Health result is {$age}s old; servers_data_fetch may have stopped" : $health->error,
        );
    }

    private static function healthLevel(NodeHealthState $state): StatusLevel
    {
        return match ($state) {
            NodeHealthState::Healthy => StatusLevel::Ok,
            NodeHealthState::Maintenance => StatusLevel::Warning,
            NodeHealthState::Unknown => StatusLevel::Unknown,
            default => StatusLevel::Error,
        };
    }
}
