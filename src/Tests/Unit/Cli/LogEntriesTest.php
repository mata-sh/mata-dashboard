<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Cli;

use Mata\Cli\LogEntries;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Cli\LogEntries
 *
 * @internal
 */
final class LogEntriesTest extends TestCase
{
    /** Evidence for an old event must not show today's errors as if they happened around it. */
    public function testEntriesAfterTheWindowEndAreLeftOut(): void
    {
        $result = [
            'entries' => [
                'error.log' => [
                    ['timestamp' => '2026-10-26 09:00:00', 'type' => 'Error', 'message' => 'today'],
                    ['timestamp' => '2026-10-23 07:30:00', 'type' => 'Error', 'message' => 'around the event'],
                    ['timestamp' => '2026-10-23 05:00:00', 'type' => 'Error', 'message' => 'before the window'],
                    ['type' => 'Error', 'message' => 'no time'],
                ],
            ],
            'readout_limit' => 200,
        ];

        $filtered = LogEntries::filter($result, type: null, file: null, since: '2026-10-23 06:00:00', until: '2026-10-23 08:00:00');

        $this->assertSame(['around the event'], array_column($filtered['entries'], 'message'));
        $this->assertTrue($filtered['files'][0]['covers_since']);
    }

    /** An error that started at the event and still repeats today is the evidence, not noise after the window. */
    public function testGroupedEntryMatchesTheWindowItOverlaps(): void
    {
        $result = [
            'grouped' => true,
            'entries' => [
                'error.log' => [
                    ['timestamp' => '2026-10-26 09:00:00', 'first_seen' => '2026-10-23 07:00:00', 'count' => 42, 'type' => 'Error', 'message' => 'since the event'],
                    ['timestamp' => '2026-10-26 09:00:00', 'first_seen' => '2026-10-25 10:00:00', 'count' => 3, 'type' => 'Error', 'message' => 'after the window'],
                ],
            ],
            'readout_limit' => 200,
        ];

        $filtered = LogEntries::filter($result, type: null, file: null, since: '2026-10-23 06:00:00', until: '2026-10-23 08:00:00');

        $this->assertSame(['since the event'], array_column($filtered['entries'], 'message'));
        $this->assertSame(42, $filtered['entries'][0]['count']);
        $this->assertSame('2026-10-23 07:00:00', $filtered['files'][0]['oldest']);
        $this->assertFalse($filtered['files'][0]['capped']);
    }
}
