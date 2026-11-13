<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Audit\AuditAggregator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Audit\AuditAggregator
 *
 * @internal
 */
final class AuditAggregatorTest extends TestCase
{
    public function testSummarizesDistinctAbandonedPackagesFromSuccessfulAuditsOnly(): void
    {
        $summary = (new AuditAggregator())->summarize([
            'server-a' => [
                'one' => ['result' => [
                    'status' => 'ok',
                    'counts' => [],
                    'abandoned' => [
                        'vendor/one' => 'replacement/package',
                        'vendor/shared' => null,
                        '' => 'ignored/package',
                        123 => null,
                    ],
                ]],
                'two' => ['result' => [
                    'status' => 'ok',
                    'counts' => [],
                    'abandoned' => ['vendor/shared' => false, 'vendor/two' => []],
                ]],
                'failed' => ['result' => [
                    'status' => 'error',
                    'counts' => [],
                    'abandoned' => ['vendor/ignored' => null],
                ]],
                'missing' => null,
            ],
            'server-b' => [
                'three' => ['result' => [
                    'status' => 'ok',
                    'counts' => [],
                    'abandoned' => ['vendor/one' => null, 'vendor/three' => 'replacement/package'],
                ]],
            ],
        ]);

        $this->assertSame(4, $summary['abandoned_packages']);
        $this->assertSame(2, $summary['servers']['server-a']['apps']['one']['abandoned']);
        $this->assertSame(2, $summary['servers']['server-a']['apps']['two']['abandoned']);
        $this->assertSame(0, $summary['servers']['server-a']['apps']['failed']['abandoned']);
        $this->assertSame(0, $summary['servers']['server-a']['apps']['missing']['abandoned']);
        $this->assertSame(2, $summary['servers']['server-b']['apps']['three']['abandoned']);
    }
}
