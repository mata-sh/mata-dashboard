<?php

declare(strict_types=1);

namespace Mata\Config;

use Mata\Config\Dto\ApplicationConfig;

/**
 * Outcome of EnvConfigManager::initialize(): the loaded configuration plus any
 * non-fatal validation warnings.
 *
 * Lives beside the manager rather than in Dto/ because it describes how loading
 * went, not what is configured.
 */
final class ConfigInitializationResult
{
    /**
     * @param ApplicationConfig                                $config   Loaded and validated application configuration
     * @param array<int, array{type: string, message: string}> $warnings Non-critical validation warnings
     */
    public function __construct(
        public readonly ApplicationConfig $config,
        public readonly array $warnings = []
    ) {}
}
