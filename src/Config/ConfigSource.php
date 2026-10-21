<?php

declare(strict_types=1);

namespace Mata\Config;

use JsonSerializable;

/**
 *  A ConfigSource is one of three kinds:
 *  - file: the value came from a file such as config.env.
 *  - process: Docker or the web server set the variable directly, so there's no file.
 *  - missing: the variable isn't set anywhere.
 */
final readonly class ConfigSource implements JsonSerializable
{
    /** @param 'file'|'missing'|'process' $kind */
    private function __construct(
        public string $kind,
        public ?string $file = null,
        public ?string $variable = null,
    ) {}

    /** @param string $file name relative to the config directory, e.g. servers.json */
    public static function file(string $file, ?string $variable = null): self
    {
        return new self('file', $file, $variable);
    }

    /** Set in the process environment (Docker, the web server) rather than by a config file. */
    public static function process(string $variable): self
    {
        return new self('process', variable: $variable);
    }

    public static function missing(string $variable): self
    {
        return new self('missing', variable: $variable);
    }

    /** E.g. `config.env:DOMAIN`, `process:DOMAIN`, `servers.json`. */
    public function label(): string
    {
        return ($this->file ?? $this->kind) . ($this->variable === null ? '' : ':' . $this->variable);
    }

    /** @return array{kind: 'file'|'missing'|'process', file: ?string, variable: ?string} */
    public function jsonSerialize(): array
    {
        return ['kind' => $this->kind, 'file' => $this->file, 'variable' => $this->variable];
    }
}
