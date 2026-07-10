<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mata\Services\Collect\ServerDataCollector;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use Mata\Services\Foundation\ApiClient;
use Mata\Services\Foundation\CacheManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Collect\ServerDataCollector
 *
 * @internal
 */
final class ServerDataCollectorTest extends TestCase
{
    public function testNodeJsonHttpErrorReturnsAnErrorEnvelope(): void
    {
        $cache = $this->createMock(CacheManager::class);
        $cache->method('getTtl')->willReturn(60);
        $cache->method('generateCachePath')->willReturn(null);

        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('getApiBase')->willReturn('https://node.example.test/api');
        $apiClient->method('request')->willThrowException(new RequestException(
            'Client error',
            new Request('GET', 'apps'),
            new Response(404, [], '{"error":true,"message":"Unknown application."}'),
        ));

        $collector = new ServerDataCollector(
            new NullLogger(),
            $cache,
            $apiClient,
            new ApiResponseTransformer(new NullLogger()),
        );

        $response = $collector->getApps();

        $this->assertNull($response['result']);
        $this->assertSame('node_response', $response['error']['kind']);
        $this->assertSame('Unknown application.', $response['error']['message']);
        $this->assertSame(404, $response['error']['status_code']);
    }
}
