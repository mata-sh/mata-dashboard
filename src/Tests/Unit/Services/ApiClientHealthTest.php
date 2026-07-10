<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mata\Services\Foundation\ApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * @covers \Mata\Services\Foundation\ApiClient
 *
 * @internal
 */
final class ApiClientHealthTest extends TestCase
{
    public function testParsesUnhealthy503HealthBody(): void
    {
        $client = $this->withHandler(new Response(503, [], json_encode([
            'status' => 'unhealthy',
            'timestamp' => '2026-07-10T12:00:00Z',
            'maintenance_mode' => false,
            'checks' => [
                'logs_writable' => true,
                'cache_writable' => false,
                'config_exists' => true,
            ],
        ], JSON_THROW_ON_ERROR)));

        $result = $client->getHealth();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertSame(503, $result['httpCode']);
        $this->assertFalse($result['maintenance_mode']);
        $this->assertSame(false, $result['checks']['cache_writable']);
        $this->assertSame('2026-07-10T12:00:00Z', $result['node_timestamp']);
        $this->assertNull($result['error']);
    }

    public function testNormalizesNetworkErrorAsUnreachable(): void
    {
        $client = $this->withHandler(new ConnectException(
            'Connection refused',
            new Request('GET', 'http://node.example.test/api/health')
        ));

        $result = $client->getHealth();

        $this->assertSame('unreachable', $result['status']);
        $this->assertNull($result['httpCode']);
        $this->assertNull($result['maintenance_mode']);
        $this->assertNull($result['checks']);
        $this->assertSame('Connection refused', $result['error']);
    }

    private function withHandler(ConnectException|Response $response): ApiClient
    {
        $apiClient = new ApiClient(
            new NullLogger(),
            'http://node.example.test/api',
            'token',
            10,
            false,
            true,
            true,
        );
        $guzzleClient = new Client([
            'base_uri' => 'http://node.example.test/api/',
            'handler' => HandlerStack::create(new MockHandler([$response])),
        ]);
        $property = new ReflectionProperty(ApiClient::class, 'client');
        $property->setValue($apiClient, $guzzleClient);

        return $apiClient;
    }
}
