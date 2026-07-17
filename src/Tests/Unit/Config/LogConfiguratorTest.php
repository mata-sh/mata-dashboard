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
    public function testCronLoggerUsesConfiguredWarningLevel(): void
    {
        $root = $this->createTempDirectory();
        $entryPoint = $root . '/app/cron/job.php';
        mkdir(dirname($entryPoint), 0777, true);
        mkdir($root . '/app/logs/cron', 0777, true);
        touch($entryPoint);

        try {
            $paths = new PathResolver($entryPoint);
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
