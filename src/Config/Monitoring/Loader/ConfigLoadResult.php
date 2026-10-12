<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader\Dto;

/**
 * A loaded config section with the problems found while loading it; a section that fell back
 * to defaults still carries the problem that caused it.
 *
 * @template-covariant T of object
 */
final readonly class ConfigLoadResult
{
    /**
     * @param T                       $section
     * @param list<ConfigLoadProblem> $problems
     */
    public function __construct(
        public object $section,
        public array $problems = [],
    ) {}
}
