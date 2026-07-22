<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use InvalidArgumentException;
use Mata\Core\DatabaseInterface;
use Mata\Models\AppFatalStateRepository;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Collect\MockServerDataCollector;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\IdResolver;
use Mata\Services\Foundation\LogSizeEvaluator;
use Mata\Services\Foundation\ProbeClient;
use Mata\Services\Monitor\AppReachabilityMonitor;
use Mata\Services\Monitor\Dto\AppReachabilityPolicy;
use Mata\Services\Monitor\MockAppErrorMonitor;
use Mata\Services\Monitor\MockAppReachabilityMonitor;
use Mata\Services\Monitor\MockNodeHealthMonitor;
use Mata\Services\Monitor\NodeHealthMonitor;
use Mata\Services\Monitor\ServerMonitoringFactory;
use Mata\Utils\Tools;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Monitor\ServerMonitoringFactory
 *
 * @internal
 */
final class ServerMonitoringFactoryTest extends TestCase
{
    public function testCreatesLiveMonitoringBundle(): void
    {
        $services = $this->factory()->createLive('server-a', [
            'api_url' => 'https://server-a.example.test/api',
            'api_key' => 'key',
        ], 10);

        $this->assertInstanceOf(ServerDataCollector::class, $services->collector);
        $this->assertInstanceOf(AppReachabilityMonitor::class, $services->reachabilityMonitor);
        $this->assertInstanceOf(\Mata\Services\Monitor\AppErrorMonitor::class, $services->errorMonitor);
        $this->assertInstanceOf(NodeHealthMonitor::class, $services->healthMonitor);
    }

    public function testLiveConstructionRequiresNonEmptyApiCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory()->createLive('server-a', ['api_url' => ' ', 'api_key' => ''], 10);
    }

    public function testCreateLiveAllSeparatesServicesFromFailures(): void
    {
        $result = $this->factory()->createLiveAll([
            'server-a' => [
                'api_url' => 'https://server-a.example.test/api',
                'api_key' => 'key',
            ],
            'invalid' => 'not an array',
            'missing-api-url' => ['api_key' => 'key'],
            'missing-api-key' => ['api_url' => 'https://server-a.example.test/api'],
        ], 10);

        $this->assertArrayHasKey('server-a', $result->servicesByServer);
        $this->assertInstanceOf(ServerDataCollector::class, $result->servicesByServer['server-a']->collector);
        $this->assertSame([
            'invalid' => 'Server invalid configuration must be an array',
            'missing-api-url' => 'Server missing-api-url missing required api_url or api_key config',
            'missing-api-key' => 'Server missing-api-key missing required api_url or api_key config',
        ], $result->failures);
    }

    public function testCreateDemoAllCreatesDemoMonitoringBundle(): void
    {
        $result = $this->factory()->createDemoAll(['server-a' => []]);
        $services = $result->servicesByServer['server-a'];

        $this->assertInstanceOf(MockServerDataCollector::class, $services->collector);
        $this->assertInstanceOf(MockAppReachabilityMonitor::class, $services->reachabilityMonitor);
        $this->assertInstanceOf(MockAppErrorMonitor::class, $services->errorMonitor);
        $this->assertInstanceOf(MockNodeHealthMonitor::class, $services->healthMonitor);
        $this->assertSame([], $result->failures);
    }

    public function testCreateDemoAllSeparatesServicesFromFailures(): void
    {
        $result = $this->factory()->createDemoAll([
            'server-a' => [],
            'invalid' => 'not an array',
        ]);

        $this->assertArrayHasKey('server-a', $result->servicesByServer);
        $this->assertInstanceOf(MockServerDataCollector::class, $result->servicesByServer['server-a']->collector);
        $this->assertSame([
            'invalid' => 'Server invalid configuration must be an array',
        ], $result->failures);
    }

    private function factory(): ServerMonitoringFactory
    {
        $logger = new NullLogger();

        return new ServerMonitoringFactory(
            $logger,
            $this->createStub(CacheStoreInterface::class),
            new ApiCacheKeyFactory(),
            new CacheTtlResolver([]),
            $this->createStub(EventManager::class),
            new ApiResponseTransformer($logger),
            new ProbeClient(),
            AppReachabilityPolicy::fromSettings([]),
            new IdResolver(),
            new AppFatalStateRepository($this->createStub(DatabaseInterface::class)),
            new LogSizeEvaluator(new Tools('/tmp'), $this->createStub(EventManager::class)),
        );
    }
}
