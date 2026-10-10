<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Models\AppFatalState;
use Mata\Models\AppFatalStateRepository;
use Mata\Services\Event\Dto\EventCommand;
use Mata\Services\Event\Dto\EventMetric;
use Mata\Services\Event\EventManager;
use Mata\Services\Identity\ServerApplicationIdResolver;
use Mata\Services\Monitor\Dto\AppErrorMonitoringResult;
use Mata\Services\Monitor\Dto\AppFatalErrorPolicy;
use Mata\Services\Monitor\Dto\AppLogSizePolicy;
use Mata\Services\Monitor\Dto\AppMonitoringSettings;
use Mata\Views\Support\NumberFormatter;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class AppErrorMonitor implements AppErrorMonitorInterface
{
    public function __construct(
        private EventManager $eventManager,
        private ServerApplicationIdResolver $idResolver,
        private AppFatalStateRepository $stateRepository,
        private AppFatalErrorPolicy $fatalErrorPolicy,
        private AppLogSizePolicy $logSizePolicy,
        private LogSizeEvaluator $logSizeEvaluator,
        private LoggerInterface $logger,
        private NumberFormatter $numberFormatter,
    ) {}

    public function monitor(string $server, array $apps): AppErrorMonitoringResult
    {
        $stats = [
            'appsChecked' => 0, 'errorMonitoringDisabled' => 0, 'logsizeMonitoringDisabled' => 0,
            'eventsDisabled' => 0,
            'unchangedFatalCursors' => 0, 'fatalEventsRecorded' => 0, 'oversizedLogsDetected' => 0,
            'logfileEventsRecorded' => 0, 'eventsNotRecorded' => 0, 'failures' => 0,
        ];

        foreach ($apps as $app => $appData) {
            try {
                if (!is_array($appData)) {
                    throw new RuntimeException('Application payload must be an array');
                }
                $monitoring = AppMonitoringSettings::fromAppPayload($appData, $this->logger);
                // Disabled events skip the app before policy accounting.
                if (!$monitoring->isEnabled('events')) {
                    ++$stats['eventsDisabled'];

                    continue;
                }
                $errorEnabled = $this->fatalErrorPolicy->isEnabled($appData);
                $logsizeEnabled = $this->logSizePolicy->isEnabled($appData);
                if (!$errorEnabled) {
                    ++$stats['errorMonitoringDisabled'];
                }
                if (!$logsizeEnabled) {
                    ++$stats['logsizeMonitoringDisabled'];
                }
                if ($errorEnabled || $logsizeEnabled) {
                    ++$stats['appsChecked'];
                }

                if ($errorEnabled) {
                    try {
                        $this->monitorFatal($server, (string) $app, $appData, $monitoring, $stats);
                    } catch (Throwable $exception) {
                        ++$stats['failures'];
                        $this->logFailure($server, (string) $app, 'fatal', $exception);
                    }
                }
                if ($logsizeEnabled) {
                    try {
                        $this->monitorLogSize($server, (string) $app, $appData, $monitoring, $stats);
                    } catch (Throwable $exception) {
                        ++$stats['failures'];
                        $this->logFailure($server, (string) $app, 'log size', $exception);
                    }
                }
            } catch (Throwable $exception) {
                ++$stats['failures'];
                $this->logFailure($server, (string) $app, 'configuration', $exception);
            }
        }

        return new AppErrorMonitoringResult(
            appsSeen: count($apps),
            appsChecked: $stats['appsChecked'],
            errorMonitoringDisabled: $stats['errorMonitoringDisabled'],
            logsizeMonitoringDisabled: $stats['logsizeMonitoringDisabled'],
            eventsDisabled: $stats['eventsDisabled'],
            demoSkipped: 0,
            unchangedFatalCursors: $stats['unchangedFatalCursors'],
            fatalEventsRecorded: $stats['fatalEventsRecorded'],
            oversizedLogsDetected: $stats['oversizedLogsDetected'],
            logfileEventsRecorded: $stats['logfileEventsRecorded'],
            eventsNotRecorded: $stats['eventsNotRecorded'],
            failures: $stats['failures'],
        );
    }

    /**
     * @param array<string,mixed> $appData
     * @param array<string,int>   $stats
     */
    private function monitorFatal(
        string $server,
        string $app,
        array $appData,
        AppMonitoringSettings $monitoring,
        array &$stats
    ): void {
        // Disabled log analysis omits status.
        if (!array_key_exists('status', $appData)) {
            return;
        }
        $status = $appData['status'];
        if (!is_array($status) || !is_int($status['fatal'] ?? null) || $status['fatal'] < 0) {
            throw new RuntimeException('Invalid fatal status');
        }
        $fatalCount = $status['fatal'];
        $cursor = $status['fatal_cursor'] ?? null;
        if ($fatalCount === 0) {
            if ($cursor !== null) {
                throw new RuntimeException('Zero fatal count requires a null fatal cursor');
            }
            [$serverId, $applicationId] = $this->resolveStateIds($server, $app);
            $this->stateRepository->clear($serverId, $applicationId);

            return;
        }
        if (!is_string($cursor) || !preg_match('/^v1:[a-f0-9]{64}$/', $cursor)) {
            throw new RuntimeException('Positive fatal count requires a valid fatal cursor');
        }

        [$serverId, $applicationId] = $this->resolveStateIds($server, $app);
        $state = $this->stateRepository->get($serverId, $applicationId);
        if ($state?->fatalCursor === $cursor) {
            ++$stats['unchangedFatalCursors'];

            return;
        }

        $result = $this->eventManager->handle(new EventCommand(
            type: 'fatal_errors_occurring',
            message: sprintf(
                'Fatal Error Detected (Count: %s): %s on Server: %s',
                $this->numberFormatter->formatCompact($fatalCount),
                $app,
                $server,
            ),
            level: 'warning',
            server: $server,
            app: $app,
            context: $this->suppressionContext($monitoring),
            metric: new EventMetric('fatal_error_count', $fatalCount, additional: [
                'previous_fatal_count' => $state?->fatalCount,
                'fatal_count_delta' => max(0, $fatalCount - ($state === null ? 0 : $state->fatalCount)),
                'previous_fatal_cursor' => $state?->fatalCursor,
                'fatal_cursor' => $cursor,
                'detection_reason' => $state instanceof AppFatalState ? 'cursor_changed' : 'initial_observation',
            ]),
        ));
        if (($result['eventRecorded'] ?? false) !== true) {
            ++$stats['eventsNotRecorded'];

            return;
        }

        ++$stats['fatalEventsRecorded'];
        $this->stateRepository->upsert($serverId, $applicationId, $cursor, $fatalCount);
    }

    /**
     * @param array<string,mixed> $appData
     * @param array<string,int>   $stats
     */
    private function monitorLogSize(
        string $server,
        string $app,
        array $appData,
        AppMonitoringSettings $monitoring,
        array &$stats
    ): void {
        // Disabled log-size monitoring omits logs.
        if (!array_key_exists('logs', $appData)) {
            return;
        }
        $logs = $appData['logs'];
        if (!is_array($logs)) {
            throw new RuntimeException('Invalid log summary');
        }
        $assessment = $this->logSizeEvaluator->evaluate($logs);
        if ($assessment->level !== 'alarm') {
            return;
        }
        ++$stats['oversizedLogsDetected'];
        $result = $this->eventManager->handle(new EventCommand(
            type: 'logfile_too_large',
            message: "Log too large ({$assessment->maxSizeFormatted}) - {$assessment->maxPath} : {$app} on Server: {$server}",
            level: 'critical',
            server: $server,
            app: $app,
            context: $this->suppressionContext($monitoring),
        ));
        if (($result['eventRecorded'] ?? false) === true) {
            ++$stats['logfileEventsRecorded'];

            return;
        }
        ++$stats['eventsNotRecorded'];
    }

    /** @return array<string,mixed> Event context for alarm suppression. */
    private function suppressionContext(AppMonitoringSettings $monitoring): array
    {
        return $monitoring->isEnabled('alarms') ? [] : ['alarms_suppressed' => true];
    }

    /** @return array{0:int,1:int} */
    private function resolveStateIds(string $server, string $app): array
    {
        $serverId = $this->idResolver->resolveServer($server)['id'];
        $applicationId = $this->idResolver->resolveApplication($app)['id'];
        if ($serverId === null || $applicationId === null) {
            throw new RuntimeException('Server or application ID is unavailable after inventory sync');
        }

        return [$serverId, $applicationId];
    }

    private function logFailure(string $server, string $app, string $check, Throwable $exception): void
    {
        $this->logger->warning("{$server}/{$app}: {$check} monitoring failed", ['exception' => $exception]);
    }
}
