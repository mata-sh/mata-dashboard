<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\ServerMetrics;

use DateTimeImmutable;
use DateTimeZone;
use Mata\Config\Monitoring\Section\ServerConfig;
use Mata\Services\ServerMetrics\ServerMetricSnapshotFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\ServerMetrics\ServerMetricSnapshotFactory
 *
 * @internal
 */
final class ServerMetricSnapshotFactoryTest extends TestCase
{
    public function testDatesCachedPayloadByItsAgeAndComputesUsagePercentages(): void
    {
        $now = new DateTimeImmutable('2026-10-31 12:00:00', new DateTimeZone('UTC'));
        $snapshot = (new ServerMetricSnapshotFactory())->fromHealthPayload([
            'data' => [
                'cpu_usage' => '12.5',
                'ram_used' => 3000,
                'ram_capacity' => 8000,
                'disk_space_used' => 50,
                'disk_space_capacity' => 0,
                'total_system_filecount' => 123456,
                'processes' => ['total' => 210, 'php' => 12, 'sql' => 4],
                'uptime' => 86400,
            ],
            'cache' => ['age' => 90, 'ttl' => 300, 'from_cache' => true],
        ], $now);

        self::assertSame('2026-10-31 11:58:30', $snapshot->collectedAt->format('Y-m-d H:i:s'));
        self::assertSame($now, $snapshot->ingestedAt);
        self::assertSame(12.5, $snapshot->cpuUsage);
        self::assertSame(37.5, $snapshot->ramUsagePercent);
        self::assertNull($snapshot->diskUsagePercent);
        self::assertSame(123456, $snapshot->totalFileCount);
        self::assertSame(210, $snapshot->processCount);
        self::assertSame(12, $snapshot->phpProcessCount);
        self::assertSame(86400, $snapshot->serverUptime);
        self::assertNull($snapshot->phpVersion);
    }

    public function testCollectorIdentityFallsBackToTheCronJob(): void
    {
        $factory = new ServerMetricSnapshotFactory();

        $fromNode = $factory->collectorIdentity(['data' => ['collector' => ['name' => '', 'version' => '1.4.0']]], ServerConfig::fromArray('shop', []));
        self::assertSame('mata-node', $fromNode->name);
        self::assertSame('1.4.0', $fromNode->version);

        $fromCron = $factory->collectorIdentity(['data' => []], ServerConfig::fromArray('shop', ['title' => 'Shop']));
        self::assertSame('collect_server_metrics_cron', $fromCron->name);
        self::assertNull($fromCron->version);
        self::assertNull($fromCron->contact);
    }
}
