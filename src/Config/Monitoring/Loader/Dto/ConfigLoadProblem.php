<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader\Dto;

use Mata\Config\ConfigSource;
use Psr\Log\LogLevel;

/**
 * A problem a loader recovered from, or rejected its file for. The loader only describes it:
 * the app records it as an event (EventCommand::fromConfigProblem), `bin/mata config validate`
 * prints it.
 */
final readonly class ConfigLoadProblem
{
    /**
     * @param bool               $forceAlarm alarm regardless of thresholds; only a servers config the app cannot load does
     * @param list<ConfigSource> $sources
     */
    public function __construct(
        public string $type,
        public string $message,
        public string $level = LogLevel::WARNING,
        public bool $forceAlarm = false,
        public array $sources = [],
    ) {}
}
