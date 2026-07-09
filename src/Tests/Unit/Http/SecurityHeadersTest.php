<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Http;

use Mata\Http\SecurityHeaders;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Http\SecurityHeaders
 *
 * @internal
 */
class SecurityHeadersTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTPS']);

        parent::tearDown();
    }

    public function testHeadersIncludeExpectedBrowserSecurityPolicy(): void
    {
        unset($_SERVER['HTTPS']);

        $headers = SecurityHeaders::headers();

        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $headers['X-Frame-Options']);
        $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
        $this->assertSame(
            "default-src 'self'; base-uri 'self'; frame-ancestors 'none'; object-src 'none'; form-action 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'",
            $headers['Content-Security-Policy']
        );
        $this->assertArrayNotHasKey('Strict-Transport-Security', $headers);
    }

    public function testHeadersIncludeHstsForHttpsRequests(): void
    {
        $_SERVER['HTTPS'] = 'on';

        $headers = SecurityHeaders::headers();

        $this->assertSame('max-age=31536000; includeSubDomains', $headers['Strict-Transport-Security']);
    }
}
