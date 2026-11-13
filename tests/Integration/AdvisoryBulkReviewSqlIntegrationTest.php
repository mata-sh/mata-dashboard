<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use DateTimeImmutable;
use Mata\Models\Advisory\AdvisoryRepository;
use Mata\Models\Advisory\AdvisoryReviewStatus;
use Mata\Services\Advisory\Dto\AdvisoryItem;
use Mata\Services\Audit\Severity;
use Mata\Tests\Helpers\SqliteTestDatabase;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Models\Advisory\AdvisoryRepository
 *
 * @internal
 */
final class AdvisoryBulkReviewSqlIntegrationTest extends TestCase
{
    private AdvisoryRepository $advisories;

    protected function setUp(): void
    {
        $db = new SqliteTestDatabase();
        $db->pdo()->exec('CREATE TABLE advisories (id INTEGER PRIMARY KEY, source_key TEXT, external_id TEXT, title TEXT, link TEXT, summary TEXT,'
            . ' severity TEXT, cvss_score REAL, cve_ids TEXT, products TEXT, matched_keywords TEXT, relevant INTEGER, baseline INTEGER,'
            . ' published_at TEXT, first_seen_at TEXT, notified_at TEXT, event_id INTEGER, review_status TEXT NOT NULL DEFAULT \'new\','
            . ' review_note TEXT, reviewed_by TEXT, reviewed_at TEXT, raw_format TEXT, raw TEXT, UNIQUE(source_key, external_id))');
        $this->advisories = new AdvisoryRepository($db);
    }

    public function testBulkReviewKeepsTheReviewOfAdvisoriesAlreadyInThatStatus(): void
    {
        $fresh = $this->store('fresh');
        $dismissed = $this->store('dismissed');
        $this->advisories->review($dismissed, AdvisoryReviewStatus::NotRelevant, 'ext not installed', 'admin', '2026-10-23 08:00:00');

        $changed = $this->advisories->reviewMany([$fresh, $dismissed], AdvisoryReviewStatus::NotRelevant, 'bulk', 'ops', '2026-10-24 12:00:00');

        $this->assertSame(1, $changed);
        $kept = $this->advisories->find($dismissed);
        $this->assertSame(['ext not installed', 'admin', '2026-10-23 08:00:00'], [$kept?->reviewNote, $kept?->reviewedBy, $kept?->reviewedAt]);
        $updated = $this->advisories->find($fresh);
        $this->assertSame([AdvisoryReviewStatus::NotRelevant, 'bulk', 'ops'], [$updated?->reviewStatus, $updated?->reviewNote, $updated?->reviewedBy]);
    }

    public function testBulkResetToNewClearsTheReview(): void
    {
        $dismissed = $this->store('dismissed');
        $this->advisories->review($dismissed, AdvisoryReviewStatus::NotRelevant, 'ext not installed', 'admin', '2026-10-23 08:00:00');

        $this->assertSame(1, $this->advisories->reviewMany([$dismissed], AdvisoryReviewStatus::New, 'ignored', 'ops', '2026-10-24 12:00:00'));
        $reset = $this->advisories->find($dismissed);
        $this->assertSame([AdvisoryReviewStatus::New, null, null, null], [$reset?->reviewStatus, $reset?->reviewNote, $reset?->reviewedBy, $reset?->reviewedAt]);
    }

    private function store(string $id): int
    {
        return $this->advisories->insert(
            'cert-bund',
            new AdvisoryItem($id, "Advisory {$id}", null, null, Severity::High, null, [], [], new DateTimeImmutable('2026-10-22 10:00:00 UTC'), 'xml', '<item/>'),
            true,
            [],
            false,
            '2026-10-24 09:00:00',
        );
    }
}
