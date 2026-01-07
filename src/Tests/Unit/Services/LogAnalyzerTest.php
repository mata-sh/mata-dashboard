<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Services;

use PHPUnit\Framework\MockObject\MockObject;
use Watcher\Services\Collect\ServerDataCollectorInterface;
use Watcher\Services\Foundation\EventManager;
use Watcher\Services\Foundation\LogAnalyzer;
use Watcher\Tests\TestCase;
use Watcher\Utils\Tools;

/**
 * @covers \Watcher\Services\Foundation\LogAnalyzer
 *
 * @internal
 */
final class LogAnalyzerTest extends TestCase
{
    private MockObject&Tools $tools;
    private EventManager&MockObject $eventManager;
    private LogAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tools = $this->createMock(Tools::class);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->analyzer = new LogAnalyzer($this->tools, $this->eventManager);
    }

    public function testAnalyzeAppLogsReturnsExpectedStructure(): void
    {
        $collector = $this->createMock(ServerDataCollectorInterface::class);
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

        $this->tools->method('convertSize')->willReturnCallback(static fn (int $size): string => $size . ' KB');
        $this->tools->method('formatNumber')->willReturnCallback(static fn (int $value): string => (string) $value);

        $this->eventManager
            ->expects($this->once())
            ->method('getServerDataThresholdLevel')
            ->with(15000, 'log_filesize_kb')
            ->willReturn('')
        ;

        $this->eventManager->expects($this->never())->method('handleEvent');

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
        $collector = $this->createMock(ServerDataCollectorInterface::class);
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

        $this->tools->method('convertSize')->willReturn('60000 KB');
        $this->tools->method('formatNumber')->willReturnCallback(static fn (int $value): string => (string) $value);

        $this->eventManager
            ->expects($this->once())
            ->method('getServerDataThresholdLevel')
            ->with(60000, 'log_filesize_kb')
            ->willReturn('alarm')
        ;

        $this->eventManager
            ->expects($this->once())
            ->method('handleEvent')
            ->with(
                'logfile_too_large',
                $this->callback(static fn (string $message): bool => str_contains($message, '60000 KB')),
                [],
                'critical',
                'srv-1',
                'app-1',
                false
            )
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

    public function testAnalyzeAppLogsTriggersHandleEventForFatalErrors(): void
    {
        $collector = $this->createMock(ServerDataCollectorInterface::class);
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

        $this->tools->method('convertSize')->willReturn('100 KB');
        $this->tools->method('formatNumber')->willReturnCallback(static fn (int $value): string => (string) $value);

        $this->eventManager
            ->expects($this->once())
            ->method('getServerDataThresholdLevel')
            ->with(100, 'log_filesize_kb')
            ->willReturn('')
        ;

        $this->eventManager
            ->expects($this->once())
            ->method('handleEvent')
            ->with(
                'fatal_errors_occuring',
                $this->callback(static fn (string $message): bool => str_contains($message, 'Fatal Error Detected')),
                [],
                'warning',
                'srv-1',
                'app-1',
                false
            )
            ->willReturn([
                'eventRecorded' => true,
                'eventId' => 10,
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
}
