<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Constants\NodeHealthState;
use Mata\Services\Monitor\Dto\NodeHealthResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Monitor\Dto\NodeHealthResult
 *
 * @internal
 */
final class NodeHealthResultTest extends TestCase
{
    public function testBuildsCanonicalResultFromArray(): void
    {
        $result = NodeHealthResult::fromArray([
            'status' => 'healthy',
            'httpCode' => 200,
            'maintenance_mode' => true,
            'checks' => ['database' => true, 0 => 'ignored'],
            'latency_ms' => 12,
            'attempts' => 2,
            'checked_at' => 123,
        ]);

        $this->assertSame(NodeHealthState::Healthy, $result->status);
        $this->assertSame(NodeHealthState::Maintenance, $result->state);
        $this->assertSame(['database' => true], $result->checks);
        $this->assertSame(2, $result->attempts);
        $this->assertSame('maintenance', $result->toArray()['state']);
    }

    public function testInvalidFieldsUseSafeDefaults(): void
    {
        $before = time();
        $result = NodeHealthResult::fromArray([
            'status' => 'invalid',
            'httpCode' => '200',
            'checks' => 'invalid',
            'attempts' => '2',
        ]);

        $this->assertSame(NodeHealthState::Unreachable, $result->status);
        $this->assertSame(NodeHealthState::Unreachable, $result->state);
        $this->assertNull($result->httpCode);
        $this->assertNull($result->checks);
        $this->assertSame(1, $result->attempts);
        $this->assertGreaterThanOrEqual($before, $result->checkedAt);
    }

    public function testWithAttemptsReturnsUpdatedCopy(): void
    {
        $result = NodeHealthResult::fromArray(['status' => 'unreachable']);
        $updated = $result->withAttempts(3);

        $this->assertNotSame($result, $updated);
        $this->assertSame(1, $result->attempts);
        $this->assertSame(3, $updated->attempts);
    }
}
