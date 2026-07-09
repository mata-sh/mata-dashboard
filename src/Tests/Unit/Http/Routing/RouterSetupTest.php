<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Http\Routing;

use Bramus\Router\Router;
use InvalidArgumentException;
use Mata\Controllers\DashboardController;
use Mata\Controllers\LoginController;
use Mata\Controllers\MetricsController;
use Mata\Controllers\ModalController;
use Mata\Core\App;
use Mata\Core\Auth;
use Mata\Http\Responses\Response;
use Mata\Http\Routing\Endpoints;
use Mata\Http\Routing\RouterSetup;
use Mata\Http\Security\CsrfGuard;
use Mata\Http\Security\RouteParameter;
use Mata\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * @covers \Mata\Http\Routing\RouterSetup
 *
 * @internal
 */
class RouterSetupTest extends TestCase
{
    public function testItProtectsTheEntireApiPrefix(): void
    {
        $beforeCalls = $this->collectProtectedRouteMiddlewareCalls();

        // Auth is method-agnostic ('*') so no verb can slip past the gate.
        $this->assertContains(['*', '/watch/.*'], $beforeCalls);
        $this->assertContains(['*', '/api/.*'], $beforeCalls);
        $this->assertNotContains(['GET|POST', '/api/metrics/.*'], $beforeCalls);
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

    public function testItReturnsJsonUnauthorizedForUnauthenticatedApiRequests(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/events';

        $auth = $this->createMock(Auth::class);
        $auth->expects($this->once())
            ->method('check')
            ->willReturn(false)
        ;

        $app = $this->createStub(App::class);
        $app->auth = $auth;

        $setup = $this->createRouterSetup(app: $app);

        /** @var Response $response */
        $response = $this->invokeMethod($setup, 'createApiAuthFailureResponse');
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Authentication required', $payload['message']);
        $this->assertSame('UNAUTHORIZED', $payload['code']);
    }

    public function testItAllowsAuthenticatedApiRequests(): void
    {
        $auth = $this->createMock(Auth::class);
        $auth->expects($this->once())
            ->method('check')
            ->willReturn(true)
        ;

        $app = $this->createStub(App::class);
        $app->auth = $auth;

        $setup = $this->createRouterSetup(app: $app);

        $this->assertNull($this->invokeMethod($setup, 'createApiAuthFailureResponse'));
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

    private function createRouterSetup(?Router $router = null, ?App $app = null): RouterSetup
    {
        return new RouterSetup(
            $router ?? $this->createStub(Router::class),
            $app ?? $this->createAppStub(),
            $this->createStub(LoginController::class),
            $this->createStub(DashboardController::class),
            $this->createStub(ModalController::class),
            $this->createMetricsController(),
            new Endpoints(),
            new CsrfGuard()
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
