<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Views;

use Mata\Core\App;
use Mata\Http\Routing\Endpoints;
use Mata\Utils\Tools;
use Mata\Views\ModalRenderer;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @covers \Mata\Views\ModalRenderer
 *
 * @internal
 */
final class ModalRendererTest extends TestCase
{
    public function testRendersApplicationLogFileMetadata(): void
    {
        $tools = $this->createStub(Tools::class);
        $tools->method('icon')->willReturn('');
        $tools->method('formatDuration')->willReturn('0s');
        $tools->method('formatBytes')->willReturnCallback(static fn (int $bytes): string => "{$bytes} bytes");
        $renderer = new ModalRenderer(
            new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/templates')),
            new Logger('test'),
            $tools,
            $this->createStub(App::class),
            $this->createStub(Endpoints::class),
        );

        $html = $renderer->renderAppLogFilesModal(
            [
                '<script>error.log</script>' => [
                    'size' => 43008,
                    'lines' => 1200,
                    'last_modified' => '2026-07-18 12:00:00',
                ],
                '/logs/debug.log' => ['size' => 2048, 'lines' => 12, 'last_modified' => ''],
            ],
            'server1',
            'app1',
            0,
            0,
            ['modal_app_log_files_refresh' => '/refresh']
        );

        $this->assertStringContainsString('&lt;script&gt;error.log&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>error.log</script>', $html);
        $this->assertStringContainsString('43008 bytes', $html);
        $this->assertStringContainsString('2048 bytes', $html);
        $this->assertStringContainsString('1,200', $html);
        $this->assertStringContainsString('2026-07-18 12:00:00', $html);
        $this->assertStringContainsString('–', $html);
    }

    public function testEscapesDatabaseProcessCells(): void
    {
        $tools = $this->createStub(Tools::class);
        $tools->method('icon')->willReturn('');
        $tools->method('formatDuration')->willReturn('0s');
        $renderer = new ModalRenderer(
            new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/templates')),
            new Logger('test'),
            $tools,
            $this->createStub(App::class),
            $this->createStub(Endpoints::class),
        );

        $html = $renderer->renderDatabaseProcessesModal(
            [['info' => '<script>alert(1)</script>']],
            'server1',
            'main',
            0,
            0,
            ['modal_db_processes_refresh' => '/refresh']
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }
}
