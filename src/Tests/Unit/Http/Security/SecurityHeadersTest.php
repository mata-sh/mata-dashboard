<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Http\Security;

use Mata\Http\Request;
use Mata\Http\Security\SecurityHeaders;
use Mata\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @covers \Mata\Http\Security\SecurityHeaders
 *
 * @internal
 */
final class SecurityHeadersTest extends TestCase
{
    public function testHeadersAreEmptyWhenDisabled(): void
    {
        self::assertSame([], SecurityHeaders::headers(new Request(), false));
    }

    public function testHeadersIncludeExpectedBrowserSecurityPolicy(): void
    {
        $headers = SecurityHeaders::headers(new Request());

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

        self::assertSame($expected, SecurityHeaders::headers(new Request())['Content-Security-Policy']);
    }

    #[DataProvider('httpsServerValueProvider')]
    public function testHeadersIncludeHstsForHttpsRequests(string $httpsValue): void
    {
        $headers = SecurityHeaders::headers(new Request(server: ['HTTPS' => $httpsValue]));

        self::assertSame('max-age=31536000; includeSubDomains', $headers['Strict-Transport-Security']);
    }

    /** @return array<string,array{0:string}> */
    public static function httpsServerValueProvider(): array
    {
        return ['apache/mod_php' => ['on'], 'cgi/fpm' => ['1']];
    }

    #[DataProvider('nonSecureServerValueProvider')]
    public function testHeadersOmitHstsForNonSecureRequests(array $server): void
    {
        self::assertArrayNotHasKey('Strict-Transport-Security', SecurityHeaders::headers(new Request(server: $server)));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function nonSecureServerValueProvider(): array
    {
        return [
            'https off' => [['HTTPS' => 'off']],
            'https zero' => [['HTTPS' => '0']],
            'https empty (nginx fastcgi_param without if_not_empty)' => [['HTTPS' => '']],
            'forwarded plain' => [['HTTP_X_FORWARDED_PROTO' => 'http']],
        ];
    }

    /**
     * A TLS-terminating proxy often exposes nothing but X-Forwarded-Proto, so HSTS has to honour it.
     * Spoofing it over plaintext is inert: RFC 6797 §8.1 requires user agents to ignore an STS
     * header that did not arrive over a secure transport.
     */
    public function testHeadersIncludeHstsForProxyTerminatedTls(): void
    {
        $headers = SecurityHeaders::headers(new Request(server: ['HTTP_X_FORWARDED_PROTO' => 'https']));

        self::assertSame('max-age=31536000; includeSubDomains', $headers['Strict-Transport-Security']);
    }
}
