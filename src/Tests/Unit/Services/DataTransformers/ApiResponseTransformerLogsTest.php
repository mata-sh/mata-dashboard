<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\DataTransformers;

use Mata\Services\DataTransformers\ApiResponseTransformer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\DataTransformers\ApiResponseTransformer
 *
 * @internal
 */
final class ApiResponseTransformerLogsTest extends TestCase
{
    public function testNormalizeAppLogsKeepsGroupedEntriesIntact(): void
    {
        $logs = [
            'logs/php-error.log' => [
                [
                    'timestamp' => '2026-07-09 12:00:00',
                    'type' => 'Error',
                    'message' => 'Boom',
                ],
            ],
        ];

        $transformer = new ApiResponseTransformer(new NullLogger());

        $response = $transformer->normalizeApiResponse(
            data: $logs,
            path: 'test-app/logs',
        );

        $this->assertSame($logs, $response['result']);
    }

    public function testNormalizeAppLogsSubPathKeepsGroupedEntriesIntact(): void
    {
        $logs = [
            'logs/php-error.log' => [
                [
                    'timestamp' => '2026-07-09 12:00:00',
                    'type' => 'Error',
                    'message' => 'Boom',
                ],
            ],
        ];

        $transformer = new ApiResponseTransformer(new NullLogger());

        $response = $transformer->normalizeApiResponse(
            data: $logs,
            path: 'test-app/logs/php-error.log',
        );

        $this->assertSame($logs, $response['result']);
    }

    public function testNormalizeAppLogsDropsStringifiedGroupWithWarning(): void
    {
        $logs = [
            'logs/php-error.log' => [
                [
                    'timestamp' => '2026-07-09 12:00:00',
                    'type' => 'Error',
                    'message' => 'Boom',
                ],
            ],
            'old.log' => 'Error: boom',
        ];
        $expectedLogs = [
            'logs/php-error.log' => $logs['logs/php-error.log'],
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'App log group is not an array, dropping',
                $this->callback(static fn (array $context): bool => $context['file'] === 'old.log'),
            );

        $transformer = new ApiResponseTransformer($logger);

        $response = $transformer->normalizeApiResponse(
            data: $logs,
            path: 'test-app/logs',
        );

        $this->assertSame($expectedLogs, $response['result']);
    }

    public function testNormalizeAppLogsDropsAllStringifiedGroups(): void
    {
        $transformer = new ApiResponseTransformer(new NullLogger());

        $response = $transformer->normalizeApiResponse(
            data: [
                'old.log' => 'Error: boom',
                'access.log' => 'GET /',
            ],
            path: 'test-app/logs',
        );

        $this->assertSame([], $response['result']);
    }

    public function testLogsStatusPathIsNotTreatedAsAppLogs(): void
    {
        $payload = [
            'files' => 3,
            'total_size' => 123,
        ];

        $transformer = new ApiResponseTransformer(new NullLogger());

        $response = $transformer->normalizeApiResponse(
            data: $payload,
            path: 'test-app/logs-status',
        );

        $this->assertSame($payload, $response['result']);
    }

    public function testUnknownPathPassesDataThroughUnchanged(): void
    {
        $payload = [
            'anything' => ['nested' => true],
        ];

        $transformer = new ApiResponseTransformer(new NullLogger());

        $response = $transformer->normalizeApiResponse(
            data: $payload,
            path: 'something-else',
        );

        $this->assertSame($payload, $response['result']);
    }
}
