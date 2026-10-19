<?php

declare(strict_types=1);

namespace Mata\Cli;

use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\EnvConfigLoader;
use Mata\Config\Validation\InstanceConfigValidator;
use Mata\Utils\PathResolver;

/**
 * The instance config as bin/mata commands see it: config/ and the env files loaded from it,
 * with LOG_PATH and TEMP_DIR applied to the paths as the app does.
 */
final class CliConfig
{
    private ?ApplicationConfig $config = null;

    public function __construct(
        public readonly PathResolver $paths,
        public readonly string $configPath,
    ) {}

    /**
     * Loads once per process. Applying the paths needs write access to logs/ and temp/, so
     * this fails for any account but the web server user.
     */
    public function load(): ApplicationConfig
    {
        if ($this->config === null) {
            $config = (new EnvConfigLoader())->load(InstanceConfigValidator::ENV_FILES, $this->configPath);
            $this->paths->applyConfig($config);
            $this->config = $config;
        }

        return $this->config;
    }
}
