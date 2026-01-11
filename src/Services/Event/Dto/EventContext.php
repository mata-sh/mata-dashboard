<?php

declare(strict_types=1);

namespace Mata\Services\Event\Dto;

/**
 * Immutable wrapper for structured event context payloads.
 *
 * Provides deterministic JSON encoding to ensure consistent hashing,
 * storage, and alert rendering across the monitoring pipeline.
 */
final class EventContext
{
    /** @var array<int|string,mixed> */
    private array $data;

    private ?string $encoded = null;

    private static ?self $emptyInstance = null;

    /**
     * @param array<int|string,mixed> $data
     */
    private function __construct(array $data)
    {
        $this->data = $this->normalise($data);
    }

    /**
     * Create context from structured data.
     *
     * @param array<int|string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if ($data === []) {
            return self::empty();
        }

        return new self($data);
    }

    public static function empty(): self
    {
        if (self::$emptyInstance === null) {
            self::$emptyInstance = new self([]);
            self::$emptyInstance->encoded = '';
        }

        return self::$emptyInstance;
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /**
     * @return array<int|string,mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(): string
    {
        if ($this->encoded !== null) {
            return $this->encoded;
        }

        if ($this->isEmpty()) {
            $this->encoded = '';

            return $this->encoded;
        }

        $options = JSON_UNESCAPED_SLASHES | (defined('JSON_PRESERVE_ZERO_FRACTION') ? JSON_PRESERVE_ZERO_FRACTION : 0);
        $encoded = json_encode($this->data, $options);

        $this->encoded = $encoded === false ? '' : $encoded;

        return $this->encoded;
    }

    /**
     * @param array<int|string,mixed> $data
     *
     * @return array<int|string,mixed>
     */
    private function normalise(array $data): array
    {
        $isList = function_exists('array_is_list') ? array_is_list($data) : $this->isListFallback($data);

        if ($isList) {
            $normalised = [];
            foreach ($data as $value) {
                $normalised[] = is_array($value) ? $this->normalise($value) : $value;
            }

            return $normalised;
        }

        $normalised = $data;
        ksort($normalised);

        foreach ($normalised as $key => $value) {
            if (is_array($value)) {
                $normalised[$key] = $this->normalise($value);
            }
        }

        return $normalised;
    }

    /**
     * @param array<int|string,mixed> $array
     */
    private function isListFallback(array $array): bool
    {
        $expectedKey = 0;
        foreach (array_keys($array) as $key) {
            if ($key !== $expectedKey) {
                return false;
            }
            ++$expectedKey;
        }

        return true;
    }
}
