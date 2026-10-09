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
        $this->assertStringContainsString('data-sort-value="43008"', $html);
        $this->assertStringContainsString('data-sort="number"', $html);
        $this->assertStringContainsString('1,200', $html);
        $this->assertStringContainsString('2026-07-18 12:00:00', $html);
        $this->assertStringContainsString('–', $html);
    }

    public function testSortsApplicationLogFilesBySizeDescending(): void
    {
        $tools = $this->createStub(Tools::class);
        $tools->method('formatBytes')->willReturnCallback(static fn (int $bytes): string => "{$bytes} bytes");
        $renderer = new ModalRenderer(
            new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/templates')),
            new Logger('test'),
            $tools,
            $this->createStub(App::class),
            $this->createStub(Endpoints::class),
        );

        $data = $renderer->buildAppLogFilesModalData(
            [
                '/logs/small.log' => ['size' => 2048],
                '/logs/unknown.log' => ['size' => null],
                '/logs/large.log' => ['size' => 43008],
                '/logs/medium.log' => ['size' => 10240],
            ],
            'server1',
            'app1',
            0,
            0,
            ['modal_app_log_files_refresh' => '/refresh']
        );

        $this->assertSame(
            ['/logs/large.log', '/logs/medium.log', '/logs/small.log', '/logs/unknown.log'],
            array_column($data['entries'], 'path')
        );
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
            ['visibility' => 'all_sessions', 'processes' => [['host' => '<script>alert(1)</script>']], 'truncated' => false],
            'server1',
            'main',
            '/refresh'
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testWarnsWhenTheProcessListIsIncomplete(): void
    {
        $renderer = new ModalRenderer(
            new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/templates')),
            new Logger('test'),
            $this->createStub(Tools::class),
            $this->createStub(App::class),
            $this->createStub(Endpoints::class),
        );
        $result = [
            'engine' => 'pgsql',
            'visibility' => 'own_sessions',
            'summary_by_database' => [],
            'processes' => [['id' => 7, 'database' => 'shop', 'command' => 'Query', 'time_seconds' => 4, 'statement' => 'SELECT']],
            'truncated' => true,
        ];

        $incomplete = $renderer->renderDatabaseProcessesModal($result, 'server1', 'shop', '/refresh');
        $complete = $renderer->renderDatabaseProcessesModal(
            ['visibility' => 'all_sessions', 'truncated' => false] + $result,
            'server1',
            'shop',
            '/refresh',
        );

        $this->assertStringContainsString('pg_read_all_stats', $incomplete);
        $this->assertStringContainsString('more exist', $incomplete);
        $this->assertStringNotContainsString('pg_read_all_stats', $complete);
        $this->assertStringNotContainsString('more exist', $complete);
    }
}
