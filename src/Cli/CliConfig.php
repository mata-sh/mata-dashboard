<?php

declare(strict_types=1);

namespace Mata\Cli;

use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\EnvConfigLoader;
use Mata\Utils\PathResolver;
use RuntimeException;
use Throwable;

/**
 * The instance config as bin/mata commands see it: config/ and the env files loaded from it,
 * with LOG_PATH and TEMP_DIR applied to the paths as the app does.
 */
final class CliConfig
{
    public readonly string $configPath;

    private ?ApplicationConfig $config = null;

    public function __construct(private PathResolver $paths)
    {
        $this->configPath = $paths->configPath;
    }

    /** Carries LOG_PATH and TEMP_DIR only after load() succeeded; the defaults before that. */
    public function paths(): PathResolver
    {
        return $this->paths;
    }

    /**
     * Loads once per process. Applying the paths needs write access to logs/ and temp/, so
     * this fails for any account but the web server user.
     *
     * @throws RuntimeException naming what to fix
     */
    public function load(): ApplicationConfig
    {
        if ($this->config !== null) {
            return $this->config;
        }

        try {
            $config = (new EnvConfigLoader())->load(EnvConfigLoader::INSTANCE_FILES, $this->configPath);
        } catch (Throwable $exception) {
            throw new RuntimeException("Cannot load the config: {$exception->getMessage()}", previous: $exception);
        }

        $paths = $this->paths->withOverrides($config->logging->path, $config->environment->tempDir);

        try {
            $paths->ensureLogsDirectory();
            $paths->ensureTempDirectory();
        } catch (RuntimeException $exception) {
            throw new RuntimeException(
                "Cannot load the config: {$exception->getMessage()}. Run as the web server user: "
                    . 'sudo -u www-data php bin/mata … (http on Arch, apache on RHEL; make does this in Docker)',
                previous: $exception,
            );
        }

        $this->paths = $paths;
        $this->config = $config;

        return $config;
    }
}
