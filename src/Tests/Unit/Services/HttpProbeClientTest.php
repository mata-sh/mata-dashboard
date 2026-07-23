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
use Mata\Services\Monitor\HttpProbeClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Monitor\HttpProbeClient
 *
 * @internal
 */
final class HttpProbeClientTest extends TestCase
{
    public function testAvailabilityNormalizesRedirectAndDoesNotSendAuth(): void
    {
        $history = [];
        $probe = $this->probe([new Response(302)], $history);

        $result = $probe->settle([
            'app' => $probe->probeReachabilityAsync('https://app.example.test', 2),
        ]);

        $this->assertSame(['app'], array_keys($result));
        $this->assertTrue($result['app']['reachable']);
        $this->assertSame(302, $result['app']['httpCode']);
        $this->assertSame('HEAD', $history[0]['request']->getMethod());
        $this->assertFalse($history[0]['request']->hasHeader('X-Mata-Auth'));
    }

    public function testAvailabilityFallsBackToGetWhenHeadIsNotAllowed(): void
    {
        $history = [];
        $probe = $this->probe([new Response(405), new Response(200)], $history);

        $result = $probe->probeReachabilityAsync('https://app.example.test', 2)->wait();

        $this->assertTrue($result['reachable']);
        $this->assertSame(200, $result['httpCode']);
        $this->assertSame(['HEAD', 'GET'], array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            $history,
        ));
    }

    public function testReachabilityRecordsLatencyForHttpFailures(): void
    {
        $probe = $this->probe([new Response(500)]);

        $result = $probe->probeReachabilityAsync('https://app.example.test', 2)->wait();

        $this->assertFalse($result['reachable']);
        $this->assertSame(500, $result['httpCode']);
        $this->assertIsInt($result['latency_ms']);
    }

    public function testAvailabilityNormalizesTransportFailure(): void
    {
        $probe = $this->probe([
            new ConnectException('Connection refused', new Request('HEAD', 'https://app.example.test')),
        ]);

        $result = $probe->probeReachabilityAsync('https://app.example.test', 2)->wait();

        $this->assertFalse($result['reachable']);
        $this->assertNull($result['httpCode']);
        $this->assertSame('Connection refused', $result['error']);
    }

    #[DataProvider('transportFailures')]
    public function testTransportFailureClassification(string $message, int $errno, string $kind): void
    {
        $probe = $this->probe([new ConnectException($message, new Request('HEAD', 'https://app.example.test'), null, ['errno' => $errno])]);

        $result = $probe->probeReachabilityAsync('https://app.example.test', 5)->wait();

        $this->assertSame($kind, $result['failure_kind']);
        $this->assertSame($errno, $result['curl_errno']);
    }

    /** @return iterable<string,array{string,int,string}> */
    public static function transportFailures(): iterable
    {
        yield 'dns resolve' => ['Could not resolve host', 6, 'dns'];

        yield 'dns resolving timeout' => ['Resolving timed out after 5000 milliseconds', 28, 'dns'];

        yield 'ssl timeout' => ['SSL connection timeout', 28, 'tls'];

        yield 'connect timeout' => ['Failed to connect: Connection timed out', 28, 'connect'];

        yield 'operation timeout' => ['Operation timed out with 0 bytes received', 28, 'timeout'];
    }

    public function testStatusProbeReportsMaintenanceEvenWhenEndpointReturns503(): void
    {
        $history = [];
        $probe = $this->probe([new Response(503, [], '{"maintenance_mode":true}')], $history);

        $result = $probe->probeStatusEndpointAsync('https://app.example.test/status.php', 2)->wait();

        $this->assertFalse($result['reachable']);
        $this->assertTrue($result['maintenance_mode']);
        $this->assertSame(503, $result['httpCode']);
        $this->assertIsInt($result['latency_ms']);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame('application/json', $history[0]['request']->getHeaderLine('Accept'));
        $this->assertFalse($history[0]['request']->hasHeader('X-Mata-Auth'));
    }

    public function testStatusProbeDefaultsMissingMaintenanceModeToFalse(): void
    {
        $probe = $this->probe([new Response(200, [], '{"status":"ok"}')]);

        $result = $probe->probeStatusEndpointAsync('https://app.example.test/health/', 2)->wait();

        $this->assertTrue($result['reachable']);
        $this->assertFalse($result['maintenance_mode']);
    }

    public function testStatusProbeRejectsOversizedBody(): void
    {
        $probe = $this->probe([new Response(200, [], str_repeat(' ', 65537))]);

        $result = $probe->probeStatusEndpointAsync('https://app.example.test/health/', 2)->wait();

        $this->assertFalse($result['reachable']);
        $this->assertSame('Invalid status endpoint response', $result['error']);
    }

    public function testStatusProbeRejectsInvalidPayloadAndRedirect(): void
    {
        $probe = $this->probe([
            new Response(200, [], '{"maintenance_mode":"true"}'),
            new Response(302, ['Location' => 'https://login.example.test']),
        ]);

        $invalid = $probe->probeStatusEndpointAsync('https://app.example.test/health/', 2)->wait();
        $redirect = $probe->probeStatusEndpointAsync('https://app.example.test/health/', 2)->wait();

        $this->assertFalse($invalid['reachable']);
        $this->assertFalse($invalid['maintenance_mode']);
        $this->assertSame('Invalid status endpoint response', $invalid['error']);
        $this->assertFalse($redirect['reachable']);
        $this->assertSame(302, $redirect['httpCode']);
    }

    public function testHealthProbeRejectsOversizedBody(): void
    {
        $probe = $this->probe([new Response(200, [], str_repeat(' ', 65537))]);

        $result = $probe->probeNodeHealthAsync('https://node.example.test/api/health', 3)->wait();

        $this->assertSame('unreachable', $result['status']);
        $this->assertSame('Health response is too large', $result['error']);
        $this->assertIsInt($result['latency_ms']);
    }

    public function testHealthNormalizesHealthyUnhealthyAndMalformedResponses(): void
    {
        $probe = $this->probe([
            new Response(200, [], '{"status":"healthy","maintenance_mode":false}'),
            new Response(503, [], '{"status":"unhealthy"}'),
            new Response(200, [], '{invalid'),
        ]);

        $healthy = $probe->probeNodeHealthAsync('https://node-a.example.test/api/health', 3)->wait();
        $unhealthy = $probe->probeNodeHealthAsync('https://node-b.example.test/api/health', 3)->wait();
        $malformed = $probe->probeNodeHealthAsync('https://node-c.example.test/api/health', 3)->wait();

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
    private function probe(array $responses, array &$history = []): HttpProbeClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new HttpProbeClient(true, new Client(['handler' => $stack]));
    }
}
