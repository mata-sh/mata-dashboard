<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Http;

use Mata\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Http\Request
 * @covers \Mata\Http\Security\IpRangeMatcher
 *
 * @internal
 */
final class RequestTest extends TestCase
{
    public function testItNormalizesMalformedValuesAndSnapshotsInput(): void
    {
        $server = [
            'REQUEST_METHOD' => ['POST'],
            'REQUEST_URI' => '/api/events',
            'HTTP_ACCEPT' => ['application/json'],
            'SCRIPT_NAME' => '/public/index.php',
            'HTTPS' => 'on',
            'REMOTE_ADDR' => ['127.0.0.1'],
        ];
        $post = ['name' => ['array'], 'enabled' => 'on', 'targets' => ['one', 2, 'three']];
        $request = new Request($server, post: $post, cookies: ['remember_token' => ['invalid']]);
        $server['REQUEST_URI'] = '/changed';
        $post['enabled'] = false;

        self::assertSame('GET', $request->method());
        self::assertSame('/api/events', $request->uri());
        self::assertNull($request->header('Accept'));
        self::assertSame('/public', $request->scriptDirectory());
        self::assertTrue($request->isSecure());
        self::assertSame('https', $request->protocol());
        self::assertNull($request->clientIp());
        self::assertNull($request->postString('name'));
        self::assertTrue($request->postBool('enabled'));
        self::assertSame(['one', 'three'], $request->postList('targets'));
        self::assertNull($request->cookie('remember_token'));
    }

    public function testItSupportsForwardedHttpsAndQueryAccess(): void
    {
        $request = new Request(
            server: [
                'REMOTE_ADDR' => '192.0.2.10',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ],
            query: ['part' => 'summary', 'targets' => ['api|web', ['invalid']]],
            trustedProxies: ['192.0.2.10'],
        );

        self::assertTrue($request->isSecure());
        self::assertSame('https', $request->protocol());
        self::assertSame('summary', $request->queryString('part'));
        self::assertSame(['api|web'], $request->queryList('targets'));
        self::assertFalse($request->hasQuery('missing'));
    }

    public function testServerSchemeOutranksForwardedProtoHeader(): void
    {
        $request = new Request(
            server: [
                'REMOTE_ADDR' => '192.0.2.10',
                'REQUEST_SCHEME' => 'http',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ],
            trustedProxies: ['192.0.2.10'],
        );

        self::assertSame('http', $request->protocol());
    }

    public function testItIgnoresForwardedProtoFromAnUntrustedClient(): void
    {
        $request = new Request(
            server: [
                'REMOTE_ADDR' => '198.51.100.20',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ],
            trustedProxies: ['192.0.2.10'],
        );

        self::assertFalse($request->isSecure());
        self::assertSame('http', $request->protocol());
    }

    #[DataProvider('trustedProxyProvider')]
    public function testItSupportsExactAndCidrTrustedProxies(string $remoteAddress, string $trustedProxy): void
    {
        $request = new Request(
            server: [
                'REMOTE_ADDR' => $remoteAddress,
                'HTTP_X_FORWARDED_PROTO' => 'HTTPS',
            ],
            trustedProxies: [$trustedProxy],
        );

        self::assertTrue($request->isSecure());
        self::assertSame('https', $request->protocol());
    }

    /** @return array<string,array{string,string}> */
    public static function trustedProxyProvider(): array
    {
        return [
            'exact IPv4' => ['192.0.2.10', '192.0.2.10'],
            'IPv4 CIDR' => ['192.0.2.129', '192.0.2.0/24'],
            'exact IPv6' => ['2001:db8::10', '2001:db8::10'],
            'IPv6 CIDR' => ['2001:db8:abcd::10', '2001:db8:abcd::/48'],
        ];
    }

    #[DataProvider('untrustedForwardedProtocolProvider')]
    public function testItRejectsUntrustedOrMalformedForwardedProtocols(array $server): void
    {
        $request = new Request(server: $server, trustedProxies: ['192.0.2.0/24']);

        self::assertFalse($request->isSecure());
        self::assertSame('http', $request->protocol());
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function untrustedForwardedProtocolProvider(): array
    {
        return [
            'missing remote address' => [['HTTP_X_FORWARDED_PROTO' => 'https']],
            'malformed remote address' => [[
                'REMOTE_ADDR' => 'not-an-ip',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ]],
            'outside CIDR range' => [[
                'REMOTE_ADDR' => '192.0.3.10',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ]],
            'comma-separated protocol chain' => [[
                'REMOTE_ADDR' => '192.0.2.10',
                'HTTP_X_FORWARDED_PROTO' => 'https,http',
            ]],
            'unknown protocol' => [[
                'REMOTE_ADDR' => '192.0.2.10',
                'HTTP_X_FORWARDED_PROTO' => 'tls',
            ]],
        ];
    }

    public function testItAddsTrustedProxiesWithoutResnapshottingInput(): void
    {
        $server = [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];
        $request = new Request(server: $server);
        $server['REMOTE_ADDR'] = '198.51.100.20';

        $configuredRequest = $request->withTrustedProxies(['192.0.2.10']);

        self::assertFalse($request->isSecure());
        self::assertTrue($configuredRequest->isSecure());
    }

    #[DataProvider('nonSecureServerProvider')]
    public function testItRejectsNonSecureServerSignals(array $server): void
    {
        self::assertFalse((new Request(server: $server))->isSecure());
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function nonSecureServerProvider(): array
    {
        return [
            'off' => [['HTTPS' => 'off']],
            'zero' => [['HTTPS' => '0']],
            'empty (nginx fastcgi_param without if_not_empty)' => [['HTTPS' => '']],
            'plain port' => [['SERVER_PORT' => '80']],
            'forwarded plain' => [['HTTP_X_FORWARDED_PROTO' => 'http']],
        ];
    }

    #[DataProvider('secureServerProvider')]
    public function testItAcceptsServerControlledTlsSignals(array $server): void
    {
        $request = new Request(server: $server);

        self::assertTrue($request->isSecure());
        self::assertSame('https', $request->protocol());
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function secureServerProvider(): array
    {
        return [
            'apache/mod_php' => [['HTTPS' => 'on']],
            'cgi/fpm' => [['HTTPS' => '1']],
            'tls port as string' => [['SERVER_PORT' => '443']],
            'tls port as int' => [['SERVER_PORT' => 443]],
        ];
    }
}
