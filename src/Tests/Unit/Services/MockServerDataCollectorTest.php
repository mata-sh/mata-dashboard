<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Config\Dto\DemoConfig;
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
    public function testDemoDatabaseDataMatchesTheNodeContract(): void
    {
        $logger = new NullLogger();
        $collector = new MockServerDataCollector(
            $logger,
            new CacheTtlResolver(new TtlConfig([])),
            new NodeResponseNormalizer($logger),
            'database-server',
            ['url' => 'https://database-server.example.test'],
        );

        $responses = [
            $collector->getDatabaseOverview(),
            $collector->fetchDatabaseProcesses(null),
            $collector->fetchDatabaseProcesses('analytics'),
        ];

        foreach ($responses as $response) {
            $this->assertIsArray($response);
            $this->assertNull($response['error']);
        }
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

    public function testDemoSeedMakesGeneratedDataReproducible(): void
    {
        $first = $this->createCollector('database-server', new DemoConfig(seed: 7));
        $second = $this->createCollector('database-server', new DemoConfig(seed: 7));

        $this->assertSame($first->getApps()['result'], $second->getApps()['result']);
        $this->assertSame($first->getServerSystemData()['result'], $second->getServerSystemData()['result']);
        $this->assertSame(
            array_keys($first->getApps()['result']),
            array_keys($first->getAppsLibraries()['result']),
        );
    }

    public function testDemoHasOneIncidentOneUnprobedAppAndNoBrokenStates(): void
    {
        $fatalApps = [];
        $unprobedApps = [];
        foreach (['production-web', 'database-server', 'staging-server', 'development-server', 'backup-server'] as $server) {
            $collector = $this->createCollector($server, new DemoConfig(seed: 1));

            foreach ($collector->getApps()['result'] as $appKey => $app) {
                if (isset($app['monitoring'])) {
                    $this->assertSame(['reachability' => false], $app['monitoring']);
                    $unprobedApps[] = "{$server}/{$appKey}";
                }
                $this->assertSame('ok', $app['session']['status']);
                if ($app['status']['fatal'] > 0) {
                    $fatalApps[] = "{$server}/{$appKey}";
                }
            }

            $this->assertLessThan(97.0, $collector->getServerSystemData()['result']['cpu_usage']);
        }

        $this->assertCount(1, $fatalApps);
        $this->assertStringStartsWith('production-web/', $fatalApps[0]);
        $this->assertCount(1, $unprobedApps);
        $this->assertStringStartsWith('database-server/', $unprobedApps[0]);
    }

    private function createCollector(string $server, DemoConfig $demo): MockServerDataCollector
    {
        $logger = new NullLogger();

        return new MockServerDataCollector(
            $logger,
            new CacheTtlResolver(new TtlConfig([])),
            new NodeResponseNormalizer($logger),
            $server,
            ['url' => 'https://' . $server . '.example.test'],
            $demo,
        );
    }
}
