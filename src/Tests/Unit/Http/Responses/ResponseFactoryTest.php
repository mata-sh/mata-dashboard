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
    public function testItCreatesAnHtmxRedirectResponse(): void
    {
        $response = ResponseFactory::htmxRedirect('/login');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertSame(['HX-Redirect' => '/login'], $response->getHeaders());
    }
}
