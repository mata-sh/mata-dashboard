<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\DataTransformers;

use Mata\Services\DataTransformers\LogDataTransformer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \Mata\Services\DataTransformers\LogDataTransformer
 *
 * @internal
 */
final class LogDataTransformerTest extends TestCase
{
    public function testFlattenGroupedLogsAddsSourceAndSortsNewestFirst(): void
    {
        $transformer = new LogDataTransformer();

        $result = $transformer->flattenGroupedLogs([
            '/var/www/app/logs/app.log' => [
                [
                    'timestamp' => '2026-07-09 12:00:00',
                    'type' => 'warning',
                    'message' => 'Old warning',
                ],
                [
                    'timestamp' => '2026-07-09 13:00:00',
                    'type' => 'critical',
                    'message' => 'New error',
                ],
            ],
        ]);

        $this->assertSame([
            [
                'timestamp' => '2026-07-09 13:00:00',
                'type' => 'Error',
                'message' => 'New error',
                'source' => 'app/logs/app.log',
                'count' => 1,
                'first_seen' => '2026-07-09 13:00:00',
            ],
            [
                'timestamp' => '2026-07-09 12:00:00',
                'type' => 'Warning',
                'message' => 'Old warning',
                'source' => 'app/logs/app.log',
                'count' => 1,
                'first_seen' => '2026-07-09 12:00:00',
            ],
        ], $result);
    }

    public function testFlattenGroupedLogsSkipsNonArrayGroups(): void
    {
        $transformer = new LogDataTransformer();

        $result = $transformer->flattenGroupedLogs([
            'file.log' => 'Error: boom',
        ]);

        $this->assertSame([], $result);
    }

    public function testFlattenGroupedLogsSkipsEmptyGroups(): void
    {
        $transformer = new LogDataTransformer();

        $result = $transformer->flattenGroupedLogs([
            'file.log' => [],
        ]);

        $this->assertSame([], $result);
    }

    public function testFlattenGroupedLogsSkipsMalformedEntriesAndLogsWarnings(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(6))
            ->method('warning')
            ->with('Skipping malformed log entry', $this->isArray())
        ;

        $transformer = new LogDataTransformer($logger);

        $result = $transformer->flattenGroupedLogs([
            'logs/app.log' => [
                ['type' => 'Error', 'message' => 'Missing timestamp'],
                ['timestamp' => '2026-07-09 12:00:00', 'message' => 'Missing type'],
                ['timestamp' => '2026-07-09 12:00:00', 'type' => 'Error'],
                ['timestamp' => 123, 'type' => 'Error', 'message' => 'Bad timestamp'],
                ['timestamp' => '2026-07-09 12:00:00', 'type' => 123, 'message' => 'Bad type'],
                ['timestamp' => '2026-07-09 12:00:00', 'type' => 'Error', 'message' => 123],
                [
                    'timestamp' => '2026-07-09 13:00:00',
                    'type' => 'Error',
                    'message' => 'Valid error',
                ],
            ],
        ]);

        $this->assertSame([
            [
                'timestamp' => '2026-07-09 13:00:00',
                'type' => 'Error',
                'message' => 'Valid error',
                'source' => 'logs/app.log',
                'count' => 1,
                'first_seen' => '2026-07-09 13:00:00',
            ],
        ], $result);
    }

    public function testFlattenGroupedLogsSortsByTimestampDescendingAcrossGroups(): void
    {
        $transformer = new LogDataTransformer();

        $result = $transformer->flattenGroupedLogs([
            'logs/old.log' => [
                [
                    'timestamp' => '2026-07-09 12:00:00',
                    'type' => 'Error',
                    'message' => 'Old',
                ],
            ],
            'logs/new.log' => [
                [
                    'timestamp' => '2026-07-09 14:00:00',
                    'type' => 'Error',
                    'message' => 'New',
                ],
            ],
            'logs/middle.log' => [
                [
                    'timestamp' => '2026-07-09 13:00:00',
                    'type' => 'Error',
                    'message' => 'Middle',
                ],
            ],
        ]);

        $this->assertSame([
            '2026-07-09 14:00:00',
            '2026-07-09 13:00:00',
            '2026-07-09 12:00:00',
        ], array_column($result, 'timestamp'));
    }

    public function testFlattenGroupedLogsNormalizesMixedCaseAndMappedTypes(): void
    {
        $transformer = new LogDataTransformer();

        $result = $transformer->flattenGroupedLogs([
            'logs/app.log' => [
                [
                    'timestamp' => '2026-07-09 12:00:00',
                    'type' => 'fatal',
                    'message' => 'Fatal error',
                ],
                [
                    'timestamp' => '2026-07-09 13:00:00',
                    'type' => 'NoTiCe',
                    'message' => 'Notice warning',
                ],
            ],
        ]);

        $this->assertSame([
            'Warning',
            'Error',
        ], array_column($result, 'type'));
    }
}
