<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Http;

use Mata\Http\SecurityHeaders;
use Mata\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

    #[DataProvider('httpsServerValueProvider')]
    public function testHeadersIncludeHstsForHttpsRequests(string $httpsValue): void
    {
        $_SERVER['HTTPS'] = $httpsValue;

        $headers = SecurityHeaders::headers();

        $this->assertSame('max-age=31536000; includeSubDomains', $headers['Strict-Transport-Security']);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function httpsServerValueProvider(): array
    {
        return [
            'apache/mod_php' => ['on'],
            'cgi/fpm' => ['1'],
        ];
    }

    public function testHeadersOmitHstsWhenHttpsServerFlagIsOff(): void
    {
        $_SERVER['HTTPS'] = 'off';

        $headers = SecurityHeaders::headers();

        $this->assertArrayNotHasKey('Strict-Transport-Security', $headers);
    }
}
