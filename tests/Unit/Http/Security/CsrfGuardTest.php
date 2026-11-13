<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Http\Security;

use Mata\Core\Session;
use Mata\Http\Request;
use Mata\Http\Security\CsrfGuard;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Http\Security\CsrfGuard
 *
 * @internal
 */
final class CsrfGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testGetTokenReturnsStablePersistedToken(): void
    {
        $csrf = $this->csrf();
        $token = $csrf->getToken();

        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $token);
        self::assertSame($token, $csrf->getToken());
        self::assertSame($token, Session::get(CsrfGuard::SESSION_KEY));
    }

    public function testValidateRequestAcceptsPostAndHeaderTokensWithPostPrecedence(): void
    {
        Session::set(CsrfGuard::SESSION_KEY, 'session-token');
        self::assertTrue($this->csrf(post: [CsrfGuard::FIELD_NAME => 'session-token'])->validateRequest());
        self::assertTrue($this->csrf(server: ['HTTP_X_CSRF_TOKEN' => 'session-token'])->validateRequest());
        self::assertFalse($this->csrf(
            post: [CsrfGuard::FIELD_NAME => 'wrong-token'],
            server: ['HTTP_X_CSRF_TOKEN' => 'session-token'],
        )->validateRequest());
    }

    public function testValidateRequestRejectsMissingOrInvalidTokens(): void
    {
        self::assertFalse($this->csrf(post: [CsrfGuard::FIELD_NAME => 'submitted-token'])->validateRequest());
        self::assertNull(Session::get(CsrfGuard::SESSION_KEY));

        Session::set(CsrfGuard::SESSION_KEY, 'session-token');
        self::assertFalse($this->csrf()->validateRequest());
        self::assertFalse($this->csrf(post: [CsrfGuard::FIELD_NAME => 'wrong-token'])->validateRequest());
        self::assertFalse($this->csrf(post: [CsrfGuard::FIELD_NAME => ['session-token']])->validateRequest());
    }

    public function testRegenerateCreatesDifferentTokenAndInvalidatesOldToken(): void
    {
        $oldToken = $this->csrf()->getToken();
        self::assertTrue($this->csrf(post: [CsrfGuard::FIELD_NAME => $oldToken])->validateRequest());

        $newToken = $this->csrf()->regenerate();

        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $newToken);
        self::assertNotSame($oldToken, $newToken);
        self::assertSame($newToken, Session::get(CsrfGuard::SESSION_KEY));
        self::assertFalse($this->csrf(post: [CsrfGuard::FIELD_NAME => $oldToken])->validateRequest());
        self::assertTrue($this->csrf(post: [CsrfGuard::FIELD_NAME => $newToken])->validateRequest());
    }

    /** @param array<string,mixed> $post
     * @param array<string,mixed> $server
     */
    private function csrf(array $post = [], array $server = []): CsrfGuard
    {
        return new CsrfGuard(new Request(server: $server, post: $post));
    }
}
