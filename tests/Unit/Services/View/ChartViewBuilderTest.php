<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\View;

use Mata\Config\Monitoring\Section\DashboardChartConfig;
use Mata\Services\DataTransformers\ChartDataTransformer;
use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use Mata\Services\View\ChartViewBuilder;
use Mata\Services\View\MetricsViewBuilder;
use Mata\Tests\TestCase;
use Mata\Views\MetricsRenderer;

/**
 * @covers \Mata\Services\View\ChartViewBuilder
 *
 * @internal
 */
final class ChartViewBuilderTest extends TestCase
{
    public function testDefaultDashboardAllowlistContainsEveryCatalogMetric(): void
    {
        $metricCatalog = new MetricCatalog();
        $queryPolicy = new MetricQueryPolicy();
        $builder = $this->builder();

        $config = $builder->buildChartConfig(DashboardChartConfig::default($queryPolicy));

        $this->assertSame($metricCatalog->metrics(), array_keys($config['metrics']));
    }

    public function testInvalidTimespanValuesFallBackWithoutReachingTheDashboardApi(): void
    {
        $metricCatalog = new MetricCatalog();
        $queryPolicy = new MetricQueryPolicy();
        $builder = $this->builder();
        $chartConfig = DashboardChartConfig::fromArray([
            'default_timespan' => ' 7D ',
            'timespans' => [
                'empty' => ['range' => '', 'granularity' => 'raw'],
                'weekly' => ['range' => '24h', 'granularity' => 'weekly'],
                'coerced-refresh' => ['range' => '24h', 'refresh' => '60'],
            ],
        ], $metricCatalog, $queryPolicy, static function (string $message): void {});

        $config = $builder->buildChartConfig($chartConfig);

        $this->assertSame('7d', $config['default_timespan']);
        $this->assertSame(['24h', '7d'], array_keys($config['timespans']));
        $this->assertSame('hourly', $config['timespans']['24h']['granularity']);
        $this->assertSame('hourly', $config['timespans']['7d']['granularity']);
    }

    public function testDashboardPayloadUsesConfiguredMetricLabel(): void
    {
        $metricCatalog = new MetricCatalog();
        $queryPolicy = new MetricQueryPolicy();
        $builder = $this->builder();
        $chartConfig = DashboardChartConfig::fromArray(
            ['metrics' => ['cpu' => ['label' => 'Processor']]],
            $metricCatalog,
            $queryPolicy,
            static function (string $message): void {},
        );
        $config = $builder->buildChartConfig($chartConfig);

        $payload = $builder->buildDashboardMetricsPayload(
            servers: [],
            metric: 'cpu',
            range: '24h',
            granularity: 'hourly',
            timespanKey: '24h',
            chartConfig: $config,
        );

        $this->assertSame('Processor', $payload['stats']['metric_label']);
    }

    private function builder(): ChartViewBuilder
    {
        return new ChartViewBuilder(
            metricsViewBuilder: $this->createStub(MetricsViewBuilder::class),
            metricsRenderer: $this->createStub(MetricsRenderer::class),
            chartTransformer: $this->createStub(ChartDataTransformer::class),
            serversConfig: [],
        );
    }
}
