<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Alarm\AlarmPresenter;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmEnvironment;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Alarm\AlarmPresenter
 *
 * @internal
 */
final class AlarmPresenterTest extends TestCase
{
    private AlarmPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new AlarmPresenter(servers: [
            'api-1' => ['primary_color' => '#E88C02', 'env' => 'test'],
        ]);
    }

    public function testItPreservesTheExistingCompactFatalErrorCount(): void
    {
        $presentation = $this->presenter->present(new Alarm(
            'fatal_errors_occurring',
            'Fatal Error Detected',
            '{"actual_value":2100,"metric":"fatal_error_count"}',
        ), 'api-1', null);

        $this->assertSame('Detected fatal errors above threshold.', $presentation->description);
        $this->assertSame('FATAL_ERRORS_OCCURRING', $presentation->metric?->label);
        $this->assertSame('2.1k', $presentation->metric?->value);
        $this->assertNull($presentation->metric?->threshold);
    }

    public function testItResolvesDiskAndCronjobMetricsWithCapturedThresholds(): void
    {
        $disk = $this->presenter->present(new Alarm(
            'remaining_diskspace_too_low',
            'Remaining diskspace is getting too low',
            '{"actual_value":91,"metric":"disk_space_usage_percent","threshold":90}',
        ), 'api-1', null);
        $cronjobs = $this->presenter->present(new Alarm(
            'cronjobs_failing',
            'Cronjobs failing',
            '{"actual_value":1200,"metric":"cronjobs_failed_count","threshold":0}',
        ), 'api-1', null);

        $this->assertSame('91%', $disk->metric?->value);
        $this->assertSame('threshold: 90%', $disk->metric?->threshold);
        $this->assertSame('1.200', $cronjobs->metric?->value);
        $this->assertSame('threshold: 0', $cronjobs->metric?->threshold);
    }

    public function testItUsesUnknownForMissingOrInvalidMetricContext(): void
    {
        $missing = $this->presenter->present(new Alarm('remaining_diskspace_too_low', 'Disk usage'), 'api-1', null);
        $invalid = $this->presenter->present(new Alarm('cronjobs_failing', 'Cronjobs failing', 'not json'), 'api-1', null);

        $this->assertSame('UNKNOWN', $missing->metric?->value);
        $this->assertNull($missing->metric?->threshold);
        $this->assertSame('UNKNOWN', $invalid->metric?->value);
        $this->assertNull($invalid->metric?->threshold);
    }

    public function testItResolvesHealthStatesAndFallsBackForUnknownTypes(): void
    {
        $unhealthy = $this->presenter->present(new Alarm('node_unhealthy', 'Unhealthy health response'), 'api-1', null);
        $unreachable = $this->presenter->present(new Alarm('node_unreachable', 'No health response'), 'api-1', null);
        $unknown = $this->presenter->present(new Alarm('custom_alarm', 'Custom alarm'), 'api-1', null);

        $this->assertSame('UNHEALTHY', $unhealthy->metric?->value);
        $this->assertSame('UNREACHABLE', $unreachable->metric?->value);
        $this->assertSame('Detected custom alarm.', $unknown->description);
        $this->assertNull($unknown->metric);
    }

    public function testItUsesApplicationEnvironmentBeforeTheServerFallback(): void
    {
        $presentation = $this->presenter->present(new Alarm('custom_alarm', 'Custom alarm'), 'API-1', 'prod');

        $this->assertSame('#E88C02', $presentation->serverColor);
        $this->assertSame(AlarmEnvironment::Prod, $presentation->environment);
        $this->assertSame('PROD', $presentation->environment?->getLabel());
        $this->assertSame('#1bbf0f', $presentation->environment?->getBadgeColor());
    }

    public function testItNormalizesLegacyLiveApplicationEnvironment(): void
    {
        $presentation = $this->presenter->present(new Alarm('custom_alarm', 'Custom alarm'), 'api-1', 'live');

        $this->assertSame(AlarmEnvironment::Prod, $presentation->environment);
        $this->assertSame('PROD', $presentation->environment?->getLabel());
        $this->assertSame('#1bbf0f', $presentation->environment?->getBadgeColor());
    }

    public function testItUsesServerEnvironmentWhenApplicationEnvironmentIsUnknown(): void
    {
        $presenter = new AlarmPresenter(servers: [
            'api-1' => ['primary_color' => '#E88C02', 'env' => 'staging'],
        ]);

        $presentation = $presenter->present(new Alarm('custom_alarm', 'Custom alarm'), 'api-1', 'unknown');

        $this->assertSame(AlarmEnvironment::Staging, $presentation->environment);
        $this->assertSame('STAGING', $presentation->environment?->getLabel());
        $this->assertSame('#663399', $presentation->environment?->getBadgeColor());
    }

    public function testItOmitsInvalidServerPresentationValues(): void
    {
        $presenter = new AlarmPresenter(servers: [
            'api-1' => ['primary_color' => 'orange', 'env' => 'unknown'],
        ]);

        $presentation = $presenter->present(new Alarm('custom_alarm', 'Custom alarm'), 'api-1', null);

        $this->assertNull($presentation->serverColor);
        $this->assertNull($presentation->environment);
    }
}
