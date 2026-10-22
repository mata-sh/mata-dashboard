<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use InvalidArgumentException;
use JsonException;
use Mata\Config\ConfigSource;
use Mata\Config\ConfigValueValidator;
use Mata\Config\Monitoring\Loader\Dto\ConfigLoadProblem;
use Mata\Config\Monitoring\Loader\Dto\ConfigLoadResult;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * Loads one JSON config file into one section and owns the failure policy:
 * - a missing optional file yields the defaults without a problem;
 * - any other failure (unreadable, malformed, empty when required, rejected by parse()) rejects the file and reports why;
 * - a rejected file throws, or with fallbackOnFailure yields the defaults plus the problems.
 *
 * @template T of object
 */
abstract class AbstractJsonConfigLoader
{
    /** @var list<ConfigLoadProblem> reported during the current load() */
    private array $problems = [];

    public function __construct(
        private bool $fallbackOnFailure = true,
    ) {}

    abstract protected function fileName(): string;

    /**
     * Event type of a rejected file; a skipped entry reports `<type>_entry`. Persisted, so rename
     * only with a migration.
     */
    abstract protected function problemType(): string;

    /** @return T */
    abstract protected function defaultSection(): object;

    /**
     * Throw to reject the whole file; report() and carry on to skip a single invalid entry.
     *
     * @param array<string, mixed> $config
     * @param string               $filePath for loaders that read a companion file next to it
     *
     * @return T
     *
     * @throws InvalidArgumentException|RuntimeException when the file must be rejected
     */
    abstract protected function parse(array $config, string $filePath): object;

    /** Return true for files setup does not create: their absence then yields the defaults without a problem. */
    protected function isOptional(): bool
    {
        return false;
    }

    /** Return true for a section the app cannot run without: a rejected file is then critical and always alarms. */
    protected function isRequiredToRun(): bool
    {
        return false;
    }

    /**
     * @return ConfigLoadResult<T>
     *
     * @throws MonitoringConfigLoadException without fallbackOnFailure, when the file is rejected
     */
    final public function load(string $configRoot): ConfigLoadResult
    {
        $this->problems = [];
        $filePath = rtrim($configRoot, '/') . '/' . $this->fileName();
        if ($this->isOptional() && !file_exists($filePath)) {
            return new ConfigLoadResult($this->defaultSection(), []);
        }

        try {
            $section = $this->parseFile($filePath);
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $message = "Could not load {$this->fileName()}: {$exception->getMessage()}";
            $this->reportLoadFailure($message, $exception instanceof JsonConfigReadException ? $exception->fileName : null);
            if (!$this->fallbackOnFailure) {
                throw new MonitoringConfigLoadException($message, $this->problems, $exception);
            }
            $section = $this->defaultSection();
        }

        return new ConfigLoadResult($section, $this->problems);
    }

    /** @param ?string $file defaults to fileName() */
    protected function report(string $type, string $message, string $level = LogLevel::WARNING, bool $forceAlarm = false, ?string $file = null): void
    {
        $this->problems[] = new ConfigLoadProblem($type, $message, $level, $forceAlarm, [
            ConfigSource::file($file ?? $this->fileName()),
        ]);
    }

    /** Reports an entry that parse() skipped while keeping the rest of the file. */
    protected function reportInvalidEntry(string $message, ?string $file = null): void
    {
        $this->report($this->problemType() . '_entry', $message, file: $file);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonConfigReadException naming the file, when it is unreadable, malformed or not an object
     */
    protected function readJsonArray(string $filePath): array
    {
        $file = basename($filePath);
        $content = is_file($filePath) && is_readable($filePath) ? file_get_contents($filePath) : false;
        if ($content === false) {
            throw new JsonConfigReadException("cannot read {$file}", $file);
        }

        try {
            $config = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new JsonConfigReadException("{$file} is not valid JSON ({$exception->getMessage()})", $file, previous: $exception);
        }

        // An empty object decodes to the same [] as an empty list.
        if (!ConfigValueValidator::isObject($config)) {
            throw new JsonConfigReadException("{$file} must contain a JSON object", $file);
        }

        return $config;
    }

    /** @return T */
    private function parseFile(string $filePath): object
    {
        $config = $this->readJsonArray($filePath);
        // Required files must say something; an empty optional file means "no overrides".
        if ($config === [] && !$this->isOptional()) {
            throw new RuntimeException('the file is empty');
        }

        return $this->parse($config, $filePath);
    }

    private function reportLoadFailure(string $message, ?string $file): void
    {
        $critical = $this->isRequiredToRun();
        $this->report(
            type: $this->problemType(),
            message: $message,
            level: $critical ? LogLevel::CRITICAL : LogLevel::WARNING,
            forceAlarm: $critical,
            file: $file,
        );
    }
}
