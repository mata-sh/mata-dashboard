<?php

declare(strict_types=1);

namespace Mata\Http;

class SecurityHeaders
{
    /**
     * @return array<string,string>
     */
    public static function headers(bool $enabled = true): array
    {
        if (!$enabled) {
            return [];
        }

        $contentSecurityPolicy = [
            "default-src 'self'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "form-action 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
        ];

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => implode('; ', $contentSecurityPolicy),
        ];

        $https = $_SERVER['HTTPS'] ?? '';
        if ($https === 'on' || $https === '1') {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    public static function apply(bool $enabled = true): void
    {
        foreach (self::headers($enabled) as $name => $value) {
            header($name . ': ' . $value);
        }
    }
}
