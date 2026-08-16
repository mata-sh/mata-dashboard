<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Controllers;

use Exception;
use Mata\Controllers\LoginController;
use Mata\Core\App;
use Mata\Core\Auth;
use Mata\Http\Security\CsrfGuard;
use Mata\Tests\TestCase;
use Mata\Utils\Tools;
use PHPUnit\Framework\MockObject\MockObject;
use Twig\Environment;

/**
 * Test suite for LoginController.
 *
 * @covers \Mata\Controllers\LoginController
 *
 * @internal
 */
// Tests configure only the collaborators used by each request after the controller is built.
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class LoginControllerTest extends TestCase
{
    private LoginController $controller;
    private App&MockObject $mockApp;
    private Auth&MockObject $mockAuth;
    private MockObject&Tools $mockTools;
    private Environment&MockObject $mockTwig;
    private CsrfGuard $csrf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockAuth = $this->createMock(Auth::class);
        $this->mockApp = $this->createMock(App::class);
        $this->mockTools = $this->createMock(Tools::class);
        $this->mockTwig = $this->createMock(Environment::class);
        $this->csrf = new CsrfGuard();
        $_SESSION = [];

        // Setup App mock to return Auth
        $this->mockApp->auth = $this->mockAuth;

        $this->controller = new LoginController(
            $this->mockApp,
            $this->mockTools,
            $this->mockTwig,
            $this->csrf
        );
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * @test
     *
     * @group login-controller
     * @group show
     */
    public function testItDisplaysLoginForm(): void
    {
        $this->mockApp->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn('/')
        ;

        $this->mockApp->expects($this->once())
            ->method('getFullBaseUrl')
            ->willReturn('http://localhost')
        ;

        $this->mockTools->expects($this->once())
            ->method('icon')
            ->with('sea-turtle-bl', 150)
            ->willReturn('<svg>logo</svg>')
        ;

        $this->mockTwig->expects($this->once())
            ->method('render')
            ->with(
                'pages/login.html.twig',
                $this->callback(function ($data) {
                    $this->assertArrayHasKey('config', $data);
                    $this->assertArrayHasKey('ui', $data);
                    $this->assertArrayHasKey('base_url', $data['config']);
                    $this->assertArrayHasKey('full_base_url', $data['config']);
                    $this->assertArrayHasKey('main_logo', $data['ui']);

                    return true;
                })
            )
            ->willReturn('<html>login form</html>')
        ;

        ob_start();
        $this->controller->show();
        $output = ob_get_clean();

        $this->assertEquals('<html>login form</html>', $output);
    }

    /**
     * @test
     *
     * @group login-controller
     * @group submit
     */
    public function testItSuccessfullyLogsInUserAndRedirects(): void
    {
        $_POST = [
            'username' => 'testuser',
            'password' => 'password123',
            'remember_me' => '1',
        ];

        $this->mockAuth->expects($this->once())
            ->method('login')
            ->with('testuser', 'password123', true)
            ->willReturn(true)
        ;

        $this->mockApp->expects($this->once())
            ->method('redirect')
            ->with('watch')
        ;

        $_SESSION[CsrfGuard::SESSION_KEY] = 'old-csrf-token';

        $this->expectOutputString('');

        try {
            $this->controller->submit();
        } catch (Exception $e) {
            // Handle exit() call from redirect
            if ($e->getMessage() !== 'exit') {
                throw $e;
            }
        }

        $this->assertArrayHasKey(CsrfGuard::SESSION_KEY, $_SESSION);
        $this->assertIsString($_SESSION[CsrfGuard::SESSION_KEY]);
        $this->assertNotSame('', $_SESSION[CsrfGuard::SESSION_KEY]);
        $this->assertNotSame('old-csrf-token', $_SESSION[CsrfGuard::SESSION_KEY]);

        unset($_POST);
    }

    /**
     * @test
     *
     * @group login-controller
     * @group submit
     */
    public function testItHandlesLoginFailureAndShowsError(): void
    {
        $_SESSION[CsrfGuard::SESSION_KEY] = 'old-csrf-token';
        $_POST = [
            'username' => 'testuser',
            'password' => 'wrongpassword',
        ];

        $this->mockAuth->expects($this->once())
            ->method('login')
            ->with('testuser', 'wrongpassword', false)
            ->willReturn(false)
        ;

        $this->mockApp->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn('/')
        ;

        $this->mockApp->expects($this->once())
            ->method('getFullBaseUrl')
            ->willReturn('http://localhost')
        ;

        $this->mockTools->expects($this->once())
            ->method('icon')
            ->with('sea-turtle-bl', 150)
            ->willReturn('<svg>logo</svg>')
        ;

        $this->mockTwig->expects($this->once())
            ->method('render')
            ->with(
                'pages/login.html.twig',
                $this->callback(function ($data) {
                    $this->assertArrayHasKey('ui', $data);
                    $this->assertArrayHasKey('login_error_message', $data['ui']);
                    $this->assertEquals('Invalid login.', $data['ui']['login_error_message']);

                    return true;
                })
            )
            ->willReturn('<html>login form with error</html>')
        ;

        ob_start();

        try {
            $this->controller->submit();
            $output = ob_get_clean();
        } catch (Exception $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertEquals('<html>login form with error</html>', $output);
        $this->assertSame('old-csrf-token', $_SESSION[CsrfGuard::SESSION_KEY]);

        unset($_POST);
    }

    /**
     * @test
     *
     * @group login-controller
     * @group submit
     */
    public function testItPreservesRawCredentialsForAuthentication(): void
    {
        $_POST = [
            'username' => '  <admin&owner>  ',
            'password' => ' password<>" ',
            'remember_me' => 'true',
        ];

        $this->mockAuth->expects($this->once())
            ->method('login')
            ->with('<admin&owner>', ' password<>" ', true)
            ->willReturn(false)
        ;
        $this->configureFailedLoginRender();

        ob_start();
        $this->controller->submit();
        ob_end_clean();
    }

    public function testItRejectsArrayCredentialsAndRememberMeInput(): void
    {
        $_POST = [
            'username' => ['admin'],
            'password' => ['password'],
            'remember_me' => ['on'],
        ];

        $this->mockAuth->expects($this->once())
            ->method('login')
            ->with('', '', false)
            ->willReturn(false)
        ;
        $this->configureFailedLoginRender();

        ob_start();
        $this->controller->submit();
        ob_end_clean();
    }

    /**
     * @test
     *
     * @group login-controller
     * @group logout
     */
    public function testItLogsOutUserAndRedirectsToLogin(): void
    {
        $this->mockAuth->expects($this->once())
            ->method('logout')
        ;
        $this->mockApp->expects($this->once())
            ->method('redirect')
            ->with('login')
        ;
        $this->mockTwig->expects($this->never())
            ->method('render')
        ;

        $this->controller->logout();
    }

    /**
     * @test
     *
     * @group login-controller
     * @group submit
     */
    public function testItHandlesMissingPostData(): void
    {
        $_POST = [];

        $this->mockAuth->expects($this->once())
            ->method('login')
            ->with('', '', false)
            ->willReturn(false)
        ;

        $this->mockApp->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn('/')
        ;

        $this->mockApp->expects($this->once())
            ->method('getFullBaseUrl')
            ->willReturn('http://localhost')
        ;

        $this->mockTools->expects($this->once())
            ->method('icon')
            ->with('sea-turtle-bl', 150)
            ->willReturn('<svg>logo</svg>')
        ;

        $this->mockTwig->expects($this->once())
            ->method('render')
            ->willReturn('<html>login form with error</html>')
        ;

        ob_start();

        try {
            $this->controller->submit();
            $output = ob_get_clean();
        } catch (Exception $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertEquals('<html>login form with error</html>', $output);

        unset($_POST);
    }

    /**
     * @test
     *
     * @group login-controller
     * @group submit
     */
    public function testItHandlesRememberMeCheckboxUnchecked(): void
    {
        $_POST = [
            'username' => 'testuser',
            'password' => 'password123',
            // remember_me not set
        ];

        $this->mockAuth->expects($this->once())
            ->method('login')
            ->with('testuser', 'password123', false)
            ->willReturn(true)
        ;

        $this->mockApp->expects($this->once())
            ->method('redirect')
            ->with('watch')
        ;

        try {
            $this->controller->submit();
        } catch (Exception $e) {
            // Handle exit
        }

        unset($_POST);
    }

    /**
     * @test
     *
     * @group login-controller
     * @group integration
     */
    public function testItProvidesCorrectDataStructureToTemplate(): void
    {
        $capturedData = null;

        $this->mockApp->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn('/base')
        ;

        $this->mockApp->expects($this->once())
            ->method('getFullBaseUrl')
            ->willReturn('https://example.com/base')
        ;

        $this->mockTools->expects($this->once())
            ->method('icon')
            ->with('sea-turtle-bl', 150)
            ->willReturn('<svg>logo</svg>')
        ;

        $this->mockTwig->expects($this->once())
            ->method('render')
            ->willReturnCallback(function ($template, $data) use (&$capturedData) {
                $capturedData = $data;

                return '<html>rendered</html>';
            })
        ;

        ob_start();
        $this->controller->show();
        ob_get_clean();

        $this->assertNotNull($capturedData);

        $this->assertArrayStructure([
            'config' => 'array',
            'ui' => 'array',
        ], $capturedData);

        $this->assertArrayStructure([
            'base_url' => 'string',
            'full_base_url' => 'string',
        ], $capturedData['config']);

        $this->assertArrayStructure([
            'main_logo' => 'string',
        ], $capturedData['ui']);

        $this->assertEquals('/base', $capturedData['config']['base_url']);
        $this->assertEquals('https://example.com/base', $capturedData['config']['full_base_url']);
        $this->assertEquals('<svg>logo</svg>', $capturedData['ui']['main_logo']);
    }

    private function configureFailedLoginRender(): void
    {
        $this->mockApp->method('getBaseUrl')->willReturn('/');
        $this->mockApp->method('getFullBaseUrl')->willReturn('http://localhost');
        $this->mockTools->method('icon')->willReturn('<svg>logo</svg>');
        $this->mockTwig->method('render')->willReturn('<html>login form</html>');
    }
}
