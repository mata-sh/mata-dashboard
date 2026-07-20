<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Alarm\Decision\BuiltinAlarmDecisionService;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Alarm\Decision\BuiltinAlarmDecisionService
 *
 * @internal
 */
final class BuiltinAlarmDecisionServiceTest extends TestCase
{
    public function testCriticalLevelIsAllowed(): void
    {
        $decision = (new BuiltinAlarmDecisionService())->decide(['level' => 'critical']);

        $this->assertTrue($decision->allow);
        $this->assertSame(['email'], $decision->channels);
        $this->assertSame(10, $decision->priority);
        $this->assertFalse($decision->enrich);
        $this->assertSame('builtin_critical_level', $decision->reason);
    }

    public function testWarningGenericTypeIsDenied(): void
    {
        $decision = (new BuiltinAlarmDecisionService())->decide([
            'level' => 'warning',
            'type' => 'disk_space_low',
        ]);

        $this->assertFalse($decision->allow);
        $this->assertSame([], $decision->channels);
        $this->assertSame(100, $decision->priority);
        $this->assertSame('builtin_default_deny', $decision->reason);
    }

    public function testFatalErrorsAreAllowedAtWarningLevel(): void
    {
        $decision = (new BuiltinAlarmDecisionService())->decide([
            'level' => 'warning',
            'type' => 'fatal_errors_occurring',
        ]);

        $this->assertTrue($decision->allow);
        $this->assertSame(30, $decision->priority);
        $this->assertSame('builtin_fatal_errors', $decision->reason);
    }

    public function testMissingEmailChannelDeniesAllowedDecision(): void
    {
        $decision = (new BuiltinAlarmDecisionService())->decide([
            'level' => 'critical',
        ], [' SMS ']);

        $this->assertFalse($decision->allow);
        $this->assertSame([], $decision->channels);
        $this->assertSame(10, $decision->priority);
        $this->assertSame('no_supported_channels', $decision->reason);
    }

    public function testReturnsConfiguredBatchLimit(): void
    {
        $this->assertSame(25, (new BuiltinAlarmDecisionService(25))->getBatchLimit());
    }
}
