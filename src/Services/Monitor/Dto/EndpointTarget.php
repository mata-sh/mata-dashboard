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

        $serverId = self::toUnsignedInt($data['server_id'] ?? null);
        $applicationId = self::toUnsignedInt($data['application_id'] ?? null);
        $serverName = isset($data['server_name']) && is_string($data['server_name'])
            ? trim($data['server_name'])
            : null;

        $metadata = $data;
        unset(
            $metadata['url'],
            $metadata['identifier'],
            $metadata['server_id'],
            $metadata['server_name'],
            $metadata['application_id']
        );

        return new self(
            identifier: $identifier,
            url: trim($url),
            serverId: $serverId,
            serverName: $serverName,
            applicationId: $applicationId,
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

    /**
     * Fetch a metadata value while providing a sensible default.
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
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

    private static function toUnsignedInt(mixed $value): ?int
    {
        $intValue = TypeConverter::toInt($value);

        if ($intValue === null || $intValue < 0) {
            return null;
        }

        return $intValue;
    }
}
