<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Collect;

use Mata\Services\Collect\NodeResponseNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Collect\NodeResponseNormalizer
 *
 * @internal
 */
final class NodeResponseNormalizerValidationTest extends TestCase
{
    private NodeResponseNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new NodeResponseNormalizer(new NullLogger());
    }

    /**
     * A fully monitored application entry as the node sends it.
     *
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private static function app(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Shop', 'path' => '/var/www/shop', 'url' => 'https://shop.example.test', 'env' => 'prod',
            'status' => ['fatal' => 0, 'warnings' => 0, 'total' => 0, 'fatal_cursor' => null],
            'logs' => ['logs/error.log' => 42],
            'session' => [
                'type' => 'file', 'status' => 'ok', 'count_last_hour' => 1,
                'count_last_day' => 2, 'count_total' => 3, 'path' => '/var/lib/php/sessions', 'size_kb' => 4,
            ],
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $app
     *
     * @return array<string,mixed>
     */
    private function normalizeApp(array $app): array
    {
        $response = $this->normalizer->normalizeApiResponse(['shop' => $app], path: 'apps');

        $this->assertNull($response['error']);

        return $response['result']['shop'];
    }

    public function testAppsPayloadCoercesRecognizedScalars(): void
    {
        $response = $this->normalizer->normalizeApiResponse([
            'shop' => [
                'title' => 'Shop',
                'path' => '/var/www/shop',
                'url' => 'https://shop.example.test',
                'env' => 'prod',
                'composer' => true,
                'status_url' => 'https://shop.example.test/health/',
                'status' => ['fatal' => '1', 'warnings' => 2, 'total' => '3', 'fatal_cursor' => 'v1:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
                'logs' => ['logs/error.log' => '42'],
                'session' => [
                    'type' => 'file',
                    'status' => 'ok',
                    'count_last_hour' => '4',
                    'count_last_day' => null,
                    'count_total' => '12',
                    'path' => null,
                    'size_kb' => null,
                    'error' => null,
                ],
            ],
        ], path: 'apps');

        $this->assertNull($response['error']);
        $this->assertArrayNotHasKey('maintenance', $response['result']['shop']);
        $this->assertSame('https://shop.example.test/health/', $response['result']['shop']['status_url']);
        $this->assertSame([
            'fatal' => 1, 'warnings' => 2, 'total' => 3,
            'fatal_cursor' => 'v1:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ], $response['result']['shop']['status']);
        $this->assertSame(42, $response['result']['shop']['logs']['logs/error.log']);
        $this->assertSame(4, $response['result']['shop']['session']['count_last_hour']);
        $this->assertTrue($response['result']['shop']['composer']);
        $this->assertNull($response['result']['shop']['session']['count_last_day']);
        $this->assertSame('prod', $response['result']['shop']['env']);
    }

    public function testAppsPayloadNormalizesLegacyLiveEnvironment(): void
    {
        $response = $this->normalizer->normalizeApiResponse([
            'shop' => [
                'title' => 'Shop', 'path' => '/var/www/shop', 'url' => 'https://shop.example.test', 'env' => 'live',
                'status' => ['fatal' => 0, 'warnings' => 0, 'total' => 0, 'fatal_cursor' => null],
                'logs' => [],
            ],
        ], path: 'apps');

        $this->assertNull($response['error']);
        $this->assertSame('prod', $response['result']['shop']['env']);
    }

    public function testAppsPayloadSupportsStagingEnvironment(): void
    {
        $response = $this->normalizer->normalizeApiResponse([
            'staging-shop' => [
                'title' => 'Staging Shop',
                'path' => '/var/www/staging-shop',
                'url' => 'https://staging-shop.example.test',
                'env' => 'stg',
                'status' => ['fatal' => 0, 'warnings' => 0, 'total' => 0, 'fatal_cursor' => null],
                'logs' => ['logs/error.log' => 0],
                'session' => [
                    'type' => 'file',
                    'status' => 'ok',
                    'count_last_hour' => 0,
                    'count_last_day' => 0,
                    'count_total' => 0,
                    'path' => '/var/lib/php/sessions',
                    'size_kb' => 0,
                ],
            ],
        ], path: 'apps');

        $this->assertNull($response['error']);
        $this->assertSame('stg', $response['result']['staging-shop']['env']);
        $this->assertFalse($response['result']['staging-shop']['composer']);
    }

    public function testNullStatusUrlIsAccepted(): void
    {
        $response = $this->normalizer->normalizeApiResponse([
            'shop' => [
                'title' => 'Shop', 'path' => '/var/www/shop', 'url' => 'https://shop.example.test', 'env' => 'prod',
                'status_url' => null, 'status' => ['fatal' => 0, 'warnings' => 0, 'total' => 0, 'fatal_cursor' => null],
                'logs' => [],
            ],
        ], path: 'apps');

        $this->assertNull($response['error']);
        $this->assertNull($response['result']['shop']['status_url']);
    }

    public function testEmptyDescriptionIsAccepted(): void
    {
        $response = $this->normalizer->normalizeApiResponse([
            'shop' => [
                'title' => 'Shop', 'path' => '/var/www/shop', 'url' => 'https://shop.example.test', 'env' => 'prod',
                'description' => '', 'status' => ['fatal' => 0, 'warnings' => 0, 'total' => 0, 'fatal_cursor' => null],
                'logs' => [],
            ],
        ], path: 'apps');

        $this->assertNull($response['error']);
        $this->assertNull($response['result']['shop']['description']);
    }

    public function testAppsRequireAConsistentFatalCursor(): void
    {
        $app = self::app();
        $this->assertNull($this->normalizer->normalizeApiResponse(['shop' => $app], path: 'apps')['error']);

        $app['status'] = ['fatal' => 1, 'warnings' => 0, 'total' => 1, 'fatal_cursor' => null];
        $this->assertSame('invalid_payload', $this->normalizer->normalizeApiResponse(['shop' => $app], path: 'apps')['error']['kind']);
        $app['status']['fatal_cursor'] = 'v2:' . str_repeat('a', 64);
        $this->assertSame('invalid_payload', $this->normalizer->normalizeApiResponse(['shop' => $app], path: 'apps')['error']['kind']);
    }

    public function testAbsentMonitoringBlockKeepsEveryCheckEnabled(): void
    {
        $result = $this->normalizeApp(self::app());

        $this->assertArrayNotHasKey('monitoring', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('logs', $result);
        $this->assertArrayHasKey('session', $result);
    }

    public function testSparseMonitoringBlockRoundTripsUntouched(): void
    {
        $result = $this->normalizeApp(self::app(['monitoring' => ['alarms' => false, 'events' => false]]));

        $this->assertSame(['alarms' => false, 'events' => false], $result['monitoring']);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('logs', $result);
        $this->assertArrayHasKey('session', $result);
    }

    public function testDisabledLogAnalysisOmitsStatus(): void
    {
        $app = self::app(['monitoring' => ['loganalysis' => false]]);
        unset($app['status']);

        $result = $this->normalizeApp($app);

        $this->assertArrayNotHasKey('status', $result);
        $this->assertArrayHasKey('logs', $result);
        $this->assertArrayHasKey('session', $result);
    }

    public function testDisabledLogSizeOmitsLogs(): void
    {
        $app = self::app(['monitoring' => ['logsize' => false]]);
        unset($app['logs']);

        $result = $this->normalizeApp($app);

        $this->assertArrayNotHasKey('logs', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('session', $result);
    }

    public function testDisabledSessionsOmitsSession(): void
    {
        $app = self::app(['monitoring' => ['sessions' => false]]);
        unset($app['session']);

        $result = $this->normalizeApp($app);

        $this->assertArrayNotHasKey('session', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('logs', $result);
    }

    public function testDisabledChecksDropKeysAnOlderNodeStillSent(): void
    {
        $result = $this->normalizeApp(self::app([
            'monitoring' => ['loganalysis' => false, 'logsize' => false, 'sessions' => false],
        ]));

        $this->assertArrayNotHasKey('status', $result);
        $this->assertArrayNotHasKey('logs', $result);
        $this->assertArrayNotHasKey('session', $result);
        $this->assertSame('Shop', $result['title']);
    }

    public function testDisabledChecksSkipTheirValidation(): void
    {
        $app = self::app([
            'monitoring' => ['loganalysis' => false, 'logsize' => false, 'sessions' => false],
            'status' => 'nonsense',
            'logs' => ['logs/error.log' => -1],
            'session' => 'nonsense',
        ]);

        $this->assertNull($this->normalizer->normalizeApiResponse(['shop' => $app], path: 'apps')['error']);
    }

    public function testLegacyFlatMonitoringFlagsAreToleratedAsUnknownKeys(): void
    {
        // Deploy order is dashboard first: an old node still sends the removed flat booleans.
        $result = $this->normalizeApp(self::app([
            'error_monitoring_enabled' => false,
            'logsize_monitoring_enabled' => 'nonsense',
            'ping' => 'off',
        ]));

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('logs', $result);
    }

    #[DataProvider('invalidMonitoringBlocks')]
    public function testInvalidMonitoringBlockIsAnExplicitError(mixed $monitoring): void
    {
        $response = $this->normalizer->normalizeApiResponse(
            ['shop' => self::app(['monitoring' => $monitoring])],
            path: 'apps'
        );

        $this->assertNull($response['result']);
        $this->assertSame('invalid_payload', $response['error']['kind']);
        $this->assertSame('apps', $response['error']['endpoint']);
        $this->assertSame('Node returned an invalid monitoring block for shop.', $response['error']['message']);
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalidMonitoringBlocks(): iterable
    {
        yield 'null' => [null];

        yield 'string' => ['off'];

        yield 'bool' => [false];

        yield 'int' => [0];

        yield 'sequential list' => [['loganalysis']];

        yield 'unknown key' => [['logsizes' => false]];

        yield 'stringified bool' => [['loganalysis' => 'false']];

        yield 'int value' => [['loganalysis' => 0]];

        yield 'null value' => [['loganalysis' => null]];

        yield 'array value' => [['loganalysis' => []]];
    }

    public function testInvalidStatusUrlIsAnExplicitError(): void
    {
        $response = $this->normalizer->normalizeApiResponse([
            'shop' => [
                'title' => 'Shop', 'path' => '/var/www/shop', 'url' => 'https://shop.example.test', 'env' => 'prod',
                'status_url' => '/health/', 'status' => ['fatal' => 0, 'warnings' => 0, 'total' => 0, 'fatal_cursor' => null],
                'logs' => [],
            ],
        ], path: 'apps');

        $this->assertNull($response['result']);
        $this->assertSame('invalid_payload', $response['error']['kind']);
    }

    public function testInvalidAppsPayloadIsAnExplicitError(): void
    {
        $response = $this->normalizer->normalizeApiResponse([
            'shop' => ['title' => 'Shop'],
        ], path: 'apps');

        $this->assertNull($response['result']);
        $this->assertSame('invalid_payload', $response['error']['kind']);
        $this->assertSame('apps', $response['error']['endpoint']);
    }

    public function testInvalidPayloadLogsTheResponse(): void
    {
        $payload = ['shop' => ['title' => 'Shop']];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Invalid node response payload',
                $this->callback(static fn (array $context): bool => $context === [
                    'endpoint' => 'apps',
                    'message' => 'Node returned an invalid application payload for shop.',
                    'response' => $payload,
                ]),
            );

        (new NodeResponseNormalizer($logger))->normalizeApiResponse($payload, path: 'apps');
    }

    public function testServerStorageKilobytesArePreserved(): void
    {
        $response = $this->normalizer->normalizeApiResponse([
            'cpu_usage' => 25,
            'ram_capacity' => 16 * 1024 * 1024,
            'ram_used' => 8 * 1024 * 1024,
            'disk_space_capacity' => 500 * 1024 * 1024,
            'disk_space_used' => 250 * 1024 * 1024,
            'total_system_filecount' => 100,
            'processes' => ['total' => 10, 'php' => 2, 'sql' => 1],
        ], path: 'server/data');

        $this->assertSame(16 * 1024 * 1024, $response['result']['ram_capacity']);
        $this->assertSame(8 * 1024 * 1024, $response['result']['ram_used']);
        $this->assertSame(500 * 1024 * 1024, $response['result']['disk_space_capacity']);
        $this->assertSame(250 * 1024 * 1024, $response['result']['disk_space_used']);
    }

    public function testInvalidServerMetricIsAnExplicitError(): void
    {
        $response = $this->normalizer->normalizeApiResponse([
            'cpu_usage' => 'not-a-number',
        ], path: 'server/data');

        $this->assertNull($response['result']);
        $this->assertSame('invalid_payload', $response['error']['kind']);
        $this->assertSame('server/data', $response['error']['endpoint']);
    }

    public function testInvalidDatabaseAndLibrariesPayloadsAreExplicitErrors(): void
    {
        // A missing required count must not render as 0 connections.
        $databaseResponse = $this->normalizer->normalizeApiResponse([
            'engine' => 'mariadb',
            'collected_at' => '2026-10-10T12:00:00+00:00',
            'status' => ['max_connections' => 151, 'connections' => null, 'running' => 2, 'uptime_seconds' => 60],
            'databases' => [],
        ], path: 'databases/overview');
        $librariesResponse = $this->normalizer->normalizeApiResponse([
            'shop' => [['name' => 'vendor/package']],
        ], path: 'apps/libraries');

        $this->assertNull($databaseResponse['result']);
        $this->assertSame('databases/overview', $databaseResponse['error']['endpoint']);
        $this->assertNull($librariesResponse['result']);
        $this->assertSame('libraries', $librariesResponse['error']['endpoint']);
    }

    public function testNodeErrorPayloadIsNotTreatedAsEndpointData(): void
    {
        $response = $this->normalizer->normalizeApiResponse([
            'error' => true,
            'message' => 'Application not found.',
        ], path: 'missing-app/sessions');

        $this->assertNull($response['result']);
        $this->assertSame([
            'kind' => 'node_response',
            'message' => 'Application not found.',
            'endpoint' => 'missing-app/sessions',
            'status_code' => null,
        ], $response['error']);
    }

    public function testNodeErrorBodyRequiresTheExpectedShape(): void
    {
        $this->assertSame([
            'kind' => 'node_response',
            'message' => 'Unknown app.',
            'endpoint' => 'apps/foo',
            'status_code' => 404,
        ], $this->normalizer->normalizeNodeErrorResponse(
            '{"error":true,"message":"Unknown app."}',
            'apps/foo',
            404,
        ));
        $this->assertSame([
            'kind' => 'node_response',
            'message' => 'Monitoring disabled',
            'endpoint' => 'apps/foo/logs',
            'status_code' => 409,
        ], $this->normalizer->normalizeNodeErrorResponse(
            '{"error":"Monitoring disabled","message":"Monitoring disabled"}',
            'apps/foo/logs',
            409,
        ));
        $this->assertNull($this->normalizer->normalizeNodeErrorResponse('<h1>Not Found</h1>', 'apps/foo', 404));
    }

    #[DataProvider('nodeErrorBodies')]
    public function testRealNodeErrorBodiesBecomeUserSafeMessages(string $body, int $status, string $message): void
    {
        $error = $this->normalizer->normalizeNodeErrorResponse($body, 'endpoint', $status);

        $this->assertSame($message, $error['message'] ?? null);
        $this->assertSame($status, $error['status_code'] ?? null);
    }

    /**
     * @return iterable<string,array{string,int,string}>
     */
    public static function nodeErrorBodies(): iterable
    {
        yield 'watcher error' => ['{"error":"Unknown app","message":"Unknown app","details":{"app":"gone"}}', 404, 'Unknown app'];

        yield 'forbidden without message' => ['{"error":"Forbidden"}', 403, 'Node rejected this dashboard (IP not allowed)'];

        yield 'rate limit without message' => ['{"error":"Rate limit exceeded","limit":60,"reset_at":1760000000}', 429, 'Node rate limit exceeded, try again later'];

        yield 'maintenance' => ['{"error":"Service unavailable","message":"Maintenance mode enabled"}', 503, 'Node is in maintenance mode'];

        yield 'auth reason' => ['{"error":"Unauthorized","message":"Invalid HMAC signature"}', 401, 'Invalid HMAC signature'];

        yield 'database host hidden' => [
            '{"error":"Database connection failed","message":"Failed to connect to the database server on db.internal:3306: Access denied for user mata@10.0.0.5","details":{"host":"db.internal","port":"3306"}}',
            503,
            'Database connection failed',
        ];

        yield 'command hidden' => ['{"error":"Server command failed","message":"Failed to execute command: crontab -l -u www","details":null}', 500, 'Server command failed'];
    }
}
