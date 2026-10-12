<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Services\Monitor\Dto\LogSizeEvaluation;
use Mata\Utils\Tools;

final class LogSizeEvaluator
{
    public function __construct(
        private Tools $tools,
        private MetricThresholds $thresholds,
    ) {}

    /** @param array<string,int> $logs */
    public function evaluate(array $logs): LogSizeEvaluation
    {
        $maxSize = 0;
        $maxPath = '';
        $firstLog = '';

        foreach ($logs as $path => $size) {
            $firstLog = $firstLog ?: $path;
            if ($size > $maxSize) {
                $maxSize = $size;
                $maxPath = htmlspecialchars($path);
            }
        }

        return new LogSizeEvaluation(
            maxSize: $maxSize,
            maxSizeFormatted: $this->tools->formatKilobytes($maxSize),
            maxPath: $maxPath,
            level: $this->thresholds->serverLevel($maxSize, 'log_filesize_kb'),
            firstLog: $firstLog,
            files: $logs,
        );
    }
}
