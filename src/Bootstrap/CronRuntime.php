<?php

declare(strict_types=1);

namespace Mata\Bootstrap;

use Exception;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\EnvConfigLoader;
use Mata\Config\LogConfigurator;
use Mata\Database\DatabaseIntegrityChecker;
use Mata\Database\DatabaseInterface;
use Mata\Database\NullDatabase;
use Mata\Database\PdoDatabase;
use Mata\Services\Cron\CronHeartbeatWriter;
use Mata\Services\Cron\CronJob;
use Mata\Utils\PathResolver;
use Mata\Utils\Tools;
use Monolog\ErrorHandler as MonologErrorHandler;
use Monolog\Logger;
use Throwable;

/**
 * Initializes and stores the shared runtime dependencies for a cron job.
 *
 * Jobs that process monitoring events or alarms build {@see MonitoringRuntime}
 * from these parts.
 */
final readonly class CronRuntime
{
    /**
     * @param array<int,array{type:string,message:string}> $validationWarnings
     */
    public function __construct(
        public PathResolver $paths,
        public Logger $log,
        public CronJob $cron,
        public ApplicationConfig $appConfig,
        public array $validationWarnings,
        public DatabaseInterface $db,
        public Tools $tools,
    ) {}

    /**
     * Boot the runtime for a cron entry point.
     *
     * Configuration failures terminate the process. Database failures use a
     * {@see NullDatabase}, allowing jobs without database access to continue.
     *
     * @param string $entryPointFile `__FILE__` of the calling script
     */
    public static function boot(string $entryPointFile): self
    {
        $paths = PathResolver::fromEntryPoint($entryPointFile);

        ini_set('display_errors', 0);
        ini_set('display_startup_errors', 0);
        ini_set('log_errors', 1);
        ini_set('error_log', $paths->cronLogsPath . '/php-error.log');

        $tools = new Tools($paths->appPath);
        $jobName = CronJob::nameFromEntryPoint($entryPointFile);

        $logConfigurator = new LogConfigurator();
        $log = $logConfigurator->createCronBootLogger($paths, $jobName);

        try {
            $configLoader = new EnvConfigLoader();
            $appConfig = $configLoader->load(EnvConfigLoader::INSTANCE_FILES, $paths->configPath);

            $paths = $paths->withOverrides(
                logFile: $appConfig->logging->filePath,
                tempDir: $appConfig->environment->tempDir,
            );
            $paths->ensureLogsDirectory();
            $paths->ensureTempDirectory();
            $paths->ensureCronLogsDirectory();

            ini_set('error_log', $paths->cronLogsPath . '/php-error.log');
            $log = $logConfigurator->createCronLogger($appConfig->logging, $paths, $jobName);
            (new MonologErrorHandler($log))
                ->registerExceptionHandler(callPrevious: false)
                ->registerErrorHandler(callPrevious: false)
                ->registerFatalHandler();

            $validationResult = $configLoader->validateConfig($appConfig);
            if (!$validationResult['is_valid']) {
                foreach ($validationResult['critical_errors'] as $error) {
                    $log->critical("[{$error['type']}] {$error['message']}");
                }

                fwrite(STDERR, "Critical configuration errors!\n");

                exit(1);
            }
        } catch (Throwable $e) {
            $log->critical('Application Initialization failed! ' . $e->getMessage(), ['exception' => $e]);
            fwrite(STDERR, "Application initialization failed!\n");

            exit(1);
        }

        $db = self::connect($appConfig, $log);
        $cron = new CronJob(
            logger: $log,
            jobName: $jobName,
            heartbeatWriter: new CronHeartbeatWriter($appConfig->logging->cronHeartbeatMaxBytes),
            lockDir: $paths->tempPath . '/',
            heartbeatFile: CronJob::heartbeatFile($paths->cronLogsPath, $jobName),
        );

        return new self(
            paths: $paths,
            log: $log,
            cron: $cron,
            appConfig: $appConfig,
            validationWarnings: $validationResult['warnings'],
            db: $db,
            tools: $tools,
        );
    }

    /** Return whether a usable database connection is available. */
    public function hasDatabase(): bool
    {
        return !$this->db instanceof NullDatabase;
    }

    /** Terminate the job if the database is unavailable. */
    public function requireDatabase(): void
    {
        if ($this->hasDatabase()) {
            return;
        }

        $this->log->critical('Database required but unavailable. Aborting cronjob.');
        fwrite(STDERR, "Database required but unavailable.\n");

        exit(1);
    }

    /**
     * Connect to the database and verify schema readiness.
     *
     * Return a {@see NullDatabase} if either step fails.
     */
    private static function connect(ApplicationConfig $appConfig, Logger $log): DatabaseInterface
    {
        try {
            $db = new PdoDatabase($appConfig->database);

            try {
                (new DatabaseIntegrityChecker($db))->assertReady();
            } catch (Throwable $probeException) {
                $log->error('Database quick probe failed; falling back to NullDatabase. ' . $probeException->getMessage());
                $log->debug('Database quick probe failure details', ['exception' => $probeException]);

                return new NullDatabase($log);
            }

            return $db;
        } catch (Exception $e) {
            $log->error('Failed to initialize DB Connection: ' . $e->getMessage());

            return new NullDatabase($log);
        }
    }
}
