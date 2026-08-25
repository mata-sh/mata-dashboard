<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use Mata\Config\ConfigValueValidator;

/** Cache TTLs in seconds, keyed by ttl.json key. */
final readonly class TtlConfig
{
    /** @var array<string,int> */
    public array $values;

    /** @param array<string,mixed> $values */
    public function __construct(array $values)
    {
        $validated = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            $validated[$key] = ConfigValueValidator::integer($value, "ttl.{$key}");
        }
        $this->values = $validated;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function default(): self
    {
        return self::fromArray([]);
    }

    /** @return array<string,int> */
    public static function defaults(): array
    {
        return [
            'serverData' => 10,
            'serverInfo' => 86400,
            'serverProcesses' => 30,
            'serverMetricsChart' => 60,
            'apps' => 60,
            'libraries' => 600,
            'appLogs' => 30,
            'sessions' => 900,
            'ping' => 360,
            'health' => 240,
            'readme' => 300,
            'databases' => 10,
            'cronjobs' => 300,
            'audit' => 86400,
        ];
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(array_replace(self::defaults(), $raw));
    }

    /** Seconds configured for the key, or null when it is absent. */
    public function get(string $key): ?int
    {
        return $this->values[$key] ?? null;
    }
}
