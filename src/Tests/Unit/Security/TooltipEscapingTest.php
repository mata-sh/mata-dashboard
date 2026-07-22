<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Security;

use Mata\Core\App;
use Mata\Http\Routing\Endpoints;
use Mata\Services\Cache\CacheTypeResolver;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\LogSizeEvaluator;
use Mata\Services\Monitor\ServerMonitor;
use Mata\Services\View\ServerViewBuilder;
use Mata\Utils\Tools;
use Mata\Views\LogRenderer;
use Mata\Views\ServerRenderer;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use Twig\Environment;

/**
 * @internal
 *
 * @coversNothing
 */
class TooltipEscapingTest extends TestCase
{
    public function testSessionTooltipEscapesRemotePathButKeepsGeneratedHtml(): void
    {
        $eventManager = $this->createStub(EventManager::class);
        $eventManager->method('getAppDataThresholdLevel')->willReturn('good');

        $builder = new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            new LogSizeEvaluator(new Tools('/tmp'), $eventManager),
            $eventManager,
            $this->createStub(Logger::class),
            new CacheTypeResolver()
        );

        $result = $this->invokePrivate($builder, 'formatSessionDataForView', [[
            'session' => [
                'type' => 'file',
                'status' => 'ok',
                'count_last_hour' => 4,
                'count_last_day' => 8,
                'count_total' => 12,
                'path' => '/tmp/sessions/<img src=x onerror=alert(1)>',
                'size_kb' => 12.5,
                'error' => null,
            ],
        ]]);

        $this->assertSame('good', $result['level']);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $result['tooltip']);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $result['tooltip']);
        $this->assertStringContainsString('<strong>1 Hour:</strong> 4', $result['tooltip']);
    }

    public function testSessionErrorTooltipEscapesRemoteErrorMessage(): void
    {
        $eventManager = $this->createStub(EventManager::class);
        $builder = new ServerViewBuilder(
            $this->createStub(ServerMonitor::class),
            new LogSizeEvaluator(new Tools('/tmp'), $eventManager),
            $eventManager,
            $this->createStub(Logger::class),
            new CacheTypeResolver()
        );

        $result = $this->invokePrivate($builder, 'formatSessionDataForView', [[
            'session' => [
                'type' => '<b>evil</b>',
                'status' => 'error',
                'count_last_hour' => null,
                'count_last_day' => null,
                'count_total' => null,
                'path' => null,
                'size_kb' => null,
                'error' => '<script>alert(1)</script>',
            ],
        ]]);

        $this->assertSame('warning', $result['level']);
        $this->assertSame('!', $result['formatted_count']);
        $this->assertStringContainsString('&lt;b&gt;evil&lt;/b&gt;', $result['tooltip']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $result['tooltip']);
        $this->assertStringNotContainsString('<b>evil</b>', $result['tooltip']);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $result['tooltip']);
    }

    public function testAvailabilityTooltipEscapesRemoteErrorButKeepsGeneratedText(): void
    {
        $renderer = $this->createServerRenderer();

        $result = $this->invokePrivate($renderer, 'buildOfflineAvailabilityData', [[
            'reachable' => false,
            'error' => '<script>alert(1)</script>',
            'httpCode' => 503,
        ]]);

        $this->assertSame('offline', $result['status']);
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt; • HTTP 503', $result['tooltip']);
        $this->assertStringNotContainsString('<script>', $result['tooltip']);
    }

    public function testLogTooltipEscapesPathsButKeepsGeneratedMarkup(): void
    {
        $tools = $this->createStub(Tools::class);
        $tools->method('formatKilobytes')->willReturn('1 KB');

        $renderer = new LogRenderer(
            $this->createStub(Environment::class),
            $this->createStub(Logger::class),
            $tools,
            $this->createStub(App::class),
            $this->createStub(Endpoints::class)
        );

        $tooltip = $renderer->formatLogSizeTooltip([
            '/var/log/app" onmouseover="alert(1)' => 1024,
        ]);

        $this->assertStringContainsString('/var/log/app&quot; onmouseover=&quot;alert(1)', $tooltip);
        $this->assertStringNotContainsString('/var/log/app" onmouseover="alert(1)', $tooltip);
        $this->assertStringContainsString('<strong>1 KB</strong><br>', $tooltip);
    }

    public function testAppCardTooltipFieldsAreEscapedSeparatelyFromHrefValues(): void
    {
        $tools = $this->createStub(Tools::class);
        $tools->method('formatDuration')->willReturn('1 s');
        $tools->method('icon')->willReturn('<svg></svg>');

        $logRenderer = $this->createStub(LogRenderer::class);
        $logRenderer->method('formatErrorAnalysisTooltip')->willReturn('status tooltip');
        $logRenderer->method('formatLogAnalysisTooltip')->willReturn('log tooltip');

        $endpoints = $this->createStub(Endpoints::class);
        $endpoints->method('url')->willReturn('/modal');

        $renderer = new ServerRenderer(
            $this->createStub(Environment::class),
            $this->createStub(Logger::class),
            $tools,
            $this->createStub(App::class),
            $endpoints,
            $logRenderer
        );

        $result = $this->invokePrivate($renderer, 'buildAppCardData', [
            'app-key',
            [
                'title' => 'App',
                'url' => 'https://example.test/?q=<img src=x onerror=alert(1)>',
                'env' => 'prod',
                'path' => '/srv/app/<script>alert(1)</script>',
            ],
            'server1',
            [
                'error_analysis' => [
                    'show_events_count' => 0,
                    'show_events_type' => 'working',
                    'alarms_enabled_class' => '',
                ],
                'log_analysis' => [
                    'level' => 'good',
                    'max_size_formatted' => '0 B',
                ],
            ],
            [
                'formatted_count' => '0',
                'level' => 'good',
                'tooltip' => 'sessions tooltip',
            ],
            1,
            1,
            [],
        ]);

        $this->assertSame('https://example.test/?q=<img src=x onerror=alert(1)>', $result['app']['url']);
        $this->assertSame('https://example.test/?q=&lt;img src=x onerror=alert(1)&gt;', $result['app']['url_tooltip']);
        $this->assertSame('/srv/app/<script>alert(1)</script>', $result['app']['path']);
        $this->assertSame('/srv/app/&lt;script&gt;alert(1)&lt;/script&gt;', $result['app']['path_tooltip']);
    }

    public function testTemplatesDoNotUseRawInsideTippyAttributes(): void
    {
        $violations = [];
        $templatesDir = realpath(__DIR__ . '/../../../../templates');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) $templatesDir));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/data-tippy-content="[^"]*\|raw/', $contents) === 1) {
                $violations[] = $file->getPathname();
            }
        }

        $this->assertSame([], $violations);
    }

    /**
     * @param array<int,mixed> $args
     */
    private function invokePrivate(object $object, string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod($object, $method);

        return $reflection->invokeArgs($object, $args);
    }

    private function createServerRenderer(): ServerRenderer
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
