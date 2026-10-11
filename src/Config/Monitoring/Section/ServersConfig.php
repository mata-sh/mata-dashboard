<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use Mata\Config\Monitoring\ServerConfigState;
use Mata\Utils\TypeConverter;

/**
 * Dashboard-owned server definitions keyed by operator-defined server name, with each
 * api_key already merged in from servers.secrets.json.
 */
final readonly class ServersConfig
{
    /** @param array<string,mixed> $values */
    private function __construct(public array $values) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $servers = [];
        foreach ($raw as $serverName => $config) {
            $serverName = (string) $serverName;
            if (str_starts_with($serverName, '_')
                || self::serverValidationErrors($serverName, $config) !== []
                || (is_array($config) && ServerConfigState::isDisabled($config))) {
                continue;
            }
            $servers[$serverName] = $config;
        }

        return new self($servers);
    }

    /**
     * @param array<string,mixed> $raw
     *
     * @return list<string>
     */
    public static function validationErrors(array $raw): array
    {
        if ($raw === []) {
            return ['Server configuration is empty'];
        }

        $errors = [];
        foreach ($raw as $serverName => $serverConfig) {
            $errors = array_merge($errors, self::serverValidationErrors((string) $serverName, $serverConfig));
        }

        return $errors;
    }

    /** @return list<string> */
    private static function serverValidationErrors(string $serverName, mixed $config): array
    {
        if (!is_array($config)) {
            return ["Server '{$serverName}' configuration must be an array"];
        }
        if (array_key_exists('disabled', $config) && !is_bool($config['disabled'])) {
            return ["Server '{$serverName}' disabled must be a boolean"];
        }
        foreach (['error_monitoring_enabled', 'logsize_monitoring_enabled'] as $key) {
            if (array_key_exists($key, $config) && !is_bool($config[$key])) {
                return ["Server '{$serverName}' {$key} must be a boolean"];
            }
        }
        if (ServerConfigState::isDisabled($config)) {
            return [];
        }

        $errors = [];
        foreach (['api_url', 'title'] as $field) {
            if (!is_string($config[$field] ?? null) || trim($config[$field]) === '') {
                $errors[] = "Server '{$serverName}' missing required field: {$field}";
            }
        }
        if (!is_string($config['api_key'] ?? null) || trim($config['api_key']) === '') {
            $errors[] = "Server '{$serverName}' has no api_key in servers.secrets.json";
        }
        if (is_string($config['api_url'] ?? null) && trim($config['api_url']) !== '') {
            if (!filter_var($config['api_url'], FILTER_VALIDATE_URL)) {
                $errors[] = "Server '{$serverName}' has invalid API URL format";
            } else {
                $scheme = parse_url($config['api_url'], PHP_URL_SCHEME);
                if ($scheme !== 'https' && $scheme !== 'http') {
                    $errors[] = "Server '{$serverName}' API URL must use HTTPS protocol (found: {$scheme}://)";
                } elseif ($scheme === 'http' && !(TypeConverter::toBool($config['allow_http'] ?? null) ?? false)) {
                    $errors[] = "Server '{$serverName}' API URL must use HTTPS protocol (found: http://). To allow HTTP connections, set 'allow_http': \"on\" in configuration.";
                }
            }
        }
        if (isset($config['primary_color']) && (!is_string($config['primary_color']) || !preg_match('/^#[0-9a-fA-F]{6}$/', $config['primary_color']))) {
            $errors[] = "Server '{$serverName}' has invalid primary_color format (should be #RRGGBB)";
        }
        if (isset($config['env']) && (!is_string($config['env']) || !in_array(strtolower(trim($config['env'])), ['prod', 'live', 'test', 'dev', 'staging'], true))) {
            $errors[] = "Server '{$serverName}' env must be one of: prod, live, test, dev, staging";
        }

        return $errors;
    }
}
