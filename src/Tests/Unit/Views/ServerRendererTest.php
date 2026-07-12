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

    private function createRenderer(): ServerRenderer
    {
        return new ServerRenderer(
            $this->createStub(Environment::class),
            $this->createStub(Logger::class),
            $this->createStub(Tools::class),
            $this->createStub(App::class),
            $this->createStub(Endpoints::class),
            $this->createStub(LogRenderer::class)
        );
    }
}
