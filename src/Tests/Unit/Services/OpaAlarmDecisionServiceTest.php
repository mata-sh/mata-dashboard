<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Mata\Config\Dto\OpaAlarmDecisionConfig;
use Mata\Services\Alarm\Decision\OpaAlarmDecisionService;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \Mata\Services\Alarm\Decision\OpaAlarmDecisionService
 */
final class OpaAlarmDecisionServiceTest extends TestCase
{
    public function testSupportedChannelsAreNormalizedForInputAndDecisions(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'result' => ['allow' => true, 'channels' => ['Email', 'email']],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $service = new OpaAlarmDecisionService(
            new OpaAlarmDecisionConfig('http://opa.test', '/v1/data/alarms/decision', 1, 10),
            new Client(['base_uri' => 'http://opa.test', 'handler' => $stack])
        );

        $decision = $service->decide([], [' Email ', 'email']);

        $payload = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['email'], $payload['input']['channels']);
        $this->assertSame(['email'], $decision->channels);
    }

    public function testDecisionInputDoesNotContainMetricsOrCounts(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'result' => ['allow' => false, 'channels' => [], 'priority' => 100, 'enrich' => false],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $service = new OpaAlarmDecisionService(
            new OpaAlarmDecisionConfig('http://opa.test', '/v1/data/alarms/decision', 1, 10),
            new Client(['base_uri' => 'http://opa.test', 'handler' => $stack])
        );

        $service->decide([
            'id' => 42,
            'type' => 'remaining_diskspace_too_low',
            'level' => 'critical',
            'context' => '{"threshold": 0.9, "env": "prod"}',
            'metrics' => [
                'counts' => ['same_type_level_or_higher' => ['1h' => 4]],
                'last_event_at' => '2026-07-11T12:00:00Z',
            ],
        ]);

        $payload = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0.9, $payload['input']['event']['threshold']);
        $this->assertSame('prod', $payload['input']['application']['env']);
        $this->assertArrayNotHasKey('metrics', $payload['input']);
        $this->assertArrayNotHasKey('counts', $payload['input']['event']);
    }
}
