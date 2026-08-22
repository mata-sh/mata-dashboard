<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Controllers;

use Mata\Controllers\LoginController;
use Mata\Core\App;
use Mata\Core\Auth;
use Mata\Core\Session;
use Mata\Http\Request;
use Mata\Http\Security\CsrfGuard;
use Mata\Tests\Helpers\ApplicationConfigFactory;
use Mata\Tests\TestCase;
use Mata\Utils\PathResolver;
use Mata\Utils\Tools;
use PHPUnit\Framework\MockObject\MockObject;
use Twig\Environment;

/**
 * @covers \Mata\Controllers\LoginController
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class LoginControllerTest extends TestCase
{
    private App&MockObject $app;
    private Auth&MockObject $auth;
    private MockObject&Tools $tools;
    private Environment&MockObject $twig;

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $this->auth = $this->createMock(Auth::class);
        $this->app = $this->getMockBuilder(App::class)
            ->setConstructorArgs([$this->auth, ApplicationConfigFactory::create(), $this->createStub(PathResolver::class), new Request()])
            ->onlyMethods(['getBaseHost', 'getFullBaseUrl', 'redirect'])
            ->getMock();
        $this->tools = $this->createMock(Tools::class);
        $this->twig = $this->createMock(Environment::class);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testItDisplaysLoginForm(): void
    {
        $this->app->expects($this->once())->method('getBaseHost')->willReturn('/');
        $this->app->expects($this->once())->method('getFullBaseUrl')->willReturn('http://localhost');
        $this->tools->expects($this->once())->method('icon')->with('sea-turtle-bl', 150)->willReturn('<svg>logo</svg>');
        $this->twig->expects($this->once())->method('render')->willReturn('<html>login form</html>');

        ob_start();
        $this->controller()->show();
        self::assertSame('<html>login form</html>', ob_get_clean());
    }

    public function testItLogsInUsingNormalizedPostInputAndRegeneratesCsrf(): void
    {
        $this->auth->expects($this->once())->method('login')->with('testuser', 'password123', true)->willReturn(true);
        $this->app->expects($this->once())->method('redirect')->with('watch');
        $csrf = Session::get(CsrfGuard::SESSION_KEY);
        Session::set(CsrfGuard::SESSION_KEY, 'old-token');

        $this->controller(['username' => ' testuser ', 'password' => 'password123', 'remember_me' => 'on'])->submit();

        self::assertNotSame('old-token', Session::get(CsrfGuard::SESSION_KEY));
    }

    public function testItRejectsArrayCredentialsAndRememberMeInput(): void
    {
        $this->auth->expects($this->once())->method('login')->with('', '', false)->willReturn(false);
        $this->configureFailureRender();

        ob_start();
        $this->controller(['username' => ['admin'], 'password' => ['password'], 'remember_me' => ['on']])->submit();
        ob_end_clean();
    }

    public function testItRendersLoginFailure(): void
    {
        $this->auth->expects($this->once())->method('login')->with('', '', false)->willReturn(false);
        $this->configureFailureRender();

        ob_start();
        $this->controller()->submit();
        self::assertSame('<html>login form with error</html>', ob_get_clean());
    }

    public function testItLogsOutAndRedirects(): void
    {
        $this->auth->expects($this->once())->method('logout');
        $this->app->expects($this->once())->method('redirect')->with('login');

        $this->controller()->logout();
    }

    /** @param array<string,mixed> $post */
    private function controller(array $post = []): LoginController
    {
        $request = new Request(post: $post);

        return new LoginController($this->app, $this->tools, $this->twig, new CsrfGuard($request), $request);
    }

    private function configureFailureRender(): void
    {
        $this->app->expects($this->once())->method('getBaseHost')->willReturn('/');
        $this->app->expects($this->once())->method('getFullBaseUrl')->willReturn('http://localhost');
        $this->tools->expects($this->once())->method('icon')->with('sea-turtle-bl', 150)->willReturn('<svg>logo</svg>');
        $this->twig->expects($this->once())->method('render')->willReturn('<html>login form with error</html>');
    }
}
