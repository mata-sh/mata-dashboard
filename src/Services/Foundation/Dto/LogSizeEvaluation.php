<?php

declare(strict_types=1);

namespace Mata\Services\Foundation\Dto;

final readonly class LogSizeEvaluation
{
    /** @param array<string,int> $files */
    public function __construct(
        public int $maxSize,
        public string $maxSizeFormatted,
        public string $maxPath,
        public string $level,
        public string $firstLog,
        public array $files,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'max_size' => $this->maxSize,
            'max_size_formatted' => $this->maxSizeFormatted,
            'max_path' => $this->maxPath,
            'level' => $this->level,
            'first_log' => $this->firstLog,
            'files' => $this->files,
        ];
    }
}
