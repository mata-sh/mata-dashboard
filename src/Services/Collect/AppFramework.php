<?php

declare(strict_types=1);

namespace Mata\Services\Collect;

/**
 * Known framework labels and bundled logos, not a restriction on node framework names.
 *
 * Only these values may select files in `public/assets/icons/frameworks/`. Magento has a label
 * but no logo, since Adobe does not license its marks; it gets the generic icon.
 */
enum AppFramework: string
{
    case Laravel = 'laravel';
    case Symfony = 'symfony';
    case WordPress = 'wordpress';
    case Drupal = 'drupal';
    case Typo3 = 'typo3';
    case Shopware = 'shopware';
    case Joomla = 'joomla';
    case CakePhp = 'cakephp';
    case Magento = 'magento';
    case Sylius = 'sylius';
    case Craft = 'craft';
    case Statamic = 'statamic';
    case Contao = 'contao';
    case Sulu = 'sulu';
    case Neos = 'neos';
    case October = 'october';
    case Yii = 'yii';
    case CodeIgniter = 'codeigniter';

    public function label(): string
    {
        return match ($this) {
            self::Laravel => 'Laravel',
            self::Symfony => 'Symfony',
            self::WordPress => 'WordPress',
            self::Drupal => 'Drupal',
            self::Typo3 => 'TYPO3',
            self::Shopware => 'Shopware',
            self::Joomla => 'Joomla',
            self::CakePhp => 'CakePHP',
            self::Magento => 'Magento',
            self::Sylius => 'Sylius',
            self::Craft => 'Craft CMS',
            self::Statamic => 'Statamic',
            self::Contao => 'Contao',
            self::Sulu => 'Sulu',
            self::Neos => 'Neos',
            self::October => 'October CMS',
            self::Yii => 'Yii',
            self::CodeIgniter => 'CodeIgniter',
        };
    }
}
