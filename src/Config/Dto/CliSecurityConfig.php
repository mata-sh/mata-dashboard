<?php

declare(strict_types=1);

namespace Watcher\Config\Dto;

/**
 * Immutable DTO representing CLI security configuration.
 *
 * Contains settings for securing CLI scripts including user restrictions
 * and allowed user lists.
 */
final class CliSecurityConfig
{
    /**
     * @param bool          $strictUserCheck Whether to enforce user restrictions
     * @param array<string> $allowedUsers    List of users allowed to run CLI scripts
     */
    public function __construct(
        public readonly bool $strictUserCheck,
        public readonly array $allowedUsers,
    ) {}
}
