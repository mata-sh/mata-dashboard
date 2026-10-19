<?php

declare(strict_types=1);

namespace Mata\Config\Validation;

use Mata\Services\Status\StatusLevel;

/** One problem found in an instance config file, as reported by its loader. */
final readonly class ConfigProblem
{
    /** @param StatusLevel::Error|StatusLevel::Warning $level */
    public function __construct(
        public string $file,
        public StatusLevel $level,
        public string $type,
        public string $message,
    ) {}

    public function asError(): self
    {
        return new self($this->file, StatusLevel::Error, $this->type, $this->message);
    }

    /** @return array{file:string,level:string,type:string,message:string} */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'level' => $this->level->label(),
            'type' => $this->type,
            'message' => $this->message,
        ];
    }
}
