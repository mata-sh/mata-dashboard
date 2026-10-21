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
 * - any other failure (unreadable, malformed, empty when required, rejected by parse()) is reported and rejects the file;
 * - with fallbackOnFailure, load() turns that rejection into the defaults plus the problems.
 *
 * @template T of object
 */
abstract class AbstractJsonConfigLoader
{
    /** @var list<ConfigLoadProblem> */
    private array $problems = [];

    public function __construct(
        private bool $fallbackOnFailure = true,
    ) {}

    abstract protected function fileName(): string;

    /** Event type reported when the file is rejected; persisted, so rename only with a migration. */
    abstract protected function loadFailureType(): string;

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

    /** Optional files are not created by setup, so their absence is normal. */
    protected function isOptional(): bool
    {
        return false;
    }

    /** @return ConfigLoadResult<T> */
    final public function load(string $configRoot): ConfigLoadResult
    {
        $this->problems = [];

        try {
            return $this->loadFile(rtrim($configRoot, '/') . '/' . $this->fileName());
        } catch (MonitoringConfigLoadException $exception) {
            if (!$this->fallbackOnFailure) {
                throw $exception;
            }

            return new ConfigLoadResult($this->defaultSection(), $exception->problems);
        }
    }

    /** @return ConfigLoadResult<T> */
    private function loadFile(string $filePath): ConfigLoadResult
    {
        if ($this->isOptional() && !file_exists($filePath)) {
            return $this->result($this->defaultSection());
        }

        try {
            $config = $this->readJsonArray($filePath);
            // Required files must say something; an empty optional file means "no overrides".
            if ($config === [] && !$this->isOptional()) {
                throw new RuntimeException('the file is empty');
            }

            return $this->result($this->parse($config, $filePath));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->reportLoadFailure(
                $exception->getMessage(),
                $exception instanceof JsonConfigReadException ? $exception->fileName : null,
            );

            throw new MonitoringConfigLoadException($exception->getMessage(), $this->problems, $exception);
        }
    }

    protected function reportLoadFailure(string $reason, ?string $sourceFile = null): void
    {
        $this->report($this->loadFailureType(), "Could not load {$this->fileName()}: {$reason}", sourceFile: $sourceFile);
    }

    /** Reports an entry that parse() skipped while keeping the rest of the file. */
    protected function reportInvalidEntry(string $message, ?string $sourceFile = null): void
    {
        $this->report($this->loadFailureType() . '_entry', $message, sourceFile: $sourceFile);
    }

    protected function report(string $type, string $message, string $level = LogLevel::WARNING, bool $forceAlarm = false, ?string $sourceFile = null): void
    {
        $this->problems[] = new ConfigLoadProblem($type, $message, $level, $forceAlarm, [
            ConfigSource::file($sourceFile ?? $this->fileName()),
        ]);
    }

    /**
     * Pairs the section with every problem reported during this load.
     *
     * @param T $section
     *
     * @return ConfigLoadResult<T>
     */
    private function result(object $section): ConfigLoadResult
    {
        return new ConfigLoadResult($section, $this->problems);
    }

    /** @return array<string, mixed> */
    protected function readJsonArray(string $filePath): array
    {
        $content = is_file($filePath) && is_readable($filePath) ? file_get_contents($filePath) : false;
        if ($content === false) {
            throw new JsonConfigReadException("Cannot read JSON config at {$filePath}", basename($filePath));
        }

        try {
            $config = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new JsonConfigReadException("Invalid JSON config at {$filePath}: {$exception->getMessage()}", basename($filePath), previous: $exception);
        }

        // An empty object decodes to the same [] as an empty list.
        if (!ConfigValueValidator::isObject($config)) {
            throw new JsonConfigReadException("JSON config at {$filePath} must be an object.", basename($filePath));
        }

        return $config;
    }
}
