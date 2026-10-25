<?php

declare(strict_types=1);

namespace Mata\Models\Advisory;

use DateTimeZone;
use JsonException;
use Mata\Database\DatabaseInterface;
use Mata\Services\Advisory\Dto\AdvisoryItem;
use Mata\Services\Audit\Severity;

/**
 * Stored advisories. A row is inserted once per (source, external ID) and is the memory that
 * keeps an advisory from being notified twice, across restarts.
 */
final class AdvisoryRepository
{
    private const COLUMNS = 'id, source_key, external_id, title, link, summary, severity, cvss_score, cve_ids, products,'
        . ' matched_keywords, relevant, baseline, published_at, first_seen_at, notified_at, event_id,'
        . ' review_status, review_note, reviewed_by, reviewed_at';

    /** Chunk size for IN (...) lookups, well below MySQL's placeholder limit. */
    private const LOOKUP_CHUNK = 500;

    public function __construct(private DatabaseInterface $db) {}

    /**
     * @param list<string> $externalIds
     *
     * @return array<string,true> the given IDs already stored for the source
     */
    public function knownIds(string $sourceKey, array $externalIds): array
    {
        $existing = [];
        foreach (array_chunk(array_values(array_unique($externalIds)), self::LOOKUP_CHUNK) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $this->db->runSelect(
                "SELECT external_id FROM advisories WHERE source_key = ? AND external_id IN ({$placeholders})",
                [$sourceKey, ...$chunk],
            );
            foreach ($rows as $row) {
                $existing[(string) $row['external_id']] = true;
            }
        }

        return $existing;
    }

    /** @param list<string> $matchedKeywords */
    public function insert(string $sourceKey, AdvisoryItem $item, bool $relevant, array $matchedKeywords, bool $baseline, string $now): int
    {
        return $this->db->runInsert(
            'INSERT INTO advisories (source_key, external_id, title, link, summary, severity, cvss_score, cve_ids, products,'
                . ' matched_keywords, relevant, baseline, published_at, first_seen_at, raw_format, raw)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $sourceKey,
                $item->externalId,
                $item->title,
                $item->link,
                $item->summary,
                $item->severity->value,
                $item->cvssScore,
                self::encodeList($item->cveIds),
                self::encodeList($item->products),
                self::encodeList($matchedKeywords),
                $relevant ? 1 : 0,
                $baseline ? 1 : 0,
                $item->publishedAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $now,
                $item->rawFormat,
                $item->raw,
            ],
        );
    }

    /**
     * Relevant advisories of a source whose notification has not been recorded yet, e.g. after
     * an event write failed. Older ones are given up so a broken setup cannot replay a backlog.
     *
     * @return list<Advisory>
     */
    public function pendingNotifications(string $sourceKey, string $notBefore): array
    {
        return $this->select(
            'WHERE source_key = ? AND relevant = 1 AND baseline = 0 AND notified_at IS NULL AND first_seen_at >= ? ORDER BY id',
            [$sourceKey, $notBefore],
        );
    }

    public function markNotified(int $id, ?int $eventId, string $now): void
    {
        $this->db->runStatement('UPDATE advisories SET notified_at = ?, event_id = ? WHERE id = ?', [$now, $eventId, $id]);
    }

    /**
     * Advisories that occurred (published, else first seen) at or after $since, newest first.
     *
     * @param ?list<string> $sourceKeys null for every source
     *
     * @return list<Advisory>
     */
    public function findSince(
        string $since,
        ?string $until = null,
        ?array $sourceKeys = null,
        bool $relevantOnly = false,
        bool $includeNotRelevantReviews = true,
        int $limit = 5000,
    ): array {
        $where = ['COALESCE(published_at, first_seen_at) >= ?'];
        $params = [$since];
        if ($until !== null) {
            $where[] = 'COALESCE(published_at, first_seen_at) < ?';
            $params[] = $until;
        }
        if ($sourceKeys !== null) {
            if ($sourceKeys === []) {
                return [];
            }
            $where[] = 'source_key IN (' . implode(',', array_fill(0, count($sourceKeys), '?')) . ')';
            $params = [...$params, ...$sourceKeys];
        }
        if ($relevantOnly) {
            $where[] = 'relevant = 1';
        }
        if (!$includeNotRelevantReviews) {
            $where[] = 'review_status <> ?';
            $params[] = AdvisoryReviewStatus::NotRelevant->value;
        }

        return $this->select(
            'WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(published_at, first_seen_at) DESC, id DESC LIMIT ' . max(1, $limit),
            $params,
        );
    }

    public function find(int $id): ?Advisory
    {
        return $this->select('WHERE id = ?', [$id])[0] ?? null;
    }

    /** @return null|array{format:string,raw:string} */
    public function raw(int $id): ?array
    {
        $row = $this->db->runSelect('SELECT raw_format, raw FROM advisories WHERE id = ?', [$id])[0] ?? null;

        return is_array($row) ? ['format' => (string) $row['raw_format'], 'raw' => (string) $row['raw']] : null;
    }

    public function review(int $id, AdvisoryReviewStatus $status, ?string $note, ?string $reviewedBy, string $now): bool
    {
        $reset = $status === AdvisoryReviewStatus::New;

        return $this->db->runStatement(
            'UPDATE advisories SET review_status = ?, review_note = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?',
            [$status->value, $reset ? null : $note, $reset ? null : $reviewedBy, $reset ? null : $now, $id],
        ) > 0;
    }

    /**
     * Per-source totals for the source overview.
     *
     * @return array<string,array{total:int,relevant:int,unreviewed:int,latest:?string}>
     */
    public function sourceTotals(): array
    {
        $rows = $this->db->runSelect(
            'SELECT source_key, COUNT(*) AS total, SUM(relevant) AS relevant,'
                . ' SUM(CASE WHEN relevant = 1 AND baseline = 0 AND review_status = ? THEN 1 ELSE 0 END) AS unreviewed,'
                . ' MAX(COALESCE(published_at, first_seen_at)) AS latest'
                . ' FROM advisories GROUP BY source_key',
            [AdvisoryReviewStatus::New->value],
        );

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['source_key']] = [
                'total' => (int) $row['total'],
                'relevant' => (int) $row['relevant'],
                'unreviewed' => (int) $row['unreviewed'],
                'latest' => $row['latest'] === null ? null : (string) $row['latest'],
            ];
        }

        return $totals;
    }

    /**
     * @param array<int,mixed> $params
     *
     * @return list<Advisory>
     */
    private function select(string $clause, array $params): array
    {
        return array_values(array_map(
            self::hydrate(...),
            $this->db->runSelect('SELECT ' . self::COLUMNS . ' FROM advisories ' . $clause, $params),
        ));
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): Advisory
    {
        return new Advisory(
            id: (int) $row['id'],
            sourceKey: (string) $row['source_key'],
            externalId: (string) $row['external_id'],
            title: (string) $row['title'],
            link: self::nullableString($row['link']),
            summary: self::nullableString($row['summary']),
            severity: Severity::tryFrom((string) $row['severity']) ?? Severity::Unknown,
            cvssScore: $row['cvss_score'] === null ? null : (float) $row['cvss_score'],
            cveIds: self::decodeList($row['cve_ids']),
            products: self::decodeList($row['products']),
            matchedKeywords: self::decodeList($row['matched_keywords']),
            relevant: (bool) $row['relevant'],
            baseline: (bool) $row['baseline'],
            publishedAt: self::nullableString($row['published_at']),
            firstSeenAt: (string) $row['first_seen_at'],
            notifiedAt: self::nullableString($row['notified_at']),
            eventId: $row['event_id'] === null ? null : (int) $row['event_id'],
            reviewStatus: AdvisoryReviewStatus::tryFrom((string) $row['review_status']) ?? AdvisoryReviewStatus::New,
            reviewNote: self::nullableString($row['review_note']),
            reviewedBy: self::nullableString($row['reviewed_by']),
            reviewedAt: self::nullableString($row['reviewed_at']),
        );
    }

    /** @param list<string> $values */
    private static function encodeList(array $values): string
    {
        return json_encode(array_values($values), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<string> */
    private static function decodeList(mixed $value): array
    {
        try {
            $decoded = json_decode((string) $value, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
