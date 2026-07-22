<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

use JsonException;
use RuntimeException;
use stdClass;

abstract class AbstractJsonConfigLoader
{
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
