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
}
