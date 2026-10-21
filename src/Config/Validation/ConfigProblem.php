<?php

declare(strict_types=1);

namespace Mata\Config\Validation;

use JsonSerializable;
use Mata\Config\ConfigSource;
use Mata\Services\Status\StatusLevel;

/** One configuration problem, with the sources its loader reported. */
final readonly class ConfigProblem implements JsonSerializable
{
    /**
     * @param StatusLevel::Error|StatusLevel::Warning $level
     * @param list<ConfigSource>                      $sources
     */
    public function __construct(
        public StatusLevel $level,
        public string $type,
        public string $message,
        public array $sources = [],
    ) {}

    /** @return list<string> distinct files behind this problem; process and missing sources have none */
    public function files(): array
    {
        $files = [];
        foreach ($this->sources as $source) {
            if ($source->file !== null && !in_array($source->file, $files, true)) {
                $files[] = $source->file;
            }
        }

        return $files;
    }

    /** @return list<string> e.g. `config.env:DOMAIN`, `servers.json` */
    public function sourceLabels(): array
    {
        $labels = [];
        foreach ($this->sources as $source) {
            $labels[] = $source->label();
        }

        return $labels;
    }

    /**
     * @return array{
     *     files: list<string>,
     *     level: string,
     *     type: string,
     *     message: string,
     *     sources: list<ConfigSource>,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'files' => $this->files(),
            'level' => $this->level->label(),
            'type' => $this->type,
            'message' => $this->message,
            'sources' => $this->sources,
        ];
    }
}
