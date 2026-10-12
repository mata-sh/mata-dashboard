<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use JsonException;
use Mata\Config\Monitoring\Loader\Dto\ConfigLoadProblem;
use Mata\Config\Monitoring\Loader\Dto\ConfigLoadResult;
use Psr\Log\LogLevel;
use RuntimeException;
use stdClass;
use Throwable;

abstract class AbstractJsonConfigLoader
{
    /** @var list<ConfigLoadProblem> reported since the last result() or rejected() */
    private array $problems = [];

    protected function report(string $type, string $message, string $level = LogLevel::WARNING, bool $forceAlarm = false): void
    {
        $this->problems[] = new ConfigLoadProblem($type, $message, $level, $forceAlarm);
    }

    /**
     * Pairs the section with every problem reported so far and clears them for the next load.
     *
     * @template T of object
     *
     * @param T $section
     *
     * @return ConfigLoadResult<T>
     */
    protected function result(object $section): ConfigLoadResult
    {
        return new ConfigLoadResult($section, $this->takeProblems());
    }

    protected function rejected(string $message, ?Throwable $previous = null): MonitoringConfigLoadException
    {
        return new MonitoringConfigLoadException($message, $this->takeProblems(), $previous);
    }

    /** @return list<ConfigLoadProblem> */
    private function takeProblems(): array
    {
        $problems = $this->problems;
        $this->problems = [];

        return $problems;
    }

    /** @return array<string, mixed> */
    protected function readJsonArray(string $filePath): array
    {
        return $this->normalizeJson($this->readJsonObject($filePath));
    }

    protected function readJsonObject(string $filePath): stdClass
    {
        $content = is_file($filePath) && is_readable($filePath) ? file_get_contents($filePath) : false;
        if ($content === false) {
            throw new RuntimeException("Cannot read JSON config at {$filePath}");
        }

        try {
            $config = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid JSON config at {$filePath}: {$exception->getMessage()}", previous: $exception);
        }

        if (!$config instanceof stdClass) {
            throw new RuntimeException("JSON config at {$filePath} must be an object.");
        }

        return $config;
    }

    /**
     * Converts decoded objects to associative arrays, recursively.
     *
     * @return ($value is stdClass ? array<string, mixed> : mixed)
     */
    private function normalizeJson(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $normalized = [];
            foreach (get_object_vars($value) as $key => $child) {
                $normalized[$key] = $this->normalizeJson($child);
            }

            return $normalized;
        }

        if (is_array($value)) {
            return array_map($this->normalizeJson(...), $value);
        }

        return $value;
    }
}
