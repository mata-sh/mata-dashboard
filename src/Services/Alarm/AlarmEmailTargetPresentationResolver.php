<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

/** Resolves server-configured color and environment presentation for alarm emails. */
final class AlarmEmailTargetPresentationResolver
{
    /** @param array<string,mixed> $servers */
    public function __construct(private array $servers = []) {}

    /**
     * @return array{color:?string,environment:?array{label:string,color:string}}
     */
    public function resolve(string $server, ?string $appEnvironment): array
    {
        $config = $this->serverConfig($server);
        $environment = $this->normalizeEnvironment($appEnvironment)
            ?? $this->normalizeEnvironment(is_string($config['env'] ?? null) ? $config['env'] : null);

        return [
            'color' => $this->color($config['primary_color'] ?? null),
            'environment' => $environment === null ? null : [
                'label' => strtoupper($environment),
                'color' => match ($environment) {
                    'live' => '#098f00',
                    'test' => '#f59e0b',
                    'dev' => '#207bdc',
                    'staging' => '#663399',
                    default => '#098f00',
                },
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function serverConfig(string $server): array
    {
        foreach ($this->servers as $name => $config) {
            if (is_array($config) && strcasecmp((string) $name, $server) === 0) {
                return $config;
            }
        }

        return [];
    }

    private function color(mixed $color): ?string
    {
        if (!is_string($color) || preg_match('/^#[0-9a-f]{6}$/i', $color) !== 1) {
            return null;
        }

        return $color;
    }

    private function normalizeEnvironment(?string $environment): ?string
    {
        return match (strtolower(trim((string) $environment))) {
            'live', 'prod', 'production' => 'live',
            'test', 'testing' => 'test',
            'dev', 'development' => 'dev',
            'staging', 'stage', 'stg' => 'staging',
            default => null,
        };
    }
}
