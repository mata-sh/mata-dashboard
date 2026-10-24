<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use DateTimeImmutable;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySource;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySourceType;
use Mata\Models\Advisory\AdvisoryRepository;
use Mata\Models\Advisory\AdvisorySourceStateRepository;
use Mata\Services\Advisory\AdvisoryRelevanceFilter;
use Mata\Services\Advisory\AdvisorySourcePoller;
use Mata\Services\Advisory\Dto\AdvisoryItem;
use Mata\Services\Advisory\Dto\AdvisoryPollStatus;
use Mata\Services\Advisory\Fetch\AdvisoryFetcherInterface;
use Mata\Services\Advisory\Fetch\AdvisoryFetchException;
use Mata\Services\Advisory\Fetch\AdvisoryFetchResult;
use Mata\Services\Audit\Severity;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Mata\Tests\Helpers\SqliteTestDatabase;
use Mata\Tests\TestCase;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;

/**
 * The acceptance criteria of the advisory poller: no backlog mail at setup, one notification
 * per advisory, nothing resent after a restart, and a warning when a source keeps failing.
 *
 * @covers \Mata\Services\Advisory\AdvisorySourcePoller
 *
 * @internal
 */
final class AdvisorySourcePollerSqlIntegrationTest extends TestCase
{
    private SqliteTestDatabase $db;
    private EventManager&Stub $events;

    /** @var list<EventCommand> */
    private array $recorded = [];

    /** @var AdvisoryFetchException|list<AdvisoryItem> what the fake source returns next */
    private AdvisoryFetchException|array $sourceReturns = [];

    private bool $eventWritesFail = false;

    protected function setUp(): void
    {
        $this->db = new SqliteTestDatabase();
        $this->db->pdo()->exec('CREATE TABLE advisory_source_state (id INTEGER PRIMARY KEY, source_key TEXT UNIQUE, baseline_at TEXT, last_polled_at TEXT,'
            . ' last_success_at TEXT, consecutive_failures INTEGER NOT NULL DEFAULT 0, last_error TEXT, last_item_count INTEGER, fetch_cursor TEXT, updated_at TEXT)');
        $this->db->pdo()->exec('CREATE TABLE advisories (id INTEGER PRIMARY KEY, source_key TEXT, external_id TEXT, title TEXT, link TEXT, summary TEXT,'
            . ' severity TEXT, cvss_score REAL, cve_ids TEXT, products TEXT, matched_keywords TEXT, relevant INTEGER, baseline INTEGER,'
            . ' published_at TEXT, first_seen_at TEXT, notified_at TEXT, event_id INTEGER, review_status TEXT NOT NULL DEFAULT \'new\','
            . ' review_note TEXT, reviewed_by TEXT, reviewed_at TEXT, raw_format TEXT, raw TEXT, UNIQUE(source_key, external_id))');

        $this->events = $this->createStub(EventManager::class);
        $this->events->method('handle')->willReturnCallback(function (EventCommand $event): array {
            if ($this->eventWritesFail) {
                return ['eventRecorded' => false, 'eventId' => null];
            }
            $this->recorded[] = $event;

            return ['eventRecorded' => true, 'eventId' => count($this->recorded)];
        });
    }

    public function testFirstPollIsABaselineAndEachLaterAdvisoryIsNotifiedOnceAcrossRestarts(): void
    {
        $this->sourceReturns = [$this->item('A', 'PHP: old issue'), $this->item('B', 'PHP: older issue')];
        $baseline = $this->poller()->poll($this->source(), $this->at('10:00'));

        $this->assertTrue($baseline->baseline);
        $this->assertSame(2, $baseline->stored);
        $this->assertSame([], $this->recorded, 'Setup must not mail what the source already lists.');

        $this->sourceReturns = [$this->item('C', 'PHP: new issue', Severity::Critical), ...$this->sourceReturns];
        $next = $this->poller()->poll($this->source(), $this->at('11:00'));

        $this->assertSame(1, $next->notified);
        $this->assertCount(1, $this->recorded);
        $this->assertSame(AdvisorySourcePoller::EVENT_MATCHED, $this->recorded[0]->type);
        $this->assertSame('critical', $this->recorded[0]->level);
        $this->assertSame('[CERT-Bund] PHP: new issue', $this->recorded[0]->message);

        // A new poller instance stands for a restarted application: all memory is in the database.
        $again = $this->poller()->poll($this->source(), $this->at('12:00'));

        $this->assertSame(0, $again->stored);
        $this->assertCount(1, $this->recorded, 'A restart must not resend advisories.');
    }

    public function testAdvisoriesFailingTheFiltersAreStoredButNotNotified(): void
    {
        $this->sourceReturns = [];
        $this->poller()->poll($this->source(), $this->at('10:00'));
        $this->sourceReturns = [
            $this->item('mailer', 'PHPMailer: issue', Severity::Critical),
            $this->item('low', 'PHP: minor issue', Severity::Low),
            $this->item('match', 'Apache: issue', Severity::High),
        ];

        $result = $this->poller()->poll($this->source(), $this->at('11:00'));

        $this->assertSame(3, $result->stored);
        $this->assertSame(1, $result->relevant);
        $this->assertCount(1, $this->recorded);
        $this->assertSame('[CERT-Bund] Apache: issue', $this->recorded[0]->message);
    }

    public function testAnAdvisoryWhoseEventWasNotRecordedIsRetriedOnTheNextPoll(): void
    {
        $this->poller()->poll($this->source(), $this->at('10:00'));
        $this->sourceReturns = [$this->item('C', 'PHP: new issue')];
        $this->eventWritesFail = true;

        $failed = $this->poller()->poll($this->source(), $this->at('11:00'));
        $this->eventWritesFail = false;
        $retried = $this->poller()->poll($this->source(), $this->at('12:00'));
        $this->poller()->poll($this->source(), $this->at('13:00'));

        $this->assertSame(0, $failed->notified);
        $this->assertSame(1, $retried->notified);
        $this->assertCount(1, $this->recorded);
    }

    public function testRepeatedFailuresWarnAndTheNextSuccessRecordsARecovery(): void
    {
        $this->sourceReturns = new AdvisoryFetchException('HTTP 503 from https://example.org/feed');
        $first = $this->poller()->poll($this->source(), $this->at('10:00'));
        $this->poller()->poll($this->source(), $this->at('11:00'));

        $this->assertSame(AdvisoryPollStatus::Failed, $first->status);
        $this->assertSame([], $this->recorded, 'Two failures stay below failure_warning_after.');

        $third = $this->poller()->poll($this->source(), $this->at('12:00'));

        $this->assertSame(3, $third->consecutiveFailures);
        $this->assertCount(1, $this->recorded);
        $this->assertSame(AdvisorySourcePoller::EVENT_SOURCE_FAILING, $this->recorded[0]->type);
        $this->assertSame('warning', $this->recorded[0]->level);

        $this->sourceReturns = [];
        $this->poller()->poll($this->source(), $this->at('13:00'));

        $this->assertSame(AdvisorySourcePoller::EVENT_SOURCE_RECOVERED, $this->recorded[1]->type);
        $this->assertSame(0, (new AdvisorySourceStateRepository($this->db))->get('cert-bund')?->consecutiveFailures);
    }

    public function testASourceIsNotFetchedBeforeItsIntervalPassed(): void
    {
        $this->poller()->poll($this->source(), $this->at('10:00'));

        $this->assertSame(AdvisoryPollStatus::NotDue, $this->poller()->poll($this->source(), $this->at('10:30'))->status);
        $this->assertSame(AdvisoryPollStatus::Polled, $this->poller()->poll($this->source(), $this->at('10:30'), force: true)->status);
    }

    private function poller(): AdvisorySourcePoller
    {
        $fetcher = new class ($this) implements AdvisoryFetcherInterface {
            public function __construct(private AdvisorySourcePollerSqlIntegrationTest $test) {}

            public function fetch(AdvisorySource $source, ?string $cursor): AdvisoryFetchResult
            {
                return $this->test->sourceResult();
            }
        };

        return new AdvisorySourcePoller(
            fetchers: [AdvisorySourceType::Feed->value => $fetcher],
            advisories: new AdvisoryRepository($this->db),
            states: new AdvisorySourceStateRepository($this->db),
            filter: new AdvisoryRelevanceFilter(),
            eventManager: $this->events,
            logger: new NullLogger(),
            failureWarningAfter: 3,
        );
    }

    /** @internal for the fake fetcher */
    public function sourceResult(): AdvisoryFetchResult
    {
        if ($this->sourceReturns instanceof AdvisoryFetchException) {
            throw $this->sourceReturns;
        }

        return new AdvisoryFetchResult($this->sourceReturns);
    }

    private function source(): AdvisorySource
    {
        return new AdvisorySource(
            key: 'cert-bund',
            name: 'CERT-Bund',
            description: '',
            type: AdvisorySourceType::Feed,
            url: 'https://example.org/feed',
            enabled: true,
            pollIntervalMinutes: 60,
            keywords: ['PHP', 'Apache'],
            minSeverity: Severity::Medium,
            notify: true,
        );
    }

    private function item(string $id, string $title, Severity $severity = Severity::High): AdvisoryItem
    {
        return new AdvisoryItem($id, $title, "https://example.org/{$id}", null, $severity, null, [], [], new DateTimeImmutable('2026-10-24 09:00:00 UTC'), 'xml', '<item/>');
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-10-24 {$time}:00 UTC");
    }
}
