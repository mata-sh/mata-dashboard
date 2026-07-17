<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheEntry;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Event\EventAggregatorServiceInterface;
use Mata\Services\Event\EventManager;
use Mata\Services\Navigation\NotificationCenter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Navigation\NotificationCenter
 *
 * @internal
 */
final class NotificationCenterTest extends TestCase
{
    public function testCountsAreReadFromTheSharedCacheAfterTheFirstRequest(): void
    {
        $aggregator = $this->createMock(EventAggregatorServiceInterface::class);
        $aggregator->expects($this->once())
            ->method('getUnacknowledgedAlarmCounts')
            ->willReturn(['warning' => 2]);
        $cacheStore = $this->createMock(CacheStoreInterface::class);
        $cacheStore->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                null,
                new CacheEntry(['info' => 0, 'warning' => 2, 'error' => 0, 'critical' => 0], time()),
            );
        $cacheStore->expects($this->once())
            ->method('put')
            ->with(
                $this->isType('string'),
                ['info' => 0, 'warning' => 2, 'error' => 0, 'critical' => 0],
            )
            ->willReturn(true);

        $notificationCenter = $this->notificationCenter($aggregator, $cacheStore);

        $this->assertSame(['info' => 0, 'warning' => 2, 'error' => 0, 'critical' => 0], $notificationCenter->getUnacknowledgedCounts());
        $this->assertSame(['info' => 0, 'warning' => 2, 'error' => 0, 'critical' => 0], $notificationCenter->getUnacknowledgedCounts());
    }

    public function testAcknowledgingAllAlarmsInvalidatesTheCountsCache(): void
    {
        $aggregator = $this->createMock(EventAggregatorServiceInterface::class);
        $aggregator->expects($this->once())
            ->method('acknowledgeAllAlarms')
            ->with('admin')
            ->willReturn(3);
        $cacheStore = $this->createMock(CacheStoreInterface::class);
        $cacheStore->expects($this->once())
            ->method('delete')
            ->with($this->isType('string'))
            ->willReturn(true);

        $notificationCenter = $this->notificationCenter($aggregator, $cacheStore);

        $this->assertSame(3, $notificationCenter->acknowledgeAll('admin'));
    }

    /**
     * @param EventAggregatorServiceInterface&MockObject $aggregator
     * @param CacheStoreInterface&MockObject             $cacheStore
     */
    private function notificationCenter(
        EventAggregatorServiceInterface $aggregator,
        CacheStoreInterface $cacheStore,
    ): NotificationCenter {
        $eventManager = $this->createMock(EventManager::class);
        $eventManager->aggregator = $aggregator;

        return new NotificationCenter($eventManager, $cacheStore, new ApiCacheKeyFactory());
    }
}
