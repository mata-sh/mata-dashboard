<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\Dto\EventContext;
use Mata\Services\Event\Dto\EventMetric;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Event\Dto\EventContext
 *
 * @internal
 */
final class EventContextTest extends TestCase
{
    public function testEventCommandMetricOverridesConflictingContextFields(): void
    {
        $context = (new EventCommand(
            type: 'disk_event',
            message: 'Disk usage',
            context: [
                'actual_value' => -1,
                'metric' => 'caller_metric',
                'threshold' => -1,
            ],
            metric: new EventMetric('disk_space_usage_percent', 91, 90, ['total' => 100])
        ))->eventContext();

        $this->assertSame([
            'actual_value' => 91,
            'additional_data' => ['total' => 100],
            'metric' => 'disk_space_usage_percent',
            'threshold' => 90,
        ], $context->toArray());
    }

    public function testForCooldownIdentityStripsIgnoredMetricFields(): void
    {
        $context = EventContext::fromArray([
            'metric' => 'disk_space_usage_percent',
            'actual_value' => 91,
            'threshold' => 90,
            'additional_data' => ['total' => 100],
            'server' => 'api-1',
        ]);

        $this->assertSame([
            'metric' => 'disk_space_usage_percent',
            'server' => 'api-1',
            'threshold' => 90,
        ], $context->forCooldownIdentity()->toArray());
    }

    public function testForCooldownIdentityReturnsTheSameContextWhenNoIgnoredFieldsExist(): void
    {
        $context = EventContext::fromArray(['metric' => 'fatal_error_count']);

        $this->assertSame($context, $context->forCooldownIdentity());
    }

    public function testForCooldownIdentityCollapsesAnOnlyIgnoredContextToTheEmptySingleton(): void
    {
        $context = EventContext::fromArray(['actual_value' => 1, 'additional_data' => ['error' => 'timeout']]);

        $this->assertSame(EventContext::empty(), $context->forCooldownIdentity());
        $this->assertSame('', $context->forCooldownIdentity()->toJson());
    }
}
