<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Event\CooldownHashFactory;
use Mata\Services\Event\Dto\EventContext;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Event\CooldownHashFactory
 *
 * @internal
 */
final class CooldownHashFactoryTest extends TestCase
{
    public function testForEventUsesCanonicalContextAndNoChannel(): void
    {
        $factory = new CooldownHashFactory();
        $hash = $factory->forEvent(
            'disk_event',
            'srv-1',
            null,
            EventContext::fromArray([
                'threshold' => 90,
                'actual_value' => 91,
                'additional_data' => ['total' => 100],
            ])
        );

        $expected = hash('sha256', json_encode([
            'type' => 'disk_event',
            'server' => 'srv-1',
            'app' => '',
            'context' => '{"threshold":90}',
            'channel' => '',
        ], JSON_UNESCAPED_SLASHES));

        $this->assertSame($expected, $hash);
    }

    public function testForAlarmChannelSeparatesChannels(): void
    {
        $factory = new CooldownHashFactory();
        $context = EventContext::fromArray(['threshold' => 90]);

        $email = $factory->forAlarmChannel('disk_event', 'srv-1', null, $context, 'email');
        $sms = $factory->forAlarmChannel('disk_event', 'srv-1', null, $context, 'sms');

        $this->assertNotSame($email, $sms);
    }
}
