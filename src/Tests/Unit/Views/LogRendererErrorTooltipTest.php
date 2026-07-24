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
