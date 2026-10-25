<?php

declare(strict_types=1);

namespace Mata\Services\Advisory;

use DateTimeImmutable;
use DateTimeZone;
use Mata\Config\Monitoring\Section\Advisory\AdvisorySource;
use Mata\Models\Advisory\Advisory;
use Mata\Models\Advisory\AdvisoryRepository;
use Mata\Models\Advisory\AdvisorySourceState;
use Mata\Models\Advisory\AdvisorySourceStateRepository;
use Mata\Services\Advisory\Dto\AdvisoryItem;
use Mata\Services\Advisory\Dto\AdvisoryPollResult;
use Mata\Services\Advisory\Dto\AdvisoryPollStatus;
use Mata\Services\Advisory\Fetch\AdvisoryFetcherInterface;
use Mata\Services\Advisory\Fetch\AdvisoryFetchException;
use Mata\Services\Audit\Severity;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Psr\Log\LoggerInterface;

/**
 * Polls one advisory source: stores what is new, records one event per new relevant
 * advisory, and tracks failures.
 *
 * - The first successful poll is the baseline: everything the source already lists is
 *   stored without notifying, so setup does not mail the backlog.
 * - An advisory counts as notified only once its event was recorded; until then it is
 *   retried on every poll for RETRY_DAYS.
 * - After `failure_warning_after` failures in a row a warning event is recorded, so a dead
 *   source does not look like a quiet one; the first success after that records a recovery.
 */
final class AdvisorySourcePoller
{
    public const EVENT_MATCHED = 'advisories.matched';
    public const EVENT_SOURCE_FAILING = 'advisories.source_failing';
    public const EVENT_SOURCE_RECOVERED = 'advisories.source_recovered';

    private const RETRY_DAYS = 7;
    private const MAX_RAW_BYTES = 512 * 1024;
    private const DUE_TOLERANCE_SECONDS = 30;

    /** @param array<string,AdvisoryFetcherInterface> $fetchers keyed by AdvisorySourceType value */
    public function __construct(
        private array $fetchers,
        private AdvisoryRepository $advisories,
        private AdvisorySourceStateRepository $states,
        private AdvisoryRelevanceFilter $filter,
        private EventManager $eventManager,
        private LoggerInterface $logger,
        private int $failureWarningAfter,
    ) {}

    public function poll(AdvisorySource $source, DateTimeImmutable $now, bool $force = false): AdvisoryPollResult
    {
        $nowText = self::utc($now);
        $state = $this->states->get($source->key);
        if (!$force && !self::isDue($state, $source, $now)) {
            return new AdvisoryPollResult($source->key, AdvisoryPollStatus::NotDue);
        }

        try {
            $fetched = $this->fetcher($source)->fetch($source, $state?->cursor);
        } catch (AdvisoryFetchException $exception) {
            return $this->failed($source, $nowText, $exception->getMessage());
        }

        $baseline = $state === null || !$state->hasBaseline();
        $items = self::uniqueItems($fetched->items);
        $knownIds = $this->advisories->knownIds($source->key, self::allIds($items));
        $stored = $relevant = 0;
        foreach ($items as $item) {
            if (self::isKnown($item, $knownIds)) {
                continue;
            }
            $relevance = $this->filter->evaluate($source, $item);
            $this->advisories->insert(
                sourceKey: $source->key,
                item: self::capRaw($item),
                relevant: $relevance->relevant,
                matchedKeywords: $relevance->matchedKeywords,
                baseline: $baseline,
                now: $nowText,
            );
            ++$stored;
            $relevant += $relevance->relevant ? 1 : 0;
        }

        $notified = $baseline ? 0 : $this->notifyPending($source, $now, $nowText);

        if ($state !== null && $state->consecutiveFailures >= $this->failureWarningAfter) {
            $this->recordRecovery($source, $state->consecutiveFailures);
        }
        $this->states->recordSuccess($source->key, $nowText, count($items), $fetched->cursor);

        return new AdvisoryPollResult(
            sourceKey: $source->key,
            status: AdvisoryPollStatus::Polled,
            fetched: count($items),
            stored: $stored,
            relevant: $relevant,
            notified: $notified,
            baseline: $baseline,
        );
    }

    /** Fetch and filter without storing or notifying, e.g. to try filters from the CLI. */
    public function preview(AdvisorySource $source): AdvisoryPollResult
    {
        try {
            $items = self::uniqueItems($this->fetcher($source)->fetch($source, null)->items);
        } catch (AdvisoryFetchException $exception) {
            return new AdvisoryPollResult($source->key, AdvisoryPollStatus::Failed, error: $exception->getMessage());
        }

        $preview = [];
        $relevant = 0;
        foreach ($items as $item) {
            $relevance = $this->filter->evaluate($source, $item);
            $relevant += $relevance->relevant ? 1 : 0;
            $preview[] = ['item' => $item, 'relevance' => $relevance];
        }

        return new AdvisoryPollResult($source->key, AdvisoryPollStatus::Previewed, count($items), relevant: $relevant, preview: $preview);
    }

    /** Event level by advisory severity: 🔴 critical, 🟡 high, 🔵 medium, low and unrated. */
    public static function eventLevel(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical => 'critical',
            Severity::High => 'warning',
            default => 'info',
        };
    }

    private static function isDue(?AdvisorySourceState $state, AdvisorySource $source, DateTimeImmutable $now): bool
    {
        if ($state?->lastPolledAt === null) {
            return true;
        }
        $nextPollAt = strtotime($state->lastPolledAt . ' UTC') + $source->pollIntervalMinutes * 60;

        return $nextPollAt - self::DUE_TOLERANCE_SECONDS <= $now->getTimestamp();
    }

    /** @return int advisories whose event was recorded */
    private function notifyPending(AdvisorySource $source, DateTimeImmutable $now, string $nowText): int
    {
        $notBefore = self::utc($now->modify('-' . self::RETRY_DAYS . ' days'));
        $notified = 0;
        foreach ($this->advisories->pendingNotifications($source->key, $notBefore) as $advisory) {
            $notified += $this->notify($source, $advisory, $nowText) ? 1 : 0;
        }

        return $notified;
    }

    private function notify(AdvisorySource $source, Advisory $advisory, string $now): bool
    {
        if (!$source->notify) {
            $this->advisories->markNotified($advisory->id, null, $now);

            return false;
        }

        $context = [
            'source' => $source->key,
            'advisory_id' => $advisory->id,
            'external_id' => $advisory->externalId,
            'additional_data' => array_filter([
                'source_name' => $source->name,
                'severity' => $advisory->severity->value,
                'cvss' => $advisory->cvssScore,
                'cve_ids' => $advisory->cveIds,
                'products' => array_slice($advisory->products, 0, 10),
                'matched_keywords' => $advisory->matchedKeywords,
                'link' => $advisory->link,
                'published_at' => $advisory->publishedAt,
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        ];
        $result = $this->eventManager->handle(new EventCommand(
            type: self::EVENT_MATCHED,
            message: sprintf('[%s] %s', $source->name, $advisory->title),
            level: self::eventLevel($advisory->severity),
            context: $context,
        ));
        if (($result['eventRecorded'] ?? false) !== true) {
            // Left pending: the next poll retries it.
            return false;
        }

        $this->advisories->markNotified($advisory->id, $result['eventId'] ?? null, $now);

        return true;
    }

    private function failed(AdvisorySource $source, string $now, string $error): AdvisoryPollResult
    {
        $failures = $this->states->recordFailure($source->key, $now, $error);
        $this->logger->warning('advisories.source_failed', ['source' => $source->key, 'error' => $error, 'consecutive_failures' => $failures]);
        if ($failures >= $this->failureWarningAfter) {
            // Cooldown identity is the source only, so a changing error text does not re-alert.
            $this->eventManager->handle(new EventCommand(
                type: self::EVENT_SOURCE_FAILING,
                message: sprintf('Advisory source %s failed %d times in a row: %s', $source->name, $failures, $error),
                level: 'warning',
                context: ['source' => $source->key, 'additional_data' => ['error' => $error, 'consecutive_failures' => $failures, 'url' => $source->url]],
            ));
        }

        return new AdvisoryPollResult($source->key, AdvisoryPollStatus::Failed, error: $error, consecutiveFailures: $failures);
    }

    private function recordRecovery(AdvisorySource $source, int $failures): void
    {
        $this->eventManager->handle(new EventCommand(
            type: self::EVENT_SOURCE_RECOVERED,
            message: sprintf('Advisory source %s is reachable again after %d failed polls', $source->name, $failures),
            level: 'info',
            context: ['source' => $source->key, 'additional_data' => ['consecutive_failures' => $failures]],
        ));
    }

    private function fetcher(AdvisorySource $source): AdvisoryFetcherInterface
    {
        return $this->fetchers[$source->type->value]
            ?? throw new AdvisoryFetchException("no fetcher for source type {$source->type->value}");
    }

    /**
     * @param list<AdvisoryItem> $items
     *
     * @return array<string,AdvisoryItem> keyed by external ID; the first occurrence wins
     */
    private static function uniqueItems(array $items): array
    {
        $unique = [];
        foreach ($items as $item) {
            $unique[$item->externalId] ??= $item;
        }

        return $unique;
    }

    /**
     * @param array<string,AdvisoryItem> $items
     *
     * @return list<string> the external IDs and aliases of all items
     */
    private static function allIds(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            array_push($ids, ...$item->ids());
        }

        return $ids;
    }

    /** @param array<string,true> $knownIds external IDs already stored */
    private static function isKnown(AdvisoryItem $item, array $knownIds): bool
    {
        foreach ($item->ids() as $id) {
            if (isset($knownIds[$id])) {
                return true;
            }
        }

        return false;
    }

    private static function capRaw(AdvisoryItem $item): AdvisoryItem
    {
        if (strlen($item->raw) <= self::MAX_RAW_BYTES) {
            return $item;
        }

        return $item->withRaw(mb_strcut($item->raw, 0, self::MAX_RAW_BYTES) . "\n[truncated]");
    }

    private static function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
