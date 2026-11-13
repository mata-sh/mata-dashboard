<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Controllers;

use Mata\Controllers\BaseController;
use Mata\Http\Request;
use Mata\Http\Responses\Response;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Controllers\BaseController
 *
 * @internal
 */
final class BaseControllerTest extends TestCase
{
    public function testExplicitNotFoundHelpersReturnTheirEndpointFormats(): void
    {
        $handler = new TestHandler();
        $controller = new BaseControllerProbe(new Logger('test', [$handler]));

        $html = $controller->htmlNotFound('Server <script>');
        self::assertSame(404, $html->getStatusCode());
        self::assertSame('text/html; charset=UTF-8', $html->getHeaders()['Content-Type']);
        self::assertStringContainsString('Server &lt;script&gt; not found', $html->getContent());
        self::assertStringNotContainsString('<script>', $html->getContent());

        $json = $controller->jsonNotFound('Server one');
        self::assertSame(404, $json->getStatusCode());
        self::assertSame('application/json; charset=UTF-8', $json->getHeaders()['Content-Type']);
        self::assertSame('Server one not found', json_decode($json->getContent(), true)['message']);
        self::assertCount(2, $handler->getRecords());
    }
}

final class BaseControllerProbe extends BaseController
{
    public function __construct(Logger $logger)
    {
        parent::__construct(new Request());
        $this->logger = $logger;
    }

    public function htmlNotFound(string $resource): Response
    {
        return $this->handleHtmlNotFound($resource, 'test');
    }

    public function jsonNotFound(string $resource): Response
    {
        return $this->handleJsonNotFound($resource, 'test');
    }
}
