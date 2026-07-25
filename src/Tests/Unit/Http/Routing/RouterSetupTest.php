<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Http\Routing;

use Bramus\Router\Router;
use InvalidArgumentException;
use Mata\Controllers\AuditController;
use Mata\Controllers\DashboardController;
use Mata\Controllers\LoginController;
use Mata\Controllers\MetricsController;
use Mata\Controllers\ModalController;
use Mata\Core\App;
use Mata\Core\Auth;
use Mata\Http\Responses\HtmlResponse;
use Mata\Http\Routing\Endpoints;
use Mata\Http\Routing\RouterSetup;
use Mata\Http\Security\CsrfGuard;
use Mata\Http\Security\RouteParameter;
use Mata\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use Twig\Environment;

/**
 * @covers \Mata\Http\Routing\RouterSetup
 *
 * @internal
 */
class RouterSetupTest extends TestCase
{
    public function testItProtectsWatchAndApiRoutesForEveryRequestMethod(): void
    {
        $beforeCalls = $this->collectProtectedRouteMiddlewareCalls();
        $methods = 'GET|POST|PUT|PATCH|DELETE|OPTIONS';

        $this->assertContains([$methods, '/watch(?:/.*)?'], $beforeCalls);
        $this->assertContains([$methods, '/api/.*'], $beforeCalls);
        $this->assertNotContains(['*', '/watch/.*'], $beforeCalls);
    }

    public function testItProtectsEveryRouteExceptHealthWhenIpWhitelistingIsEnabled(): void
    {
        $beforeCalls = [];
        $router = $this->createStub(Router::class);
        $router->method('before')
            ->willReturnCallback(function (string $methods, string $pattern, callable $handler) use (&$beforeCalls): void {
                $beforeCalls[] = [$methods, $pattern];
            })
        ;

        $setup = $this->createRouterSetup(router: $router);
        $this->invokeMethod($setup, 'setupIpWhitelist');

        $methods = 'GET|POST|PUT|PATCH|DELETE|OPTIONS';
        $this->assertContains([$methods, '/(?!health$).*'], $beforeCalls);
        $this->assertTrue($this->routeMatchesAnyPattern('/login', ['/(?!health$).*']));
        $this->assertTrue($this->routeMatchesAnyPattern('/watch', ['/(?!health$).*']));
        $this->assertFalse($this->routeMatchesAnyPattern('/health', ['/(?!health$).*']));
    }

    public function testItRendersBrandedForbiddenPageForBlockedBrowserRequests(): void
    {
        $server = $_SERVER;
        unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_HX_REQUEST']);
        $_SERVER['REQUEST_URI'] = '/watch';

        try {
            $twig = $this->createMock(Environment::class);
            $twig->expects($this->once())
                ->method('render')
                ->with(
                    'pages/error.html.twig',
                    $this->callback(static fn (array $context): bool => $context === [
                        'asset_base_url' => '/assets',
                        'error' => [
                            'type' => 'error',
                            'title' => 'Access forbidden',
                            'heading' => 'Access denied',
                            'message' => 'This network is not allowed to access the application.',
                        ],
                    ])
                )
                ->willReturn('<html>Access denied</html>')
            ;

            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects($this->once())
                ->method('warning')
                ->with('Blocked IP access', ['ip' => '203.0.113.10'])
            ;

            $response = $this->invokeMethod(
                $this->createRouterSetup(twig: $twig, logger: $logger),
                'ipAccessDeniedResponse',
                ['203.0.113.10']
            );

            $this->assertInstanceOf(HtmlResponse::class, $response);
            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame('<html>Access denied</html>', $response->getContent());
        } finally {
            $_SERVER = $server;
        }
    }

    public function testItRunsAuthenticationMiddlewareForTheWatchRootRoute(): void
    {
        $auth = $this->createMock(Auth::class);
        $auth->expects($this->once())->method('check')->willReturn(true);

        $app = $this->createStub(App::class);
        $app->auth = $auth;
        $router = new Router();
        $router->get('/watch', static function (): void {});

        $setup = $this->createRouterSetup(router: $router, app: $app);
        $this->invokeMethod($setup, 'setupProtectedRouteAuth');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/watch';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $router->run();
    }

    #[DataProvider('protectedApiRouteProvider')]
    public function testApiAuthMiddlewareCoversEveryApiRoute(string $route): void
    {
        $beforeCalls = $this->collectProtectedRouteMiddlewareCalls();
        $apiPatterns = array_column(
            array_filter($beforeCalls, fn (array $call): bool => str_starts_with($call[1], '/api/')),
            1
        );

        $this->assertNotSame([], $apiPatterns);
        $this->assertTrue(
            $this->routeMatchesAnyPattern($route, $apiPatterns),
            sprintf('Route %s is not covered by API auth middleware', $route)
        );
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function protectedApiRouteProvider(): array
    {
        return [
            'events' => ['/api/events'],
            'alarms' => ['/api/alarms'],
            'libraries' => ['/api/libraries'],
            'logs' => ['/api/logs/server-01/app-01'],
            'logs-status' => ['/api/logs-status/server-01/app-01'],
            'metric series' => ['/api/metrics/server-01/cpu'],
            'metric compare' => ['/api/metrics/server-01/compare'],
            'metrics dashboard' => ['/api/metrics/dashboard'],
        ];
    }

    public function testApiAuthMiddlewareAllowsAuthenticatedRequests(): void
    {
        $apiMiddleware = null;
        $router = $this->createStub(Router::class);
        $router->method('before')
            ->willReturnCallback(function (string $methods, string $pattern, callable $handler) use (&$apiMiddleware): void {
                if ($methods === 'GET|POST|PUT|PATCH|DELETE|OPTIONS' && $pattern === '/api/.*') {
                    $apiMiddleware = $handler;
                }
            })
        ;

        $auth = $this->createMock(Auth::class);
        $auth->expects($this->once())
            ->method('check')
            ->willReturn(true)
        ;

        $app = $this->createStub(App::class);
        $app->auth = $auth;

        $setup = $this->createRouterSetup(router: $router, app: $app);
        $this->invokeMethod($setup, 'setupProtectedRouteAuth');

        $this->assertIsCallable($apiMiddleware);
        $this->assertNull($apiMiddleware());
    }

    public function testCsrfMiddlewareCoversUnsafeHttpMethods(): void
    {
        $csrfMiddleware = $this->collectCsrfMiddlewareCall();

        $this->assertSame('POST|PUT|PATCH|DELETE', $csrfMiddleware['methods']);
        $this->assertSame('/.*', $csrfMiddleware['pattern']);
        $this->assertSame(['POST', 'PUT', 'PATCH', 'DELETE'], explode('|', $csrfMiddleware['methods']));
    }

    public function testCsrfMiddlewareAllowsValidUnsafeRequests(): void
    {
        $this->resetCsrfGlobals();
        $csrfMiddleware = $this->collectCsrfMiddlewareCall()['handler'];

        $_SESSION[CsrfGuard::SESSION_KEY] = 'session-token';
        $_POST[CsrfGuard::FIELD_NAME] = 'session-token';

        $this->assertNull($csrfMiddleware());
    }

    #[DataProvider('unsafeHttpMethodProvider')]
    public function testCsrfMiddlewareRejectsUnsafeBrowserRequestsWhenTokenFails(string $method): void
    {
        $this->resetCsrfGlobals();
        $_SERVER['REQUEST_METHOD'] = $method;

        $csrfMiddleware = null;
        $router = $this->createStub(Router::class);
        $router->method('before')
            ->willReturnCallback(function (string $methods, string $pattern, callable $handler) use (&$csrfMiddleware): void {
                if ($methods === 'POST|PUT|PATCH|DELETE' && $pattern === '/.*') {
                    $csrfMiddleware = $handler;
                }
            })
        ;

        $app = $this->createMock(App::class);
        $app->expects($this->once())
            ->method('redirect')
            ->with('login')
            ->willThrowException(new RuntimeException("csrf rejected {$method}"))
        ;

        $setup = $this->createRouterSetup(router: $router, app: $app);
        $this->invokeMethod($setup, 'setupCsrfProtection');

        $this->assertIsCallable($csrfMiddleware);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("csrf rejected {$method}");

        $csrfMiddleware();
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function unsafeHttpMethodProvider(): array
    {
        return [
            'POST' => ['POST'],
            'PUT' => ['PUT'],
            'PATCH' => ['PATCH'],
            'DELETE' => ['DELETE'],
        ];
    }

    public function testRegistersApplicationLogFilesModalRoutes(): void
    {
        $router = $this->createStub(Router::class);
        $router->method('mount')
            ->willReturnCallback(static fn (string $path, callable $callback) => $callback())
        ;
        $endpoints = new Endpoints();
        $setup = $this->createRouterSetup(router: $router, endpoints: $endpoints);

        $this->invokeMethod($setup, 'setupWatchRoutes');

        $this->assertSame(
            'watch/modal/{server}/{app}/log-files',
            $endpoints->get('modal_app_log_files_render')
        );
        $this->assertSame(
            'watch/modal/{server}/{app}/log-files/refresh',
            $endpoints->get('modal_app_log_files_refresh')
        );
    }

    public function testRegistersDatabaseProcessModalRoutes(): void
    {
        $router = $this->createStub(Router::class);
        $router->method('mount')
            ->willReturnCallback(static fn (string $path, callable $callback) => $callback())
        ;
        $endpoints = new Endpoints();
        $setup = $this->createRouterSetup(router: $router, endpoints: $endpoints);

        $this->invokeMethod($setup, 'setupWatchRoutes');

        $this->assertSame(
            'watch/modal/{server}/database/{database}/processes',
            $endpoints->get('modal_db_processes_render')
        );
        $this->assertSame(
            'watch/modal/{server}/database/{database}/processes/refresh',
            $endpoints->get('modal_db_processes_refresh')
        );
    }

    public function testRouteParameterValidationAcceptsSafeValues(): void
    {
        RouteParameter::assertSafe('server-01.example_org', 'Invalid route parameter');

        $this->assertTrue(true);
    }

    public function testRouteParameterValidationRejectsUnsafeValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid route parameter');

        RouteParameter::assertSafe('../server', 'Invalid route parameter');
    }

    /**
     * @return array<int,array{0:string,1:string}>
     */
    private function collectProtectedRouteMiddlewareCalls(): array
    {
        $beforeCalls = [];
        $router = $this->createStub(Router::class);
        $router->method('before')
            ->willReturnCallback(function (string $methods, string $pattern, callable $handler) use (&$beforeCalls): void {
                $beforeCalls[] = [$methods, $pattern];
            })
        ;

        $setup = $this->createRouterSetup($router);

        $this->invokeMethod($setup, 'setupProtectedRouteAuth');

        return $beforeCalls;
    }

    /**
     * @return array{methods:string,pattern:string,handler:callable}
     */
    private function collectCsrfMiddlewareCall(): array
    {
        $csrfMiddleware = null;
        $router = $this->createStub(Router::class);
        $router->method('before')
            ->willReturnCallback(function (string $methods, string $pattern, callable $handler) use (&$csrfMiddleware): void {
                if ($methods === 'POST|PUT|PATCH|DELETE' && $pattern === '/.*') {
                    $csrfMiddleware = [
                        'methods' => $methods,
                        'pattern' => $pattern,
                        'handler' => $handler,
                    ];
                }
            })
        ;

        $setup = $this->createRouterSetup($router);
        $this->invokeMethod($setup, 'setupCsrfProtection');

        $this->assertIsArray($csrfMiddleware);

        return $csrfMiddleware;
    }

    private function resetCsrfGlobals(): void
    {
        $_SESSION = [];
        $_POST = [];
        unset(
            $_SERVER['HTTP_ACCEPT'],
            $_SERVER['HTTP_HX_REQUEST'],
            $_SERVER['HTTP_X_CSRF_TOKEN'],
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['REQUEST_URI']
        );
    }

    /**
     * @param array<int,string> $patterns
     */
    private function routeMatchesAnyPattern(string $route, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match('#^' . $pattern . '$#', $route) === 1) {
                return true;
            }
        }

        return false;
    }

    private function createRouterSetup(
        ?Router $router = null,
        ?App $app = null,
        ?Endpoints $endpoints = null,
        ?Environment $twig = null,
        ?LoggerInterface $logger = null
    ): RouterSetup {
        return new RouterSetup(
            $router ?? $this->createStub(Router::class),
            $app ?? $this->createAppStub(),
            $this->createStub(LoginController::class),
            $this->createStub(DashboardController::class),
            $this->createStub(AuditController::class),
            $this->createStub(ModalController::class),
            $this->createMetricsController(),
            $endpoints ?? new Endpoints(),
            new CsrfGuard(),
            $twig ?? $this->createStub(Environment::class),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    private function createMetricsController(): MetricsController
    {
        $reflection = new ReflectionClass(MetricsController::class);

        // @var MetricsController $controller
        return $reflection->newInstanceWithoutConstructor();
    }

    private function createAppStub(): App
    {
        $auth = $this->createStub(Auth::class);
        $auth->method('check')->willReturn(true);

        $app = $this->createStub(App::class);
        $app->auth = $auth;

        return $app;
    }
}
