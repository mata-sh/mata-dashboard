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
