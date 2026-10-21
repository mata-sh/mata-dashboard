<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Loader;

use Mata\Config\Monitoring\Section\DataRetentionConfig;

/**
 * Loads data_retention.json: metric, event, and alarm retention.
 *
 * @extends AbstractJsonConfigLoader<DataRetentionConfig>
 */
final class DataRetentionConfigLoader extends AbstractJsonConfigLoader
{
    public const DATA_RETENTION_FILE = 'data_retention.json';

    protected function fileName(): string
    {
        return self::DATA_RETENTION_FILE;
    }

    protected function problemType(): string
    {
        return 'data_retention_config_invalid';
    }

    protected function defaultSection(): DataRetentionConfig
    {
        return DataRetentionConfig::default();
    }

    protected function parse(array $config, string $filePath): DataRetentionConfig
    {
        return DataRetentionConfig::fromArray($config);
    }
}
