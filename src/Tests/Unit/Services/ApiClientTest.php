<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Response;
use Mata\Services\Foundation\ApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Foundation\ApiClient
 *
 * @internal
 */
final class ApiClientTest extends TestCase
{
    public function testConcurrentRequestsReceiveUniqueValidAuthenticationTokens(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"request":1}'),
            new Response(200, [], '{"request":2}'),
        ]));
        $apiClient = $this->apiClient($stack);
        $stack->push(Middleware::history($history));

        Utils::all([
            $apiClient->requestAsync('GET', 'server/data', [
                'headers' => ['X-Mata-Auth' => 'reused-token'],
            ]),
            $apiClient->requestAsync('GET', 'apps'),
        ])->wait();

        $this->assertCount(2, $history);
        $firstToken = $history[0]['request']->getHeaderLine('X-Mata-Auth');
        $secondToken = $history[1]['request']->getHeaderLine('X-Mata-Auth');

        $this->assertNotSame('reused-token', $firstToken);
        $this->assertNotSame($firstToken, $secondToken);
        $this->assertValidToken($firstToken);
        $this->assertValidToken($secondToken);
    }

    public function testRedirectedHttpRequestReceivesANewAuthenticationToken(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(302, ['Location' => '/api/apps']),
            new Response(200, [], '{"apps":[]}'),
        ]));
        $apiClient = $this->apiClient($stack);
        $stack->push(Middleware::history($history));

        $apiClient->request('GET', 'server/data');

        $this->assertCount(2, $history);
        $firstToken = $history[0]['request']->getHeaderLine('X-Mata-Auth');
        $secondToken = $history[1]['request']->getHeaderLine('X-Mata-Auth');
        $this->assertNotSame($firstToken, $secondToken);
        $this->assertValidToken($firstToken);
        $this->assertValidToken($secondToken);
    }

    private function apiClient(HandlerStack $stack): ApiClient
    {
        return new ApiClient(
            new NullLogger(),
            'https://node.example.test/api',
            'node-1',
            'shared-secret',
            handlerStack: $stack
        );
    }

    private function assertValidToken(string $token): void
    {
        $parts = explode('|', $token);

        $this->assertCount(4, $parts);
        [$server, $timestamp, $nonce, $signature] = $parts;
        $this->assertSame('node-1', $server);
        $this->assertMatchesRegularExpression('/^\d{10}$/', $timestamp);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $nonce);
        $this->assertSame(
            hash_hmac('sha256', $server . '|' . $timestamp . '|' . $nonce, 'shared-secret'),
            $signature
        );
    }
}
