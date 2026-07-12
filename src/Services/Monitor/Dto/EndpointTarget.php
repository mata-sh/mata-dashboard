<?php

declare(strict_types=1);

namespace Mata\Services\Monitor\Dto;

use Mata\Utils\TypeConverter;

/**
 * Immutable data transfer object representing an endpoint availability target.
 *
 * Encapsulates the normalized metadata we need when probing application URLs,
 * so downstream services can rely on consistent typing instead of juggling
 * loosely structured arrays.
 */
final class EndpointTarget
{
    /**
     * @param array<string,mixed> $metadata additional metadata preserved from the source payload
     */
    public function __construct(
        public readonly string $identifier,
        public readonly string $url,
        public readonly ?int $serverId = null,
        public readonly ?string $serverName = null,
        public readonly ?int $applicationId = null,
        public readonly array $metadata = [],
        public readonly ?string $statusUrl = null,
    ) {}

    /**
     * Build a target from unstructured array data.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data, int|string|null $originalKey = null): ?self
    {
        $url = $data['url'] ?? null;
        if (!is_string($url) || trim($url) === '') {
            return null;
        }

        $identifier = self::resolveIdentifier($data, $originalKey, $url);

        $serverId = TypeConverter::toInt($data['server_id'] ?? null);
        $serverId = $serverId !== null && $serverId > 0 ? $serverId : null;
        $applicationId = TypeConverter::toInt($data['application_id'] ?? null);
        $applicationId = $applicationId !== null && $applicationId > 0 ? $applicationId : null;
        $serverName = TypeConverter::toString($data['server_name'] ?? null);
        $statusUrl = TypeConverter::toString($data['status_url'] ?? null);

        $metadata = $data;
        unset(
            $metadata['url'],
            $metadata['identifier'],
            $metadata['server_id'],
            $metadata['server_name'],
            $metadata['application_id'],
            $metadata['status_url']
        );

        return new self(
            identifier: $identifier,
            url: trim($url),
            serverId: $serverId,
            serverName: $serverName,
            applicationId: $applicationId,
            statusUrl: $statusUrl,
            metadata: $metadata
        );
    }

    /**
     * Convenience helper for simple string URLs.
     */
    public static function fromUrl(string $url, ?string $identifier = null): ?self
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $identifier = $identifier !== null && $identifier !== ''
            ? $identifier
            : $url;

        return new self($identifier, $url);
    }

    /**
     * Hydrate multiple targets from a raw apps API payload.
     *
     * @param array<int|string,mixed> $appsData
     * @param null|string             $serverName optional server context to attach
     *
     * @return array<string,EndpointTarget>
     */
    public static function fromRawAppData(array $appsData, ?string $serverName = null): array
    {
        $targets = [];

        foreach ($appsData as $appKey => $appData) {
            if (!is_array($appData)) {
                continue;
            }

            $candidate = $appData;
            if ($serverName !== null) {
                $candidate['server_name'] ??= $serverName;
            }

            $target = self::fromArray($candidate, $appKey);
            if ($target === null) {
                continue;
            }

            $targets[$target->identifier] = $target;
        }

        return $targets;
    }

    public function resolveProbeUrl(): string
    {
        return $this->statusUrl ?? $this->url;
    }

    /**
     * Resolve a metadata value, using the default when it is absent.
     */
    public function resolveMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Normalize identifiers with consistent precedence.
     *
     * @param array<string,mixed> $data
     */
    private static function resolveIdentifier(array $data, int|string|null $originalKey, string $url): string
    {
        if (is_string($originalKey) && $originalKey !== '') {
            return $originalKey;
        }

        $candidate = $data['identifier'] ?? null;
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }

        return $url;
    }
}
