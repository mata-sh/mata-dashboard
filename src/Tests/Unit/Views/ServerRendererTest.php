<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Views;

use Mata\Core\App;
use Mata\Http\Routing\Endpoints;
use Mata\Services\Monitor\Dto\NodeHealthState;
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
            'state' => NodeHealthState::Healthy->value,
        ], NodeHealthState::Healthy->value, 'Healthy'];

        yield 'maintenance' => [[
            'state' => NodeHealthState::Maintenance->value,
        ], NodeHealthState::Maintenance->value, 'Maintenance'];

        yield 'unhealthy' => [[
            'state' => NodeHealthState::Unhealthy->value,
        ], NodeHealthState::Unhealthy->value, 'Unhealthy'];

        yield 'unreachable' => [[
            'state' => NodeHealthState::Unreachable->value,
        ], NodeHealthState::Unreachable->value, 'Unreachable'];

        yield 'unknown' => [[], NodeHealthState::Unknown->value, 'Health check failed'];
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

        $html = $renderer->renderErrorContainer("No Servers configured.\nconfig/servers.json", []);

        $this->assertStringContainsString("No Servers configured.<br />\nconfig/servers.json", $html);
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
            'process_count' => '/modal/production%20server/metrics?metric=process_count&range=24h',
        ], $result);
    }

    public function testMarksLogModalsClickableWhenMonitoringIsEnabled(): void
    {
        $result = $this->buildAppCardData(
            ['level' => 'good', 'max_size_formatted' => '12 KB', 'logsize_monitoring_enabled' => true],
            ['show_events_count' => 0, 'show_events_type' => 'working', 'loganalysis_enabled' => true],
        );

        $this->assertTrue($result['app']['status_clickable']);
        $this->assertTrue($result['app']['loganalysis_enabled']);
        $this->assertTrue($result['logs']['file_size_clickable']);
        $this->assertTrue($result['logs']['logsize_monitoring_enabled']);
    }

    public function testDefaultsToClickableWhenFlagsAreAbsent(): void
    {
        $result = $this->buildAppCardData(
            ['level' => 'good', 'max_size_formatted' => '12 KB'],
            ['show_events_count' => 0, 'show_events_type' => 'working'],
        );

        $this->assertTrue($result['app']['status_clickable']);
        $this->assertTrue($result['logs']['file_size_clickable']);
    }

    public function testMarksLogModalsInertWhenMonitoringIsDisabled(): void
    {
        $result = $this->buildAppCardData(
            ['level' => '', 'max_size_formatted' => '–', 'logsize_monitoring_enabled' => false],
            ['show_events_count' => 0, 'show_events_type' => 'working', 'loganalysis_enabled' => false],
        );

        $this->assertFalse($result['app']['status_clickable']);
        $this->assertFalse($result['logs']['file_size_clickable']);
    }

    public function testTitleTooltipShowsEscapedDescriptionAboveCacheAge(): void
    {
        $result = $this->buildAppCardData(
            ['level' => 'good', 'max_size_formatted' => '12 KB'],
            ['show_events_count' => 0, 'show_events_type' => 'working'],
            extraAppData: ['description' => 'Shop <img src=x onerror=alert(1)>'],
        );

        $this->assertSame(
            'Shop &lt;img src=x onerror=alert(1)&gt;<br>Cache Updated: 1 s ago (TTL: 1 s)',
            $result['app']['title_tooltip']
        );
    }

    public function testTitleTooltipShowsOnlyCacheAgeWithoutDescription(): void
    {
        $result = $this->buildAppCardData(
            ['level' => 'good', 'max_size_formatted' => '12 KB'],
            ['show_events_count' => 0, 'show_events_type' => 'working'],
            extraAppData: ['description' => '   '],
        );

        $this->assertSame('Cache Updated: 1 s ago (TTL: 1 s)', $result['app']['title_tooltip']);
    }

    public function testRendersInertFilesizeDashAndUnclickableStatus(): void
    {
        $html = $this->renderAppCard(
            ['level' => '', 'max_size_formatted' => '–', 'logsize_monitoring_enabled' => false],
            ['show_events_count' => 0, 'show_events_type' => 'working', 'loganalysis_enabled' => false],
            ['formatted_count' => '–', 'level' => '', 'tooltip' => 'Session monitoring disabled'],
        );

        $this->assertStringContainsString('<span class="app-filesize count" data-tippy-content=', $html);
        $this->assertStringContainsString('>–</span>', $html);
        $this->assertStringNotContainsString('aria-label="Open error log file details"', $html);
        $this->assertStringNotContainsString('/modal/modal_app_log_files_render', $html);
        $this->assertStringNotContainsString('/modal/modal_app_logs_render', $html);
        // The README trigger is unaffected, proving only the gated modals were dropped.
        $this->assertStringContainsString('/modal/modal_app_readme_render', $html);
        $this->assertStringContainsString('class="app-sessions count "', $html);
        $this->assertStringContainsString('Session monitoring disabled', $html);
    }

    public function testRendersInteractiveFilesizeButtonWhenMonitoringIsEnabled(): void
    {
        $html = $this->renderAppCard(
            ['level' => 'good', 'max_size_formatted' => '12 KB', 'logsize_monitoring_enabled' => true],
            ['show_events_count' => 3, 'show_events_type' => 'errors', 'loganalysis_enabled' => true],
        );

        $this->assertStringContainsString('class="app-filesize count good modal-trigger"', $html);
        $this->assertStringContainsString('aria-label="Open error log file details"', $html);
        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
        $this->assertStringContainsString('hx-toggle="modal"', $html);
    }

    /**
     * @param array<string,mixed> $logAnalysisData
     * @param array<string,mixed> $errorAnalysisData
     * @param array<string,mixed> $sessionData
     * @param array<string,mixed> $extraAppData
     *
     * @return array<string,mixed>
     */
    private function buildAppCardData(
        array $logAnalysisData,
        array $errorAnalysisData,
        array $sessionData = ['formatted_count' => '0', 'level' => 'good', 'tooltip' => 'sessions'],
        array $extraAppData = []
    ): array {
        $tools = $this->createStub(Tools::class);
        $tools->method('formatDuration')->willReturn('1 s');

        $endpoints = $this->createStub(Endpoints::class);
        $endpoints->method('url')->willReturn('/modal/app/log-files');

        $renderer = new ServerRenderer(
            $this->createStub(Environment::class),
            $this->createStub(Logger::class),
            $tools,
            $this->createStub(App::class),
            $endpoints,
            $this->createStub(LogRenderer::class)
        );

        return (new ReflectionMethod($renderer, 'buildAppCardData'))->invokeArgs($renderer, [
            'shop',
            ['title' => 'Shop', 'url' => 'https://shop.example.test', 'env' => 'prod', 'path' => '/srv/shop']
            + $extraAppData,
            'server-a',
            ['log_analysis' => $logAnalysisData, 'error_analysis' => $errorAnalysisData],
            $sessionData,
            1,
            60,
            [],
        ]);
    }

    /**
     * @param array<string,mixed> $logAnalysisData
     * @param array<string,mixed> $errorAnalysisData
     * @param array<string,mixed> $sessionData
     */
    private function renderAppCard(
        array $logAnalysisData,
        array $errorAnalysisData,
        array $sessionData = ['formatted_count' => '0', 'level' => 'good', 'tooltip' => 'sessions']
    ): string {
        $tools = $this->createStub(Tools::class);
        $tools->method('formatDuration')->willReturn('1 s');

        $endpoints = $this->createStub(Endpoints::class);
        $endpoints->method('url')->willReturnCallback(
            static fn (string $name): string => '/modal/' . $name
        );

        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 4) . '/templates'),
            ['autoescape' => 'name']
        );
        $renderer = new ServerRenderer(
            $twig,
            $this->createStub(Logger::class),
            $tools,
            $this->createStub(App::class),
            $endpoints,
            $this->createStub(LogRenderer::class)
        );

        return (new ReflectionMethod($renderer, 'renderSingleAppCard'))->invokeArgs($renderer, [
            'server-a',
            [
                'key' => 'shop',
                'data' => [
                    'title' => 'Shop',
                    'url' => 'https://shop.example.test',
                    'env' => 'prod',
                    'path' => '/srv/shop',
                ],
                'logAnalysis' => ['log_analysis' => $logAnalysisData, 'error_analysis' => $errorAnalysisData],
                'session' => $sessionData,
                'availability' => [],
            ],
            1,
            60,
        ]);
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
