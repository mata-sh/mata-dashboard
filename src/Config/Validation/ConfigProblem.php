<?php

declare(strict_types=1);

namespace Mata\Config\Validation;

/** One problem found in an instance config file, as reported by its loader. */
final readonly class ConfigProblem
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    /** @param self::ERROR|self::WARNING $level */
    public function __construct(
        public string $file,
        public string $level,
        public string $type,
        public string $message,
    ) {}

    public function asError(): self
    {
        return new self($this->file, self::ERROR, $this->type, $this->message);
    }

    /** @return array{file:string,level:string,type:string,message:string} */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'level' => $this->level,
            'type' => $this->type,
            'message' => $this->message,
        ];
    }
}
