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
        return self::worst($this->problems);
    }

    /** The worst level reported for one file; null when it does not exist and nothing was reported. */
    public function fileStatus(string $file): ?StatusLevel
    {
        $problems = array_filter($this->problems, static fn (ConfigProblem $problem): bool => $problem->file === $file);
        if ($problems === [] && !($this->files[$file] ?? false)) {
            return null;
        }

        return self::worst($problems);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $files = [];
        foreach (array_keys($this->files) as $file) {
            $files[] = ['file' => $file, 'status' => $this->fileStatus($file)?->label() ?? 'missing'];
        }

        return [
            'status' => $this->level()->label(),
            'config_path' => $this->configPath,
            'files' => $files,
            'problems' => array_map(static fn (ConfigProblem $problem): array => $problem->toArray(), $this->problems),
        ];
    }

    /** @param array<ConfigProblem> $problems */
    private static function worst(array $problems): StatusLevel
    {
        return StatusLevel::worst(array_map(static fn (ConfigProblem $problem): StatusLevel => $problem->level, $problems));
    }
}
