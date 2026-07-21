<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Alarm\NotificationRuleResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Alarm\NotificationRuleResolver
 *
 * @internal
 */
final class NotificationRuleResolverTest extends TestCase
{
    public function testDefaultsAreInherited(): void
    {
        $rule = $this->resolver()->resolve('unknown', null);

        $this->assertTrue($rule->enabled);
        $this->assertSame(['email'], $rule->channels);
        $this->assertNull($rule->reason);
    }

    public function testEventRuleOverlaysFieldsAndCanClearChannels(): void
    {
        $resolver = $this->resolver([
            'event_types' => [
                'disabled' => ['enabled' => false],
                'silent' => ['channels' => []],
            ],
        ]);

        $disabled = $resolver->resolve('disabled', null);
        $this->assertFalse($disabled->enabled);
        $this->assertSame(['email'], $disabled->channels);
        $this->assertSame('event_type_disabled', $disabled->reason);

        $silent = $resolver->resolve('silent', null);
        $this->assertTrue($silent->enabled);
        $this->assertSame([], $silent->channels);
        $this->assertNull($silent->reason);
    }

    public function testDefaultsDisabledTracksDefaultReasonAcrossChannelOverlays(): void
    {
        $resolver = $this->resolver([
            'defaults' => ['enabled' => false, 'channels' => ['email']],
            'event_types' => ['fatal' => ['channels' => []]],
        ]);

        $disabled = $resolver->resolve('fatal', null);

        $this->assertFalse($disabled->enabled);
        $this->assertSame([], $disabled->channels);
        $this->assertSame('default_disabled', $disabled->reason);
    }

    public function testApplicationOverrideOnlyChangesChannelsWithoutClaimingInheritedDisable(): void
    {
        $resolver = $this->resolver([
            'event_types' => ['fatal' => ['enabled' => false, 'channels' => ['email']]],
            'application_overrides' => [
                'channels-only-app' => ['fatal' => ['channels' => []]],
            ],
        ]);

        $disabled = $resolver->resolve('fatal', 'channels-only-app');

        $this->assertFalse($disabled->enabled);
        $this->assertSame([], $disabled->channels);
        $this->assertSame('event_type_disabled', $disabled->reason);
    }

    public function testExplicitApplicationEnabledFieldUpdatesDisableReason(): void
    {
        $resolver = $this->resolver([
            'event_types' => ['fatal' => ['enabled' => false, 'channels' => []]],
            'application_overrides' => [
                'enabled-app' => ['fatal' => ['enabled' => true, 'channels' => ['email']]],
                'disabled-app' => ['fatal' => ['enabled' => false, 'channels' => ['email']]],
            ],
        ]);

        $enabled = $resolver->resolve('fatal', 'enabled-app');
        $this->assertTrue($enabled->enabled);
        $this->assertSame(['email'], $enabled->channels);
        $this->assertNull($enabled->reason);

        $disabled = $resolver->resolve('fatal', 'disabled-app');
        $this->assertFalse($disabled->enabled);
        $this->assertSame(['email'], $disabled->channels);
        $this->assertSame('application_override_disabled', $disabled->reason);
    }

    /** @param array<string,mixed> $overrides */
    private function resolver(array $overrides = []): NotificationRuleResolver
    {
        return new NotificationRuleResolver(array_replace([
            'defaults' => ['enabled' => true, 'channels' => ['email']],
            'event_types' => [],
            'application_overrides' => [],
        ], $overrides));
    }
}
