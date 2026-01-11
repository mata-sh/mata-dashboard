<?php

declare(strict_types=1);

namespace Mata\Services\Foundation;

use Exception;
use Mata\Core\DatabaseInterface;
use Mata\Models\ConfigSyncRepository;
use Monolog\Logger;

/**
 * Settings to Database Synchronization Service.
 *
 * Syncs servers.json settings to database tables, triggered only when
 * the settings file has been modified since the last sync. Enables performance-
 * optimized settings-driven architecture with database persistence for metrics.
 *
 * Key Features:
 * - File modification timestamp tracking for efficient sync detection
 * - Upsert logic to create/update server records based on settings
 * - Automatic filtering of inactive servers (active=false)
 * - Sync history and status tracking
 * - Graceful degradation when database is unavailable
 *
 * Note: "Settings" refers to JSON-based configuration (vs. "Config" which uses DTOs from .env files)
 */
final class SettingsDatabaseSync
{
    private Logger $log;
    private ConfigSyncRepository $repository;

    /**
     * @param DatabaseInterface         $db         Database connection
     * @param Logger                    $log        Application logger
     * @param null|ConfigSyncRepository $repository Optional repository override (primarily for testing)
     */
    public function __construct(DatabaseInterface $db, Logger $log, ?ConfigSyncRepository $repository = null)
    {
        $this->log = $log;
        $this->repository = $repository ?? new ConfigSyncRepository($db);
    }

    /**
     * Sync servers configuration to database if file was modified.
     *
     * Checks if the config file was modified since last successful sync.
     * Only performs database operations if changes detected.
     *
     * @param string              $configFilePath Absolute path to servers.json
     * @param array<string,mixed> $serversConfig  Loaded servers configuration
     */
    public function syncIfNeeded(string $configFilePath, array $serversConfig): void
    {
        try {
            // Check if sync is needed
            if (!$this->needsSync($configFilePath)) {
                return;
            }

            // Perform the sync
            $this->performSync($configFilePath, $serversConfig);

            // Update tracking on success
            $this->updateSyncTracking($configFilePath, true);

            $this->log->info("Config synced to database ({$configFilePath})");
        } catch (Exception $e) {
            $this->log->error('Config sync failed: ' . $e->getMessage());
            $this->updateSyncTracking($configFilePath, false, $e->getMessage());
        }
    }

    /**
     * Check if config file needs syncing based on modification time.
     *
     * @param string $configFilePath Path to config file
     *
     * @return bool True if sync needed, false if already up to date
     */
    private function needsSync(string $configFilePath): bool
    {
        if (!file_exists($configFilePath)) {
            $this->log->warning("Config file not found for sync check: {$configFilePath}");

            return false;
        }

        $fileModifiedTime = filemtime($configFilePath);
        if ($fileModifiedTime === false) {
            $this->log->warning("Cannot get modification time for: {$configFilePath}");

            return false;
        }

        // Check if we've synced this file before
        $tracking = $this->repository->fetchSyncTracking($configFilePath);

        // First sync - always needed
        if (!$tracking) {
            $this->log->info("First time sync for: {$configFilePath}");

            return true;
        }

        // File was modified after last sync - needs sync
        $lastSyncedModTime = strtotime($tracking['file_modified_at']);
        if ($fileModifiedTime > $lastSyncedModTime) {
            $this->log->info("Config file modified since last sync: {$configFilePath}");

            return true;
        }

        // Previous sync failed - retry
        if ($tracking['sync_status'] === 'failed') {
            $this->log->info("Retrying failed sync for: {$configFilePath}");

            return true;
        }

        // No sync needed
        return false;
    }

    /**
     * Perform actual sync of servers to database.
     *
     * Filters active servers and upserts them into the servers table.
     * Uses server name as the unique identifier to match config with database.
     *
     * @param string              $configFilePath Path to config file
     * @param array<string,mixed> $serversConfig  Server configuration array
     */
    private function performSync(string $configFilePath, array $serversConfig): void
    {
        $this->log->info('Syncing servers from config to database');

        $syncedCount = 0;
        $skippedCount = 0;

        foreach ($serversConfig as $serverName => $serverConfig) {
            // Skip documentation/meta entries
            if (str_starts_with($serverName, '_')) {
                continue;
            }

            // Skip inactive servers (active=false explicitly set)
            if (isset($serverConfig['active']) && $serverConfig['active'] === false) {
                ++$skippedCount;
                $this->log->debug("Skipping inactive server: {$serverName}");

                continue;
            }

            // Prepare server data for upsert
            $this->upsertServer($serverName, $serverConfig);
            ++$syncedCount;
        }

        $this->log->info("Sync completed: {$syncedCount} servers synced, {$skippedCount} inactive servers skipped");
    }

    /**
     * Insert or update server record in database.
     *
     * @param string              $serverName   Unique server identifier (config key)
     * @param array<string,mixed> $serverConfig Server configuration
     */
    private function upsertServer(string $serverName, array $serverConfig): void
    {
        // Extract fields from config
        $hostname = $serverConfig['api_url'] ?? $serverConfig['url'] ?? null;
        $provider = $serverConfig['provider'] ?? null;
        $metadata = json_encode([
            'title' => $serverConfig['title'] ?? $serverName,
            'api_url' => $serverConfig['api_url'] ?? null,
            'api_key' => $serverConfig['api_key'] ?? null,
            'primary_color' => $serverConfig['primary_color'] ?? null,
            'secondary_color' => $serverConfig['secondary_color'] ?? null,
            'info' => $serverConfig['info'] ?? null,
            'check_disk_space' => $serverConfig['check_disk_space'] ?? null,
            'allow_http' => $serverConfig['allow_http'] ?? null,
            'suppress_http_warning' => $serverConfig['suppress_http_warning'] ?? null,
        ]);

        if ($metadata === false) {
            $metadata = '{}';
        }

        $isNewServer = $this->repository->upsertServer($serverName, $hostname, $provider, $metadata);

        if ($isNewServer) {
            $this->log->info("Created new server record: {$serverName}");

            return;
        }

        $this->log->debug("Updated server: {$serverName}");
    }

    /**
     * Update sync tracking record.
     *
     * @param string      $configFilePath Path to config file
     * @param bool        $success        Whether sync was successful
     * @param null|string $errorMessage   Error message if failed
     */
    private function updateSyncTracking(string $configFilePath, bool $success, ?string $errorMessage = null): void
    {
        $fileModifiedTime = filemtime($configFilePath);
        if ($fileModifiedTime === false) {
            return;
        }

        $syncStatus = $success ? 'success' : 'failed';
        $syncDetails = json_encode([
            'error' => $errorMessage,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);

        if ($syncDetails === false) {
            $syncDetails = '{}';
        }

        $this->repository->upsertSyncTracking($configFilePath, $fileModifiedTime, $syncStatus, $syncDetails);
    }
}
