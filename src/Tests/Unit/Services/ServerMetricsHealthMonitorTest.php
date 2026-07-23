<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use DateTimeImmutable;
use Mata\Core\DatabaseInterface;
use Mata\Models\ConfigSyncRepository;
use Mata\Models\ServerMetrics\ServerMetricsRepository;
use Mata\Persistence\Identity\ServerApplicationIdResolver;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Mata\Services\Monitor\ServerMetricsHealthMonitor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \Mata\Services\Monitor\ServerMetricsHealthMonitor
 *
 * @internal
 */
final class ServerMetricsHealthMonitorTest extends TestCase
{
    /** @var array<int,string> */
    private array $healthLogPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->healthLogPaths as $healthLogPath) {
            @unlink($healthLogPath);
        }

        parent::tearDown();
    }

    public function testHealthyRunEmitsNoEvents(): void
    {
        $now = new DateTimeImmutable('2026-07-20T12:00:00+00:00');
        $events = [];
        $monitor = $this->monitor(
            baselineCounts: $this->counts('api-a', 10),
            baselineLatest: $this->latest('api-a', $now->modify('-2 minutes')),
            afterCounts: $this->counts('api-a', 12),
            afterLatest: $this->latest('api-a', $now),
            events: $events,
            expectedEventCount: 0,
        );

        $baseline = $monitor->snapshot();
        $monitor->evaluate(['api-a'], $baseline, $now);

        self::assertSame([], $events);
    }

    public function testEmptyRunEmitsEmptyWarning(): void
    {
        $now = new DateTimeImmutable('2026-07-20T12:00:00+00:00');
        $events = [];
        $monitor = $this->monitor(
            baselineCounts: $this->counts('api-a', 10),
            baselineLatest: $this->latest('api-a', $now->modify('-2 minutes')),
            afterCounts: $this->counts('api-a', 10),
            afterLatest: $this->latest('api-a', $now->modify('-2 minutes')),
            events: $events,
            expectedEventCount: 1,
        );

        $baseline = $monitor->snapshot();
        $monitor->evaluate(['api-a'], $baseline, $now);

        self::assertSame(['server_metrics.ingestion_run_empty'], array_map(
            static fn (EventCommand $event): string => $event->type,
            $events
        ));
        self::assertSame('warning', $events[0]->level);
        self::assertSame('No new raw samples were inserted across 1 server', $events[0]->message);
    }

    public function testEmptyRunUsesPluralServerMessage(): void
    {
        $now = new DateTimeImmutable('2026-07-20T12:00:00+00:00');
        $counts = [
            ['server_name' => 'api-a', 'sample_count' => 10],
            ['server_name' => 'api-b', 'sample_count' => 10],
        ];
        $latest = [
            ['server_name' => 'api-a', 'last_collected' => $now->modify('-2 minutes')->format('Y-m-d H:i:s')],
            ['server_name' => 'api-b', 'last_collected' => $now->modify('-2 minutes')->format('Y-m-d H:i:s')],
        ];
        $events = [];
        $monitor = $this->monitor(
            baselineCounts: $counts,
            baselineLatest: $latest,
            afterCounts: $counts,
            afterLatest: $latest,
            events: $events,
            expectedEventCount: 1,
        );

        $baseline = $monitor->snapshot();
        $monitor->evaluate(['api-a', 'api-b'], $baseline, $now);

        self::assertSame('No new raw samples were inserted across 2 servers', $events[0]->message);
    }

    public function testStalledServerEmitsPerServerWarning(): void
    {
        $now = new DateTimeImmutable('2026-07-20T12:00:00+00:00');
        $lastSampleAt = $now->modify('-20 minutes');
        $events = [];
        $monitor = $this->monitor(
            baselineCounts: $this->counts('api-a', 10),
            baselineLatest: $this->latest('api-a', $lastSampleAt),
            afterCounts: $this->counts('api-a', 10),
            afterLatest: $this->latest('api-a', $lastSampleAt),
            events: $events,
            expectedEventCount: 2,
        );

        $baseline = $monitor->snapshot();
        $monitor->evaluate(['api-a'], $baseline, $now);

        self::assertSame('server_metrics.ingestion_stalled', $events[1]->type);
        self::assertSame('warning', $events[1]->level);
        self::assertSame('api-a', $events[1]->server);
        self::assertSame([
            'lag_minutes' => 20,
            'last_sample_at' => $lastSampleAt->format(DATE_ATOM),
        ], $events[1]->context);
    }

    /**
     * @param array<int,array<string,mixed>> $baselineCounts
     * @param array<int,array<string,mixed>> $baselineLatest
     * @param array<int,array<string,mixed>> $afterCounts
     * @param array<int,array<string,mixed>> $afterLatest
     * @param array<int,EventCommand>        $events
     */
    private function monitor(
        array $baselineCounts,
        array $baselineLatest,
        array $afterCounts,
        array $afterLatest,
        array &$events,
        int $expectedEventCount,
    ): ServerMetricsHealthMonitor {
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects($this->exactly(4))
            ->method('runQuery')
            ->willReturnOnConsecutiveCalls($baselineCounts, $baselineLatest, $afterCounts, $afterLatest);
        $repository = new ServerMetricsRepository(
            $database,
            new ServerApplicationIdResolver(),
            new ConfigSyncRepository($database),
        );
        $eventManager = $this->createMock(EventManager::class);
        $eventManager->expects($this->exactly($expectedEventCount))
            ->method('handle')
            ->willReturnCallback(static function (EventCommand $event) use (&$events): array {
                $events[] = $event;

                return ['eventRecorded' => true, 'eventId' => 1];
            });

        $healthLogPath = tempnam(sys_get_temp_dir(), 'mata-metrics-health-');
        if ($healthLogPath === false) {
            throw new RuntimeException('Unable to create health log path.');
        }
        $this->healthLogPaths[] = $healthLogPath;

        return new ServerMetricsHealthMonitor(
            $repository,
            $eventManager,
            new NullLogger(),
            $healthLogPath,
            15,
            0,
            0,
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function counts(string $server, int $count): array
    {
        return [['server_name' => $server, 'sample_count' => $count]];
    }

    /** @return array<int,array<string,mixed>> */
    private function latest(string $server, DateTimeImmutable $timestamp): array
    {
        return [[
            'server_name' => $server,
            'last_collected' => $timestamp->format('Y-m-d H:i:s'),
        ]];
    }
}
