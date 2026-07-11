<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Alarm\Dto\AlarmLevel;
use Mata\Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class AlarmLevelTest extends TestCase
{
    public function testErrorLevelHasExpectedValueAndEmoji(): void
    {
        $this->assertSame(AlarmLevel::Error, AlarmLevel::fromString('error'));
        $this->assertSame('🟠', AlarmLevel::Error->getEmoji());
    }
}
