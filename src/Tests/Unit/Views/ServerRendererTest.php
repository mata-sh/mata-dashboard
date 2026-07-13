<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Views;

use Mata\Constants\NodeHealthState;
use Mata\Core\App;
use Mata\Http\Routing\Endpoints;
use Mata\Utils\Tools;
use Mata\Views\LogRenderer;
use Mata\Views\ServerRenderer;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @covers \Mata\Views\ServerRenderer
 *
 * @internal
 */
final class ServerRendererTest extends TestCase
{
    /**
     * @param array<string,mixed> $nodeHealth
     */
    #[DataProvider('nodeHealthStates')]
    public function testBuildsNodeHealthData(array $nodeHealth, string $state, string $label): void
    {
        $method = new ReflectionMethod(ServerRenderer::class, 'buildNodeHealthData');
        $result = $method->invoke($this->createRenderer(), $nodeHealth);

        $this->assertSame($state, $result['state']);
        $this->assertSame($label, $result['label']);
        $this->assertSame($label, $result['tooltip']);
    }

    /**
     * @return iterable<string,array{array<string,mixed>,string,string}>
     */
    public static function nodeHealthStates(): iterable
    {
        yield 'healthy' => [[
            'state' => NodeHealthState::HEALTHY,
        ], NodeHealthState::HEALTHY, 'Healthy'];

        yield 'maintenance' => [[
            'state' => NodeHealthState::MAINTENANCE,
        ], NodeHealthState::MAINTENANCE, 'Maintenance'];

        yield 'unhealthy' => [[
            'state' => NodeHealthState::UNHEALTHY,
        ], NodeHealthState::UNHEALTHY, 'Unhealthy'];

        yield 'unreachable' => [[
            'state' => NodeHealthState::UNREACHABLE,
        ], NodeHealthState::UNREACHABLE, 'Unreachable'];

        yield 'unknown' => [[], NodeHealthState::UNKNOWN, 'Health check failed'];
    }

    public function testBuildsProbeDerivedMaintenanceData(): void
    {
        $method = new ReflectionMethod(ServerRenderer::class, 'buildAvailabilityData');

        $result = $method->invoke($this->createRenderer(), [
            'reachable' => false,
            'httpCode' => 503,
            'maintenance_mode' => true,
        ]);

        $this->assertSame('maintenance', $result['status']);
        $this->assertSame('Maintenance mode', $result['tooltip']);
    }

    public function testCalculatesWholeUsagePercentage(): void
    {
        $method = new ReflectionMethod(ServerRenderer::class, 'calculateUsagePercent');
        $renderer = $this->createRenderer();

        $this->assertSame(80, $method->invoke($renderer, 80, 100));
        $this->assertSame(0, $method->invoke($renderer, 80, 0));
    }

    public function testRenderErrorContainerRendersLineBreaksAsHtml(): void
    {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 4) . '/templates'),
            ['autoescape' => 'name']
        );
        $renderer = new ServerRenderer(
            $twig,
            $this->createStub(Logger::class),
            $this->createStub(Tools::class),
            $this->createStub(App::class),
            $this->createStub(Endpoints::class),
            $this->createStub(LogRenderer::class)
        );

        $html = $renderer->renderErrorContainer("No Servers configured.\nconfig/prod/servers.json", []);

        $this->assertStringContainsString("No Servers configured.<br />\nconfig/prod/servers.json", $html);
    }

    public function testBuildsMetricModalEndpointsForEachCardMetric(): void
    {
        $endpoints = new Endpoints();
        $endpoints->register('modal_server_metrics_render', '/modal/{server}/metrics');

        $method = new ReflectionMethod(ServerRenderer::class, 'buildMetricModalEndpoints');
        $result = $method->invoke($this->createRenderer($endpoints), 'production server');

        $this->assertSame([
            'cpu' => '/modal/production%20server/metrics?metric=cpu&range=24h',
            'ram_percent' => '/modal/production%20server/metrics?metric=ram_percent&range=24h',
            'disk_percent' => '/modal/production%20server/metrics?metric=disk_percent&range=24h',
            'file_count' => '/modal/production%20server/metrics?metric=file_count&range=24h',
        ], $result);
    }

    private function createRenderer(?Endpoints $endpoints = null): ServerRenderer
    {
        return new ServerRenderer(
            $this->createStub(Environment::class),
            $this->createStub(Logger::class),
            $this->createStub(Tools::class),
            $this->createStub(App::class),
            $endpoints ?? $this->createStub(Endpoints::class),
            $this->createStub(LogRenderer::class)
        );
    }
}
