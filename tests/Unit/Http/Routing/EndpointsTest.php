<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Http\Routing;

use InvalidArgumentException;
use Mata\Http\Routing\Endpoints;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Http\Routing\Endpoints
 *
 * @internal
 */
class EndpointsTest extends TestCase
{
    private Endpoints $endpoints;

    protected function setUp(): void
    {
        $this->endpoints = new Endpoints();
    }

    /**
     * Test: Route registration stores route correctly.
     */
    public function testRegisterStoresRoute(): void
    {
        // Act
        $this->endpoints->register('login', '/auth/login');

        // Assert
        $this->assertEquals('/auth/login', $this->endpoints->url('login'));
    }

    /**
     * Test: Get method returns registered route.
     *
     * @deprecated Testing deprecated get() method
     */
    public function testGetReturnsRegisteredRoute(): void
    {
        // Arrange
        $this->endpoints->register('dashboard', '/dashboard');

        // Act
        $result = $this->endpoints->get('dashboard');

        // Assert
        $this->assertEquals('/dashboard', $result);
    }

    /**
     * Test: Get method returns null for non-existent route.
     *
     * @deprecated Testing deprecated get() method
     */
    public function testGetReturnsNullForNonExistentRoute(): void
    {
        // Act
        $result = $this->endpoints->get('nonexistent');

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test: URL method returns null for non-existent route.
     */
    public function testUrlReturnsNullForNonExistentRoute(): void
    {
        // Act
        $result = $this->endpoints->url('nonexistent');

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test: URL method returns simple route without parameters.
     */
    public function testUrlReturnsSimpleRoute(): void
    {
        // Arrange
        $this->endpoints->register('home', '/');

        // Act
        $result = $this->endpoints->url('home');

        // Assert
        $this->assertEquals('/', $result);
    }

    /**
     * Test: URL method substitutes single placeholder.
     */
    public function testUrlSubstitutesSinglePlaceholder(): void
    {
        // Arrange
        $this->endpoints->register('server', '/api/servers/{server}');

        // Act
        $result = $this->endpoints->url('server', ['server' => 'production']);

        // Assert
        $this->assertEquals('/api/servers/production', $result);
    }

    /**
     * Test: URL method substitutes multiple placeholders.
     */
    public function testUrlSubstitutesMultiplePlaceholders(): void
    {
        // Arrange
        $this->endpoints->register('metrics_server', '/api/metrics/{server}/{metric}');

        // Act
        $result = $this->endpoints->url('metrics_server', [
            'server' => 'production',
            'metric' => 'cpu',
        ]);

        // Assert
        $this->assertEquals('/api/metrics/production/cpu', $result);
    }

    /**
     * Test: URL method URL-encodes parameter values.
     */
    public function testUrlEncodesParameterValues(): void
    {
        // Arrange
        $this->endpoints->register('search', '/search/{query}');

        // Act
        $result = $this->endpoints->url('search', ['query' => 'hello world']);

        // Assert
        $this->assertEquals('/search/hello%20world', $result);
    }

    /**
     * Test: URL method handles special characters in parameters.
     */
    public function testUrlHandlesSpecialCharacters(): void
    {
        // Arrange
        $this->endpoints->register('file', '/files/{path}');

        // Act
        $result = $this->endpoints->url('file', ['path' => 'folder/sub/file.txt']);

        // Assert
        $this->assertEquals('/files/folder%2Fsub%2Ffile.txt', $result);
    }

    /**
     * Test: URL method appends query string parameters.
     */
    public function testUrlAppendsQueryString(): void
    {
        // Arrange
        $this->endpoints->register('search', '/search');

        // Act
        $result = $this->endpoints->url('search', [], ['q' => 'test', 'page' => '2']);

        // Assert
        $this->assertEquals('/search?q=test&page=2', $result);
    }

    /**
     * Test: URL method combines route params and query params.
     */
    public function testUrlCombinesRouteParamsAndQueryParams(): void
    {
        // Arrange
        $this->endpoints->register('metrics_server', '/api/metrics/{server}/{metric}');

        // Act
        $result = $this->endpoints->url('metrics_server', [
            'server' => 'production',
            'metric' => 'cpu',
        ], [
            'granularity' => 'hourly',
            'range' => '24h',
        ]);

        // Assert
        $this->assertEquals('/api/metrics/production/cpu?granularity=hourly&range=24h', $result);
    }

    /**
     * Test: URL method throws exception for unsubstituted placeholder.
     */
    public function testUrlThrowsExceptionForUnsubstitutedPlaceholder(): void
    {
        // Arrange
        $this->endpoints->register('metrics_server', '/api/metrics/{server}/{metric}');

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route "metrics_server" has unsubstituted placeholder "{metric}"');

        // Act
        $this->endpoints->url('metrics_server', ['server' => 'production']);
    }

    /**
     * Test: URL method throws exception when missing all parameters.
     */
    public function testUrlThrowsExceptionWhenMissingAllParameters(): void
    {
        // Arrange
        $this->endpoints->register('metrics_server', '/api/metrics/{server}/{metric}');

        // Expect
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsubstituted placeholder');

        // Act
        $this->endpoints->url('metrics_server');
    }

    /**
     * Test: URL method handles route with query params but unsubstituted placeholders.
     */
    public function testUrlThrowsExceptionForUnsubstitutedPlaceholderEvenWithQueryParams(): void
    {
        // Arrange
        $this->endpoints->register('metrics_server', '/api/metrics/{server}/{metric}');

        // Expect
        $this->expectException(InvalidArgumentException::class);

        // Act - even with query params, should fail on unsubstituted placeholder
        $this->endpoints->url('metrics_server', ['server' => 'prod'], ['range' => '24h']);
    }

    /**
     * Test: All method returns all registered routes.
     */
    public function testAllReturnsAllRoutes(): void
    {
        // Arrange
        $this->endpoints->register('home', '/');
        $this->endpoints->register('login', '/auth/login');
        $this->endpoints->register('dashboard', '/dashboard');

        // Act
        $result = $this->endpoints->all();

        // Assert
        $this->assertEquals([
            'home' => '/',
            'login' => '/auth/login',
            'dashboard' => '/dashboard',
        ], $result);
    }

    /**
     * Test: Only method returns specified routes.
     */
    public function testOnlyReturnsSpecifiedRoutes(): void
    {
        // Arrange
        $this->endpoints->register('home', '/');
        $this->endpoints->register('login', '/auth/login');
        $this->endpoints->register('dashboard', '/dashboard');
        $this->endpoints->register('settings', '/settings');

        // Act
        $result = $this->endpoints->only(['login', 'dashboard']);

        // Assert
        $this->assertEquals([
            'login' => '/auth/login',
            'dashboard' => '/dashboard',
        ], $result);
    }

    /**
     * Test: Only method handles non-existent route names.
     */
    public function testOnlyHandlesNonExistentRoutes(): void
    {
        // Arrange
        $this->endpoints->register('home', '/');
        $this->endpoints->register('login', '/auth/login');

        // Act
        $result = $this->endpoints->only(['login', 'nonexistent']);

        // Assert
        $this->assertEquals([
            'login' => '/auth/login',
        ], $result);
    }

    /**
     * Test: Routes can be overwritten by re-registering.
     */
    public function testRegisterOverwritesExistingRoute(): void
    {
        // Arrange
        $this->endpoints->register('home', '/old-home');

        // Act
        $this->endpoints->register('home', '/new-home');

        // Assert
        $this->assertEquals('/new-home', $this->endpoints->url('home'));
    }

    /**
     * Test: URL method handles empty query array.
     */
    public function testUrlHandlesEmptyQueryArray(): void
    {
        // Arrange
        $this->endpoints->register('home', '/');

        // Act
        $result = $this->endpoints->url('home', [], []);

        // Assert
        $this->assertEquals('/', $result);
    }

    /**
     * Test: URL method handles array values in query params.
     */
    public function testUrlHandlesArrayValuesInQueryParams(): void
    {
        // Arrange
        $this->endpoints->register('search', '/search');

        // Act
        $result = $this->endpoints->url('search', [], [
            'tags' => ['php', 'testing'],
            'active' => '1',
        ]);

        // Assert
        // http_build_query converts arrays to tag[0]=php&tag[1]=testing
        $this->assertStringContainsString('/search?', $result);
        $this->assertStringContainsString('tags', $result);
    }
}
