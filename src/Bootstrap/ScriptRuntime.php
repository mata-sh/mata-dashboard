<?php

declare(strict_types=1);

namespace Mata\Bootstrap;

use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\EnvConfigLoader;
use Mata\Utils\PathResolver;

/** Shared configuration and arguments for operational CLI scripts. */
final readonly class ScriptRuntime
{
    /** @param list<string> $arguments */
    public function __construct(
        public PathResolver $paths,
        public ApplicationConfig $appConfig,
        public array $arguments,
    ) {}

    /**
     * Load and validate application configuration for a CLI script.
     *
     * @param list<string> $arguments
     */
    public static function boot(array $arguments): self
    {
        $paths = new PathResolver(dirname(__DIR__, 2));

        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(E_ALL);

        $configLoader = new EnvConfigLoader();
        $appConfig = $configLoader->load([
            'config.env',
            'db.config.env',
            'mail.config.env',
        ], $paths->configPath);

        $validationResult = $configLoader->validateConfig($appConfig);
        if (!$validationResult['is_valid']) {
            foreach ($validationResult['critical_errors'] as $error) {
                fwrite(STDERR, "[CONFIG ERROR] {$error['identifier']}: {$error['message']}\n");
            }

            exit(1);
        }

        return new self(
            paths: $paths,
            appConfig: $appConfig,
            arguments: $arguments,
        );
    }
}
