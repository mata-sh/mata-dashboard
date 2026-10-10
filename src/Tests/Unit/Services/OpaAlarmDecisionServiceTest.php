<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Mata\Config\Dto\OpaAlarmDecisionConfig;
use Mata\Services\Alarm\Evaluation\Dto\ResolvedNotificationRule;
use Mata\Services\Alarm\Evaluation\OpaAlarmDecisionService;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \Mata\Services\Alarm\Evaluation\OpaAlarmDecisionService
 */
final class OpaAlarmDecisionServiceTest extends TestCase
{
    public function testBatchLimitIsClamped(): void
    {
        $service = new OpaAlarmDecisionService(
            new OpaAlarmDecisionConfig('http://opa.test', '/v1/data/alarms/decision', 1),
            0,
        );

        $this->assertSame(1, $service->getBatchLimit());
    }

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
            new OpaAlarmDecisionConfig('http://opa.test', '/v1/data/alarms/decision', 1),
            10,
            new Client(['base_uri' => 'http://opa.test', 'handler' => $stack])
        );

        $decision = $service->decide(
            [],
            [' Email ', 'email'],
            new ResolvedNotificationRule(true, ['email'], null),
        );

        $payload = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['email'], $payload['input']['channels']);
        $this->assertSame([
            'enabled' => true,
            'channels' => ['email'],
            'suppression_reason' => null,
        ], $payload['input']['notification_rule']);
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
            new OpaAlarmDecisionConfig('http://opa.test', '/v1/data/alarms/decision', 1),
            10,
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

    public function testAllowedResultWithoutChannelsCreatesDashboardOnlyAlarm(): void
    {
        $decision = $this->serviceForResult(['allow' => true, 'priority' => 10])->decide([]);

        $this->assertTrue($decision->allow);
        $this->assertSame([], $decision->channels);
        $this->assertNull($decision->reason);
    }

    public function testNonCandidateOnlyChannelCreatesDashboardOnlyAlarm(): void
    {
        $decision = $this->serviceForResult([
            'allow' => true,
            'channels' => ['webhook'],
        ])->decide([], []);

        $this->assertTrue($decision->allow);
        $this->assertSame([], $decision->channels);
        $this->assertNull($decision->reason);
    }

    public function testMixedCandidateAndNonCandidateChannelsDeliverCandidateSubset(): void
    {
        $decision = $this->serviceForResult([
            'allow' => true,
            'channels' => ['email', 'sms'],
        ])->decide([], ['email']);

        $this->assertTrue($decision->allow);
        $this->assertSame(['email'], $decision->channels);
        $this->assertNull($decision->reason);
    }

    public function testDisabledNotificationRuleDoesNotSuppressAlarm(): void
    {
        $decision = $this->serviceForResult([
            'allow' => true,
            'channels' => ['email'],
        ])->decide([], [], new ResolvedNotificationRule(false, []));

        $this->assertTrue($decision->allow);
        $this->assertSame([], $decision->channels);
        $this->assertNull($decision->reason);
    }

    public function testMalformedChannelsDoNotReplaceADenyReason(): void
    {
        $decision = $this->serviceForResult([
            'allow' => false,
            'channels' => ['email', null],
            'reason' => 'maintenance_window',
        ])->decide([]);

        $this->assertFalse($decision->allow);
        $this->assertSame('maintenance_window', $decision->reason);
    }

    public function testMalformedChannelDeniesWholeResult(): void
    {
        $decision = $this->serviceForResult([
            'allow' => true,
            'channels' => ['email', null],
        ])->decide([]);

        $this->assertFalse($decision->allow);
        $this->assertSame('unsupported_channels', $decision->reason);
    }

    /** @param array<string,mixed> $result */
    private function serviceForResult(array $result): OpaAlarmDecisionService
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['result' => $result], JSON_THROW_ON_ERROR)),
        ]));

        return new OpaAlarmDecisionService(
            new OpaAlarmDecisionConfig('http://opa.test', '/v1/data/alarms/decision', 1),
            10,
            new Client(['base_uri' => 'http://opa.test', 'handler' => $stack])
        );
    }
}
