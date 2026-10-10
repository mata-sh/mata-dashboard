<?php

declare(strict_types=1);

namespace Mata\Bootstrap;

use Exception;
use Mata\Config\DatabaseIntegrityChecker;
use Mata\Config\Dto\ApplicationConfig;
use Mata\Config\EnvConfigLoader;
use Mata\Config\LogConfigurator;
use Mata\Database\DatabaseInterface;
use Mata\Database\NullDatabase;
use Mata\Database\PdoDatabase;
use Mata\Services\Cron\CronJob;
use Mata\Utils\PathResolver;
use Mata\Utils\Tools;
use Monolog\ErrorHandler as MonologErrorHandler;
use Monolog\Logger;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Throwable;

/**
 * Initializes and stores the shared runtime dependencies for a cron job.
 *
 * Jobs that process monitoring events or alarms use {@see CronMonitoringRuntime}
 * for their additional services.
 */
final readonly class CronRuntime
{
    /**
     * @param array<int,array{identifier:string,message:string}> $validationWarnings
     */
    public function __construct(
        public PathResolver $paths,
        public Logger $log,
        public CronJob $cron,
        public ApplicationConfig $appConfig,
        public array $validationWarnings,
        public DatabaseInterface $db,
        public Tools $tools,
        public MailerInterface $mailer,
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
        ini_set('error_log', $paths->logsPath . '/cron/php-error.log');

        $tools = new Tools($paths->appPath);
        $jobName = CronJob::nameFromEntryPoint($entryPointFile);

        $logConfigurator = new LogConfigurator();
        $log = $logConfigurator->createCronBootLogger($paths, $jobName);

        try {
            $configLoader = new EnvConfigLoader();
            $appConfig = $configLoader->load([
                'config.env',
                'db.config.env',
                'mail.config.env',
            ], $paths->appPath . '/config');
            $log = $logConfigurator->createCronLogger($appConfig->logging, $paths, $jobName);
            (new MonologErrorHandler($log))
                ->registerExceptionHandler(callPrevious: false)
                ->registerErrorHandler(callPrevious: false)
                ->registerFatalHandler();

            $validationResult = $configLoader->validateConfig($appConfig);
            if (!$validationResult['is_valid']) {
                foreach ($validationResult['critical_errors'] as $error) {
                    $log->critical("[{$error['identifier']}] {$error['message']}");
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
        $mailer = new Mailer(Transport::fromDsn($appConfig->mail->dsn));
        $cron = new CronJob(
            logger: $log,
            jobName: $jobName,
            lockDir: $paths->tempPath . '/',
            heartbeatFile: "{$paths->logsPath}/cron/heartbeat-{$jobName}.jsonl",
        );

        return new self(
            paths: $paths,
            log: $log,
            cron: $cron,
            appConfig: $appConfig,
            validationWarnings: $validationResult['warnings'],
            db: $db,
            tools: $tools,
            mailer: $mailer,
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
