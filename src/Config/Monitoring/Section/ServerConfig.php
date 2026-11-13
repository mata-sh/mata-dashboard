<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use Mata\Utils\TypeConverter;
use SensitiveParameter;

/**
 * One enabled server from servers.json, with its api_key merged in from servers.secrets.json.
 * {@see ServersConfig} builds it from an entry it validated, so title, api_url and api_key are
 * non-empty, env is one of prod, live, test, dev or staging, and primary_color is #RRGGBB.
 */
final readonly class ServerConfig
{
    /**
     * @param ?string              $env           lower-cased and trimmed
     * @param ?string              $provider      stored with the server record
     * @param ?string              $collectorName names the metrics collector when the node does not, before node_name
     * @param array<string,scalar> $options       every scalar key as written, api_key excluded, for the settings overview
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $apiUrl,
        #[SensitiveParameter]
        public string $apiKey,
        public ?string $url = null,
        public ?string $env = null,
        public ?string $primaryColor = null,
        public ?string $secondaryColor = null,
        public ?string $info = null,
        public ?string $provider = null,
        public bool $allowHttp = false,
        public bool $suppressHttpWarning = false,
        public bool $errorMonitoringEnabled = true,
        public bool $logsizeMonitoringEnabled = true,
        public ?string $collectorName = null,
        public ?string $nodeName = null,
        public ?string $collectorVersion = null,
        public ?string $collectorContact = null,
        public array $options = [],
    ) {}

    /**
     * One servers.json entry with api_key merged in, as {@see ServersConfig} validated it.
     * allow_http and suppress_http_warning accept "on"-style strings; the monitoring flags are
     * JSON booleans and default to true.
     *
     * @param array<string,mixed> $entry
     */
    public static function fromArray(string $name, array $entry): self
    {
        $options = [];
        foreach ($entry as $key => $value) {
            if ($key !== 'api_key' && is_scalar($value)) {
                $options[$key] = $value;
            }
        }
        $env = self::string($entry, 'env');

        return new self(
            name: $name,
            title: self::string($entry, 'title') ?? $name,
            apiUrl: self::string($entry, 'api_url') ?? '',
            apiKey: self::string($entry, 'api_key') ?? '',
            url: self::string($entry, 'url'),
            env: $env === null ? null : strtolower(trim($env)),
            primaryColor: self::string($entry, 'primary_color'),
            secondaryColor: self::string($entry, 'secondary_color'),
            info: self::string($entry, 'info'),
            provider: self::string($entry, 'provider'),
            allowHttp: TypeConverter::toBool($entry['allow_http'] ?? null) ?? false,
            suppressHttpWarning: TypeConverter::toBool($entry['suppress_http_warning'] ?? null) ?? false,
            errorMonitoringEnabled: self::flag($entry, 'error_monitoring_enabled'),
            logsizeMonitoringEnabled: self::flag($entry, 'logsize_monitoring_enabled'),
            collectorName: TypeConverter::toString($entry['collector_name'] ?? null),
            nodeName: TypeConverter::toString($entry['node_name'] ?? null),
            collectorVersion: TypeConverter::toString($entry['collector_version'] ?? null),
            collectorContact: TypeConverter::toString($entry['collector_contact'] ?? null),
            options: $options,
        );
    }

    /** @param array<string,mixed> $entry */
    private static function string(array $entry, string $key): ?string
    {
        $value = $entry[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param array<string,mixed> $entry */
    private static function flag(array $entry, string $key): bool
    {
        $value = $entry[$key] ?? null;

        return is_bool($value) ? $value : true;
    }
}
