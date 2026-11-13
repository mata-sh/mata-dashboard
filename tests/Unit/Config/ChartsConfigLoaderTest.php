<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\Monitoring\Loader\ChartsConfigLoader;
use Mata\Config\Monitoring\Section\ChartsConfig;
use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use Mata\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @covers \Mata\Config\Monitoring\Loader\ChartsConfigLoader
 *
 * @internal
 */
final class ChartsConfigLoaderTest extends TestCase
{
    private string $configRoot;
    private ChartsConfigLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configRoot = $this->createTempDirectory();
        $this->loader = new ChartsConfigLoader(metricCatalog: new MetricCatalog(), queryPolicy: new MetricQueryPolicy());
    }

    protected function tearDown(): void
    {
        $this->cleanupTempFiles([$this->configRoot]);
        parent::tearDown();
    }

    public function testMissingFileUsesEmptyConfigWithoutWarning(): void
    {
        $loaded = $this->loader->load($this->configRoot);

        $this->assertSame([], $loaded->problems);
        $this->assertEquals(ChartsConfig::empty(), $loaded->section);
    }

    public function testMalformedFileWarnsAndFallsBackToEmptyConfig(): void
    {
        file_put_contents($this->configRoot . '/charts.json', '{');
        $loaded = $this->loader->load($this->configRoot);

        $this->assertCount(1, $loaded->problems);
        $this->assertEquals(ChartsConfig::empty(), $loaded->section);
    }

    public function testUnknownMetricsAndInvalidTimespansWarnAndAreDropped(): void
    {
        file_put_contents($this->configRoot . '/charts.json', json_encode([
            'dashboard' => [
                'metrics' => [
                    'cpu' => ['label' => 'Processor'],
                    'not_a_metric' => ['label' => 'Nope'],
                ],
                'timespans' => [
                    '24h' => ['range' => '24h'],
                    'broken' => ['range' => 'not-a-range'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $loaded = $this->loader->load($this->configRoot);
        $config = $loaded->section;

        $this->assertCount(2, $loaded->problems);

        $this->assertSame([
            'cpu' => ['key' => 'cpu', 'label' => 'Processor'],
        ], $config->dashboard?->metrics);
        $this->assertSame([
            '24h' => [
                'key' => '24h',
                'label' => '24H',
                'range' => '24h',
                'granularity' => 'hourly',
                'refresh' => 60,
            ],
        ], $config->dashboard?->timespans);
    }

    public function testListFormIsPreserved(): void
    {
        file_put_contents($this->configRoot . '/charts.json', json_encode([
            'dashboard' => [
                'metrics' => ['cpu', 'disk_used', 'unknown'],
                'timespans' => ['6h', '24h'],
            ],
        ], JSON_THROW_ON_ERROR));

        $config = $this->loader->load($this->configRoot)->section;

        $this->assertSame(['cpu', 'disk_used'], array_keys($config->dashboard?->metrics ?? []));
        $this->assertSame(['6h', '24h'], array_keys($config->dashboard?->timespans ?? []));
    }

    /** @param array<string,mixed> $entry */
    #[DataProvider('invalidTimespanProvider')]
    public function testInvalidTimespanFieldsWarnAndDropEntry(array $entry): void
    {
        file_put_contents($this->configRoot . '/charts.json', json_encode([
            'dashboard' => ['timespans' => ['custom' => $entry]],
        ], JSON_THROW_ON_ERROR));
        $loaded = $this->loader->load($this->configRoot);

        $this->assertCount(1, $loaded->problems);
        $this->assertSame(['24h', '7d'], array_keys($loaded->section->dashboard?->timespans ?? []));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidTimespanProvider(): iterable
    {
        yield 'empty range' => [['range' => '']];

        yield 'unknown granularity' => [['range' => '24h', 'granularity' => 'weekly']];

        yield 'non-string granularity' => [['range' => '24h', 'granularity' => ['daily']]];

        yield 'numeric-string refresh' => [['range' => '24h', 'refresh' => '60']];

        yield 'float refresh' => [['range' => '24h', 'refresh' => 60.5]];

        yield 'boolean refresh' => [['range' => '24h', 'refresh' => true]];

        yield 'refresh below minimum' => [['range' => '24h', 'refresh' => 9]];
    }

    public function testTimespanValuesAreNormalizedWithTheSharedRangeParser(): void
    {
        file_put_contents($this->configRoot . '/charts.json', json_encode([
            'dashboard' => [
                'default_timespan' => ' Day ',
                'timespans' => [
                    ' Day ' => [
                        'label' => 'Day',
                        'range' => ' 24H ',
                        'granularity' => ' AUTO ',
                        'refresh' => 60,
                    ],
                    'leading-zero' => ['range' => '01h'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $config = $this->loader->load($this->configRoot)->section;

        $this->assertSame('day', $config->dashboard?->defaultTimespan);
        $this->assertSame([
            'day' => [
                'key' => 'day',
                'label' => 'Day',
                'range' => '24h',
                'granularity' => 'hourly',
                'refresh' => 60,
            ],
            'leading-zero' => [
                'key' => 'leading-zero',
                'label' => 'LEADING-ZERO',
                'range' => '01h',
                'granularity' => 'raw',
                'refresh' => 60,
            ],
        ], $config->dashboard?->timespans);
    }
}
