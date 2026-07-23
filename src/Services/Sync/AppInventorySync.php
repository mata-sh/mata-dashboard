<?php

declare(strict_types=1);

namespace Mata\Services\Sync;

use Mata\Models\ConfigSyncRepository;
use Mata\Persistence\Identity\ServerApplicationIdResolver;
use Mata\Utils\TypeConverter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Syncs applications discovered in node /apps payloads to the database.
 *
 * Application rows are the join target for events and alarms. Without them,
 * app-specific events are stored with application_id NULL and alarm
 * subjects fall back to the global placeholder.
 */
final class AppInventorySync
{
    public function __construct(
        private ConfigSyncRepository $repository,
        private ServerApplicationIdResolver $idResolver,
        private LoggerInterface $log,
    ) {}

    /**
     * Upsert applications from a normalized node payload and refresh resolver maps.
     *
     * @param array<string,array<string,mixed>> $rawAppsData Normalized applications keyed by app code
     *
     * @return int Number of applications synced
     */
    public function sync(array $rawAppsData): int
    {
        if ($rawAppsData === []) {
            return 0;
        }

        $synced = 0;
        $created = 0;

        foreach ($rawAppsData as $appKey => $appData) {
            $code = trim((string) $appKey);
            if ($code === '' || !is_array($appData)) {
                continue;
            }

            $displayName = trim(TypeConverter::toString($appData['title'] ?? null) ?? '');
            if ($displayName === '') {
                $displayName = $code;
            }
            $appEnv = TypeConverter::toString($appData['env'] ?? null);

            try {
                if ($this->repository->upsertApplication($code, $displayName, $appEnv)) {
                    ++$created;
                }
                ++$synced;
            } catch (Throwable $exception) {
                $this->log->warning("Failed to sync application {$code}", ['error' => $exception->getMessage()]);
            }
        }

        if ($synced > 0) {
            try {
                $this->idResolver->refreshFromRepository();
            } catch (Throwable $exception) {
                $this->log->warning('Failed to refresh ID resolver after application sync', ['error' => $exception->getMessage()]);
            }
        }

        if ($created > 0) {
            $this->log->info("Application inventory synced: {$created} new, {$synced} total");
        }

        return $synced;
    }
}
