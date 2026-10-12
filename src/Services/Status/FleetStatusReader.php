<?php

declare(strict_types=1);

namespace Mata\Services\Status;

use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cron\CronHeartbeatReader;
use Mata\Services\Cron\Dto\CronJobState;
use Mata\Services\Monitor\Dto\NodeHealthResult;
use Mata\Services\Monitor\Dto\NodeHealthState;
use Mata\Services\Status\Dto\FleetStatus;

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

    /** @param array<string,mixed> $servers enabled servers from ServersConfig, keyed by name */
    public function read(array $servers, int $now): FleetStatus
    {
        $serverRows = [];
        foreach ($servers as $name => $config) {
            $serverRows[] = $this->serverStatus((string) $name, is_array($config) ? $config : [], $now);
        }

        $cronRows = [];
        foreach ($this->cron->read($now) as $job) {
            $cronRows[] = [
                'job' => $job->job,
                'state' => $job->state->value,
                'level' => match ($job->state) {
                    CronJobState::Overdue => StatusLevel::Critical,
                    CronJobState::Never => StatusLevel::Warning,
                    default => StatusLevel::Ok,
                },
                'last_run' => $job->lastRun,
                'interval_seconds' => $job->interval,
            ];
        }

        return new FleetStatus($serverRows, $cronRows, $now);
    }

    /**
     * @param array<string,mixed> $config
     *
     * @return array{name:string,title:string,health:string,level:StatusLevel,checked_at:?int,age_seconds:?int,error:?string}
     */
    private function serverStatus(string $name, array $config, int $now): array
    {
        $apiUrl = is_string($config['api_url'] ?? null) ? $config['api_url'] : '';
        $title = is_string($config['title'] ?? null) ? $config['title'] : $name;
        $entry = $this->cache->getStale($this->keys->createApiRequestKey($apiUrl, 'GET', 'health'), -1);
        if ($entry === null) {
            return ['name' => $name, 'title' => $title, 'health' => 'unknown', 'level' => StatusLevel::Unknown, 'checked_at' => null, 'age_seconds' => null, 'error' => 'No cached health result; has servers_data_fetch run?'];
        }

        $health = NodeHealthResult::fromArray($entry->value);
        $age = max(0, $now - $entry->storedAt);
        $stale = $age > 2 * $this->healthTtlSeconds + self::STALE_GRACE_SECONDS;
        $level = $stale ? StatusLevel::Unknown : match ($health->state) {
            NodeHealthState::Healthy => StatusLevel::Ok,
            NodeHealthState::Maintenance => StatusLevel::Warning,
            NodeHealthState::Unknown => StatusLevel::Unknown,
            default => StatusLevel::Critical,
        };

        return [
            'name' => $name,
            'title' => $title,
            'health' => $health->state->value,
            'level' => $level,
            'checked_at' => $entry->storedAt,
            'age_seconds' => $age,
            'error' => $stale ? "Health result is {$age}s old; servers_data_fetch may have stopped" : $health->error,
        ];
    }
}
