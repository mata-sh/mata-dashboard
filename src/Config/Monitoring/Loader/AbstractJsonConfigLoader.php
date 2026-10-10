<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use JsonException;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use RuntimeException;
use stdClass;

abstract class AbstractJsonConfigLoader
{
    /**
     * Report a config problem through EventManager, which works before monitoring config is
     * loaded: it uses its default cooldown and logs the problem when no database is available.
     * The message is the cooldown identity, so each distinct problem is reported once per window.
     */
    protected function reportProblem(
        EventManager $events,
        string $type,
        string $message,
        string $level = 'warning',
        bool $forceAlarm = false,
    ): void {
        $events->handle(new EventCommand(
            type: $type,
            message: $message,
            level: $level,
            forceAlarm: $forceAlarm,
            context: ['detail' => $message],
        ));
    }

    protected function readJsonObject(string $filePath): stdClass
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("Cannot read JSON config at {$filePath}");
        }

        $content = file_get_contents($filePath);
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

    protected function normalizeJson(mixed $value): mixed
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
