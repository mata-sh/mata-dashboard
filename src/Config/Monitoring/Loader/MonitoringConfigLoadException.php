<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Loader\Dto\ConfigLoadProblem;
use RuntimeException;
use Throwable;

/** A config file the loader rejected; carries the problems found up to the rejection. */
final class MonitoringConfigLoadException extends RuntimeException
{
    /** @param list<ConfigLoadProblem> $problems */
    public function __construct(
        string $message,
        public readonly array $problems = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @param list<ConfigLoadProblem> $earlier problems of sections loaded before this one */
    public function after(array $earlier): self
    {
        return new self($this->getMessage(), [...$earlier, ...$this->problems], $this->getPrevious());
    }
}
