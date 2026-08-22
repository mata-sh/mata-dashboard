<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Http;

use Mata\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Http\Request
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
            server: ['HTTP_X_FORWARDED_PROTO' => 'https'],
            query: ['part' => 'summary', 'targets' => ['api|web', ['invalid']]],
        );

        self::assertTrue($request->isSecure());
        self::assertSame('https', $request->protocol());
        self::assertSame('summary', $request->queryString('part'));
        self::assertSame(['api|web'], $request->queryList('targets'));
        self::assertFalse($request->hasQuery('missing'));
    }

    public function testServerSchemeOutranksForwardedProtoHeader(): void
    {
        $request = new Request(server: [
            'REQUEST_SCHEME' => 'http',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertSame('http', $request->protocol());
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
            'terminating proxy' => [['HTTP_X_FORWARDED_PROTO' => 'https']],
        ];
    }
}
