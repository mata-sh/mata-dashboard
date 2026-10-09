<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Views;

use Mata\Core\App;
use Mata\Http\Routing\Endpoints;
use Mata\Services\Collect\NodeResponseNormalizer;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Utils\Tools;
use Mata\Views\DatabaseRenderer;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @covers \Mata\Views\DatabaseRenderer
 *
 * @internal
 */
final class DatabaseRendererTest extends TestCase
{
    public function testShowsServerStatusOnceForSeveralDatabasesAndOmitsUnreportedCounters(): void
    {
        $overview = (new NodeResponseNormalizer(new NullLogger()))->normalizeApiResponse([
            'engine' => 'pgsql',
            'collected_at' => '2026-10-10T12:00:00+00:00',
            'status' => [
                'max_connections' => 151,
                'connections' => 12,
                'running' => 2,
                'max_used_connections' => null,
                'refused_connections' => null,
                'aborted_connects' => null,
                'uptime_seconds' => 864000,
            ],
            'databases' => [
                ['name' => 'shop', 'description' => 'Shop'],
                ['name' => 'analytics', 'description' => 'Reports'],
                ['name' => 'crm', 'description' => 'CRM'],
            ],
        ], path: 'databases/overview');

        $html = $this->render($overview);

        $this->assertSame(1, substr_count($html, '/ 151'));
        $this->assertStringContainsString('analytics', $html);
        $this->assertStringNotContainsString('Refused', $html);
        $this->assertStringNotContainsString('server-fetch-error', $html);
    }

    public function testTitleTooltipShowsOverviewCacheAge(): void
    {
        $html = $this->render(['result' => null, 'error' => null, 'cache_age' => 42, 'cache_ttl' => 300]);

        $this->assertStringContainsString('data-tippy-content="Server Updated: 42 ago (TTL: 300)"', $html);
    }

    public function testOmitsTitleTooltipWithoutOverview(): void
    {
        $html = $this->render(['result' => null, 'error' => null]);

        $this->assertStringNotContainsString('Server Updated', $html);
    }

    public function testRefreshButtonReplacesItsOwnCard(): void
    {
        $html = $this->render(['result' => null, 'error' => null]);

        $this->assertStringContainsString('id="database-db1-server"', $html);
        $this->assertStringContainsString('hx-target="#database-db1-server" hx-get="watch/refresh/database/db1"', $html);
    }

    #[DataProvider('nodeErrors')]
    public function testDistinguishesDisabledMonitoringFromDatabaseOutage(int $status, string $expected, bool $isError): void
    {
        $html = $this->render([
            'result' => null,
            'error' => ['kind' => 'node_response', 'message' => 'Node message', 'endpoint' => 'databases/overview', 'status_code' => $status],
        ]);

        $this->assertStringContainsString($expected, $html);
        $this->assertSame($isError, str_contains($html, 'server-fetch-error'));
    }

    /**
     * @return iterable<string,array{int,string,bool}>
     */
    public static function nodeErrors(): iterable
    {
        yield 'monitoring not configured' => [404, 'not configured on this node', false];

        yield 'database server down' => [503, 'Node message', true];
    }

    /**
     * @param array<string,mixed> $overviewResponse
     */
    private function render(array $overviewResponse): string
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getDatabaseOverview')->willReturn($overviewResponse);
        $collector->method('getServerSystemData')->willReturn(['result' => ['processes' => ['sql' => 3]]]);

        $tools = $this->createStub(Tools::class);
        $tools->method('formatDuration')->willReturnCallback(static fn (int $seconds): string => (string) $seconds);

        $endpoints = new Endpoints();
        $endpoints->register('refresh_database', 'watch/refresh/database/{server}');

        $renderer = new DatabaseRenderer(
            new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/templates')),
            $this->createStub(Logger::class),
            $tools,
            $this->createStub(App::class),
            $endpoints,
        );

        return $renderer->renderDatabaseCards(['db1' => $collector], ['db1' => ['title' => 'DB server']]);
    }
}
