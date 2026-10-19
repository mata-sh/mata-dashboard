<?php

declare(strict_types=1);

namespace Mata\Config\Validation;

use Mata\Services\Status\StatusLevel;

/** Result of validating an instance config directory. */
final readonly class ConfigValidationReport
{
    /**
     * @param array<string,bool>  $files    checked file name => whether it exists
     * @param list<ConfigProblem> $problems
     */
    public function __construct(
        public string $configPath,
        public array $files,
        public array $problems,
    ) {}

    /** Error when the app would ignore a whole file or drop a server; warning for skipped entries and advice. */
    public function level(): StatusLevel
    {
        return StatusLevel::worst(array_map(static fn (ConfigProblem $problem): StatusLevel => $problem->statusLevel(), $this->problems));
    }

    /** ok, warning, or error for one file; missing when it does not exist and nothing was reported. */
    public function fileStatus(string $file): string
    {
        $status = ($this->files[$file] ?? false) ? 'ok' : 'missing';
        foreach ($this->problems as $problem) {
            if ($problem->file !== $file) {
                continue;
            }
            if ($problem->level === ConfigProblem::ERROR) {
                return ConfigProblem::ERROR;
            }
            $status = ConfigProblem::WARNING;
        }

        return $status;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $files = [];
        foreach (array_keys($this->files) as $file) {
            $files[] = ['file' => $file, 'status' => $this->fileStatus($file)];
        }

        return [
            'schema_version' => 1,
            'status' => $this->level()->label(),
            'config_path' => $this->configPath,
            'files' => $files,
            'problems' => array_map(static fn (ConfigProblem $problem): array => $problem->toArray(), $this->problems),
        ];
    }
}
