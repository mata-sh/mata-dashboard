<?php

declare(strict_types=1);

namespace Mata\Config;

use Mata\Config\Dto\LoggingConfig;
use Mata\Utils\PathResolver;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Throwable;

final class LogConfigurator
{
    public function createLogger(string $channel, string $path, Level $level): Logger
    {
        $logger = new Logger($channel);
        $this->applyHandler($logger, $path, $level);

        return $logger;
    }

    public function createBootLogger(PathResolver $paths, string $channel = 'boot'): Logger
    {
        $path = $paths->bootLogPath;

        return $this->createLogger($channel, $path, Level::Error);
    }

    public function createCronBootLogger(
        PathResolver $paths,
        string $jobName,
        string $channel = 'cronjob'
    ): Logger {
        return $this->createLogger($channel, $this->cronLogPath($paths, $jobName), Level::Error);
    }

    public function createCronLogger(
        LoggingConfig $config,
        PathResolver $paths,
        string $jobName,
        string $channel = 'cronjob'
    ): Logger {
        $logger = new Logger($channel);
        $resolvedLevel = $this->resolveLogLevel($config->level, $logger);
        $this->applyHandler($logger, $this->cronLogPath($paths, $jobName), $resolvedLevel);

        return $logger;
    }

    public function createConfiguredLogger(
        LoggingConfig $config,
        PathResolver $paths,
        string $channel = 'app'
    ): Logger {
        $logger = new Logger($channel);
        $this->applyConfiguredLogger($logger, $config, $paths);

        return $logger;
    }

    public function applyConfiguredLogger(Logger $logger, LoggingConfig $config, PathResolver $paths): void
    {
        $path = $paths->appLogPath;
        $level = $this->resolveLogLevel($config->level, $logger);
        $this->applyHandler($logger, $path, $level);
    }

    private function applyHandler(Logger $logger, string $path, Level $level): void
    {
        $handler = new StreamHandler($path, $level);
        $handler->setFormatter($this->createFormatter());
        $logger->setHandlers([$handler]);
    }

    private function cronLogPath(PathResolver $paths, string $jobName): string
    {
        return $paths->logsPath . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . "app-{$jobName}.log";
    }

    private function createFormatter(): LineFormatter
    {
        $dateFormat = 'Y-m-d H:i:s';
        $output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";

        return new LineFormatter($output, $dateFormat, true, true);
    }

    private function resolveLogLevel(string $level, Logger $logger): Level
    {
        try {
            return Level::fromName($level);
        } catch (Throwable $e) {
            $logger->warning('Invalid monolog level configured, falling back to debug', [
                'level' => $level,
            ]);

            return Level::Debug;
        }
    }
}
