<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mata\Services\Foundation\ProbeClient;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Foundation\ProbeClient
 *
 * @internal
 */
final class ProbeClientTest extends TestCase
{
    public function testAvailabilityNormalizesRedirectAndDoesNotSendAuth(): void
    {
        $history = [];
        $probe = $this->probe([new Response(302)], $history);

        $result = $probe->settle([
            'app' => $probe->checkAvailabilityAsync('https://app.example.test', 2),
        ]);

        $this->assertSame(['app'], array_keys($result));
        $this->assertTrue($result['app']['reachable']);
        $this->assertSame(302, $result['app']['httpCode']);
        $this->assertSame('HEAD', $history[0]['request']->getMethod());
        $this->assertFalse($history[0]['request']->hasHeader('X-Mata-Auth'));
    }

    public function testAvailabilityNormalizesTransportFailure(): void
    {
        $probe = $this->probe([
            new ConnectException('Connection refused', new Request('HEAD', 'https://app.example.test')),
        ]);

        $result = $probe->checkAvailabilityAsync('https://app.example.test', 2)->wait();

        $this->assertFalse($result['reachable']);
        $this->assertNull($result['httpCode']);
        $this->assertSame('Connection refused', $result['error']);
    }

    public function testStatusProbeReportsMaintenanceEvenWhenEndpointReturns503(): void
    {
        $history = [];
        $probe = $this->probe([new Response(503, [], '{"maintenance_mode":true}')], $history);

        $result = $probe->checkStatusAsync('https://app.example.test/status.php', 2)->wait();

        $this->assertFalse($result['reachable']);
        $this->assertTrue($result['maintenance_mode']);
        $this->assertSame(503, $result['httpCode']);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame('application/json', $history[0]['request']->getHeaderLine('Accept'));
        $this->assertFalse($history[0]['request']->hasHeader('X-Mata-Auth'));
    }

    public function testStatusProbeDefaultsMissingMaintenanceModeToFalse(): void
    {
        $probe = $this->probe([new Response(200, [], '{"status":"ok"}')]);

        $result = $probe->checkStatusAsync('https://app.example.test/health/', 2)->wait();

        $this->assertTrue($result['reachable']);
        $this->assertFalse($result['maintenance_mode']);
    }

    public function testStatusProbeRejectsOversizedBody(): void
    {
        $probe = $this->probe([new Response(200, [], str_repeat(' ', 65537))]);

        $result = $probe->checkStatusAsync('https://app.example.test/health/', 2)->wait();

        $this->assertFalse($result['reachable']);
        $this->assertSame('Invalid status endpoint response', $result['error']);
    }

    public function testStatusProbeRejectsInvalidPayloadAndRedirect(): void
    {
        $probe = $this->probe([
            new Response(200, [], '{"maintenance_mode":"true"}'),
            new Response(302, ['Location' => 'https://login.example.test']),
        ]);

        $invalid = $probe->checkStatusAsync('https://app.example.test/health/', 2)->wait();
        $redirect = $probe->checkStatusAsync('https://app.example.test/health/', 2)->wait();

        $this->assertFalse($invalid['reachable']);
        $this->assertFalse($invalid['maintenance_mode']);
        $this->assertSame('Invalid status endpoint response', $invalid['error']);
        $this->assertFalse($redirect['reachable']);
        $this->assertSame(302, $redirect['httpCode']);
    }

    public function testHealthNormalizesHealthyUnhealthyAndMalformedResponses(): void
    {
        $probe = $this->probe([
            new Response(200, [], '{"status":"healthy","maintenance_mode":false}'),
            new Response(503, [], '{"status":"unhealthy"}'),
            new Response(200, [], '{invalid'),
        ]);

        $healthy = $probe->checkHealthAsync('https://node-a.example.test/api/health', 3)->wait();
        $unhealthy = $probe->checkHealthAsync('https://node-b.example.test/api/health', 3)->wait();
        $malformed = $probe->checkHealthAsync('https://node-c.example.test/api/health', 3)->wait();

        $this->assertSame('healthy', $healthy['status']);
        $this->assertSame('unhealthy', $unhealthy['status']);
        $this->assertSame(503, $unhealthy['httpCode']);
        $this->assertSame('unreachable', $malformed['status']);
        $this->assertSame(200, $malformed['httpCode']);
    }

    /**
     * @param list<ConnectException|Response> $responses
     * @param list<array<string,mixed>>       $history
     */
    private function probe(array $responses, array &$history = []): ProbeClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new ProbeClient(true, new Client(['handler' => $stack]));
    }
}
