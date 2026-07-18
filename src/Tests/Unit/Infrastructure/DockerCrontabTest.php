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
    /**
     * servers_data_fetch and servers_metrics_collect run chained in one entry so
     * collect ingests from the freshly warmed api-cache (see manifest comments).
     */
    private const CHAINED_COLLECTION_ENTRY = '*/5 * * * * . "$CRON_ENV_FILE" && cd "$APP_ROOT" && { "$PHP_BIN" cron/servers_data_fetch.php >> "$CRON_LOG_DIR/app-servers-data-fetch.log" 2>&1 ; "$PHP_BIN" cron/servers_metrics_collect.php >> "$CRON_LOG_DIR/app-servers-metrics-collect.log" 2>&1 ; }';

    /** @var list<string> */
    private const CHAINED_SCRIPTS = [
        'servers_data_fetch.php',
        'servers_metrics_collect.php',
    ];

    /** @var array<string,array{schedule:string,log:string}> */
    private const JOBS = [
        'servers_metrics_aggregate.php' => [
            'schedule' => '4-59/15 * * * *',
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
        $manifestScripts = [...array_keys($entries), ...self::CHAINED_SCRIPTS];
        $expectedScripts = [...array_keys(self::JOBS), ...self::CHAINED_SCRIPTS];
        $runnableScripts = $this->runnableScripts();
        sort($manifestScripts);
        sort($expectedScripts);

        self::assertSame($expectedScripts, $runnableScripts);
        self::assertSame($runnableScripts, $manifestScripts);
        self::assertCount(count(self::JOBS), $entries);
        self::assertStringContainsString(self::CHAINED_COLLECTION_ENTRY, $this->crontab());

        foreach (self::CHAINED_SCRIPTS as $script) {
            self::assertSame(1, substr_count($this->crontab(), "cron/{$script}"));
        }

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

    public function testManifestUsesUserCrontabFormat(): void
    {
        $crontab = $this->crontab();

        self::assertStringContainsString('SHELL=/bin/sh', $crontab);
        self::assertStringContainsString('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', $crontab);
        self::assertStringContainsString('APP_ROOT=/var/www/html', $crontab);
        self::assertStringContainsString('PHP_BIN=/usr/local/bin/php', $crontab);
        self::assertMatchesRegularExpression('#^LOG_DIR=/var/www/html/logs$#m', $crontab);
        self::assertStringContainsString('CRON_LOG_DIR=/var/www/html/logs/cron', $crontab);
        self::assertStringContainsString('CRON_ENV_FILE=/etc/mata/cron.env', $crontab);
        self::assertStringContainsString('. "$CRON_ENV_FILE" && cd "$APP_ROOT"', $crontab);
        self::assertDoesNotMatchRegularExpression('/^(?:\S+\s+){5}(?:root|www-data)\s/m', $crontab);
    }

    public function testLogMaintenanceCoversLogsTreeAndPreservesManagedFiles(): void
    {
        $crontab = $this->crontab();

        // 30-day delete of stale active logs across the whole logs/ tree.
        self::assertStringContainsString(
            'find "$LOG_DIR" -type f -name "*.log" ! -path "*/logs/health/*" -mtime +30 -delete',
            $crontab,
        );

        // 30-day delete of compressed archives and numbered leftovers; audit archives are kept.
        self::assertStringContainsString(
            'find "$LOG_DIR" -type f \( -name "*.log.*.gz" -o -name "*.log.[0-9]" \) ! -path "*/logs/health/*" ! -name "user-management-audit.log.*.gz" -mtime +30 -delete',
            $crontab,
        );

        // Weekly rotation skips empty files and uses cron-escaped date formatting.
        self::assertStringContainsString(
            'find "$LOG_DIR" -type f -name "*.log" ! -path "*/logs/health/*" -size +0c -exec sh -c',
            $crontab,
        );
        self::assertStringContainsString('date +\%Y\%m\%d', $crontab);

        // The app-managed health report directory is excluded from all three log jobs.
        self::assertSame(3, substr_count($crontab, '! -path "*/logs/health/*"'));
    }

    /** @return array<string,array{schedule:string,log:string}> */
    private function jobEntries(): array
    {
        $entries = [];
        $pattern = '/^(?<schedule>(?:\S+\s+){4}\S+)\s+\. "\$CRON_ENV_FILE" && cd "\$APP_ROOT" && "\$PHP_BIN" cron\/(?<script>[a-z_]+\.php) >> "\$CRON_LOG_DIR\/(?<log>app-[a-z-]+\.log)" 2>&1$/';

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
