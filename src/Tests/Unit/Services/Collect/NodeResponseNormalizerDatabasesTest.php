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
final class NodeResponseNormalizerDatabasesTest extends TestCase
{
    public function testNormalizesDatabaseEntryToTheCanonicalContract(): void
    {
        $normalized = $this->normalizer()->normalizeDatabaseData([
            'processes' => ['sql' => '4'],
            'databases' => [
                'main' => [
                    'database' => ' main ',
                    'configured_database' => ' replica ',
                    'host' => ' db.example.test ',
                    'port' => '3306',
                    'description' => ' Primary database ',
                    'max_connections' => '100',
                    'active_threads' => '1.6',
                    'active_processes' => '3',
                    'process_list' => [['id' => 1]],
                    'connection_error' => ' connection refused ',
                    'unknown' => 'dropped',
                ],
            ],
        ]);

        $database = $normalized['databases']['main'];

        $this->assertSame(4, $normalized['processes']['sql']);
        $this->assertSame([
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
        ], array_keys($database));
        $this->assertSame([
            'database' => 'main',
            'configured_database' => 'replica',
            'host' => 'db.example.test',
            'port' => 3306,
            'description' => 'Primary database',
            'max_connections' => 100,
            'active_threads' => 2,
            'active_processes' => 3,
            'process_list' => [['id' => 1]],
            'connection_error' => 'connection refused',
        ], $database);
        $this->assertArrayNotHasKey('unknown', $database);
    }

    public function testNullsInvalidPortsAndCounts(): void
    {
        $normalized = $this->normalizer()->normalizeDatabaseData([
            'processes' => ['sql' => 0],
            'databases' => [
                'port_zero' => ['port' => 0],
                'port_high' => ['port' => 65536],
                'port_negative' => ['port' => -1],
                'port_boolean' => ['port' => true],
                'port_garbage' => ['port' => 'invalid'],
                'counts' => [
                    'port' => 3306,
                    'max_connections' => -1,
                    'active_threads' => 'invalid',
                    'active_processes' => -2,
                ],
            ],
        ]);

        $this->assertNull($normalized['databases']['port_zero']['port']);
        $this->assertNull($normalized['databases']['port_high']['port']);
        $this->assertNull($normalized['databases']['port_negative']['port']);
        $this->assertNull($normalized['databases']['port_boolean']['port']);
        $this->assertNull($normalized['databases']['port_garbage']['port']);
        $this->assertSame('', $normalized['databases']['port_zero']['connection_error']);
        $this->assertSame(3306, $normalized['databases']['counts']['port']);
        $this->assertNull($normalized['databases']['counts']['max_connections']);
        $this->assertNull($normalized['databases']['counts']['active_threads']);
        $this->assertNull($normalized['databases']['counts']['active_processes']);
    }

    public function testPreservesDatabaseMapKeyOrderIncludingNumericLookingKeys(): void
    {
        $normalized = $this->normalizer()->normalizeDatabaseData([
            'processes' => ['sql' => 0],
            'databases' => [
                'first' => [],
                '7' => [],
                '02' => [],
            ],
        ]);

        $this->assertSame(['first', 7, '02'], array_keys($normalized['databases']));
    }

    public function testFiltersInvalidProcessListRows(): void
    {
        $normalized = $this->normalizer()->normalizeDatabaseData([
            'processes' => ['sql' => 0],
            'databases' => [
                'main' => [
                    'process_list' => [
                        ['id' => 1],
                        'invalid',
                        ['id' => 2],
                        null,
                        3,
                    ],
                ],
            ],
        ]);

        $this->assertSame([['id' => 1], ['id' => 2]], $normalized['databases']['main']['process_list']);
    }

    public function testReplacesNonArrayEntryWithSyntheticErrorAndLogsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Malformed database entry from node',
                $this->callback(static fn (array $context): bool => $context['endpoint'] === 'server/databases'
                    && $context['database'] === 'broken'),
            );

        $normalized = (new NodeResponseNormalizer($logger))->normalizeDatabaseData([
            'processes' => ['sql' => 0],
            'databases' => ['broken' => 'invalid'],
        ]);

        $this->assertSame($this->syntheticErrorEntry(), $normalized['databases']['broken']);
    }

    public function testReplacesEntryWithNonScalarConnectionErrorWithSyntheticError(): void
    {
        $normalized = $this->normalizer()->normalizeDatabaseData([
            'processes' => ['sql' => 0],
            'databases' => ['broken' => ['connection_error' => ['invalid']]],
        ]);

        $this->assertSame($this->syntheticErrorEntry(), $normalized['databases']['broken']);
    }

    public function testKeepsAbsentDatabasesAbsentAndAcceptsNullAndEmptyMaps(): void
    {
        $normalizer = $this->normalizer();

        $absent = $normalizer->normalizeDatabaseData(['processes' => ['sql' => 0]]);
        $null = $normalizer->normalizeDatabaseData(['processes' => ['sql' => 0], 'databases' => null]);
        $empty = $normalizer->normalizeDatabaseData(['processes' => ['sql' => 0], 'databases' => []]);

        $this->assertArrayNotHasKey('databases', $absent);
        $this->assertArrayNotHasKey('databases', $null);
        $this->assertSame([], $empty['databases']);
    }

    public function testReturnsErrorEnvelopeForNonMapDatabasesValue(): void
    {
        $response = $this->normalizer()->normalizeApiResponse([
            'processes' => ['sql' => 0],
            'databases' => 'invalid',
        ], path: 'server/databases');

        $this->assertNull($response['result']);
        $this->assertSame('invalid_payload', $response['error']['kind']);
        $this->assertSame('server/databases', $response['error']['endpoint']);
        $this->assertSame('Node returned an invalid databases map.', $response['error']['message']);
    }

    public function testDatabaseNormalizationIsIdempotent(): void
    {
        $normalizer = $this->normalizer();
        $normalized = $normalizer->normalizeDatabaseData([
            'processes' => ['sql' => '2'],
            'databases' => [
                'main' => [
                    'port' => '3306',
                    'active_threads' => '1.6',
                    'process_list' => [['id' => 1], 'invalid'],
                ],
            ],
        ]);

        $this->assertSame($normalized, $normalizer->normalizeDatabaseData($normalized));
    }

    private function normalizer(): NodeResponseNormalizer
    {
        return new NodeResponseNormalizer(new NullLogger());
    }

    /**
     * @return array<string,mixed>
     */
    private function syntheticErrorEntry(): array
    {
        return [
            'database' => null,
            'configured_database' => null,
            'host' => null,
            'port' => null,
            'description' => null,
            'max_connections' => null,
            'active_threads' => null,
            'active_processes' => null,
            'process_list' => [],
            'connection_error' => 'Malformed entry from node',
        ];
    }
}
