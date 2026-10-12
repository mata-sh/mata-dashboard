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
    protected function fileName(): string
    {
        return 'data_retention.json';
    }

    protected function loadFailureType(): string
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
