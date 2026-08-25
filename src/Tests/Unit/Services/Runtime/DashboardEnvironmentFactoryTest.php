<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Runtime;

use Mata\Config\Monitoring\Section\TtlConfig;
use Mata\Database\DatabaseInterface;
use Mata\Models\AlarmRepository;
use Mata\Models\AppFatalStateRepository;
use Mata\Models\EventRepository;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Collect\MockServerDataCollector;
use Mata\Services\Collect\NodeResponseNormalizer;
use Mata\Services\Event\EventAggregatorService;
use Mata\Services\Event\EventManager;
use Mata\Services\Event\MockEventAggregatorService;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Monitor\Dto\AppReachabilityPolicy;
use Mata\Services\Monitor\HttpProbeClient;
use Mata\Services\Monitor\LogSizeEvaluator;
use Mata\Services\Monitor\ServerMonitoringFactory;
use Mata\Services\Runtime\DashboardEnvironmentFactory;
use Mata\Tests\Helpers\MonitoringConfigFactory;
use Mata\Utils\Tools;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Runtime\DashboardEnvironmentFactory
 *
 * @internal
 */
final class DashboardEnvironmentFactoryTest extends TestCase
{
    public function testCreatesLiveDashboardDependencies(): void
    {
        $factory = $this->environmentFactory('prod');

        $this->assertInstanceOf(EventAggregatorService::class, $factory->createEventAggregator());
        $this->assertSame([], $factory->createMonitoring([], $this->monitoringFactory())->servicesByServer);
    }

    public function testCreatesDemoDashboardDependencies(): void
    {
        $factory = $this->environmentFactory('demo');
        $result = $factory->createMonitoring(['server-a' => []], $this->monitoringFactory());

        $this->assertInstanceOf(MockEventAggregatorService::class, $factory->createEventAggregator());
        $this->assertInstanceOf(MockServerDataCollector::class, $result->servicesByServer['server-a']->collector);
    }

    private function environmentFactory(string $environment): DashboardEnvironmentFactory
    {
        $database = $this->createStub(DatabaseInterface::class);

        return new DashboardEnvironmentFactory(
            $environment,
            new EventRepository($database),
            new AlarmRepository($database),
            new ServerApplicationIdResolver(),
            new NullLogger(),
            __DIR__ . '/missing-events.json',
        );
    }

    private function monitoringFactory(): ServerMonitoringFactory
    {
        $logger = new NullLogger();

        return new ServerMonitoringFactory(
            $logger,
            $this->createStub(CacheStoreInterface::class),
            new ApiCacheKeyFactory(),
            new CacheTtlResolver(new TtlConfig([])),
            $this->createStub(EventManager::class),
            new NodeResponseNormalizer($logger),
            new HttpProbeClient(),
            AppReachabilityPolicy::fromMonitoringConfig(MonitoringConfigFactory::create()),
            new ServerApplicationIdResolver(),
            new AppFatalStateRepository($this->createStub(DatabaseInterface::class)),
            new LogSizeEvaluator(new Tools('/tmp'), $this->createStub(EventManager::class)),
        );
    }
}
