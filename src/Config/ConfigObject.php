<?php

declare(strict_types=1);

namespace Mata\Config;

use InvalidArgumentException;

/**
 * Reads the keys of one object of a JSON config file: applies defaults, names each key by its dotted path
 * (e.g. `advisories.sources.php.name`) and rejects unknown keys; ConfigValueValidator checks the values.
 * Only a missing key takes the default; an explicit null is checked like any other value and fails.
 */
final readonly class ConfigObject
{
    /** @param array<array-key,mixed> $values */
    private function __construct(
        public string $path,
        public array $values,
    ) {}

    /** @throws InvalidArgumentException when $value is not an object */
    public static function fromValue(mixed $value, string $path): self
    {
        return new self($path, ConfigValueValidator::object($value, $path));
    }

    public function path(string $key): string
    {
        return "{$this->path}.{$key}";
    }

    /** Whether the key is present, even with a null value. */
    public function hasKey(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function raw(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    /** @param list<string> $allowed */
    public function rejectUnknownKeys(array $allowed): void
    {
        $unknown = array_diff(array_map('strval', array_keys($this->values)), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException("{$this->path} contains unknown key " . reset($unknown) . '.');
        }
    }

    /** A missing key reads as an empty object. */
    public function object(string $key): self
    {
        return self::fromValue($this->raw($key, []), $this->path($key));
    }

    public function integer(string $key, int $default, ?int $minimum = null, ?int $maximum = null): int
    {
        return ConfigValueValidator::integer($this->raw($key, $default), $this->path($key), $minimum, $maximum);
    }

    public function boolean(string $key, bool $default): bool
    {
        return ConfigValueValidator::boolean($this->raw($key, $default), $this->path($key));
    }

    /** The key is required. */
    public function envName(string $key): string
    {
        return ConfigValueValidator::envName($this->raw($key), $this->path($key));
    }

    /** Trimmed; a null default makes the key required. */
    public function text(string $key, ?string $default, int $maxLength, bool $allowEmpty = false): string
    {
        return ConfigValueValidator::text($this->raw($key, $default), $this->path($key), $maxLength, $allowEmpty);
    }

    /**
     * A missing key reads as an empty list.
     *
     * @param callable(string):bool $isValid
     * @param string                $expected completes "<path> must be …", e.g. "a list of email addresses"
     *
     * @return list<string>
     */
    public function stringList(string $key, callable $isValid, string $expected): array
    {
        return ConfigValueValidator::stringList($this->raw($key, []), $this->path($key), $isValid, $expected);
    }
}
