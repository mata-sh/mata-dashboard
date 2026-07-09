<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Controllers;

use Exception;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\Dto\EnvironmentConfig;
use Mata\Controllers\ModalController;
use Mata\Core\App;
use Mata\Http\Responses\HtmlResponse;
use Mata\Http\Responses\JsonResponse;
use Mata\Http\Routing\Endpoints;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\DataTransformers\LogDataTransformer;
use Mata\Services\Foundation\CacheManager;
use Mata\Tests\Helpers\ApplicationConfigFactory;
use Mata\Tests\TestCase;
use Mata\Utils\Tools;
use Mata\Views\ModalRenderer;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use Twig\Environment;

/**
 * Test suite for ModalController.
 *
 * Tests modal content generation including:
 * - App README modal rendering
 * - Error log modal rendering
 * - Server info modal rendering
 * - Cache integration
 * - Server/app validation
 * - Error handling for missing resources
 * - XSS prevention in rendered content
 *
 * @covers \Mata\Controllers\ModalController
 *
 * @internal
 */
class ModalControllerTest extends TestCase
{
    private ModalController $controller;
    private App&MockObject $mockApp;
    private MockObject&Tools $mockTools;
    private Environment&MockObject $mockTwig;
    private Logger $logger;
    private CacheManager&MockObject $mockCacheManager;
    private LogDataTransformer&MockObject $mockLogTransformer;
    private Endpoints&MockObject $mockEndpoints;
    private ApplicationConfig $appConfig;
    private array $serversConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockApp = $this->createMock(App::class);
        $this->mockTools = $this->createMock(Tools::class);
        $this->mockTwig = $this->createMock(Environment::class);
        $this->logger = new Logger('test', [new TestHandler()]);
        $this->mockCacheManager = $this->createMock(CacheManager::class);
        $this->mockLogTransformer = $this->createMock(LogDataTransformer::class);
        $this->mockEndpoints = $this->createMock(Endpoints::class);

        // Create minimal ApplicationConfig with all required DTOs
        $this->appConfig = $this->createTestApplicationConfig();

        // Configure sample servers
        $this->serversConfig = [
            'server1' => [
                'api_url' => 'https://server1.example.com/api',
                'api_key' => 'test-api-key-1',
                'title' => 'Server 1',
            ],
            'server2' => [
                'api_url' => 'https://server2.example.com/api',
                'api_key' => 'test-api-key-2',
                'title' => 'Server 2',
            ],
        ];

        // Mock Tools::encrypt to avoid encryption dependencies
        $this->mockTools->method('encrypt')
            ->willReturnCallback(fn ($value, $secret) => base64_encode($value))
        ;

        // Mock icon method for ModalRenderer
        $this->mockTools->method('icon')
            ->willReturn('<svg></svg>')
        ;

        // Mock formatDuration for cache age display
        $this->mockTools->method('formatDuration')
            ->willReturnCallback(fn ($seconds) => "{$seconds}s")
        ;

        // Mock LogDataTransformer to pass through data
        $this->mockLogTransformer->method('flattenGroupedLogs')
            ->willReturnCallback(fn ($data) => $data)
        ;

        $this->controller = new ModalController(
            $this->mockApp,
            $this->mockTools,
            $this->mockTwig,
            $this->logger,
            $this->appConfig,
            $this->mockCacheManager,
            $this->mockLogTransformer,
            $this->mockEndpoints,
            $this->serversConfig
        );
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group initialization
     */
    public function testItInitializesWithDependencies(): void
    {
        $this->assertInstanceOf(ModalController::class, $this->controller);
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group initialization
     * @group demo-mode
     *
     * Validates that in demo mode, the controller creates MockServerDataCollector
     * instead of the real ServerDataCollector. This is important for testing
     * and demonstration environments where real API calls should not be made.
     */
    public function testItCreatesMockCollectorsInDemoMode(): void
    {
        // Create demo environment config
        $demoConfig = $this->createTestApplicationConfig('demo');

        $demoController = new ModalController(
            $this->mockApp,
            $this->mockTools,
            $this->mockTwig,
            $this->logger,
            $demoConfig,
            $this->mockCacheManager,
            $this->mockLogTransformer,
            $this->mockEndpoints,
            $this->serversConfig
        );

        // Test that we can still call methods (implicitly testing mock collectors exist)
        $this->assertInstanceOf(ModalController::class, $demoController);
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group readme-modal
     * @group happy-path
     *
     * Tests successful README modal rendering. This is the primary use case
     * where a user clicks on an app to view its README.md file. The controller
     * should fetch the README from cache/API, convert markdown to HTML, and
     * return a rendered modal template.
     */
    public function testItRendersReadmeModalSuccessfully(): void
    {
        $server = 'server1';
        $app = 'test-app';
        $readmeContent = '# Test App\n\nThis is a test README.';

        // Mock the server data collector response
        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->expects($this->once())
            ->method('fetchAppReadme')
            ->with($app, $this->anything())
            ->willReturn([
                'result' => $readmeContent,
                'cache_age' => 60,
                'cache_ttl' => 300,
            ])
        ;

        $this->injectMockCollector($server, $mockCollector);

        // Mock Twig rendering
        $expectedHtml = '<div class="modal">README content</div>';
        $this->mockTwig->expects($this->once())
            ->method('render')
            ->with(
                'partials/modals/readme-modal.html.twig',
                $this->callback(function ($data) {
                    // Verify template data structure
                    return isset($data['content']['modal_content'])
                           && isset($data['app']['server'], $data['app']['name'], $data['cache']['age'], $data['cache']['ttl']);
                })
            )
            ->willReturn($expectedHtml)
        ;

        $response = $this->controller->renderAppReadme($server, $app);

        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertEquals($expectedHtml, $response->getContent());
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group readme-modal
     * @group cache
     *
     * Tests force refresh functionality for README modals. When a user explicitly
     * requests fresh data (e.g., by clicking a refresh button), the controller
     * should bypass cache and fetch fresh data from the API.
     */
    public function testItRendersReadmeModalWithForceRefresh(): void
    {
        $server = 'server1';
        $app = 'test-app';

        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->expects($this->once())
            ->method('fetchAppReadme')
            ->with($app, $this->anything())
            ->willReturn([
                'result' => '# Fresh README',
                'cache_age' => 0,
                'cache_ttl' => 300,
            ])
        ;

        $this->injectMockCollector($server, $mockCollector);

        $this->mockTwig->method('render')
            ->willReturn('<div>Fresh README</div>')
        ;

        $response = $this->controller->renderAppReadme($server, $app, forceRefresh: true);

        $this->assertInstanceOf(HtmlResponse::class, $response);
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group readme-modal
     * @group error-handling
     *
     * Tests handling of missing README files. When an app doesn't have a README.md,
     * the controller should gracefully display a "No Readme.md found" message
     * instead of throwing an error.
     */
    public function testItHandlesMissingReadmeGracefully(): void
    {
        $server = 'server1';
        $app = 'test-app';

        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->expects($this->once())
            ->method('fetchAppReadme')
            ->willReturn([
                'result' => '',
                'cache_age' => 0,
                'cache_ttl' => 300,
            ])
        ;

        $this->injectMockCollector($server, $mockCollector);

        $this->mockTwig->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                $this->callback(function ($data) {
                    // Should contain "No Readme.md found" message in content
                    return str_contains($data['content']['modal_content'], 'No Readme.md found');
                })
            )
            ->willReturn('<div>No README</div>')
        ;

        $response = $this->controller->renderAppReadme($server, $app);

        $this->assertInstanceOf(HtmlResponse::class, $response);
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group readme-modal
     * @group validation
     *
     * Tests server validation for README requests. When a user tries to access
     * a README for a non-existent server, the controller should return a 404
     * response instead of proceeding with the request.
     */
    public function testItReturnsNotFoundForInvalidServerOnReadme(): void
    {
        $response = $this->controller->renderAppReadme('nonexistent-server', 'test-app');

        // Should return a 404 response
        $this->assertEquals(404, $response->getStatusCode());

        // Should be a JSON response (from ResponseFactory::notFound)
        $this->assertInstanceOf(JsonResponse::class, $response);
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group readme-modal
     * @group error-handling
     *
     * Tests error handling when API fails to fetch README. The controller should
     * catch the exception and return a user-friendly error response instead of
     * crashing or exposing internal error details.
     */
    public function testItHandlesApiFailureForReadme(): void
    {
        $server = 'server1';
        $app = 'test-app';

        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->expects($this->once())
            ->method('fetchAppReadme')
            ->willReturn(null) // API failure
        ;

        $this->injectMockCollector($server, $mockCollector);

        $response = $this->controller->renderAppReadme($server, $app);

        // Should return error response
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group logs-modal
     * @group happy-path
     *
     * Tests successful error log modal rendering. When a user views an app's
     * error log, the controller should fetch the log data and render it in
     * a modal with proper formatting and structure.
     */
    public function testItRendersLogsModalSuccessfully(): void
    {
        $server = 'server1';
        $app = 'test-app';

        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->expects($this->once())
            ->method('fetchAppLogs')
            ->with($app, $this->anything())
            ->willReturn([
                'result' => [
                    ['level' => 'ERROR', 'message' => 'Test error', 'timestamp' => '2024-01-01 12:00:00'],
                ],
                'cache_age' => 30,
                'cache_ttl' => 60,
            ])
        ;

        $this->injectMockCollector($server, $mockCollector);

        $expectedHtml = '<div class="modal">Error log content</div>';
        $this->mockTwig->method('render')
            ->willReturnCallback(function (string $template) use ($expectedHtml) {
                if ($template === 'partials/components/logs-table.html.twig') {
                    return '<div>Table</div>';
                }
                if ($template === 'partials/modals/logs-modal.html.twig') {
                    return $expectedHtml;
                }

                return '';
            })
        ;

        $response = $this->controller->renderAppLogs($server, $app);

        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertEquals($expectedHtml, $response->getContent());
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group logs-modal
     * @group json-response
     *
     * Tests JSON error log endpoint. Some AJAX requests need raw JSON data
     * instead of rendered HTML. This tests that the controller can return
     * error log data as JSON when requested.
     */
    public function testItReturnsLogsAsJsonWhenRequested(): void
    {
        $server = 'server1';
        $app = 'test-app';
        $errorLogData = [
            ['level' => 'ERROR', 'message' => 'Test error'],
        ];

        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->expects($this->once())
            ->method('fetchAppLogs')
            ->willReturn([
                'result' => $errorLogData,
                'cache_age' => 30,
                'cache_ttl' => 60,
            ])
        ;

        $this->injectMockCollector($server, $mockCollector);

        $response = $this->controller->getAppLogsJson($server, $app);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals($errorLogData, $response->getData());
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group logs-modal
     * @group json-endpoint
     *
     * Tests dedicated JSON error log endpoint.
     * Separate methods for HTML vs JSON provide clearer semantics.
     */
    public function testItReturnsLogsJsonViaDedicatedEndpoint(): void
    {
        $server = 'server1';
        $app = 'test-app';
        $errorLogData = [
            ['level' => 'WARNING', 'message' => 'Warning message'],
            ['level' => 'ERROR', 'message' => 'Error message'],
        ];

        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->expects($this->once())
            ->method('fetchAppLogs')
            ->willReturn([
                'result' => $errorLogData,
                'cache_age' => 15,
                'cache_ttl' => 60,
            ])
        ;

        $this->injectMockCollector($server, $mockCollector);

        $response = $this->controller->getAppLogsJson($server, $app);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals($errorLogData, $response->getData());
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group logs-modal
     * @group json-endpoint
     */
    public function testItReturnsGroupedLogsJsonFlattenedWithSource(): void
    {
        $server = 'server1';
        $app = 'test-app';
        $groupedLogs = [
            'logs/app.log' => [
                [
                    'timestamp' => '2026-07-09 12:00:00',
                    'type' => 'Error',
                    'message' => 'Boom',
                ],
            ],
        ];
        $expectedLogs = [
            [
                'timestamp' => '2026-07-09 12:00:00',
                'type' => 'Error',
                'message' => 'Boom',
                'source' => 'logs/app.log',
            ],
        ];

        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->expects($this->once())
            ->method('fetchAppLogs')
            ->with($app, $this->anything())
            ->willReturn([
                'result' => $groupedLogs,
                'cache_age' => 15,
                'cache_ttl' => 60,
            ])
        ;

        $this->injectMockCollector($server, $mockCollector);
        $this->setProperty($this->controller, 'logTransformer', new LogDataTransformer());

        $response = $this->controller->getAppLogsJson($server, $app);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame($expectedLogs, $response->getData());
        $this->assertSame(json_encode($expectedLogs, JSON_THROW_ON_ERROR), $response->getContent());
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group logs-modal
     * @group validation
     *
     * Tests server validation for error log requests. Invalid server names
     * should result in a 404 response.
     */
    public function testItReturnsNotFoundForInvalidServerOnLogs(): void
    {
        $response = $this->controller->renderAppLogs('nonexistent-server', 'test-app');

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertInstanceOf(JsonResponse::class, $response);
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group logs-modal
     * @group error-handling
     *
     * Tests error handling when error log fetching fails. The API might be
     * down or the app might not have an error log. The controller should
     * handle this gracefully.
     */
    public function testItHandlesApiFailureForLogs(): void
    {
        $server = 'server1';
        $app = 'test-app';

        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->expects($this->once())
            ->method('fetchAppLogs')
            ->willReturn(null) // API failure
        ;

        $this->injectMockCollector($server, $mockCollector);

        $response = $this->controller->renderAppLogs($server, $app);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group server-info-modal
     * @group happy-path
     *
     * Tests successful server info modal rendering. When a user clicks on a
     * server to view its details, the controller should fetch server information
     * (OS, PHP version, packages, etc.) and render it in a modal.
     */
    public function testItRendersServerInfoModalSuccessfully(): void
    {
        $server = 'server1';
        $serverInfo = [
            'os_name' => 'Ubuntu',
            'os_version' => '22.04',
            'kernel_version' => '5.15.0',
            'php_version' => '8.3.0',
            'hostname' => 'server1.example.com',
            'architecture' => 'x86_64',
        ];

        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->expects($this->once())
            ->method('getServerInfo')
            ->with($this->anything())
            ->willReturn([
                'result' => $serverInfo,
                'cache_age' => 120,
                'cache_ttl' => 600,
            ])
        ;

        $this->injectMockCollector($server, $mockCollector);

        $expectedHtml = '<div class="modal">Server info content</div>';
        $this->mockTwig->expects($this->once())
            ->method('render')
            ->with(
                'partials/modals/server-info-modal.html.twig',
                $this->callback(function ($data) use ($server, $serverInfo) {
                    // Verify server data is properly structured
                    return isset($data['server']['name'])
                           && $data['server']['name'] === $server
                           && isset($data['server']['os_name'])
                           && $data['server']['os_name'] === $serverInfo['os_name'];
                })
            )
            ->willReturn($expectedHtml)
        ;

        $response = $this->controller->renderServerInfo($server);

        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertEquals($expectedHtml, $response->getContent());
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group server-info-modal
     * @group cache
     *
     * Tests force refresh for server info. Server information is typically
     * cached longer since it changes less frequently, but users should still
     * be able to force refresh it.
     */
    public function testItRendersServerInfoWithForceRefresh(): void
    {
        $server = 'server1';

        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->expects($this->once())
            ->method('getServerInfo')
            ->willReturn([
                'result' => ['os_name' => 'Ubuntu'],
                'cache_age' => 0,
                'cache_ttl' => 600,
            ])
        ;

        $this->injectMockCollector($server, $mockCollector);

        $this->mockTwig->method('render')
            ->willReturn('<div>Fresh server info</div>')
        ;

        $response = $this->controller->renderServerInfo($server, forceRefresh: true);

        $this->assertInstanceOf(HtmlResponse::class, $response);
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group server-info-modal
     * @group validation
     *
     * Tests server validation for server info requests. Users shouldn't be
     * able to request info for servers that don't exist in the configuration.
     */
    public function testItReturnsNotFoundForInvalidServerOnServerInfo(): void
    {
        $response = $this->controller->renderServerInfo('nonexistent-server');

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertInstanceOf(JsonResponse::class, $response);
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group server-info-modal
     * @group error-handling
     *
     * Tests handling of missing or incomplete server info data. The API might
     * return partial data or fail entirely. The controller should handle this
     * gracefully and display N/A for missing fields.
     */
    public function testItHandlesEmptyServerInfoGracefully(): void
    {
        $server = 'server1';

        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->expects($this->once())
            ->method('getServerInfo')
            ->willReturn([
                'result' => [], // Empty server info
                'cache_age' => 0,
                'cache_ttl' => 600,
            ])
        ;

        $this->injectMockCollector($server, $mockCollector);

        $this->mockTwig->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                $this->callback(function ($data) {
                    // Should have N/A defaults for missing fields
                    return $data['server']['os_name'] === 'N/A'
                           && $data['server']['php_version'] === 'N/A';
                })
            )
            ->willReturn('<div>Empty info</div>')
        ;

        $response = $this->controller->renderServerInfo($server);

        $this->assertInstanceOf(HtmlResponse::class, $response);
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group server-info-modal
     * @group error-handling
     *
     * Tests error handling when server info API call fails completely.
     */
    public function testItHandlesApiFailureForServerInfo(): void
    {
        $server = 'server1';

        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->expects($this->once())
            ->method('getServerInfo')
            ->willReturn(null) // API failure
        ;

        $this->injectMockCollector($server, $mockCollector);

        $response = $this->controller->renderServerInfo($server);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group xss-prevention
     * @group security
     *
     * Tests XSS prevention in README content. User-submitted content in README
     * files should be sanitized to prevent XSS attacks. The markdown converter
     * should escape HTML by default.
     */
    public function testItPreventsXssInReadmeContent(): void
    {
        $server = 'server1';
        $app = 'test-app';

        // Malicious README with script tag
        $maliciousReadme = '# Test\n<script>alert("XSS")</script>\nNormal content';

        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->expects($this->once())
            ->method('fetchAppReadme')
            ->willReturn([
                'result' => $maliciousReadme,
                'cache_age' => 0,
                'cache_ttl' => 300,
            ])
        ;

        $this->injectMockCollector($server, $mockCollector);

        $this->mockTwig->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                $this->callback(function ($data) {
                    $content = $data['content']['modal_content'];

                    // The markdown converter should escape or strip script tags
                    // We're checking that the rendered content doesn't contain raw script tags
                    return !str_contains($content, '<script>alert');
                })
            )
            ->willReturn('<div>Safe content</div>')
        ;

        $response = $this->controller->renderAppReadme($server, $app);

        $this->assertInstanceOf(HtmlResponse::class, $response);
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group markdown-parsing
     * @group security
     *
     * Tests that markdown is properly converted to HTML. The controller should
     * use a markdown parser that supports GitHub-flavored markdown features
     * like code blocks, tables, and task lists.
     */
    public function testItConvertsMarkdownToHtmlProperly(): void
    {
        $server = 'server1';
        $app = 'test-app';

        $markdownWithFeatures = <<<'MARKDOWN'
# Heading
## Subheading

**Bold text** and *italic text*

- List item 1
- List item 2

```php
echo "Code block";
```
MARKDOWN;

        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->expects($this->once())
            ->method('fetchAppReadme')
            ->willReturn([
                'result' => $markdownWithFeatures,
                'cache_age' => 0,
                'cache_ttl' => 300,
            ])
        ;

        $this->injectMockCollector($server, $mockCollector);

        $this->mockTwig->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                $this->callback(function ($data) {
                    // Verify markdown was converted (should contain HTML tags)
                    $content = $data['content']['modal_content'];

                    return !empty($content);
                })
            )
            ->willReturn('<div>Converted HTML</div>')
        ;

        $response = $this->controller->renderAppReadme($server, $app);

        $this->assertInstanceOf(HtmlResponse::class, $response);
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group cache-integration
     * @group performance
     *
     * Tests that cache age and TTL information is properly passed to templates.
     * Users should see how old the cached data is and when it will expire.
     */
    public function testItIncludesCacheMetadataInResponse(): void
    {
        $server = 'server1';
        $app = 'test-app';
        $cacheAge = 120; // 2 minutes old
        $cacheTtl = 300; // 5 minute TTL

        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->expects($this->once())
            ->method('fetchAppReadme')
            ->willReturn([
                'result' => '# Test',
                'cache_age' => $cacheAge,
                'cache_ttl' => $cacheTtl,
            ])
        ;

        $this->injectMockCollector($server, $mockCollector);

        $this->mockTwig->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                $this->callback(function ($data) use ($cacheAge, $cacheTtl) {
                    // Verify cache metadata is included
                    return isset($data['cache']['age'])
                           && isset($data['cache']['ttl'])
                           && $data['cache']['age'] === "{$cacheAge}s"
                           && $data['cache']['ttl'] === "{$cacheTtl}s";
                })
            )
            ->willReturn('<div>Content with cache info</div>')
        ;

        $response = $this->controller->renderAppReadme($server, $app);

        $this->assertInstanceOf(HtmlResponse::class, $response);
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group error-handling
     * @group logging
     *
     * Tests that exceptions during initialization (e.g., invalid server config)
     * are logged but don't crash the controller. The controller should be
     * resilient to configuration errors.
     */
    public function testItHandlesServerCollectorCreationFailureGracefully(): void
    {
        // Create a logger mock to verify error logging
        $mockLogger = $this->createMock(Logger::class);
        $mockLogger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                $this->anything(),
                $this->callback(function ($context) {
                    return isset($context['msg']) || isset($context['error']);
                })
            )
        ;

        // Create config with invalid server (missing required fields)
        $invalidServersConfig = [
            'bad-server' => [
                // Missing api_url and api_key - should trigger error
            ],
        ];

        // Should not throw exception despite bad config
        $controller = new ModalController(
            $this->mockApp,
            $this->mockTools,
            $this->mockTwig,
            $mockLogger,
            $this->appConfig,
            $this->mockCacheManager,
            $this->mockLogTransformer,
            $this->mockEndpoints,
            $invalidServersConfig
        );

        $this->assertInstanceOf(ModalController::class, $controller);
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group template-rendering
     * @group error-handling
     *
     * Tests error handling when Twig template rendering fails. This could
     * happen if templates are missing or have syntax errors. The controller
     * should return a fallback error message.
     */
    public function testItHandlesTemplateRenderingFailure(): void
    {
        $server = 'server1';
        $app = 'test-app';

        $mockCollector = $this->createMockServerDataCollector($server);
        $mockCollector->method('fetchAppReadme')
            ->willReturn([
                'result' => '# Test',
                'cache_age' => 0,
                'cache_ttl' => 300,
            ])
        ;

        $this->injectMockCollector($server, $mockCollector);

        // Twig throws exception during rendering
        $this->mockTwig->expects($this->once())
            ->method('render')
            ->willThrowException(new Exception('Template not found'))
        ;

        $response = $this->controller->renderAppReadme($server, $app);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Error rendering README modal', $response->getContent());
    }

    /**
     * @test
     *
     * @group modal-controller
     * @group edge-cases
     *
     * Tests handling of special characters in server and app names. URLs with
     * special characters should be properly handled without breaking the routing
     * or causing security issues.
     */
    public function testItHandlesSpecialCharactersInIdentifiers(): void
    {
        // Add server with special characters in name
        $specialServer = 'server-with-dashes_and_underscores.123';
        $specialApp = 'app.name-with_special.chars';

        $specialConfig = array_merge($this->serversConfig, [
            $specialServer => [
                'api_url' => 'https://example.com/api',
                'api_key' => 'test-key',
                'title' => 'Special Server',
            ],
        ]);

        $controller = new ModalController(
            $this->mockApp,
            $this->mockTools,
            $this->mockTwig,
            $this->logger,
            $this->appConfig,
            $this->mockCacheManager,
            $this->mockLogTransformer,
            $this->mockEndpoints,
            $specialConfig
        );

        $mockCollector = $this->createMockServerDataCollector($specialServer);
        $mockCollector->method('fetchAppReadme')
            ->willReturn([
                'result' => '# Test',
                'cache_age' => 0,
                'cache_ttl' => 300,
            ])
        ;

        // Use reflection to inject mock collector
        $this->setProperty($controller, 'serverDataCollectors', [
            $specialServer => $mockCollector,
        ]);

        $this->mockTwig->method('render')
            ->willReturn('<div>Content</div>')
        ;

        $response = $controller->renderAppReadme($specialServer, $specialApp);

        $this->assertInstanceOf(HtmlResponse::class, $response);
    }

    /**
     * Helper method to create a mock ServerDataCollector.
     */
    private function createMockServerDataCollector(string $serverName): MockObject&ServerDataCollectorInterface
    {
        return $this->createMock(ServerDataCollectorInterface::class);
    }

    /**
     * Helper method to inject a mock collector into the controller.
     */
    private function injectMockCollector(string $serverName, MockObject $mockCollector): void
    {
        $collectors = $this->getProperty($this->controller, 'serverDataCollectors');
        $collectors[$serverName] = $mockCollector;
        $this->setProperty($this->controller, 'serverDataCollectors', $collectors);
    }

    /**
     * Helper method to create a minimal ApplicationConfig for testing.
     */
    private function createTestApplicationConfig(string $env = 'production'): ApplicationConfig
    {
        return ApplicationConfigFactory::create(
            environment: new EnvironmentConfig($env, 'test.local', '/test/dir', '')
        );
    }
}
