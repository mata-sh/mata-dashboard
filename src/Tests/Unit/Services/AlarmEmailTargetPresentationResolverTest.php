<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Alarm\AlarmEmailTargetPresentationResolver;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Alarm\AlarmEmailTargetPresentationResolver
 *
 * @internal
 */
final class AlarmEmailTargetPresentationResolverTest extends TestCase
{
    public function testItUsesApplicationEnvironmentBeforeTheServerFallback(): void
    {
        $resolver = new AlarmEmailTargetPresentationResolver([
            'api-1' => ['primary_color' => '#E88C02', 'env' => 'test'],
        ]);

        $presentation = $resolver->resolve('API-1', 'prod');

        $this->assertSame('#E88C02', $presentation['color']);
        $this->assertSame(['label' => 'LIVE', 'color' => '#098f00'], $presentation['environment']);
    }

    public function testItUsesServerEnvironmentWhenApplicationEnvironmentIsUnknown(): void
    {
        $resolver = new AlarmEmailTargetPresentationResolver([
            'api-1' => ['primary_color' => '#E88C02', 'env' => 'staging'],
        ]);

        $presentation = $resolver->resolve('api-1', 'unknown');

        $this->assertSame(['label' => 'STAGING', 'color' => '#663399'], $presentation['environment']);
    }

    public function testItOmitsInvalidServerPresentationValues(): void
    {
        $resolver = new AlarmEmailTargetPresentationResolver([
            'api-1' => ['primary_color' => 'orange', 'env' => 'unknown'],
        ]);

        $presentation = $resolver->resolve('api-1', null);

        $this->assertNull($presentation['color']);
        $this->assertNull($presentation['environment']);
    }
}
