<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Views;

use Mata\Core\App;
use Mata\Http\Routing\Endpoints;
use Mata\Utils\Tools;
use Mata\Views\DatabaseRenderer;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
                'main' => [
                    'database' => 'main',
                    'configured_database' => 'main_replica',
                    'host' => 'db.example.test',
                    'port' => 3306,
                    'description' => 'Primary database',
                    'max_connections' => 1234,
                    'active_threads' => 12,
                    'active_processes' => 34,
                    'process_list' => [[], []],
                    'connection_error' => '',
                ],
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
            'status' => 'ok',
            'status_tooltip' => 'Connected',
        ]], $entries);
    }

    public function testEscapesConnectionErrorTooltip(): void
    {
        $entries = $this->buildEntries([
            'databases' => [
                'main' => [
                    'connection_error' => '<script>alert(1)</script>',
                ],
            ],
        ]);

        $this->assertSame('error', $entries[0]['status']);
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $entries[0]['status_tooltip']);
    }

    public function testEscapesDescription(): void
    {
        $entries = $this->buildEntries([
            'databases' => [
                'main' => [
                    'description' => '<img src=x onerror=alert(1)>',
                ],
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

        yield 'empty databases map' => [['databases' => []], true];
    }

    public function testDefaultsMissingAndNonNumericFields(): void
    {
        $entries = $this->buildEntries([
            'databases' => [
                'main' => [
                    'host' => 'db.example.test',
                    'max_connections' => 'not a number',
                    'active_threads' => [],
                    'active_processes' => null,
                    'process_list' => 'not an array',
                ],
            ],
        ]);

        $this->assertSame('db.example.test', $entries[0]['endpoint']);
        $this->assertNull($entries[0]['max_connections']);
        $this->assertNull($entries[0]['active_threads']);
        $this->assertNull($entries[0]['active_processes']);
        $this->assertSame(0, $entries[0]['process_count']);
    }

    public function testSkipsNonArrayEntries(): void
    {
        $entries = $this->buildEntries([
            'databases' => [
                'invalid' => 'invalid',
                'valid' => [],
            ],
        ]);

        $this->assertCount(1, $entries);
        $this->assertSame('valid', $entries[0]['name']);
    }

    public function testKeepsSqlProcesses(): void
    {
        $data = $this->createRenderer()->buildDatabaseCardData([
            'processes' => ['sql' => 7],
        ], $this->serverConfig(), 'server-id');

        $this->assertSame(7, $data['system']['processes_sql']);
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
     * @return array<string,string>
     */
    private function serverConfig(): array
    {
        return [
            'title' => 'Test server',
            'primary_color' => '#000000',
        ];
    }

    private function createRenderer(): DatabaseRenderer
    {
        return new DatabaseRenderer(
            $this->createStub(Environment::class),
            $this->createStub(Logger::class),
            $this->createStub(Tools::class),
            $this->createStub(App::class),
            $this->createStub(Endpoints::class),
        );
    }
}
