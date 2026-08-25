<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Config\Monitoring\Section\NotificationRulesConfig;
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
        $rule = $this->resolver()->resolveFor('unknown', null);

        $this->assertTrue($rule->enabled);
        $this->assertSame(['email'], $rule->channels);
        $this->assertNull($rule->suppressionReason);
    }

    public function testEventRuleOverlaysFieldsAndCanClearChannels(): void
    {
        $resolver = $this->resolver([
            'event_types' => [
                'disabled' => ['enabled' => false],
                'silent' => ['channels' => []],
            ],
        ]);

        $disabled = $resolver->resolveFor('disabled', null);
        $this->assertFalse($disabled->enabled);
        $this->assertSame(['email'], $disabled->channels);
        $this->assertSame('event_type_disabled', $disabled->suppressionReason);

        $silent = $resolver->resolveFor('silent', null);
        $this->assertTrue($silent->enabled);
        $this->assertSame([], $silent->channels);
        $this->assertNull($silent->suppressionReason);
    }

    public function testDefaultsDisabledTracksDefaultSuppressionReasonAcrossChannelOverlays(): void
    {
        $resolver = $this->resolver([
            'defaults' => ['enabled' => false, 'channels' => ['email']],
            'event_types' => ['fatal' => ['channels' => []]],
        ]);

        $disabled = $resolver->resolveFor('fatal', null);

        $this->assertFalse($disabled->enabled);
        $this->assertSame([], $disabled->channels);
        $this->assertSame('default_disabled', $disabled->suppressionReason);
    }

    public function testApplicationOverrideOnlyChangesChannelsWithoutClaimingInheritedDisable(): void
    {
        $resolver = $this->resolver([
            'event_types' => ['fatal' => ['enabled' => false, 'channels' => ['email']]],
            'application_overrides' => [
                'channels-only-app' => ['fatal' => ['channels' => []]],
            ],
        ]);

        $disabled = $resolver->resolveFor('fatal', 'channels-only-app');

        $this->assertFalse($disabled->enabled);
        $this->assertSame([], $disabled->channels);
        $this->assertSame('event_type_disabled', $disabled->suppressionReason);
    }

    public function testExplicitApplicationEnabledFieldUpdatesSuppressionReason(): void
    {
        $resolver = $this->resolver([
            'event_types' => ['fatal' => ['enabled' => false, 'channels' => []]],
            'application_overrides' => [
                'enabled-app' => ['fatal' => ['enabled' => true, 'channels' => ['email']]],
                'disabled-app' => ['fatal' => ['enabled' => false, 'channels' => ['email']]],
            ],
        ]);

        $enabled = $resolver->resolveFor('fatal', 'enabled-app');
        $this->assertTrue($enabled->enabled);
        $this->assertSame(['email'], $enabled->channels);
        $this->assertNull($enabled->suppressionReason);

        $disabled = $resolver->resolveFor('fatal', 'disabled-app');
        $this->assertFalse($disabled->enabled);
        $this->assertSame(['email'], $disabled->channels);
        $this->assertSame('application_override_disabled', $disabled->suppressionReason);
    }

    /** @param array<string,mixed> $overrides */
    private function resolver(array $overrides = []): NotificationRuleResolver
    {
        $config = array_replace([
            'defaults' => ['enabled' => true, 'channels' => ['email']],
            'event_types' => [],
            'application_overrides' => [],
        ], $overrides);

        return new NotificationRuleResolver(new NotificationRulesConfig(
            defaults: $config['defaults'],
            eventTypes: $config['event_types'],
            applicationOverrides: $config['application_overrides'],
        ));
    }
}
