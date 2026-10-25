<?php

declare(strict_types=1);

namespace Mata\Services\Collect;

/**
 * Frameworks and CMSs a node can report for an app in `/apps` `framework.name`.
 *
 * The value doubles as the logo file name in `public/assets/icons/frameworks/`.
 */
enum AppFramework: string
{
    case Laravel = 'laravel';
    case Symfony = 'symfony';
    case WordPress = 'wordpress';
    case Drupal = 'drupal';
    case Typo3 = 'typo3';

    public function label(): string
    {
        return match ($this) {
            self::Laravel => 'Laravel',
            self::Symfony => 'Symfony',
            self::WordPress => 'WordPress',
            self::Drupal => 'Drupal',
            self::Typo3 => 'TYPO3',
        };
    }
}
