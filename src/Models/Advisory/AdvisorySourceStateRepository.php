<?php

declare(strict_types=1);

namespace Mata\Models\Advisory;

use Mata\Database\DatabaseInterface;

/** Poll bookkeeping per advisory source; written only by the locked poll cron and bin/mata advisory fetch. */
final class AdvisorySourceStateRepository
{
    private const ERROR_LIMIT = 1000;

    public function __construct(private DatabaseInterface $db) {}

    public function get(string $sourceKey): ?AdvisorySourceState
    {
        return $this->all()[$sourceKey] ?? null;
    }

    /** @return array<string,AdvisorySourceState> */
    public function all(): array
    {
        $states = [];
        $rows = $this->db->runSelect(
            'SELECT source_key, baseline_at, last_polled_at, last_success_at, consecutive_failures, last_error, last_item_count, fetch_cursor'
                . ' FROM advisory_source_state',
        );
        foreach ($rows as $row) {
            $key = (string) $row['source_key'];
            $states[$key] = new AdvisorySourceState(
                sourceKey: $key,
                baselineAt: $row['baseline_at'] === null ? null : (string) $row['baseline_at'],
                lastPolledAt: $row['last_polled_at'] === null ? null : (string) $row['last_polled_at'],
                lastSuccessAt: $row['last_success_at'] === null ? null : (string) $row['last_success_at'],
                consecutiveFailures: (int) $row['consecutive_failures'],
                lastError: $row['last_error'] === null ? null : (string) $row['last_error'],
                lastItemCount: $row['last_item_count'] === null ? null : (int) $row['last_item_count'],
                cursor: $row['fetch_cursor'] === null ? null : (string) $row['fetch_cursor'],
            );
        }

        return $states;
    }

    /** A successful poll resets the failure streak; the first one also sets the baseline. */
    public function recordSuccess(string $sourceKey, string $now, int $itemCount, ?string $cursor): void
    {
        $state = $this->get($sourceKey);
        if ($state === null) {
            $this->db->runInsert(
                'INSERT INTO advisory_source_state (source_key, baseline_at, last_polled_at, last_success_at, consecutive_failures, last_item_count, fetch_cursor, updated_at)'
                    . ' VALUES (?, ?, ?, ?, 0, ?, ?, ?)',
                [$sourceKey, $now, $now, $now, $itemCount, $cursor, $now],
            );

            return;
        }

        $this->db->runStatement(
            'UPDATE advisory_source_state SET baseline_at = ?, last_polled_at = ?, last_success_at = ?, consecutive_failures = 0,'
                . ' last_error = NULL, last_item_count = ?, fetch_cursor = ?, updated_at = ? WHERE source_key = ?',
            [$state->baselineAt ?? $now, $now, $now, $itemCount, $cursor ?? $state->cursor, $now, $sourceKey],
        );
    }

    /** @return int the failure streak including this failure */
    public function recordFailure(string $sourceKey, string $now, string $error): int
    {
        $error = mb_substr($error, 0, self::ERROR_LIMIT);
        $state = $this->get($sourceKey);
        $failures = ($state->consecutiveFailures ?? 0) + 1;
        if ($state === null) {
            $this->db->runInsert(
                'INSERT INTO advisory_source_state (source_key, last_polled_at, consecutive_failures, last_error, updated_at) VALUES (?, ?, ?, ?, ?)',
                [$sourceKey, $now, $failures, $error, $now],
            );
        } else {
            $this->db->runStatement(
                'UPDATE advisory_source_state SET last_polled_at = ?, consecutive_failures = ?, last_error = ?, updated_at = ? WHERE source_key = ?',
                [$now, $failures, $error, $now, $sourceKey],
            );
        }

        return $failures;
    }
}
