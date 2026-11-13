<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Views;

use Mata\Core\App;
use Mata\Http\Routing\Endpoints;
use Mata\Utils\Tools;
use Mata\Views\LogRenderer;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * @covers \Mata\Views\LogRenderer
 *
 * @internal
 */
final class LogRendererErrorTooltipTest extends TestCase
{
    public function testStatesAlarmsOnByDefault(): void
    {
        $tooltip = $this->renderer()->formatErrorStatusTooltip('logs/error.log', 3, 2, 200);

        self::assertStringContainsString("Error Alarms: <span style='color:green'>On</span>", $tooltip);
        self::assertStringContainsString('3 Errors', $tooltip);
    }

    public function testStatesAlarmsOffWithoutHidingObservedCounts(): void
    {
        $tooltip = $this->renderer()->formatErrorStatusTooltip('logs/error.log', 3, 2, 200, false);

        self::assertStringContainsString("Error Alarms: <span style='color:gray'>Off</span>", $tooltip);
        self::assertStringContainsString('3 Errors', $tooltip);
        self::assertStringContainsString('2 Warnings', $tooltip);
    }

    public function testStatesAlarmStateOnHealthyApps(): void
    {
        $tooltip = $this->renderer()->formatErrorStatusTooltip('logs/error.log', 0, 0, 200, false);

        self::assertStringContainsString('✓ Works fine!', $tooltip);
        self::assertStringContainsString("Error Alarms: <span style='color:gray'>Off</span>", $tooltip);
    }

    public function testAnalysisTooltipReadsTheMonitoringFlag(): void
    {
        $tooltip = $this->renderer()->formatErrorAnalysisTooltip([
            'first_log' => 'logs/error.log',
            'fatal_count_raw' => 7,
            'warning_count_raw' => 0,
            'total_count_raw' => 4231,
            'error_monitoring_enabled' => false,
        ]);

        self::assertStringContainsString("Error Alarms: <span style='color:gray'>Off</span>", $tooltip);
        self::assertStringContainsString('7 Errors', $tooltip);
    }

    public function testAnalysisTooltipDefaultsToOnWhenFlagIsAbsent(): void
    {
        $tooltip = $this->renderer()->formatErrorAnalysisTooltip([
            'first_log' => 'logs/error.log',
            'fatal_count_raw' => 0,
            'warning_count_raw' => 0,
            'total_count_raw' => 12,
        ]);

        self::assertStringContainsString("Error Alarms: <span style='color:green'>On</span>", $tooltip);
    }

    public function testAnalysisTooltipStatesAlarmsOffWhenAppAlarmsAreDisabled(): void
    {
        $tooltip = $this->renderer()->formatErrorAnalysisTooltip([
            'first_log' => 'logs/error.log',
            'fatal_count_raw' => 2,
            'warning_count_raw' => 0,
            'total_count_raw' => 12,
            'error_monitoring_enabled' => true,
            'alarms_enabled' => false,
        ]);

        self::assertStringContainsString("Error Alarms: <span style='color:gray'>Off</span>", $tooltip);
        self::assertStringContainsString('2 Errors', $tooltip);
    }

    public function testDoesNotClaimHealthyLogsWhenLogAnalysisIsDisabled(): void
    {
        $tooltip = $this->renderer()->formatErrorStatusTooltip('logs/error.log', 0, 0, 0, true, false);

        self::assertStringContainsString('Log analysis disabled', $tooltip);
        self::assertStringNotContainsString('✓ Works fine!', $tooltip);
        self::assertStringNotContainsString('Total Lines', $tooltip);
        self::assertStringContainsString("Error Alarms: <span style='color:green'>On</span>", $tooltip);
    }

    public function testAnalysisTooltipReadsTheLogAnalysisFlag(): void
    {
        $tooltip = $this->renderer()->formatErrorAnalysisTooltip([
            'first_log' => 'logs/error.log',
            'fatal_count_raw' => 0,
            'warning_count_raw' => 0,
            'total_count_raw' => 0,
            'loganalysis_enabled' => false,
        ]);

        self::assertStringContainsString('Log analysis disabled', $tooltip);
        self::assertStringNotContainsString('✓ Works fine!', $tooltip);
    }

    public function testLogAnalysisTooltipStatesDisabledLogSizeMonitoring(): void
    {
        $tooltip = $this->renderer()->formatLogAnalysisTooltip([
            'files' => [],
            'logsize_monitoring_enabled' => false,
        ]);

        self::assertSame('Log size monitoring disabled', $tooltip);
    }

    public function testLogAnalysisTooltipKeepsSizeBreakdownWhenEnabled(): void
    {
        $tools = $this->createStub(Tools::class);
        $tools->method('formatKilobytes')->willReturn('1 KB');

        $renderer = new LogRenderer(
            $this->createStub(Environment::class),
            new Logger('test'),
            $tools,
            $this->createStub(App::class),
            $this->createStub(Endpoints::class),
        );

        $tooltip = $renderer->formatLogAnalysisTooltip([
            'files' => ['logs/error.log' => 1024],
            'logsize_monitoring_enabled' => true,
        ]);

        self::assertStringContainsString('logs/error.log <strong>1 KB</strong>', $tooltip);
    }

    private function renderer(): LogRenderer
    {
        return new LogRenderer(
            $this->createStub(Environment::class),
            new Logger('test'),
            $this->createStub(Tools::class),
            $this->createStub(App::class),
            $this->createStub(Endpoints::class),
        );
    }
}
