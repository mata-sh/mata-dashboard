<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheEntry;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cron\CronHeartbeatReader;
use Mata\Services\Status\FleetStatusReader;
use Mata\Services\Status\StatusLevel;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class FleetStatusReaderTest extends TestCase
{
    /** When servers_data_fetch dies the last cached result stays "healthy"; status must not report ok. */
    public function testHealthyResultFromAStoppedCronIsUnknown(): void
    {
        $now = 1_800_000_000;
        $cache = $this->createStub(CacheStoreInterface::class);
        $cache->method('getStale')->willReturn(new CacheEntry(['status' => 'healthy', 'checked_at' => $now - 7200], $now - 7200));
        $reader = new FleetStatusReader($cache, new ApiCacheKeyFactory(), new CronHeartbeatReader(sys_get_temp_dir() . '/mata-no-cron', sys_get_temp_dir()), healthTtlSeconds: 240);

        $status = $reader->read(['web-01' => ['title' => 'Web', 'api_url' => 'https://node.example.com/']], $now);

        $this->assertSame(StatusLevel::Unknown, $status->level());
        $this->assertSame(3, $status->level()->value);
    }
}
