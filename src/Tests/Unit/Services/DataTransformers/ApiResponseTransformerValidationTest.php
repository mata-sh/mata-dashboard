<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\DataTransformers;

use Mata\Services\DataTransformers\ApiResponseTransformer;
use PHPUnit\Framework\TestCase;
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
                'env' => 'live',
                'maintenance' => 'false',
                'status' => ['fatal' => '1', 'warnings' => 2, 'total' => '3'],
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
        $this->assertSame(false, $response['result']['shop']['maintenance']);
        $this->assertSame(['fatal' => 1, 'warnings' => 2, 'total' => 3], $response['result']['shop']['status']);
        $this->assertSame(42, $response['result']['shop']['logs']['logs/error.log']);
        $this->assertSame(4, $response['result']['shop']['session']['count_last_hour']);
        $this->assertNull($response['result']['shop']['session']['count_last_day']);
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

    public function testInvalidServerMetricIsAnExplicitError(): void
    {
        $response = $this->transformer->normalizeApiResponse([
            'cpu_usage' => 'not-a-number',
        ], path: 'server-data');

        $this->assertNull($response['result']);
        $this->assertSame('invalid_payload', $response['error']['kind']);
        $this->assertSame('server-data', $response['error']['endpoint']);
    }

    public function testInvalidDatabaseAndLibrariesPayloadsAreExplicitErrors(): void
    {
        $databaseResponse = $this->transformer->normalizeApiResponse([], path: 'server-databases');
        $librariesResponse = $this->transformer->normalizeApiResponse([
            'shop' => [['name' => 'vendor/package']],
        ], path: 'apps/libraries');

        $this->assertNull($databaseResponse['result']);
        $this->assertSame('server-databases', $databaseResponse['error']['endpoint']);
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
