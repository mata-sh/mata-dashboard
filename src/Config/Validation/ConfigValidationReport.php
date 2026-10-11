<?php

declare(strict_types=1);

namespace Mata\Config\Validation;

/** Result of validating an instance config directory. */
final readonly class ConfigValidationReport
{
    public const EXIT_OK = 0;
    public const EXIT_WARNING = 1;
    public const EXIT_ERROR = 2;

    /**
     * @param array<string,bool>  $files    checked file name => whether it exists
     * @param list<ConfigProblem> $problems
     */
    public function __construct(
        public string $configPath,
        public array $files,
        public array $problems,
    ) {}

    public function exitCode(): int
    {
        $code = self::EXIT_OK;
        foreach ($this->problems as $problem) {
            if ($problem->level === ConfigProblem::ERROR) {
                return self::EXIT_ERROR;
            }
            $code = self::EXIT_WARNING;
        }

        return $code;
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
            'status' => match ($this->exitCode()) {
                self::EXIT_ERROR => 'error',
                self::EXIT_WARNING => 'warning',
                default => 'ok',
            },
            'config_path' => $this->configPath,
            'files' => $files,
            'problems' => array_map(static fn (ConfigProblem $problem): array => $problem->toArray(), $this->problems),
        ];
    }
}
