<?php

declare(strict_types=1);

namespace Watcher\Config\Dto;

/**
 * Immutable DTO representing security secrets configuration.
 *
 * Contains sensitive security tokens and encryption keys used
 * throughout the application.
 */
final class SecretsConfig
{
    public function __construct(
        public readonly ?string $encryptionSecret,
    ) {}
}
