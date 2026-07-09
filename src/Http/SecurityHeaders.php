<?php

declare(strict_types=1);

namespace Mata\Http;

class SecurityHeaders
{
    public static function apply(): void
    {
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

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Content-Security-Policy: ' . implode('; ', $contentSecurityPolicy));

        if (isset($_SERVER['HTTPS'])) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
