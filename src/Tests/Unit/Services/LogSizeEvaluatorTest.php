<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Event\EventManager;
use Mata\Services\Monitor\LogSizeEvaluator;
use Mata\Tests\TestCase;
use Mata\Utils\Tools;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \Mata\Services\Monitor\LogSizeEvaluator
 *
 * @internal
 */
final class LogSizeEvaluatorTest extends TestCase
{
    private EventManager&MockObject $events;
    private LogSizeEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->events = $this->createMock(EventManager::class);
        $tools = new class ('/tmp') extends Tools {
            public function formatKilobytes(int $kilobytes): string
            {
                return $kilobytes . ' KB';
            }
        };
        $this->evaluator = new LogSizeEvaluator($tools, $this->events);
    }

    public function testEmptyLogsUseZeroAndMultipleLogsKeepFirstLargestTie(): void
    {
        $this->events->expects($this->exactly(2))->method('getServerDataThresholdLevel')
            ->willReturnOnConsecutiveCalls('', 'alarm');

        $empty = $this->evaluator->evaluate([]);
        $multiple = $this->evaluator->evaluate(['a<&.log' => 100, 'second.log' => 100]);

        $this->assertSame(0, $empty->maxSize);
        $this->assertSame('', $empty->maxPath);
        $this->assertSame('a&lt;&amp;.log', $multiple->maxPath);
        $this->assertSame('a<&.log', $multiple->firstLog);
        $this->assertSame('alarm', $multiple->level);
    }
}
