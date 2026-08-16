<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Http\Responses;

use Mata\Http\Responses\ResponseFactory;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Http\Responses\ResponseFactory
 *
 * @internal
 */
class ResponseFactoryTest extends TestCase
{
    public function testItCreatesExplicitSafeNotFoundResponses(): void
    {
        $html = ResponseFactory::htmlNotFound('Server <script>');
        self::assertSame(404, $html->getStatusCode());
        self::assertSame('text/html; charset=UTF-8', $html->getHeaders()['Content-Type']);
        self::assertStringContainsString('Server &lt;script&gt; not found', $html->getContent());

        $json = ResponseFactory::jsonNotFound('Server one');
        self::assertSame(404, $json->getStatusCode());
        self::assertSame('application/json; charset=UTF-8', $json->getHeaders()['Content-Type']);
        self::assertSame('Server one not found', $json->getData()['message']);
    }

    public function testItCreatesAnHtmxRedirectResponse(): void
    {
        $response = ResponseFactory::htmxRedirect('/login');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertSame(['HX-Redirect' => '/login'], $response->getHeaders());
    }
}
