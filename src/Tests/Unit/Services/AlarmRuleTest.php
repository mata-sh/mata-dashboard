<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Alarm\Dto\AlarmRule;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Alarm\Dto\AlarmRule
 *
 * @internal
 */
final class AlarmRuleTest extends TestCase
{
    public function testItNormalizesConfiguredStrategies(): void
    {
        $consecutive = AlarmRule::fromArray([
            'strategy' => 'consecutive_failures',
            'required_count' => 2,
            'window_minutes' => 10,
        ]);
        $eventCount = AlarmRule::fromArray([
            'alarm_threshold_event_count' => 3,
            'alarm_threshold_window_minutes' => 30,
        ]);
        $immediate = AlarmRule::fromArray(['strategy' => 'immediate']);

        $this->assertSame(AlarmRule::STRATEGY_CONSECUTIVE_FAILURES, $consecutive->strategy);
        $this->assertSame(2, $consecutive->requiredCount);
        $this->assertSame(10, $consecutive->windowMinutes);
        $this->assertSame(AlarmRule::STRATEGY_EVENT_COUNT_WINDOW, $eventCount->strategy);
        $this->assertSame(3, $eventCount->requiredCount);
        $this->assertSame(30, $eventCount->windowMinutes);
        $this->assertSame(AlarmRule::STRATEGY_IMMEDIATE, $immediate->strategy);
    }

    public function testItUsesImmediateRulesForEmptyOrDisabledCountRules(): void
    {
        $empty = AlarmRule::fromArray([]);
        $disabled = AlarmRule::fromArray(['alarm_threshold_event_count' => 0]);

        $this->assertSame(AlarmRule::STRATEGY_IMMEDIATE, $empty->strategy);
        $this->assertSame(AlarmRule::STRATEGY_IMMEDIATE, $disabled->strategy);
    }
}
