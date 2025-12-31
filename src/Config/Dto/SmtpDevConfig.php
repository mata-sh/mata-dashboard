<?php

declare(strict_types=1);

namespace Watcher\Config\Dto;

/**
 * Immutable DTO representing development SMTP configuration.
 *
 * Contains SMTP server settings for development environment email testing.
 */
final class SmtpDevConfig
{
    public function __construct(
        public readonly ?string $host,
        public readonly int $port,
        public readonly ?string $user,
        public readonly ?string $password,
        public readonly string $secure,
        public readonly ?string $from,
        public readonly ?string $fromName,
    ) {}
}
