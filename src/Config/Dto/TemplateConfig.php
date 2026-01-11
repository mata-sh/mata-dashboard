<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable DTO representing template engine configuration.
 *
 * Contains settings for Twig template engine including debug mode
 * and cache settings.
 */
final class TemplateConfig
{
    public function __construct(
        public readonly bool $debugEnabled,
    ) {}
}
