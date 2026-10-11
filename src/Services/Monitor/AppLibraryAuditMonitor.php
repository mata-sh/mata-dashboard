<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Models\Monitor\AppLibraryAuditStateRepository;
use Mata\Services\Audit\Severity;
use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\Dto\EventMetric;
use Mata\Services\Event\EventManager;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Monitor\Dto\AppLibraryAuditMonitoringResult;
use Mata\Services\Monitor\Dto\AppMonitoringSettings;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Records an event when an application's Composer audit reports security advisories
 * that were not present in the previously evaluated audit.
 *
 * Audits come through the shared api-cache, so the node runs `composer audit` at most
 * once per audit TTL. Fixed advisories shrink the stored set without an event.
 */
final class AppLibraryAuditMonitor
{
    public const EVENT_TYPE = 'library_outdated_security';

    /** Advisory severities that raise the event to critical. */
    private const CRITICAL_SEVERITIES = [Severity::Critical, Severity::High];

    public function __construct(
        private EventManager $eventManager,
        private ServerApplicationIdResolver $idResolver,
        private AppLibraryAuditStateRepository $stateRepository,
        private LoggerInterface $logger,
    ) {}

    public function monitor(string $server, ServerDataCollectorInterface $collector): AppLibraryAuditMonitoringResult
    {
        $appsResponse = $collector->getApps();
        $apps = $appsResponse['result'] ?? null;
        if (($appsResponse['error'] ?? null) !== null || !is_array($apps)) {
            $this->logger->warning("{$server}: apps unavailable, skipping library audit monitoring");

            return new AppLibraryAuditMonitoringResult(failures: 1);
        }

        $counters = [
            'appsChecked' => 0, 'appsSkipped' => 0, 'auditsUnavailable' => 0, 'unchangedAdvisories' => 0,
            'eventsRecorded' => 0, 'eventsNotRecorded' => 0, 'failures' => 0,
        ];

        foreach ($apps as $app => $appData) {
            $app = (string) $app;

            try {
                if (!is_array($appData)) {
                    throw new RuntimeException('Application payload must be an array');
                }
                $monitoring = AppMonitoringSettings::fromAppPayload($appData, $this->logger);
                if (($appData['composer'] ?? false) !== true
                    || !$monitoring->isEnabled('events')
                    || !$monitoring->isEnabled('libraryaudit')) {
                    ++$counters['appsSkipped'];

                    continue;
                }

                ++$counters['appsChecked'];
                $this->monitorApp($server, $app, $collector, $monitoring, $counters);
            } catch (Throwable $exception) {
                ++$counters['failures'];
                $this->logger->warning("{$server}/{$app}: library audit monitoring failed", ['exception' => $exception]);
            }
        }

        return new AppLibraryAuditMonitoringResult(
            appsSeen: count($apps),
            appsChecked: $counters['appsChecked'],
            appsSkipped: $counters['appsSkipped'],
            auditsUnavailable: $counters['auditsUnavailable'],
            unchangedAdvisories: $counters['unchangedAdvisories'],
            eventsRecorded: $counters['eventsRecorded'],
            eventsNotRecorded: $counters['eventsNotRecorded'],
            failures: $counters['failures'],
        );
    }

    /** @param array<string,int> $counters */
    private function monitorApp(
        string $server,
        string $app,
        ServerDataCollectorInterface $collector,
        AppMonitoringSettings $monitoring,
        array &$counters,
    ): void {
        $response = $collector->fetchAppComposerAudit($app);
        $audit = $response['result'] ?? null;
        if ($response === null || ($response['error'] ?? null) !== null || !is_array($audit) || ($audit['status'] ?? null) !== 'ok') {
            // A failed audit must not clear the stored set, or the next good audit re-reports everything.
            ++$counters['auditsUnavailable'];

            return;
        }

        $advisories = $this->advisoriesById($audit['advisories'] ?? []);
        [$serverId, $applicationId] = $this->resolveStateIds($server, $app);
        if ($advisories === []) {
            $this->stateRepository->clear($serverId, $applicationId);

            return;
        }

        // PHP turns numeric-string keys into ints; state holds strings.
        $currentIds = array_map('strval', array_keys($advisories));
        $previous = $this->stateRepository->get($serverId, $applicationId);
        $newIds = array_values(array_diff($currentIds, $previous->advisoryIds ?? []));
        if ($newIds === []) {
            ++$counters['unchangedAdvisories'];
            if ($previous?->advisoryIds !== $currentIds) {
                $this->stateRepository->upsert($serverId, $applicationId, $currentIds);
            }

            return;
        }

        $newAdvisories = array_intersect_key($advisories, array_flip($newIds));
        $worstSeverity = $this->worstSeverity($newAdvisories);
        $result = $this->eventManager->handle(new EventCommand(
            type: self::EVENT_TYPE,
            message: sprintf(
                'Library Security Advisories (New: %d, Total: %d): %s on Server: %s',
                count($newIds),
                count($currentIds),
                $app,
                $server,
            ),
            level: in_array($worstSeverity, self::CRITICAL_SEVERITIES, true) ? 'critical' : 'warning',
            server: $server,
            app: $app,
            context: $monitoring->isEnabled('alarms') ? [] : ['alarms_suppressed' => true],
            metric: new EventMetric('library_advisory_count', count($currentIds), additional: [
                'new_advisory_ids' => $newIds,
                'new_packages' => array_values(array_unique(array_column($newAdvisories, 'package'))),
                'worst_new_severity' => $worstSeverity->value,
                'previous_advisory_count' => $previous === null ? null : count($previous->advisoryIds),
                'detection_reason' => $previous === null ? 'initial_observation' : 'new_advisories',
            ]),
        ));
        if (($result['eventRecorded'] ?? false) !== true) {
            // Keep the previous set so the next run reports these advisories again.
            ++$counters['eventsNotRecorded'];

            return;
        }

        ++$counters['eventsRecorded'];
        $this->stateRepository->upsert($serverId, $applicationId, $currentIds);
    }

    /**
     * Key normalized advisories by a stable ID, sorted for deterministic state.
     *
     * @return array<string,array{package:string,severity:Severity}>
     */
    private function advisoriesById(mixed $advisories): array
    {
        if (!is_array($advisories)) {
            throw new RuntimeException('Invalid audit advisories');
        }

        $byId = [];
        foreach ($advisories as $advisory) {
            if (!is_array($advisory) || !is_string($advisory['package'] ?? null)) {
                throw new RuntimeException('Invalid audit advisory');
            }
            $package = $advisory['package'];
            $id = $advisory['advisory_id'] ?? null;
            if (!is_string($id) || $id === '') {
                // Composer always reports advisoryId; fall back to a CVE/title identity just in case.
                $id = $package . '#' . ($advisory['cve'] ?? $advisory['title'] ?? '');
            }
            $byId[$id] = [
                'package' => $package,
                'severity' => Severity::tryFrom((string) ($advisory['severity'] ?? '')) ?? Severity::Unknown,
            ];
        }
        ksort($byId, SORT_STRING);

        return $byId;
    }

    /** @param array<string,array{package:string,severity:Severity}> $advisories */
    private function worstSeverity(array $advisories): Severity
    {
        $present = array_column($advisories, 'severity');
        foreach (Severity::cases() as $severity) {
            if (in_array($severity, $present, true)) {
                return $severity;
            }
        }

        return Severity::Unknown;
    }

    /** @return array{0:int,1:int} */
    private function resolveStateIds(string $server, string $app): array
    {
        $serverId = $this->idResolver->resolveServer($server)['id'];
        $applicationId = $this->idResolver->resolveApplication($app)['id'];
        if ($serverId === null || $applicationId === null) {
            throw new RuntimeException('Server or application ID is unavailable; servers_data_fetch syncs the inventory');
        }

        return [$serverId, $applicationId];
    }
}
