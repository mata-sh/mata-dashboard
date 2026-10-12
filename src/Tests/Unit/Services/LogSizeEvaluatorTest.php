<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Monitor\LogSizeEvaluator;
use Mata\Services\Monitor\MetricThresholds;
use Mata\Tests\TestCase;
use Mata\Utils\Tools;

/**
 * @covers \Mata\Services\Monitor\LogSizeEvaluator
 *
 * @internal
 */
final class LogSizeEvaluatorTest extends TestCase
{
    private LogSizeEvaluator $evaluator;

    protected function setUp(): void
    {
        $tools = new class ('/tmp') extends Tools {
            public function formatKilobytes(int $kilobytes): string
            {
                return $kilobytes . ' KB';
            }
        };
        $this->evaluator = new LogSizeEvaluator($tools, new MetricThresholds(['log_filesize_kb' => ['alarm' => 50]], []));
    }

    public function testEmptyLogsUseZeroAndMultipleLogsKeepFirstLargestTie(): void
    {
        $empty = $this->evaluator->evaluate([]);
        $multiple = $this->evaluator->evaluate(['a<&.log' => 100, 'second.log' => 100]);

        $this->assertSame(0, $empty->maxSize);
        $this->assertSame('', $empty->maxPath);
        $this->assertSame('a&lt;&amp;.log', $multiple->maxPath);
        $this->assertSame('a<&.log', $multiple->firstLog);
        $this->assertSame('alarm', $multiple->level);
    }
}
