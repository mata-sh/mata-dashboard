<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Core;

use Mata\Core\Session;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Mata\Core\Session
 *
 * @internal
 */
final class SessionTest extends TestCase
{
    private string $originalSavePath;

    /** @var array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite: string} */
    private array $originalCookieParams;

    /** @var array<string,string> */
    private array $originalSessionIni;

    private string $sessionDirectory;
    private array $cookies;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->originalSavePath = session_save_path();
        $this->originalCookieParams = session_get_cookie_params();
        $this->originalSessionIni = [];
        foreach (['session.use_strict_mode', 'session.use_only_cookies', 'session.use_cookies'] as $option) {
            $this->originalSessionIni[$option] = (string) ini_get($option);
        }
        $this->sessionDirectory = sys_get_temp_dir() . '/mata_session_test_' . bin2hex(random_bytes(5));
        $this->cookies = $_COOKIE;
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            Session::destroy();
        }
        session_id('');
        session_save_path($this->originalSavePath);
        session_set_cookie_params($this->originalCookieParams);
        foreach ($this->originalSessionIni as $option => $value) {
            ini_set($option, $value);
        }
        $_COOKIE = $this->cookies;
        $this->removeDirectory($this->sessionDirectory);
        parent::tearDown();
    }

    public function testStartIsIdempotentAndAppliesSecurePolicy(): void
    {
        Session::start($this->sessionDirectory, true);
        $id = session_id();
        Session::start('/path/ignored/after/start');

        self::assertSame($id, session_id());
        self::assertSame('1', ini_get('session.use_strict_mode'));
        self::assertSame('1', ini_get('session.use_only_cookies'));
        self::assertTrue(session_get_cookie_params()['secure']);
        self::assertTrue(session_get_cookie_params()['httponly']);
        self::assertSame('Lax', session_get_cookie_params()['samesite']);
        self::assertSame('0700', substr(sprintf('%o', fileperms($this->sessionDirectory)), -4));
    }

    public function testStartWithNullPathRetainsConfiguredSavePath(): void
    {
        Session::start();

        self::assertSame($this->originalSavePath, session_save_path());
    }

    public function testRegenerateFailsWithoutAnActiveSession(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Application session has not been started');

        Session::regenerate();
    }

    public function testItRegeneratesAndDestroysTheSession(): void
    {
        Session::start($this->sessionDirectory);
        $oldId = session_id();
        Session::set('value', 'secret');

        Session::regenerate();
        self::assertNotSame($oldId, session_id());

        Session::destroy();
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame([], $_SESSION);
    }

    public function testStaticStorageMethods(): void
    {
        Session::start($this->sessionDirectory);
        Session::set('value', ['key' => 'secret']);

        self::assertSame(['key' => 'secret'], Session::get('value'));
        Session::remove('value');
        self::assertNull(Session::get('value'));

        Session::set('value', 'secret');
        Session::clear();
        self::assertNull(Session::get('value'));
        Session::close();
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    public function testInvalidSavePathFailsStartup(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('session save path');

        Session::start('/dev/null/sessions');
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (glob($path . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($path);
    }
}
