<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Audit\AuditAggregator;
use Mata\Services\Audit\AuditRowEnricher;
use Mata\Services\Collect\ServerDataCollectorInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Audit\AuditRowEnricher
 *
 * @internal
 */
final class AuditRowEnricherTest extends TestCase
{
    public function testNullCachedAuditProducesAnUnauditedRow(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getCachedAppComposerAudit')->willReturn(null);

        $row = $this->enricher()->enrichRow(
            ['name' => 'vendor/package', 'audited' => false],
            $this->enricher()->appAudit($collector, 'app')
        );

        $this->assertSame(['name' => 'vendor/package', 'audited' => false, 'vulns' => 0, 'severity' => null], $row);
    }

    public function testOkAuditWithAbsentPackageProducesNoVulnerabilities(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getCachedAppComposerAudit')->willReturn(['result' => ['status' => 'ok', 'advisories' => []]]);
        $enricher = $this->enricher();

        $row = $enricher->enrichRow(['name' => 'vendor/package', 'audited' => true], $enricher->appAudit($collector, 'app'));

        $this->assertSame(['name' => 'vendor/package', 'audited' => true, 'vulns' => 0, 'severity' => null], $row);
    }

    public function testVulnerablePackageStampsAuditFields(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getCachedAppComposerAudit')->willReturn(['result' => [
            'status' => 'ok',
            'advisories' => [['package' => 'vendor/package', 'severity' => 'high', 'id' => 'CVE-1']],
        ]]);
        $enricher = $this->enricher();

        $row = $enricher->enrichRow(['name' => 'vendor/package', 'audited' => true], $enricher->appAudit($collector, 'app'));

        $this->assertSame('high', $row['severity']);
        $this->assertSame(1, $row['vulns']);
        $this->assertSame([['package' => 'vendor/package', 'severity' => 'high', 'id' => 'CVE-1']], $row['advisories']);
    }

    public function testErrorStatusIsNotAudited(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getCachedAppComposerAudit')->willReturn(['result' => ['status' => 'error', 'advisories' => []]]);

        $this->assertFalse($this->enricher()->appAudit($collector, 'app')['audited']);
    }

    public function testMissingStatusIsNotAudited(): void
    {
        $collector = $this->createStub(ServerDataCollectorInterface::class);
        $collector->method('getCachedAppComposerAudit')->willReturn(['result' => ['advisories' => []]]);

        $this->assertFalse($this->enricher()->appAudit($collector, 'app')['audited']);
    }

    private function enricher(): AuditRowEnricher
    {
        return new AuditRowEnricher(new AuditAggregator());
    }
}
