<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Mata\Services\Foundation\LogAnalyzer;
use Mata\Tests\TestCase;
use Mata\Utils\Tools;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \Mata\Services\Foundation\LogAnalyzer
 *
 * @internal
 */
final class LogAnalyzerTest extends TestCase
{
    private Tools $tools;
    private EventManager&MockObject $eventManager;
    private LogAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tools = new class ('/tmp') extends Tools {
            public function formatKilobytes(int $kilobytes): string
            {
                return $kilobytes . ' KB';
            }
        };
        $this->eventManager = $this->createMock(EventManager::class);
        $this->analyzer = new LogAnalyzer($this->tools, $this->eventManager);
    }

    public function testAnalyzeAppLogsReturnsExpectedStructure(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $appData = [
            'logs' => [
                '/var/log/app/error.log' => 5000,
                '/var/log/app/debug.log' => 15000,
            ],
            'status' => [
                'fatal' => 0,
                'warnings' => 2,
                'total' => 200,
            ],
        ];


        $this->eventManager
            ->expects($this->once())
            ->method('getServerDataThresholdLevel')
            ->with(15000, 'log_filesize_kb')
            ->willReturn('')
        ;

        $this->eventManager->expects($this->never())->method('handle');

        $result = $this->analyzer->analyzeAppLogs(
            $appData,
            'app-1',
            'srv-1',
            $collector,
            alarmsEnabled: true
        );

        $this->assertSame('/var/log/app/debug.log', $result['log_analysis']['max_path']);
        $this->assertSame('15000 KB', $result['log_analysis']['max_size_formatted']);
        $this->assertSame('2', $result['error_analysis']['warning_count']);
    }

    public function testAnalyzeAppLogsTriggersHandleEventForLargeLogFile(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $appData = [
            'logs' => [
                '/tmp/huge.log' => 60000,
            ],
            'status' => [
                'fatal' => 0,
                'warnings' => 0,
                'total' => 10,
            ],
        ];

        $this->eventManager
            ->expects($this->once())
            ->method('getServerDataThresholdLevel')
            ->with(60000, 'log_filesize_kb')
            ->willReturn('alarm')
        ;

        $this->eventManager
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(static fn (EventCommand $event): bool => $event->type === 'logfile_too_large'
                && str_contains($event->message, '60000 KB')
                && $event->level === 'critical'
                && $event->server === 'srv-1'
                && $event->app === 'app-1'
                && $event->context === []
                && $event->metric === null))
            ->willReturn([
                'eventRecorded' => true,
                'eventId' => 1,
            ])
        ;

        $this->analyzer->analyzeAppLogs(
            $appData,
            'app-1',
            'srv-1',
            $collector,
            alarmsEnabled: true
        );
    }

    public function testAnalyzeAppLogsRecordsLargeLogFactWhenNodeAlarmsAreDisabled(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $appData = [
            'logs' => [
                '/tmp/huge.log' => 60000,
            ],
            'status' => [
                'fatal' => 0,
                'warnings' => 0,
                'total' => 10,
            ],
        ];

        $this->eventManager
            ->expects($this->once())
            ->method('getServerDataThresholdLevel')
            ->with(60000, 'log_filesize_kb')
            ->willReturn('alarm')
        ;
        $this->eventManager->expects($this->once())
            ->method('handle')
            ->with($this->callback(static fn (EventCommand $event): bool => $event->type === 'logfile_too_large'));

        $result = $this->analyzer->analyzeAppLogs(
            $appData,
            'app-1',
            'srv-1',
            $collector,
            alarmsEnabled: false
        );

        $this->assertSame('alarm', $result['log_analysis']['level']);
    }

    public function testAnalyzeAppLogsRecordsFatalErrorFactWhenNodeAlarmsAreDisabled(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $appData = [
            'logs' => [
                '/tmp/error.log' => 100,
            ],
            'status' => [
                'fatal' => 3,
                'warnings' => 0,
                'total' => 3,
            ],
        ];

        $this->eventManager
            ->expects($this->once())
            ->method('getServerDataThresholdLevel')
            ->with(100, 'log_filesize_kb')
            ->willReturn('')
        ;

        $this->eventManager
            ->expects($this->once())
            ->method('handle')
            ->with($this->callback(static fn (EventCommand $event): bool => $event->type === 'fatal_errors_occurring'
                && str_contains($event->message, 'Fatal Error Detected')
                && $event->level === 'warning'
                && $event->server === 'srv-1'
                && $event->app === 'app-1'
                && $event->metric?->name === 'fatal_error_count'
                && $event->metric->value === 3))
            ->willReturn([
                'eventRecorded' => true,
                'eventId' => 10,
            ])
        ;

        $result = $this->analyzer->analyzeAppLogs(
            $appData,
            'app-1',
            'srv-1',
            $collector,
            alarmsEnabled: false
        );

        $this->assertSame('', $result['error_analysis']['alarms_enabled_class']);
    }
}
