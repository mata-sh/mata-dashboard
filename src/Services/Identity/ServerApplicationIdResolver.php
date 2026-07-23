<?php

declare(strict_types=1);

namespace Mata\Services\Identity;

use Mata\Models\ConfigSyncRepository;

/**
 * Resolves server and application identifiers from human-readable names or numeric IDs.
 *
 * Keeps in-memory lookup tables that can be hydrated from the database via ConfigSyncRepository
 * and enriched with names sourced from configuration files.
 */
class ServerApplicationIdResolver
{
    private ?ConfigSyncRepository $repository;

    /** @var array<string,null|int> */
    private array $serverIdByName = [];

    /** @var array<int,string> */
    private array $serverNameById = [];

    /** @var array<string,null|int> */
    private array $applicationIdByName = [];

    /** @var array<int,string> */
    private array $applicationNameById = [];

    /**
     * @param null|ConfigSyncRepository $repository        optional repository for refreshing lookup tables
     * @param array<string,int>         $serverIdMap       optional initial server name to ID map
     * @param array<string,int>         $applicationIdMap  optional initial application name/code to ID map
     * @param array<int|string,mixed>   $serverConfig      optional server config used to seed known names
     * @param array<int|string,mixed>   $applicationConfig optional app config used to seed known names
     */
    public function __construct(
        ?ConfigSyncRepository $repository = null,
        array $serverIdMap = [],
        array $applicationIdMap = [],
        array $serverConfig = [],
        array $applicationConfig = []
    ) {
        $this->repository = $repository;

        if ($serverIdMap || $applicationIdMap || $serverConfig || $applicationConfig) {
            $this->updateMaps($serverIdMap, $applicationIdMap, $serverConfig, $applicationConfig);
        }
    }

    /**
     * Refresh lookup tables from the repository, optionally enriching them with config entries.
     *
     * @param array<int|string,mixed> $serverConfig
     * @param array<int|string,mixed> $applicationConfig
     */
    public function refreshFromRepository(
        array $serverConfig = [],
        array $applicationConfig = []
    ): void {
        if ($this->repository === null) {
            $this->updateMaps([], [], $serverConfig, $applicationConfig);

            return;
        }

        $this->updateMaps(
            $this->repository->fetchServerIdMap(),
            $this->repository->fetchApplicationIdMap(),
            $serverConfig,
            $applicationConfig
        );
    }

    /**
     * Update resolver maps at runtime.
     *
     * @param array<string,null|int>  $serverIdMap
     * @param array<string,null|int>  $applicationIdMap
     * @param array<int|string,mixed> $serverConfig
     * @param array<int|string,mixed> $applicationConfig
     */
    public function updateMaps(
        array $serverIdMap,
        array $applicationIdMap,
        array $serverConfig = [],
        array $applicationConfig = []
    ): void {
        [$this->serverIdByName, $this->serverNameById] = $this->buildIdMaps($serverIdMap, $serverConfig);
        [$this->applicationIdByName, $this->applicationNameById] = $this->buildIdMaps($applicationIdMap, $applicationConfig);
    }

    /**
     * Resolve server identifier to an ID/name tuple.
     *
     * @param null|int|string $server human input (name or ID)
     * @param null|int        $id     pre-resolved ID provided by caller
     *
     * @return array{id: null|int, name: null|string}
     */
    public function resolveServer(int|string|null $server, ?int $id = null): array
    {
        return $this->resolveGeneric($server, $id, $this->serverIdByName, $this->serverNameById);
    }

    /**
     * Resolve application identifier to an ID/name tuple.
     *
     * @param null|int|string $app human input (code/name or ID)
     * @param null|int        $id  pre-resolved ID provided by caller
     *
     * @return array{id: null|int, name: null|string}
     */
    public function resolveApplication(int|string|null $app, ?int $id = null): array
    {
        return $this->resolveGeneric($app, $id, $this->applicationIdByName, $this->applicationNameById);
    }

    // =====================================================================================================
    // Internal Helpers
    // =====================================================================================================

    /**
     * Build bidirectional ID/name lookup maps.
     *
     * @param array<string,null|int>  $idMap
     * @param array<int|string,mixed> $config
     *
     * @return array{0: array<string,null|int>, 1: array<int,string>}
     */
    private function buildIdMaps(array $idMap, array $config): array
    {
        $byName = [];
        $byId = [];

        $names = array_unique(array_merge(array_keys($idMap), $this->collectNames($config)));

        foreach ($names as $name) {
            $id = $idMap[$name] ?? null;
            $byName[$name] = $id;

            if ($id !== null) {
                if (isset($byId[$id]) && $byId[$id] !== $name) {
                    // Soft guard for duplicates — prefer first occurrence.
                    continue;
                }
                $byId[$id] = $name;
            }
        }

        return [$byName, $byId];
    }

    /**
     * Generic resolution logic for either servers or applications.
     *
     * @param array<string,null|int> $byName
     * @param array<int,string>      $byId
     *
     * @return array{id: null|int, name: null|string}
     */
    private function resolveGeneric(
        int|string|null $value,
        ?int $id,
        array &$byName,
        array &$byId
    ): array {
        $name = $this->normaliseName($value);

        if ($id !== null) {
            return [
                'id' => $id,
                'name' => $byId[$id] ?? $name ?? ($value !== null ? (string) $value : null),
            ];
        }

        if (is_int($value)) {
            return [
                'id' => $value,
                'name' => $byId[$value] ?? (string) $value,
            ];
        }

        if ($name === null) {
            return ['id' => null, 'name' => null];
        }

        $resolvedId = $byName[$name] ?? null;
        if ($resolvedId !== null && !isset($byId[$resolvedId])) {
            $byId[$resolvedId] = $name;
        }

        return [
            'id' => $resolvedId,
            'name' => $name,
        ];
    }

    /**
     * Extracts all potential name identifiers from configuration.
     *
     * @param array<int|string,mixed> $config
     *
     * @return array<int,string>
     */
    private function collectNames(array $config): array
    {
        $names = [];

        foreach ($config as $key => $value) {
            if (is_string($key)) {
                $names[] = $key;

                continue;
            }

            if (is_array($value)) {
                foreach (['name', 'identifier', 'code'] as $field) {
                    if (isset($value[$field]) && is_string($value[$field])) {
                        $names[] = $value[$field];

                        break;
                    }
                }
            }
        }

        $names = array_map('trim', array_filter($names, static fn ($n) => $n !== ''));

        return array_values(array_unique($names));
    }

    /**
     * Normalises a possible name or identifier value.
     */
    private function normaliseName(int|string|null $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }

        return null;
    }
}
