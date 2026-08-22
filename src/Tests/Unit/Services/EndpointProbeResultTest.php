<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Monitor\Dto\EndpointProbeResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Monitor\Dto\EndpointProbeResult
 *
 * @internal
 */
final class EndpointProbeResultTest extends TestCase
{
    public function testBuildsCanonicalResultAndPreservesArrayShape(): void
    {
        $data = [
            'reachable' => false,
            'httpCode' => 503,
            'latency_ms' => 12,
            'error' => 'HTTP 503',
            'failure_kind' => 'http',
            'curl_errno' => null,
            'timeout_ms' => 5000,
            'attempts' => 2,
            'url' => 'https://app.example.test/status.php',
            'maintenance_mode' => true,
            'checked_at' => 123,
        ];

        $result = EndpointProbeResult::fromArray($data);

        $this->assertFalse($result->reachable);
        $this->assertSame(503, $result->httpCode);
        $this->assertSame(12, $result->latencyMs);
        $this->assertTrue($result->maintenanceMode);
        $this->assertSame($data, $result->toArray());
    }

    public function testInvalidFieldsUseSafeDefaults(): void
    {
        $before = time();
        $result = EndpointProbeResult::fromArray([
            'reachable' => 1,
            'httpCode' => '200',
            'latency_ms' => '12',
            'error' => [],
            'failure_kind' => false,
            'curl_errno' => '28',
            'timeout_ms' => '5000',
            'attempts' => '2',
            'url' => 42,
            'maintenance_mode' => 'true',
            'checked_at' => '123',
        ]);

        $this->assertFalse($result->reachable);
        $this->assertNull($result->httpCode);
        $this->assertNull($result->latencyMs);
        $this->assertNull($result->error);
        $this->assertNull($result->failureKind);
        $this->assertNull($result->curlErrno);
        $this->assertSame(0, $result->timeoutMs);
        $this->assertSame(1, $result->attempts);
        $this->assertSame('', $result->url);
        $this->assertFalse($result->maintenanceMode);
        $this->assertGreaterThanOrEqual($before, $result->checkedAt);
    }

    public function testWithAttemptsReturnsUpdatedCopy(): void
    {
        $result = EndpointProbeResult::fromArray(['attempts' => 1, 'checked_at' => 123]);
        $updated = $result->withAttempts(3);

        $this->assertNotSame($result, $updated);
        $this->assertSame(1, $result->attempts);
        $this->assertSame(3, $updated->attempts);
        $this->assertSame(123, $updated->checkedAt);
    }
}
