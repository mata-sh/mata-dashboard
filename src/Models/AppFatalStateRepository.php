<?php

declare(strict_types=1);

namespace Mata\Models;

use Mata\Database\DatabaseInterface;

final class AppFatalStateRepository
{
    public function __construct(private DatabaseInterface $db) {}

    public function get(int $serverId, int $applicationId): ?AppFatalState
    {
        $rows = $this->db->runSelect(
            'SELECT server_id, application_id, fatal_cursor, fatal_count FROM app_fatal_monitor_state WHERE server_id = ? AND application_id = ? LIMIT 1',
            [$serverId, $applicationId],
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return new AppFatalState(
            (int) $row['server_id'],
            (int) $row['application_id'],
            (string) $row['fatal_cursor'],
            (int) $row['fatal_count'],
        );
    }

    public function upsert(int $serverId, int $applicationId, string $fatalCursor, int $fatalCount): void
    {
        $existing = $this->get($serverId, $applicationId);
        if ($existing !== null) {
            $this->db->runStatement(
                'UPDATE app_fatal_monitor_state SET fatal_cursor = ?, fatal_count = ?, updated_at = CURRENT_TIMESTAMP WHERE server_id = ? AND application_id = ?',
                [$fatalCursor, $fatalCount, $serverId, $applicationId],
            );

            return;
        }

        $this->db->runInsert(
            'INSERT INTO app_fatal_monitor_state (server_id, application_id, fatal_cursor, fatal_count, created_at, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            [$serverId, $applicationId, $fatalCursor, $fatalCount],
        );
    }

    public function clear(int $serverId, int $applicationId): void
    {
        $this->db->runStatement(
            'DELETE FROM app_fatal_monitor_state WHERE server_id = ? AND application_id = ?',
            [$serverId, $applicationId],
        );
    }
}
