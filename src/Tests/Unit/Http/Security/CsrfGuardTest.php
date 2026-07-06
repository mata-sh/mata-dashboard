<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Http\Security;

use Mata\Http\Security\CsrfGuard;
use Mata\Tests\TestCase;

/**
 * Test suite for CSRF guard.
 *
 * @covers \Mata\Http\Security\CsrfGuard
 *
 * @internal
 */
class CsrfGuardTest extends TestCase
{
    private CsrfGuard $csrf;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);

        $this->csrf = new CsrfGuard();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);

        parent::tearDown();
    }

    /**
     * @group csrf
     */
    public function testGetTokenReturnsStablePersistedToken(): void
    {
        $token = $this->csrf->getToken();

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $token);
        $this->assertSame($token, $this->csrf->getToken());
        $this->assertSame($token, $_SESSION[CsrfGuard::SESSION_KEY]);
    }

    /**
     * @group csrf
     */
    public function testValidateRequestAcceptsPostAndHeaderTokensWithPostPrecedence(): void
    {
        $_SESSION[CsrfGuard::SESSION_KEY] = 'session-token';
        $_POST[CsrfGuard::FIELD_NAME] = 'session-token';

        $this->assertTrue($this->csrf->validateRequest());

        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'session-token';

        $this->assertTrue($this->csrf->validateRequest());

        $_POST[CsrfGuard::FIELD_NAME] = 'wrong-token';

        $this->assertFalse($this->csrf->validateRequest());
    }

    /**
     * @group csrf
     */
    public function testValidateRequestRejectsMissingOrInvalidTokens(): void
    {
        $_POST[CsrfGuard::FIELD_NAME] = 'submitted-token';
        $this->assertFalse($this->csrf->validateRequest());
        $this->assertArrayNotHasKey(CsrfGuard::SESSION_KEY, $_SESSION);

        $_SESSION[CsrfGuard::SESSION_KEY] = 'session-token';
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $this->assertFalse($this->csrf->validateRequest());

        $_POST[CsrfGuard::FIELD_NAME] = 'wrong-token';
        $this->assertFalse($this->csrf->validateRequest());

        $_POST[CsrfGuard::FIELD_NAME] = ['session-token'];
        $this->assertFalse($this->csrf->validateRequest());
    }

    /**
     * @group csrf
     */
    public function testRegenerateCreatesDifferentTokenAndInvalidatesOldToken(): void
    {
        $oldToken = $this->csrf->getToken();
        $_POST[CsrfGuard::FIELD_NAME] = $oldToken;

        $this->assertTrue($this->csrf->validateRequest());

        $newToken = $this->csrf->regenerate();

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $newToken);
        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame($newToken, $_SESSION[CsrfGuard::SESSION_KEY]);

        $_POST[CsrfGuard::FIELD_NAME] = $oldToken;
        $this->assertFalse($this->csrf->validateRequest());

        $_POST[CsrfGuard::FIELD_NAME] = $newToken;
        $this->assertTrue($this->csrf->validateRequest());
    }
}
