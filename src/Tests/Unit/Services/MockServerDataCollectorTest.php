<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Cache\CacheTtlResolver;
use Mata\Services\Collect\MockServerDataCollector;
use Mata\Services\DataTransformers\ApiResponseTransformer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Collect\MockServerDataCollector
 *
 * @internal
 */
final class MockServerDataCollectorTest extends TestCase
{
    public function testDemoAppsAlwaysMatchTheAppsResponseContract(): void
    {
        $logger = new NullLogger();
        $transformer = new ApiResponseTransformer($logger);

        foreach (['database-server', 'backup-server'] as $server) {
            $collector = new MockServerDataCollector(
                $logger,
                new CacheTtlResolver([]),
                $transformer,
                $server,
                ['url' => 'https://' . $server . '.example.test'],
            );

            for ($i = 0; $i < 20; ++$i) {
                $response = $collector->getApps();

                $this->assertNull($response['error']);
                $this->assertIsArray($response['result']);
                $this->assertNotEmpty($response['result']);
            }
        }
    }
}
