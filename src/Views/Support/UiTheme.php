<?php

declare(strict_types=1);

namespace Mata\Views\Support;

/**
 * Color theme of the web UI, chosen per browser via the theme toggle's cookie.
 */
enum UiTheme: string
{
    case Dark = 'dark';
    case Light = 'light';

    /** Written by public/assets/js/theme.js. */
    public const COOKIE = 'mata_theme';

    /**
     * Any value other than a known theme, including a tampered cookie, falls back to dark.
     */
    public static function fromCookie(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Dark;
    }
}
