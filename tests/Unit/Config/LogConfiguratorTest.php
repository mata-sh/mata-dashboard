<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Config\Dto\LoggingConfig;
use Mata\Config\LogConfigurator;
use Mata\Tests\TestCase;
use Mata\Utils\PathResolver;

/**
 * @internal
 *
 * @covers \Mata\Config\LogConfigurator
 */
final class LogConfiguratorTest extends TestCase
{
    public function testWebLoggerAddsUrlAndHttpMethodWithoutIp(): void
    {
        $root = $this->createTempDirectory();
        $entryPoint = $root . '/app/public/index.php';
        mkdir(dirname($entryPoint), 0777, true);
        mkdir($root . '/app/logs', 0777, true);
        touch($entryPoint);
        $server = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/events?token=secret';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';

        try {
            $paths = PathResolver::fromEntryPoint($entryPoint);
            $logger = (new LogConfigurator())->createConfiguredLogger(
                new LoggingConfig('logs/app.log', 'info'),
                $paths,
            );
            $logger->info('request');
            $logger->close();
            $contents = file_get_contents($root . '/app/logs/app.log');

            self::assertIsString($contents);
            self::assertStringContainsString('POST', $contents);
            self::assertStringContainsString('/api/events?token=secret', $contents);
            self::assertStringNotContainsString('192.0.2.10', $contents);
        } finally {
            $_SERVER = $server;
            $this->cleanupTempFiles([$root]);
        }
    }

    public function testCronLoggerUsesConfiguredWarningLevel(): void
    {
        $root = $this->createTempDirectory();
        $entryPoint = $root . '/app/cron/job.php';
        mkdir(dirname($entryPoint), 0777, true);
        mkdir($root . '/app/logs/cron', 0777, true);
        touch($entryPoint);

        try {
            $paths = PathResolver::fromEntryPoint($entryPoint);
            $config = new LoggingConfig('logs/app.log', 'warning');
            $logger = (new LogConfigurator())->createCronLogger($config, $paths, 'test-job');

            $logger->debug('debug message');
            $logger->info('info message');
            $logger->warning('warning message');
            $logger->error('error message');
            $logger->close();

            $contents = file_get_contents($root . '/app/logs/cron/app-test-job.log');

            self::assertIsString($contents);
            self::assertStringNotContainsString('debug message', $contents);
            self::assertStringNotContainsString('info message', $contents);
            self::assertStringContainsString('warning message', $contents);
            self::assertStringContainsString('error message', $contents);
        } finally {
            $this->cleanupTempFiles([$root]);
        }
    }
}
