<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Config\Monitoring\Section\TtlConfig;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Collect\MockServerDataCollector;
use Mata\Services\Collect\NodeResponseNormalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Collect\MockServerDataCollector
 *
 * @internal
 */
final class MockServerDataCollectorTest extends TestCase
{
    public function testDemoDatabasesMatchTheServerDatabasesResponseContract(): void
    {
        $logger = new NullLogger();
        $collector = new MockServerDataCollector(
            $logger,
            new CacheTtlResolver(new TtlConfig([])),
            new NodeResponseNormalizer($logger),
            'database-server',
            ['url' => 'https://database-server.example.test'],
        );

        $response = $collector->getServerDatabases();

        $this->assertNull($response['error']);
        $this->assertIsArray($response['result']);
        $this->assertIsArray($response['result']['databases']);

        $keys = [
            'database',
            'configured_database',
            'host',
            'port',
            'description',
            'max_connections',
            'active_threads',
            'active_processes',
            'process_list',
            'connection_error',
        ];

        foreach ($response['result']['databases'] as $database) {
            $this->assertIsArray($database);

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $database);
            }

            $this->assertIsString($database['connection_error']);
        }
    }

    public function testProductionWebAlwaysHasAnAlarmLevelCpuUsage(): void
    {
        $logger = new NullLogger();
        $collector = new MockServerDataCollector(
            $logger,
            new CacheTtlResolver(new TtlConfig([])),
            new NodeResponseNormalizer($logger),
            'production-web',
            ['url' => 'https://production-web.example.test'],
        );

        $response = $collector->getServerSystemData();

        $this->assertNull($response['error']);
        $this->assertSame(97.0, $response['result']['cpu_usage']);
    }

    public function testNonProductionServersUseTheirGeneratedBaseCpuUsage(): void
    {
        $logger = new NullLogger();
        $normalizer = new NodeResponseNormalizer($logger);
        $cpuRanges = [
            'database-server' => [25, 45],
            'staging-server' => [10, 30],
            'development-server' => [20, 40],
            'backup-server' => [5, 25],
            'load-balancer' => [15, 35],
        ];

        foreach ($cpuRanges as $server => [$minimum, $maximum]) {
            $collector = new MockServerDataCollector(
                $logger,
                new CacheTtlResolver(new TtlConfig([])),
                $normalizer,
                $server,
                ['url' => 'https://' . $server . '.example.test'],
            );
            $cpuUsage = $collector->getServerSystemData()['result']['cpu_usage'];

            $this->assertGreaterThanOrEqual($minimum, $cpuUsage, $server);
            $this->assertLessThanOrEqual($maximum, $cpuUsage, $server);
        }
    }

    public function testDemoAppsAlwaysMatchTheAppsResponseContract(): void
    {
        $logger = new NullLogger();
        $normalizer = new NodeResponseNormalizer($logger);

        foreach (['database-server', 'backup-server'] as $server) {
            $collector = new MockServerDataCollector(
                $logger,
                new CacheTtlResolver(new TtlConfig([])),
                $normalizer,
                $server,
                ['url' => 'https://' . $server . '.example.test'],
            );

            for ($i = 0; $i < 20; ++$i) {
                $response = $collector->getApps();

                $this->assertNull($response['error']);
                $this->assertIsArray($response['result']);
                $this->assertNotEmpty($response['result']);
            }
        }
    }
}
