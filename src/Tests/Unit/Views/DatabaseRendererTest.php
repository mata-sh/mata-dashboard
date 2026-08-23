<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Views;

use Mata\Core\App;
use Mata\Http\Routing\Endpoints;
use Mata\Services\Collect\NodeResponseNormalizer;
use Mata\Utils\Tools;
use Mata\Views\DatabaseRenderer;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Twig\Environment;

/**
 * @covers \Mata\Views\DatabaseRenderer
 *
 * @internal
 */
final class DatabaseRendererTest extends TestCase
{
    public function testBuildsFullHealthyEntry(): void
    {
        $entries = $this->buildEntries([
            'databases' => [
                'main' => $this->databaseEntry([
                    'database' => 'main',
                    'configured_database' => 'main_replica',
                    'host' => 'db.example.test',
                    'port' => 3306,
                    'description' => 'Primary database',
                    'max_connections' => 1234,
                    'active_threads' => 12,
                    'active_processes' => 34,
                    'process_list' => [[], []],
                ]),
            ],
        ]);

        $this->assertSame([[
            'name' => 'main',
            'configured_database' => 'main_replica',
            'endpoint' => 'db.example.test:3306',
            'description' => 'Primary database',
            'max_connections' => '1.234',
            'active_threads' => '12',
            'active_processes' => '34',
            'process_count' => 2,
            'process_modal_url' => null,
            'status' => 'ok',
            'status_tooltip' => 'Connected',
        ]], $entries);
    }

    public function testUsesDefaultPrimaryColorWhenServerConfigOmitsIt(): void
    {
        $serverConfig = $this->serverConfig();
        unset($serverConfig['primary_color']);

        $data = $this->createRenderer()->buildDatabaseCardData([], $serverConfig, 'server-id');

        $this->assertSame('#fefefe', $data['server']['primary_color']);
    }

    public function testAddsProcessListModalUrl(): void
    {
        $endpoints = new Endpoints();
        $endpoints->register(
            'modal_db_processes_render',
            '/watch/modal/{server}/database/{database}/processes'
        );
        $data = $this->createRenderer($endpoints)->buildDatabaseCardData(
            ['databases' => ['main db' => $this->databaseEntry()]],
            $this->serverConfig(),
            'server-id',
            server: 'server-01'
        );

        $this->assertSame(
            '/watch/modal/server-01/database/main%20db/processes',
            $data['databases'][0]['process_modal_url']
        );
    }

    public function testFallsBackToReportedDatabaseName(): void
    {
        $entries = $this->buildEntries([
            'databases' => ['' => $this->databaseEntry(['database' => 'reported_database'])],
        ]);

        $this->assertSame('reported_database', $entries[0]['name']);
        $this->assertNull($entries[0]['configured_database']);
    }

    public function testEscapesConnectionErrorTooltipAndMarksEntryAsError(): void
    {
        $entries = $this->buildEntries([
            'databases' => [
                'main' => $this->databaseEntry([
                    'connection_error' => '<script>alert(1)</script>',
                ]),
            ],
        ]);

        $this->assertSame('error', $entries[0]['status']);
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $entries[0]['status_tooltip']);
    }

    public function testTruncatesConnectionErrorTooltip(): void
    {
        $entries = $this->buildEntries([
            'databases' => [
                'main' => $this->databaseEntry(['connection_error' => str_repeat('x', 200)]),
            ],
        ]);

        $this->assertSame(str_repeat('x', 159) . '…', $entries[0]['status_tooltip']);
    }

    public function testEscapesDescription(): void
    {
        $entries = $this->buildEntries([
            'databases' => [
                'main' => $this->databaseEntry([
                    'description' => '<img src=x onerror=alert(1)>',
                ]),
            ],
        ]);

        $this->assertSame('&lt;img src=x onerror=alert(1)&gt;', $entries[0]['description']);
    }

    /**
     * @param array<string,mixed> $databaseData
     */
    #[DataProvider('databaseAvailability')]
    public function testReportsDatabaseAvailability(array $databaseData, bool $available): void
    {
        $data = $this->createRenderer()->buildDatabaseCardData($databaseData, $this->serverConfig(), 'server-id');

        $this->assertSame([], $data['databases']);
        $this->assertSame($available, $data['databases_available']);
    }

    /**
     * @return iterable<string,array{array<string,mixed>,bool}>
     */
    public static function databaseAvailability(): iterable
    {
        yield 'missing databases key' => [[], false];

        yield 'null databases key' => [['databases' => null], false];

        yield 'empty databases map' => [['databases' => []], true];
    }

    public function testTreatsNormalizedNullDatabasesAsNotReported(): void
    {
        $normalized = (new NodeResponseNormalizer(new NullLogger()))->normalizeDatabaseData([
            'processes' => ['sql' => 0],
            'databases' => null,
        ]);

        $data = $this->createRenderer()->buildDatabaseCardData($normalized, $this->serverConfig(), 'server-id');

        $this->assertFalse($data['databases_available']);
        $this->assertSame([], $data['databases']);
    }

    public function testKeepsSqlProcesses(): void
    {
        $data = $this->createRenderer()->buildDatabaseCardData([
            'processes' => ['sql' => 7],
        ], $this->serverConfig(), 'server-id');

        $this->assertSame(7, $data['system']['processes_sql']);
    }

    public function testAcceptsTransformerNormalizedDatabaseContract(): void
    {
        $normalized = (new NodeResponseNormalizer(new NullLogger()))->normalizeDatabaseData([
            'processes' => ['sql' => '7'],
            'databases' => [
                'main' => ['database' => 'main', 'active_threads' => '1.6'],
            ],
        ]);

        $data = $this->createRenderer()->buildDatabaseCardData($normalized, $this->serverConfig(), 'server-id');

        $this->assertSame(7, $data['system']['processes_sql']);
        $this->assertSame('2', $data['databases'][0]['active_threads']);
        $this->assertSame('ok', $data['databases'][0]['status']);
    }

    /**
     * @param array<string,mixed> $databaseData
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildEntries(array $databaseData): array
    {
        $method = new ReflectionMethod(DatabaseRenderer::class, 'buildDatabaseEntries');

        return $method->invoke($this->createRenderer(), $databaseData);
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function databaseEntry(array $overrides = []): array
    {
        return array_merge([
            'database' => null,
            'configured_database' => null,
            'host' => null,
            'port' => null,
            'description' => null,
            'max_connections' => null,
            'active_threads' => null,
            'active_processes' => null,
            'process_list' => [],
            'connection_error' => '',
        ], $overrides);
    }

    /**
     * @return array<string,string>
     */
    private function serverConfig(): array
    {
        return [
            'title' => 'Test server',
            'primary_color' => '#000000',
        ];
    }

    private function createRenderer(?Endpoints $endpoints = null): DatabaseRenderer
    {
        return new DatabaseRenderer(
            $this->createStub(Environment::class),
            $this->createStub(Logger::class),
            $this->createStub(Tools::class),
            $this->createStub(App::class),
            $endpoints ?? $this->createStub(Endpoints::class),
        );
    }
}
