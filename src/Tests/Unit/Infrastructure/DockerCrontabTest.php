<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Infrastructure;

use Mata\Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class DockerCrontabTest extends TestCase
{
    /** @var array<string,array{schedule:string,log:string}> */
    private const JOBS = [
        'servers_data_fetch.php' => [
            'schedule' => '*/5 * * * *',
            'log' => 'app-servers-data-fetch.log',
        ],
        'servers_metrics_collect.php' => [
            'schedule' => '*/5 * * * *',
            'log' => 'app-servers-metrics-collect.log',
        ],
        'servers_metrics_aggregate.php' => [
            'schedule' => '*/15 * * * *',
            'log' => 'app-servers-metrics-aggregate.log',
        ],
        'alarms_evaluate_critical.php' => [
            'schedule' => '* * * * *',
            'log' => 'app-alarms-evaluate-critical.log',
        ],
        'alarms_evaluate_non_critical.php' => [
            'schedule' => '*/5 * * * *',
            'log' => 'app-alarms-evaluate-non-critical.log',
        ],
        'alarms_dispatch.php' => [
            'schedule' => '* * * * *',
            'log' => 'app-alarms-dispatch.log',
        ],
        'retention_cleanup.php' => [
            'schedule' => '25 2 * * *',
            'log' => 'app-retention-cleanup.log',
        ],
    ];

    public function testRunnableJobsMatchTheCronManifest(): void
    {
        $entries = $this->jobEntries();
        $manifestScripts = array_keys($entries);
        $expectedScripts = array_keys(self::JOBS);
        $runnableScripts = $this->runnableScripts();
        sort($manifestScripts);
        sort($expectedScripts);

        self::assertSame($expectedScripts, $runnableScripts);
        self::assertSame($runnableScripts, $manifestScripts);
        self::assertCount(count(self::JOBS), $entries);

        foreach (self::JOBS as $script => $expected) {
            self::assertSame(1, substr_count($this->crontab(), "cron/{$script}"));
            self::assertArrayHasKey($script, $entries);
            self::assertSame($expected['schedule'], $entries[$script]['schedule']);
            self::assertSame($expected['log'], $entries[$script]['log']);
        }
    }

    public function testManifestExcludesNonRunnableAndLegacyEntries(): void
    {
        $crontab = $this->crontab();

        self::assertStringNotContainsString('cron/bootstrap.php', $crontab);
        self::assertStringNotContainsString('cron/bootstrap_events.php', $crontab);
        self::assertStringNotContainsString('fetch_cronjob_heartbeats.php', $crontab);
        self::assertDoesNotMatchRegularExpression('#logs/cron_[a-z_]+\.log#', $crontab);
    }

    public function testManifestUsesUserCrontabFormatAndPreservesMetricsHealthRotation(): void
    {
        $crontab = $this->crontab();

        self::assertStringContainsString('SHELL=/bin/sh', $crontab);
        self::assertStringContainsString('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', $crontab);
        self::assertStringContainsString('APP_ROOT=/var/www/html', $crontab);
        self::assertStringContainsString('PHP_BIN=/usr/local/bin/php', $crontab);
        self::assertStringContainsString('CRON_LOG_DIR=/var/www/html/logs/cron', $crontab);
        self::assertDoesNotMatchRegularExpression('/^(?:\S+\s+){5}(?:root|www-data)\s/m', $crontab);
        self::assertStringContainsString('find "$CRON_LOG_DIR" -type f -name "*.log"', $crontab);
        self::assertStringContainsString('find "$CRON_LOG_DIR" -type f -name "*.log.*.gz"', $crontab);
        self::assertStringContainsString('! -name "collect_server_metrics_health.log"', $crontab);
        self::assertStringContainsString('! -name "collect_server_metrics_health.log.*.gz"', $crontab);
        self::assertStringContainsString('date +\%Y\%m\%d', $crontab);
    }

    /** @return array<string,array{schedule:string,log:string}> */
    private function jobEntries(): array
    {
        $entries = [];
        $pattern = '/^(?<schedule>(?:\S+\s+){4}\S+)\s+cd "\$APP_ROOT" && "\$PHP_BIN" cron\/(?<script>[a-z_]+\.php) >> "\$CRON_LOG_DIR\/(?<log>app-[a-z-]+\.log)" 2>&1$/';

        foreach (explode("\n", $this->crontab()) as $line) {
            if (preg_match($pattern, $line, $matches) !== 1) {
                continue;
            }

            self::assertArrayNotHasKey($matches['script'], $entries, "Duplicate cron entry for {$matches['script']}");
            $entries[$matches['script']] = [
                'schedule' => $matches['schedule'],
                'log' => $matches['log'],
            ];
        }

        return $entries;
    }

    /** @return list<string> */
    private function runnableScripts(): array
    {
        $paths = glob($this->projectRoot() . '/cron/*.php');

        self::assertNotFalse($paths);

        $scripts = array_map('basename', $paths);
        $scripts = array_values(array_diff($scripts, ['bootstrap.php', 'bootstrap_events.php']));
        sort($scripts);

        return $scripts;
    }

    private function crontab(): string
    {
        $crontab = file_get_contents($this->projectRoot() . '/docker/cron/crontab');

        self::assertNotFalse($crontab);

        return $crontab;
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
