<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable DTO representing environment configuration.
 *
 * Contains fundamental environment settings including runtime environment,
 * domain, and directory path information. APP_ENV is compared case-insensitively.
 */
final class EnvironmentConfig
{
    /** `prod`, `dev` or `demo`, lower-cased. */
    public readonly string $env;

    public function __construct(
        string $env,
        public readonly string $domain,
        public readonly string $dir,
        public readonly string $tempDir,
    ) {
        $this->env = strtolower(trim($env));
    }

    /** Demo instances generate their data instead of reading nodes and the database. */
    public function isDemo(): bool
    {
        return $this->env === 'demo';
    }

    /** Production: the CLI asks for a typed confirmation before it writes. */
    public function isProd(): bool
    {
        return $this->env === 'prod';
    }
}
