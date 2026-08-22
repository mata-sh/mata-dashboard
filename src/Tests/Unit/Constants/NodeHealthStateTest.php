<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Constants;

use Mata\Constants\NodeHealthState;
use Mata\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @coversNothing
 */
final class NodeHealthStateTest extends TestCase
{
    #[DataProvider('stateLabels')]
    public function testLabelMatchesState(NodeHealthState $state, string $expected): void
    {
        $this->assertSame($expected, $state->label());
    }

    /**
     * @return iterable<string,array{NodeHealthState,string}>
     */
    public static function stateLabels(): iterable
    {
        yield 'healthy' => [NodeHealthState::Healthy, 'Healthy'];

        yield 'unhealthy' => [NodeHealthState::Unhealthy, 'Unhealthy'];

        yield 'unreachable' => [NodeHealthState::Unreachable, 'Unreachable'];

        yield 'maintenance' => [NodeHealthState::Maintenance, 'Maintenance'];

        yield 'unknown' => [NodeHealthState::Unknown, 'Health check failed'];
    }

    public function testFromNodePayloadMapsKnownStatus(): void
    {
        $this->assertSame(NodeHealthState::Healthy, NodeHealthState::fromNodePayload('healthy'));
        $this->assertSame(NodeHealthState::Unhealthy, NodeHealthState::fromNodePayload('UNHEALTHY'));
    }

    public function testFromNodePayloadDegradesUnrecognisedInputToUnknown(): void
    {
        $this->assertSame(NodeHealthState::Unknown, NodeHealthState::fromNodePayload('helth'));
        $this->assertSame(NodeHealthState::Unknown, NodeHealthState::fromNodePayload(null));
        $this->assertSame(NodeHealthState::Unknown, NodeHealthState::fromNodePayload(''));
    }

    /**
     * Backing values are rendered into node-health-* CSS classes by the server card,
     * so a rename here silently drops the health chip's colour.
     */
    public function testBackingValuesAreStable(): void
    {
        $this->assertSame(
            ['healthy', 'unhealthy', 'unreachable', 'maintenance', 'unknown'],
            array_column(NodeHealthState::cases(), 'value'),
        );
    }
}
