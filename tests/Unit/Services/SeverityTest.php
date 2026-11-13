<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Audit\Severity;
use Mata\Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class SeverityTest extends TestCase
{
    /**
     * Case declaration order is the rank order used by AuditAggregator::worstSeverity(),
     * which returns the first severity with a non-zero count. Reordering silently changes
     * which severity a package reports as its worst.
     */
    public function testValuesAreInDescendingRankOrder(): void
    {
        $this->assertSame(
            ['critical', 'high', 'medium', 'low', 'unknown'],
            Severity::values(),
        );
    }

    public function testTryFromRejectsUnknownSeverityStrings(): void
    {
        $this->assertNull(Severity::tryFrom('catastrophic'));
        $this->assertNull(Severity::tryFrom('Critical'));
        $this->assertSame(Severity::Critical, Severity::tryFrom('critical'));
    }
}
