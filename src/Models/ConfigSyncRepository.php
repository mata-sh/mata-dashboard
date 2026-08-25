<?php

declare(strict_types=1);

namespace Mata\Models;

use Mata\Database\DatabaseInterface;

/**
 * Persistence layer for server configuration synchronization.
 *
 * Encapsulates all database interactions required by the
 * ServerConfigDatabaseSync service, keeping the service focused on
 * decision-making instead of SQL concerns.
 */
class ConfigSyncRepository
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Fetch sync tracking metadata for a configuration file.
     *
     * @return null|array<string,mixed>
     */
    public function fetchSyncTracking(string $configFilePath): ?array
    {
        $sql = <<<'SQL'
            SELECT file_modified_at, last_synced_at, sync_status
            FROM config_sync_tracking
            WHERE config_file_path = ?
        SQL;

        $result = $this->db->runQuery($sql, [$configFilePath]);

        return $result[0] ?? null;
    }

    /**
     * Insert or update a server definition.
     *
     * @return bool true when a new record was inserted, false when updated
     */
    public function upsertServer(
        string $serverName,
        ?string $hostname,
        ?string $provider,
        string $metadata
    ): bool {
        $existing = $this->db->runQuery(
            'SELECT id FROM servers WHERE name = ?',
            [$serverName]
        );

        if (!empty($existing)) {
            $sql = <<<'SQL'
                UPDATE servers
                SET hostname = ?,
                    provider = ?,
                    metadata = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE name = ?
            SQL;
            $this->db->runQuery($sql, [$hostname, $provider, $metadata, $serverName]);

            return false;
        }

        $sql = <<<'SQL'
            INSERT INTO servers (name, hostname, provider, metadata, created_at, updated_at)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        SQL;
        $this->db->runQuery($sql, [$serverName, $hostname, $provider, $metadata]);

        return true;
    }

    /**
     * Resolve server ID by canonical name.
     *
     * @return null|int Server ID or null when not found
     */
    public function findServerIdByName(string $serverName): ?int
    {
        $result = $this->db->runQuery(
            'SELECT id FROM servers WHERE name = ? LIMIT 1',
            [$serverName]
        );

        if (is_array($result) && isset($result[0]['id'])) {
            return (int) $result[0]['id'];
        }

        return null;
    }

    /**
     * Insert or update an application definition discovered from node payloads.
     *
     * Skips the UPDATE when the stored definition already matches, so
     * updated_at only moves when the application's definition changed.
     *
     * @return bool true when a new record was inserted, false otherwise
     */
    public function upsertApplication(
        string $code,
        string $displayName,
        ?string $appEnv
    ): bool {
        $existing = $this->db->runQuery(
            'SELECT id, display_name, app_env FROM applications WHERE code = ?',
            [$code]
        );

        if (is_array($existing) && $existing !== []) {
            $current = $existing[0];
            if (($current['display_name'] ?? null) === $displayName
                && ($current['app_env'] ?? null) === $appEnv
            ) {
                return false;
            }

            $sql = <<<'SQL'
                UPDATE applications
                SET display_name = ?,
                    app_env = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE code = ?
            SQL;
            $this->db->runQuery($sql, [$displayName, $appEnv, $code]);

            return false;
        }

        $sql = <<<'SQL'
            INSERT INTO applications (code, display_name, app_env, created_at, updated_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        SQL;
        $this->db->runQuery($sql, [$code, $displayName, $appEnv]);

        return true;
    }

    /**
     * Fetch map of server names to IDs.
     *
     * @return array<string,int>
     */
    public function fetchServerIdMap(): array
    {
        $result = $this->db->runQuery('SELECT id, name FROM servers', []);

        if (!is_array($result)) {
            return [];
        }

        $map = [];
        foreach ($result as $row) {
            $name = (string) ($row['name'] ?? '');
            $id = isset($row['id']) ? (int) $row['id'] : null;
            if ($name === '' || $id === null) {
                continue;
            }
            $map[$name] = $id;
        }

        return $map;
    }

    /**
     * Fetch map of application codes to IDs.
     *
     * @return array<string,int>
     */
    public function fetchApplicationIdMap(): array
    {
        $result = $this->db->runQuery('SELECT id, code FROM applications', []);

        if (!is_array($result)) {
            return [];
        }

        $map = [];
        foreach ($result as $row) {
            $code = (string) ($row['code'] ?? '');
            $id = isset($row['id']) ? (int) $row['id'] : null;
            if ($code === '' || $id === null) {
                continue;
            }
            $map[$code] = $id;
        }

        return $map;
    }

    /**
     * Insert or update sync tracking metadata for the configuration file.
     */
    public function upsertSyncTracking(
        string $configFilePath,
        int $fileModifiedTime,
        string $syncStatus,
        string $syncDetails
    ): void {
        $existing = $this->db->runQuery(
            'SELECT id FROM config_sync_tracking WHERE config_file_path = ?',
            [$configFilePath]
        );

        if (!empty($existing)) {
            $sql = <<<'SQL'
                UPDATE config_sync_tracking
                SET file_modified_at = FROM_UNIXTIME(?),
                    last_synced_at = CURRENT_TIMESTAMP,
                    sync_status = ?,
                    sync_details = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE config_file_path = ?
            SQL;
            $this->db->runQuery($sql, [$fileModifiedTime, $syncStatus, $syncDetails, $configFilePath]);

            return;
        }

        $sql = <<<'SQL'
            INSERT INTO config_sync_tracking
            (config_file_path, file_modified_at, last_synced_at, sync_status, sync_details, created_at, updated_at)
            VALUES (?, FROM_UNIXTIME(?), CURRENT_TIMESTAMP, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        SQL;
        $this->db->runQuery($sql, [$configFilePath, $fileModifiedTime, $syncStatus, $syncDetails]);
    }
}
