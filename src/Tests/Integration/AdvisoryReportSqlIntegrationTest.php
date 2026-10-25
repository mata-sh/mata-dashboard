<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use DateTimeImmutable;
use Mata\Config\Monitoring\Section\AdvisoriesConfig;
use Mata\Models\Advisory\AdvisoryRepository;
use Mata\Models\Advisory\AdvisoryReviewStatus;
use Mata\Models\Advisory\AdvisorySourceStateRepository;
use Mata\Services\Advisory\Dto\AdvisoryItem;
use Mata\Services\Advisory\Report\AdvisoryReportBuilder;
use Mata\Services\Advisory\Report\AdvisoryReportPdfWriter;
use Mata\Services\Advisory\Report\AdvisoryReportPresenter;
use Mata\Services\Advisory\Report\AdvisoryReportQuery;
use Mata\Services\Advisory\Report\AdvisoryReportRenderer;
use Mata\Services\Audit\Severity;
use Mata\Tests\Helpers\SqliteTestDatabase;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Advisory\Report\AdvisoryReportBuilder
 * @covers \Mata\Services\Advisory\Report\AdvisoryReportRenderer
 *
 * @internal
 */
final class AdvisoryReportSqlIntegrationTest extends TestCase
{
    private SqliteTestDatabase $db;
    private AdvisoryRepository $advisories;

    protected function setUp(): void
    {
        $this->db = new SqliteTestDatabase();
        $this->db->pdo()->exec('CREATE TABLE advisory_source_state (id INTEGER PRIMARY KEY, source_key TEXT UNIQUE, baseline_at TEXT, last_polled_at TEXT,'
            . ' last_success_at TEXT, consecutive_failures INTEGER NOT NULL DEFAULT 0, last_error TEXT, last_item_count INTEGER, fetch_cursor TEXT, updated_at TEXT)');
        $this->db->pdo()->exec('CREATE TABLE advisories (id INTEGER PRIMARY KEY, source_key TEXT, external_id TEXT, title TEXT, link TEXT, summary TEXT,'
            . ' severity TEXT, cvss_score REAL, cve_ids TEXT, products TEXT, matched_keywords TEXT, relevant INTEGER, baseline INTEGER,'
            . ' published_at TEXT, first_seen_at TEXT, notified_at TEXT, event_id INTEGER, review_status TEXT NOT NULL DEFAULT \'new\','
            . ' review_note TEXT, reviewed_by TEXT, reviewed_at TEXT, raw_format TEXT, raw TEXT, UNIQUE(source_key, external_id))');
        $this->advisories = new AdvisoryRepository($this->db);

        $this->store('a', 'PHP | ext-soap: heap overflow', Severity::Critical, '2026-10-22 10:00:00', ['php-src <8.4.26 (fixed 8.4.26)'], ['CVE-2026-1']);
        $this->store('b', 'PHP: SQL injection in ext-pgsql', Severity::High, '2026-10-21 10:00:00', ['php-src <8.3.35'], ['CVE-2026-2', 'CVE-2026-1']);
        $this->store('c', 'Apache: minor issue', Severity::Low, '2026-10-20 10:00:00', ['Apache HTTP Server']);
        $this->store('old', 'PHP: ancient issue', Severity::Critical, '2026-09-01 10:00:00');
        $this->store('noise', 'KNIME: unrelated', Severity::High, '2026-10-22 11:00:00', relevant: false);
        $dismissed = $this->store('dismissed', 'PHP: not used here', Severity::High, '2026-10-22 12:00:00');
        $this->advisories->review($dismissed, AdvisoryReviewStatus::NotRelevant, 'ext not installed', 'admin', '2026-10-23 08:00:00');
    }

    public function testTheReportCountsRelevantAdvisoriesOfThePeriodAndGroupsProductsAcrossVersions(): void
    {
        $report = $this->builder()->build($this->lastWeek(), $this->now());

        $this->assertSame(3, $report->total);
        $this->assertSame(['critical' => 1, 'high' => 1, 'medium' => 0, 'low' => 1, 'unknown' => 0], $report->severityCounts);
        $this->assertSame(2, $report->cveCount);
        $this->assertSame(Severity::Critical, $report->worstSeverity());
        $this->assertSame(['product' => 'php-src', 'count' => 2, 'worst' => 'critical'], $report->topProducts[0]);
        $this->assertSame('ok', $report->sources[0]['health']);
    }

    public function testFiltersNarrowTheReport(): void
    {
        $all = $this->builder()->build(new AdvisoryReportQuery($this->now()->modify('-7 days'), $this->now(), relevantOnly: false, includeNotRelevant: true), $this->now());
        $high = $this->builder()->build(new AdvisoryReportQuery($this->now()->modify('-7 days'), $this->now(), minSeverity: Severity::High), $this->now());

        $this->assertSame(5, $all->total);
        $this->assertSame(2, $high->total);
    }

    public function testEveryFormatRendersAndMarkdownTablesSurvivePipesInTitles(): void
    {
        $report = $this->builder()->build($this->lastWeek(), $this->now());
        $root = dirname(__DIR__, 3);
        $renderer = new AdvisoryReportRenderer($root . '/templates', new AdvisoryReportPresenter('MATA'), new AdvisoryReportPdfWriter($root . '/' . AdvisoryReportPdfWriter::FONT_DIR));

        $markdown = $renderer->markdown($report);
        $this->assertStringContainsString('PHP \| ext-soap: heap overflow', $markdown);
        $this->assertStringNotContainsString("\n\n\n", $markdown);

        $json = json_decode($renderer->json($report), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(3, $json['totals']['advisories']);
        $this->assertSame('2026-10-22T10:00:00Z', $json['advisories'][0]['published_at']);

        $this->assertStringContainsString('PHP | ext-soap: heap overflow', $renderer->html($report));
        $this->assertStringStartsWith('%PDF-', $renderer->pdf($report));
    }

    private function builder(): AdvisoryReportBuilder
    {
        $config = AdvisoriesConfig::fromArray(['sources' => ['cert-bund' => ['type' => 'feed', 'name' => 'CERT-Bund', 'url' => 'https://example.org/rss']]], static fn (): ?string => null, static fn (): null => null);
        $states = new AdvisorySourceStateRepository($this->db);
        $states->recordSuccess('cert-bund', '2026-10-24 11:00:00', 6, null);

        return new AdvisoryReportBuilder($config, $this->advisories, $states);
    }

    /**
     * @param list<string> $products
     * @param list<string> $cves
     */
    private function store(string $id, string $title, Severity $severity, string $published, array $products = [], array $cves = [], bool $relevant = true): int
    {
        return $this->advisories->insert(
            'cert-bund',
            new AdvisoryItem($id, $title, "https://example.org/{$id}", null, $severity, null, $cves, $products, new DateTimeImmutable($published . ' UTC'), 'xml', '<item/>'),
            $relevant,
            [],
            false,
            '2026-10-24 09:00:00',
        );
    }

    private function lastWeek(): AdvisoryReportQuery
    {
        return AdvisoryReportQuery::lastDays(7, $this->now());
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-24 12:00:00 UTC');
    }
}
