<?php

declare(strict_types=1);

namespace Mata\Models\Monitor;

use JsonException;
use Mata\Database\DatabaseInterface;

final class AppLibraryAuditStateRepository
{
    public function __construct(private DatabaseInterface $db) {}

    public function get(int $serverId, int $applicationId): ?AppLibraryAuditState
    {
        $rows = $this->db->runSelect(
            'SELECT server_id, application_id, advisory_ids FROM app_library_audit_state WHERE server_id = ? AND application_id = ? LIMIT 1',
            [$serverId, $applicationId],
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        try {
            $decoded = json_decode((string) $row['advisory_ids'], true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }
        // Corrupt state reads as an empty set, so every current advisory is reported again.
        $advisoryIds = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];

        return new AppLibraryAuditState((int) $row['server_id'], (int) $row['application_id'], $advisoryIds);
    }

    /** @param list<string> $advisoryIds */
    public function upsert(int $serverId, int $applicationId, array $advisoryIds): void
    {
        $encoded = json_encode($advisoryIds, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if ($this->get($serverId, $applicationId) !== null) {
            $this->db->runStatement(
                'UPDATE app_library_audit_state SET advisory_ids = ?, updated_at = CURRENT_TIMESTAMP WHERE server_id = ? AND application_id = ?',
                [$encoded, $serverId, $applicationId],
            );

            return;
        }

        $this->db->runInsert(
            'INSERT INTO app_library_audit_state (server_id, application_id, advisory_ids, created_at, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            [$serverId, $applicationId, $encoded],
        );
    }

    public function clear(int $serverId, int $applicationId): void
    {
        $this->db->runStatement(
            'DELETE FROM app_library_audit_state WHERE server_id = ? AND application_id = ?',
            [$serverId, $applicationId],
        );
    }
}
