<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Http\Security;

use Mata\Http\Security\SecurityHeaders;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Http\Security\SecurityHeaders
 *
 * @internal
 */
final class SecurityHeadersTest extends TestCase
{
    public function testHeadersAreEmptyWhenDisabled(): void
    {
        self::assertSame([], SecurityHeaders::headers(false, false));
    }

    public function testHeadersIncludeExpectedBrowserSecurityPolicy(): void
    {
        $headers = SecurityHeaders::headers(false);

        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertSame('DENY', $headers['X-Frame-Options']);
        self::assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
        self::assertArrayNotHasKey('Strict-Transport-Security', $headers);
    }

    /**
     * Pins the policy verbatim. `base-uri 'self'` in particular is load-bearing: the layout
     * emits <base href> from App::getFullBaseUrl(), and a cross-origin value makes the browser
     * drop the element, breaking every relative asset and htmx endpoint.
     */
    public function testContentSecurityPolicyIsPinned(): void
    {
        $expected = "default-src 'self'; base-uri 'self'; frame-ancestors 'none'; object-src 'none';"
            . " form-action 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';"
            . " img-src 'self' data:; font-src 'self'; connect-src 'self'";

        self::assertSame($expected, SecurityHeaders::headers(false)['Content-Security-Policy']);
    }

    public function testHeadersIncludeHstsForSecureRequests(): void
    {
        $headers = SecurityHeaders::headers(true);

        self::assertSame('max-age=31536000; includeSubDomains', $headers['Strict-Transport-Security']);
    }

    public function testHeadersOmitHstsForInsecureRequests(): void
    {
        self::assertArrayNotHasKey('Strict-Transport-Security', SecurityHeaders::headers(false));
    }
}
