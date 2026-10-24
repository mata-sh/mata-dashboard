<?php

declare(strict_types=1);

namespace Mata\Config;

use InvalidArgumentException;

/**
 * One object of a JSON config file, read key by key. A missing or null key takes the default;
 * messages name the key by its dotted path, e.g. `advisories.sources.php.name`.
 */
final readonly class ConfigObject
{
    /** @param array<array-key,mixed> $values */
    private function __construct(
        public string $path,
        public array $values,
    ) {}

    /** @throws InvalidArgumentException when $value is not an object */
    public static function of(mixed $value, string $path): self
    {
        return new self($path, ConfigValueValidator::object($value, $path));
    }

    public function path(string $key): string
    {
        return "{$this->path}.{$key}";
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function raw(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
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
        return self::of($this->raw($key, []), $this->path($key));
    }

    public function integer(string $key, int $default, int $minimum, int $maximum): int
    {
        return ConfigValueValidator::integer($this->raw($key, $default), $this->path($key), $minimum, $maximum);
    }

    public function boolean(string $key, bool $default): bool
    {
        $value = $this->raw($key, $default);
        if (!is_bool($value)) {
            throw new InvalidArgumentException("{$this->path($key)} must be a boolean.");
        }

        return $value;
    }

    /** The name of an environment variable; the key is required. */
    public function envName(string $key): string
    {
        $value = $this->raw($key);
        if (!is_string($value) || !ConfigValueValidator::isEnvName($value)) {
            throw new InvalidArgumentException("{$this->path($key)} must name an environment variable.");
        }

        return $value;
    }

    /** Trimmed; a null default makes the key required. */
    public function text(string $key, ?string $default, int $maxLength, bool $allowEmpty = false): string
    {
        $value = $this->raw($key, $default);
        if (!is_string($value) || (!$allowEmpty && trim($value) === '') || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("{$this->path($key)} must be a " . ($allowEmpty ? '' : 'non-empty ') . "string of at most {$maxLength} characters.");
        }

        return trim($value);
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
        $list = $this->raw($key, []);
        if (!is_array($list) || !array_is_list($list)) {
            throw new InvalidArgumentException("{$this->path($key)} must be {$expected}.");
        }

        $strings = [];
        foreach ($list as $item) {
            if (!is_string($item) || !$isValid($item)) {
                throw new InvalidArgumentException("{$this->path($key)} must be {$expected}.");
            }
            $strings[] = $item;
        }

        return $strings;
    }
}
