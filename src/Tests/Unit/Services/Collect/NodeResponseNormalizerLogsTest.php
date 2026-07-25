<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Collect;

use Mata\Services\Collect\NodeResponseNormalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Collect\NodeResponseNormalizer
 *
 * @internal
 */
final class NodeResponseNormalizerLogsTest extends TestCase
{
    public function testNormalizeAppLogsRejectsPayloadWithoutReadoutMetadata(): void
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

        $normalizer = new NodeResponseNormalizer(new NullLogger());

        $response = $normalizer->normalizeApiResponse(
            data: $logs,
            path: 'test-app/logs',
        );

        $this->assertSame('Node returned invalid application log metadata.', $response['error']['message'] ?? null);
    }

    public function testNormalizeAppLogsSubPathKeepsGroupedEntriesIntact(): void
    {
        $entries = [
            'logs/php-error.log' => [
                [
                    'timestamp' => '2026-07-09 12:00:00',
                    'type' => 'Error',
                    'message' => 'Boom',
                ],
            ],
        ];

        $normalizer = new NodeResponseNormalizer(new NullLogger());

        $response = $normalizer->normalizeApiResponse(
            data: ['entries' => $entries, 'readout_limit' => 200],
            path: 'test-app/logs/php-error.log',
        );

        $this->assertSame(['entries' => $entries, 'readout_limit' => 200], $response['result']);
    }

    public function testNormalizeAppLogsKeepsReadoutLimitMetadata(): void
    {
        $entries = [
            'logs/php-error.log' => [
                [
                    'timestamp' => '2026-07-09 12:00:00',
                    'type' => 'Error',
                    'message' => 'Boom',
                ],
            ],
        ];

        $normalizer = new NodeResponseNormalizer(new NullLogger());

        $response = $normalizer->normalizeApiResponse(
            data: ['entries' => $entries, 'readout_limit' => 200],
            path: 'test-app/logs',
        );

        $this->assertSame(['entries' => $entries, 'readout_limit' => 200], $response['result']);
    }

    public function testNormalizeAppLogsDropsStringifiedGroupWithWarning(): void
    {
        $entries = [
            'logs/php-error.log' => [
                [
                    'timestamp' => '2026-07-09 12:00:00',
                    'type' => 'Error',
                    'message' => 'Boom',
                ],
            ],
            'old.log' => 'Error: boom',
        ];
        $expectedEntries = [
            'logs/php-error.log' => $entries['logs/php-error.log'],
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'App log group is not an array, dropping',
                $this->callback(static fn (array $context): bool => $context['file'] === 'old.log'),
            );

        $normalizer = new NodeResponseNormalizer($logger);

        $response = $normalizer->normalizeApiResponse(
            data: ['entries' => $entries, 'readout_limit' => 200],
            path: 'test-app/logs',
        );

        $this->assertSame(['entries' => $expectedEntries, 'readout_limit' => 200], $response['result']);
    }

    public function testNormalizeAppLogsDropsAllStringifiedGroups(): void
    {
        $normalizer = new NodeResponseNormalizer(new NullLogger());

        $response = $normalizer->normalizeApiResponse(
            data: [
                'entries' => [
                    'old.log' => 'Error: boom',
                    'access.log' => 'GET /',
                ],
                'readout_limit' => 200,
            ],
            path: 'test-app/logs',
        );

        $this->assertSame(['entries' => [], 'readout_limit' => 200], $response['result']);
    }

    public function testLogsStatusPathIsNotTreatedAsAppLogs(): void
    {
        $payload = [
            'files' => 3,
            'total_size' => 123,
        ];

        $normalizer = new NodeResponseNormalizer(new NullLogger());

        $response = $normalizer->normalizeApiResponse(
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

        $normalizer = new NodeResponseNormalizer(new NullLogger());

        $response = $normalizer->normalizeApiResponse(
            data: $payload,
            path: 'something-else',
        );

        $this->assertSame($payload, $response['result']);
    }
}
