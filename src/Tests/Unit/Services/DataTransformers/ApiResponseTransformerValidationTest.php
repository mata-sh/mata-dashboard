<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\DataTransformers;

use Mata\Services\DataTransformers\ApiResponseTransformer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\DataTransformers\ApiResponseTransformer
 *
 * @internal
 */
final class ApiResponseTransformerValidationTest extends TestCase
{
    private ApiResponseTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new ApiResponseTransformer(new NullLogger());
    }

    public function testAppsPayloadCoercesRecognizedScalars(): void
    {
        $response = $this->transformer->normalizeApiResponse([
            'shop' => [
                'title' => 'Shop',
                'path' => '/var/www/shop',
                'url' => 'https://shop.example.test',
                'env' => 'prod',
                'composer' => true,
                'status_url' => 'https://shop.example.test/health/',
                'status' => ['fatal' => '1', 'warnings' => 2, 'total' => '3', 'fatal_cursor' => 'v1:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
                'error_monitoring_enabled' => true, 'logsize_monitoring_enabled' => true,
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
        $response = $this->transformer->normalizeApiResponse([
            'shop' => [
                'title' => 'Shop', 'path' => '/var/www/shop', 'url' => 'https://shop.example.test', 'env' => 'live',
                'status' => ['fatal' => 0, 'warnings' => 0, 'total' => 0, 'fatal_cursor' => null],
                'error_monitoring_enabled' => true, 'logsize_monitoring_enabled' => true, 'logs' => [],
            ],
        ], path: 'apps');

        $this->assertNull($response['error']);
        $this->assertSame('prod', $response['result']['shop']['env']);
    }

    public function testAppsPayloadSupportsStagingEnvironment(): void
    {
        $response = $this->transformer->normalizeApiResponse([
            'staging-shop' => [
                'title' => 'Staging Shop',
                'path' => '/var/www/staging-shop',
                'url' => 'https://staging-shop.example.test',
                'env' => 'stg',
                'status' => ['fatal' => 0, 'warnings' => 0, 'total' => 0, 'fatal_cursor' => null],
                'error_monitoring_enabled' => true, 'logsize_monitoring_enabled' => true,
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
        $response = $this->transformer->normalizeApiResponse([
            'shop' => [
                'title' => 'Shop', 'path' => '/var/www/shop', 'url' => 'https://shop.example.test', 'env' => 'prod',
                'status_url' => null, 'status' => ['fatal' => 0, 'warnings' => 0, 'total' => 0, 'fatal_cursor' => null],
                'error_monitoring_enabled' => true, 'logsize_monitoring_enabled' => true, 'logs' => [],
            ],
        ], path: 'apps');

        $this->assertNull($response['error']);
        $this->assertNull($response['result']['shop']['status_url']);
    }

    public function testEmptyDescriptionIsAccepted(): void
    {
        $response = $this->transformer->normalizeApiResponse([
            'shop' => [
                'title' => 'Shop', 'path' => '/var/www/shop', 'url' => 'https://shop.example.test', 'env' => 'prod',
                'description' => '', 'status' => ['fatal' => 0, 'warnings' => 0, 'total' => 0, 'fatal_cursor' => null],
                'error_monitoring_enabled' => true, 'logsize_monitoring_enabled' => true, 'logs' => [],
            ],
        ], path: 'apps');

        $this->assertNull($response['error']);
        $this->assertNull($response['result']['shop']['description']);
    }

    public function testAppsRequireStrictMonitoringBooleansAndConsistentFatalCursor(): void
    {
        $app = [
            'title' => 'Shop', 'path' => '/var/www/shop', 'url' => 'https://shop.example.test', 'env' => 'prod',
            'status' => ['fatal' => 0, 'warnings' => 0, 'total' => 0, 'fatal_cursor' => null],
            'error_monitoring_enabled' => true, 'logsize_monitoring_enabled' => true, 'logs' => [],
        ];
        $this->assertNull($this->transformer->normalizeApiResponse(['shop' => $app], path: 'apps')['error']);

        $app['error_monitoring_enabled'] = 'true';
        $this->assertSame('invalid_payload', $this->transformer->normalizeApiResponse(['shop' => $app], path: 'apps')['error']['kind']);
        $app['error_monitoring_enabled'] = true;
        unset($app['logsize_monitoring_enabled']);
        $this->assertSame('invalid_payload', $this->transformer->normalizeApiResponse(['shop' => $app], path: 'apps')['error']['kind']);
        $app['logsize_monitoring_enabled'] = true;
        $app['status'] = ['fatal' => 1, 'warnings' => 0, 'total' => 1, 'fatal_cursor' => null];
        $this->assertSame('invalid_payload', $this->transformer->normalizeApiResponse(['shop' => $app], path: 'apps')['error']['kind']);
        $app['status']['fatal_cursor'] = 'v2:' . str_repeat('a', 64);
        $this->assertSame('invalid_payload', $this->transformer->normalizeApiResponse(['shop' => $app], path: 'apps')['error']['kind']);
    }

    public function testInvalidStatusUrlIsAnExplicitError(): void
    {
        $response = $this->transformer->normalizeApiResponse([
            'shop' => [
                'title' => 'Shop', 'path' => '/var/www/shop', 'url' => 'https://shop.example.test', 'env' => 'prod',
                'status_url' => '/health/', 'status' => ['fatal' => 0, 'warnings' => 0, 'total' => 0, 'fatal_cursor' => null],
                'error_monitoring_enabled' => true, 'logsize_monitoring_enabled' => true, 'logs' => [],
            ],
        ], path: 'apps');

        $this->assertNull($response['result']);
        $this->assertSame('invalid_payload', $response['error']['kind']);
    }

    public function testInvalidAppsPayloadIsAnExplicitError(): void
    {
        $response = $this->transformer->normalizeApiResponse([
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

        (new ApiResponseTransformer($logger))->normalizeApiResponse($payload, path: 'apps');
    }

    public function testServerStorageKilobytesArePreserved(): void
    {
        $response = $this->transformer->normalizeApiResponse([
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
        $response = $this->transformer->normalizeApiResponse([
            'cpu_usage' => 'not-a-number',
        ], path: 'server/data');

        $this->assertNull($response['result']);
        $this->assertSame('invalid_payload', $response['error']['kind']);
        $this->assertSame('server/data', $response['error']['endpoint']);
    }

    public function testInvalidDatabaseAndLibrariesPayloadsAreExplicitErrors(): void
    {
        $databaseResponse = $this->transformer->normalizeApiResponse([], path: 'server/databases');
        $librariesResponse = $this->transformer->normalizeApiResponse([
            'shop' => [['name' => 'vendor/package']],
        ], path: 'apps/libraries');

        $this->assertNull($databaseResponse['result']);
        $this->assertSame('server/databases', $databaseResponse['error']['endpoint']);
        $this->assertNull($librariesResponse['result']);
        $this->assertSame('libraries', $librariesResponse['error']['endpoint']);
    }

    public function testNodeErrorPayloadIsNotTreatedAsEndpointData(): void
    {
        $response = $this->transformer->normalizeApiResponse([
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
        ], $this->transformer->normalizeNodeErrorResponse(
            '{"error":true,"message":"Unknown app."}',
            'apps/foo',
            404,
        ));
        $this->assertNull($this->transformer->normalizeNodeErrorResponse('<h1>Not Found</h1>', 'apps/foo', 404));
    }
}
