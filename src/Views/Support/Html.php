<?php

declare(strict_types=1);

namespace Mata\Views\Support;

/**
 * HTML escaping helpers for values embedded into generated markup.
 */
final class Html
{
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Escape dynamic values before embedding them in HTML-enabled tooltip markup.
     */
    public static function escapeTooltipValue(mixed $value): string
    {
        return self::escape($value);
    }
}
