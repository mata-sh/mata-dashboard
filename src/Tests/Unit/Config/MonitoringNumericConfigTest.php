<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use InvalidArgumentException;
use Mata\Config\Monitoring\Section\AlarmRateLimit;
use Mata\Config\Monitoring\Section\DataRetentionConfig;
use Mata\Config\Monitoring\Section\IntervalsConfig;
use Mata\Config\Monitoring\Section\ThresholdsConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class MonitoringNumericConfigTest extends TestCase
{
    #[DataProvider('invalidIntegerValues')]
    public function testIntervalsRejectUnsupportedIntegerTypes(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('intervals.events.min_event_interval_minutes.custom must be an integer.');

        IntervalsConfig::fromArray([
            'events' => ['min_event_interval_minutes' => ['custom' => $value]],
        ]);
    }

    #[DataProvider('invalidIntegerValues')]
    public function testDataRetentionRejectsUnsupportedIntegerTypes(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('data_retention.pruning.batch_limit must be an integer.');

        DataRetentionConfig::fromArray(['pruning' => ['batch_limit' => $value]]);
    }

    #[DataProvider('invalidIntegerValues')]
    public function testAlarmRateLimitRejectsUnsupportedIntegerTypes(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limits.channels.email.per_minute must be an integer.');

        AlarmRateLimit::fromArray(['per_minute' => $value], path: 'limits.channels.email');
    }

    #[DataProvider('invalidIntegerValues')]
    public function testThresholdAlarmRuleReportsAndReplacesUnsupportedIntegerTypes(mixed $value): void
    {
        $messages = [];
        $config = ThresholdsConfig::fromArray([
            'alarms' => [
                'app_unreachable' => [
                    'strategy' => 'consecutive_failures',
                    'required_count' => $value,
                    'window_minutes' => 10,
                ],
            ],
        ], static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->assertSame(2, $config->alarmRules['app_unreachable']['required_count']);
        $this->assertStringContainsString(
            'thresholds.alarms.app_unreachable.required_count must be an integer.',
            $messages[0],
        );
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalidIntegerValues(): iterable
    {
        yield 'float' => [1.0];

        yield 'numeric string' => ['1'];

        yield 'boolean' => [true];

        yield 'array' => [[]];

        yield 'object' => [(object) []];

        yield 'null' => [null];
    }
}
