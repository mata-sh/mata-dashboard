<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Alarm\AlarmEmailPresentationResolver;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Alarm\AlarmEmailPresentationResolver
 *
 * @internal
 */
final class AlarmEmailPresentationResolverTest extends TestCase
{
    private AlarmEmailPresentationResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AlarmEmailPresentationResolver([
            'server_data' => [
                'disk_space_usage_percent' => ['alarm' => 90],
                'cronjobs_failed_count' => ['alarm' => 0],
            ],
        ]);
    }

    public function testItPreservesTheExistingCompactFatalErrorCount(): void
    {
        $presentation = $this->resolver->resolve(new Alarm(
            'fatal_errors_occurring',
            'Fatal Error Detected (Count: 2.1k): Shop on Server: api-1',
        ));

        $this->assertSame('Detected fatal errors above threshold.', $presentation['description']);
        $this->assertSame('FATAL_ERRORS_OCCURRING', $presentation['metric']['label']);
        $this->assertSame('2.1k', $presentation['metric']['value']);
        $this->assertNull($presentation['metric']['threshold']);
    }

    public function testItResolvesDiskAndCronjobMetricsWithConfiguredThresholds(): void
    {
        $disk = $this->resolver->resolve(new Alarm(
            'remaining_diskspace_too_low',
            'Remaining diskspace is getting too low (91 % used) on Server: api-1',
        ));
        $cronjobs = $this->resolver->resolve(new Alarm(
            'cronjobs_failing',
            'Cronjobs failing (1200/1500, stale: 2, missing: 1) on Server: api-1',
        ));

        $this->assertSame('91%', $disk['metric']['value']);
        $this->assertSame('threshold: 90%', $disk['metric']['threshold']);
        $this->assertSame('1.200', $cronjobs['metric']['value']);
        $this->assertSame('threshold: 0', $cronjobs['metric']['threshold']);
    }

    public function testItResolvesHealthStatesAndFallsBackForUnknownTypes(): void
    {
        $unhealthy = $this->resolver->resolve(new Alarm('node_unhealthy', 'Unhealthy health response'));
        $unreachable = $this->resolver->resolve(new Alarm('node_unreachable', 'No health response'));
        $unknown = $this->resolver->resolve(new Alarm('custom_alarm', 'Custom alarm'));

        $this->assertSame('UNHEALTHY', $unhealthy['metric']['value']);
        $this->assertSame('UNREACHABLE', $unreachable['metric']['value']);
        $this->assertSame('Detected custom alarm.', $unknown['description']);
        $this->assertNull($unknown['metric']);
    }
}
