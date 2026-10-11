<?php

declare(strict_types=1);

namespace Mata\Services\Sync;

use Exception;
use Mata\Config\Monitoring\ServerConfigState;
use Mata\Database\DatabaseInterface;
use Mata\Database\NullDatabase;
use Mata\Models\Sync\ConfigSyncRepository;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\EventManager;
use Monolog\Logger;

/**
 * Server Configuration Database Synchronization Service.
 *
 * Syncs servers.json configuration to database tables when the configuration
 * file has changed since the last sync.
 *
 * Key Features:
 * - File modification timestamp tracking for efficient sync detection
 * - Upsert logic to create/update server records based on configuration
 * - Automatic filtering of disabled servers
 * - Sync history and status tracking
 * - Graceful degradation when database is unavailable: skipped without a database,
 *   and a failed sync is reported as an event instead of being thrown
 */
final class ServerConfigDatabaseSync
{
    private bool $databaseAvailable;
    private Logger $log;
    private EventManager $events;
    private ConfigSyncRepository $repository;

    /**
     * @param DatabaseInterface         $db         Database connection
     * @param Logger                    $log        Application logger
     * @param EventManager              $events     Receives sync failures
     * @param null|ConfigSyncRepository $repository Optional repository override (primarily for testing)
     */
    public function __construct(DatabaseInterface $db, Logger $log, EventManager $events, ?ConfigSyncRepository $repository = null)
    {
        $this->databaseAvailable = !$db instanceof NullDatabase;
        $this->log = $log;
        $this->events = $events;
        $this->repository = $repository ?? new ConfigSyncRepository($db);
    }

    /**
     * Sync servers configuration to database if file was modified.
     *
     * Checks if the config file was modified since last successful sync.
     * Only performs database operations if changes detected. Does nothing without a
     * database or when no servers were loaded (servers.json missing or invalid).
     *
     * @param string              $configFilePath Absolute path to servers.json
     * @param array<string,mixed> $serversConfig  Loaded servers configuration
     */
    public function syncIfNeeded(string $configFilePath, array $serversConfig): void
    {
        if (!$this->databaseAvailable || $serversConfig === []) {
            return;
        }

        try {
            // Check if sync is needed
            if (!$this->needsSync($configFilePath)) {
                return;
            }

            // Perform the sync
            $this->performSync($configFilePath, $serversConfig);

            // Update tracking on success
            $this->updateSyncTracking($configFilePath, true);

            $this->log->info("Server configuration synced to database ({$configFilePath})");
        } catch (Exception $e) {
            $this->reportFailure($configFilePath, $e);
        }
    }

    /** Report a failed sync; recording the failure may hit the same broken database, so it must not throw. */
    private function reportFailure(string $configFilePath, Exception $failure): void
    {
        $message = 'Failed to sync server configuration to database: ' . $failure->getMessage();
        $this->log->error($message);
        // Persisted event identifier; rename only with a migration.
        $this->events->handle(new EventCommand(type: 'settings_sync_error', message: $message, level: 'warning', context: ['detail' => $message]));

        try {
            $this->updateSyncTracking($configFilePath, false, $failure->getMessage());
        } catch (Exception $trackingFailure) {
            $this->log->error('Could not record the failed server configuration sync: ' . $trackingFailure->getMessage());
        }
    }

    /**
     * Check if the server configuration file needs syncing based on modification time.
     *
     * @param string $configFilePath Path to server configuration file
     *
     * @return bool True if sync needed, false if already up to date
     */
    private function needsSync(string $configFilePath): bool
    {
        if (!file_exists($configFilePath)) {
            $this->log->warning("Server configuration file not found for sync check: {$configFilePath}");

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
            $this->log->info("Server configuration file modified since last sync: {$configFilePath}");

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
     * Filters disabled servers and upserts the rest into the servers table.
     * Uses server name as the unique identifier to match configuration with database.
     *
     * @param string              $configFilePath Path to server configuration file
     * @param array<string,mixed> $serversConfig  Server configuration array
     */
    private function performSync(string $configFilePath, array $serversConfig): void
    {
        $this->log->info('Syncing servers from configuration to database');

        $syncedCount = 0;
        $skippedCount = 0;

        foreach ($serversConfig as $serverName => $serverConfig) {
            // Skip documentation/meta entries
            if (str_starts_with($serverName, '_')) {
                continue;
            }

            if (ServerConfigState::isDisabled($serverConfig)) {
                ++$skippedCount;
                $this->log->debug("Skipping disabled server: {$serverName}");

                continue;
            }

            // Prepare server data for upsert
            $this->upsertServer($serverName, $serverConfig);
            ++$syncedCount;
        }

        $this->log->info("Sync completed: {$syncedCount} servers synced, {$skippedCount} disabled servers skipped");
    }

    /**
     * Insert or update server record in database.
     *
     * @param string              $serverName   Unique server identifier (config key)
     * @param array<string,mixed> $serverConfig Server configuration
     */
    private function upsertServer(string $serverName, array $serverConfig): void
    {
        // Extract fields from configuration
        $hostname = $serverConfig['api_url'] ?? $serverConfig['url'] ?? null;
        $provider = $serverConfig['provider'] ?? null;
        $metadata = json_encode([
            'title' => $serverConfig['title'] ?? $serverName,
            'api_url' => $serverConfig['api_url'] ?? null,
            'api_key' => $serverConfig['api_key'] ?? null,
            'primary_color' => $serverConfig['primary_color'] ?? null,
            'secondary_color' => $serverConfig['secondary_color'] ?? null,
            'env' => $serverConfig['env'] ?? null,
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
     * @param string      $configFilePath Path to server configuration file
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
